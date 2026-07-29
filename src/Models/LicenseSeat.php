<?php

declare(strict_types=1);

namespace Blax\Shop\Models;

use Blax\Shop\Enums\SeatStatus;
use Blax\Shop\Events\SeatAssigned;
use Blax\Shop\Events\SeatReassigned;
use Blax\Shop\Events\SeatRevoked;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One assignable license seat produced by a seat-based purchase or subscription
 * of `quantity = N` (which yields N seats). A seat can be handed to any user,
 * moved to a different user at any time, or reclaimed — and each transition
 * runs the *product's own* {@see ProductAction} grants against the seat's
 * current holder rather than the buyer. That is the whole point: an assigned
 * seat confers exactly the access the product would confer if the holder had
 * bought it personally, with zero duplicated grant logic.
 *
 * Grants flow through the same engine as a normal purchase
 * ({@see Product::callActions()}), but with an explicit `grantee` in the action
 * context. Host action jobs resolve the target as
 * `grantee ?? purchaser ?? subscription->user`, so passing `grantee` here
 * redirects the grant to the seat holder without touching buyer-grant flows.
 *
 * @property string $id
 * @property string $product_id
 * @property string|null $product_purchase_id
 * @property string|null $subscription_id
 * @property string|null $assignee_id
 * @property string|null $assignee_type
 * @property \Blax\Shop\Enums\SeatStatus $status
 * @property \Illuminate\Support\Carbon|null $assigned_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \stdClass $meta
 *
 * @property-read Product|null $product
 * @property-read ProductPurchase|null $purchase
 * @property-read Model|null $assignee
 */
class LicenseSeat extends Model
{
    use HasUuids;

    protected $fillable = [
        'product_id',
        'product_purchase_id',
        'subscription_id',
        'assignee_id',
        'assignee_type',
        'status',
        'assigned_at',
        'revoked_at',
        'expires_at',
        'meta',
    ];

    protected $casts = [
        'status' => SeatStatus::class,
        'assigned_at' => 'datetime',
        'revoked_at' => 'datetime',
        'expires_at' => 'datetime',
        'meta' => 'object',
    ];

    protected $attributes = [
        'status' => 'unassigned',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('shop.tables.license_seats', 'license_seats'));
    }

    /**
     * The product this seat grants access to.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(config('shop.models.product', Product::class));
    }

    /**
     * The one-time purchase that spawned this seat (null for subscription seats).
     *
     * @return BelongsTo<ProductPurchase, $this>
     */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(
            config('shop.models.product_purchase', ProductPurchase::class),
            'product_purchase_id'
        );
    }

    /**
     * The subscription that spawned this seat (null for one-time-purchase seats).
     *
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(
            config('shop.models.subscription', Subscription::class),
            'subscription_id'
        );
    }

    /**
     * The user currently holding this seat (polymorphic; null when free).
     *
     * @return MorphTo<Model, $this>
     */
    public function assignee(): MorphTo
    {
        return $this->morphTo('assignee');
    }

    /** @param Builder<self> $query */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->where('status', SeatStatus::UNASSIGNED->value);
    }

    /** @param Builder<self> $query */
    public function scopeAssigned(Builder $query): Builder
    {
        return $query->where('status', SeatStatus::ASSIGNED->value);
    }

    /** Seats that still count against the pool (free or held), i.e. not retired. */
    /** @param Builder<self> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SeatStatus::UNASSIGNED->value,
            SeatStatus::ASSIGNED->value,
        ]);
    }

    public function isAssigned(): bool
    {
        return $this->status === SeatStatus::ASSIGNED;
    }

    /**
     * Hand a free seat to a user and grant them the product's access. If the
     * seat is already held by someone else this transparently becomes a
     * {@see reassign()} so callers never have to branch.
     */
    public function assign(Model $assignee): self
    {
        if ($this->isAssigned() && $this->assignee_id !== null && ! $this->assigneeIs($assignee)) {
            return $this->reassign($assignee);
        }

        $this->forceFill([
            'assignee_id' => (string) $assignee->getKey(),
            'assignee_type' => $assignee->getMorphClass(),
            'status' => SeatStatus::ASSIGNED,
            'assigned_at' => now(),
            'revoked_at' => null,
        ])->save();

        $this->grant($assignee);
        SeatAssigned::dispatch($this);

        return $this;
    }

    /**
     * Move a held seat to a different user in one operation: revoke the previous
     * holder's grants, then grant the new holder. No-op grant churn if the seat
     * is already assigned to the same user.
     */
    public function reassign(Model $assignee): self
    {
        $previous = $this->assignee;

        if ($previous && ! $this->assigneeIs($assignee)) {
            $this->ungrant($previous);
        }

        $this->forceFill([
            'assignee_id' => (string) $assignee->getKey(),
            'assignee_type' => $assignee->getMorphClass(),
            'status' => SeatStatus::ASSIGNED,
            'assigned_at' => now(),
            'revoked_at' => null,
        ])->save();

        $this->grant($assignee);
        SeatReassigned::dispatch($this, $previous);

        return $this;
    }

    /**
     * Take the seat back from its holder and return it to the pool
     * (UNASSIGNED), revoking the holder's grants. The seat can be assigned
     * again afterwards.
     */
    public function reclaim(): self
    {
        $previous = $this->assignee;

        if ($previous) {
            $this->ungrant($previous);
        }

        $this->forceFill([
            'assignee_id' => null,
            'assignee_type' => null,
            'status' => SeatStatus::UNASSIGNED,
            'revoked_at' => now(),
        ])->save();

        SeatRevoked::dispatch($this, $previous);

        return $this;
    }

    /**
     * Permanently retire the seat (pool shrank, subscription canceled): revoke
     * the holder's grants and mark it REVOKED so it is no longer assignable.
     */
    public function retire(): self
    {
        $previous = $this->assignee;

        if ($previous) {
            $this->ungrant($previous);
        }

        $this->forceFill([
            'assignee_id' => null,
            'assignee_type' => null,
            'status' => SeatStatus::REVOKED,
            'revoked_at' => now(),
        ])->save();

        SeatRevoked::dispatch($this, $previous);

        return $this;
    }

    /**
     * Re-run the current holder's grant with the seat's current `expires_at` —
     * used on subscription renewal so a held seat's access extends into the new
     * billing cycle. No-op for a free seat.
     */
    public function refreshGrant(): self
    {
        if ($this->isAssigned() && $this->assignee) {
            $this->grant($this->assignee);
        }

        return $this;
    }

    /**
     * Fire the product's "seat assigned" grant actions against $assignee. Uses
     * the same {@see ProductAction} engine as a normal purchase, so the holder
     * receives exactly the product's configured access.
     */
    protected function grant(Model $assignee): void
    {
        $product = $this->product;

        if ($product && method_exists($product, 'callActions')) {
            $product->callActions(
                config('shop.seats.assigned_event', 'seat.assigned'),
                $this->purchase,
                [
                    'grantee' => $assignee,
                    'seat' => $this,
                    'expiresAtOverride' => $this->expires_at,
                ]
            );
        }
    }

    /**
     * Fire the product's "seat revoked" actions against $assignee so host apps
     * can undo exactly what {@see grant()} conferred.
     */
    protected function ungrant(Model $assignee): void
    {
        $product = $this->product;

        if ($product && method_exists($product, 'callActions')) {
            $product->callActions(
                config('shop.seats.revoked_event', 'seat.revoked'),
                $this->purchase,
                [
                    'grantee' => $assignee,
                    'seat' => $this,
                ]
            );
        }
    }

    protected function assigneeIs(Model $other): bool
    {
        return (string) $this->assignee_id === (string) $other->getKey()
            && $this->assignee_type === $other->getMorphClass();
    }
}
