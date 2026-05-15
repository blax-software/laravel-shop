<?php

declare(strict_types=1);

namespace Blax\Shop\Models;

use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Traits\HasBookingLifecycle;
use Blax\Shop\Traits\HasLoanLifecycle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Persisted record of "this purchasable was sold/loaned/booked to this purchaser".
 *
 * Single source of truth for any consumption event in the package:
 *
 *  - **E-commerce purchase**: status flows `CART → PENDING → COMPLETED`,
 *    `from`/`until` left null.
 *  - **Booking**: status `COMPLETED`, `from`/`until` carry the reserved window
 *    (see {@see HasBookingLifecycle}).
 *  - **Loan**: status `PENDING` until returned, then `COMPLETED`; `from`/`until`
 *    are check-out and due dates (see {@see HasLoanLifecycle}).
 *
 * The polymorphic `purchasable_*` columns point at what was sold (typically a
 * {@see Product} but can be any {@see \Blax\Shop\Contracts\Purchasable}). The
 * polymorphic `purchaser_*` columns point at who bought it (typically a User,
 * but any model).
 *
 * @property string $id
 * @property \Blax\Shop\Enums\PurchaseStatus $status
 * @property string|null $cart_id
 * @property string|null $price_id
 * @property string $purchasable_id
 * @property string $purchasable_type
 * @property string $purchaser_id
 * @property string $purchaser_type
 * @property int $quantity
 * @property int $amount      Total amount charged, in cents.
 * @property int $amount_paid Amount captured so far, in cents.
 * @property string|null $charge_id
 * @property \Illuminate\Support\Carbon|null $from   Booking start / loan check-out.
 * @property \Illuminate\Support\Carbon|null $until  Booking end / loan due date.
 * @property \stdClass $meta
 *
 * @property-read Model $purchasable
 * @property-read Model $purchaser
 * @property-read Product|null $product
 * @property-read ProductPrice|null $price
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ProductActionRun> $actionRuns
 */
class ProductPurchase extends Model
{
    use HasBookingLifecycle, HasLoanLifecycle, HasUuids;

    protected $fillable = [
        'status',
        'cart_id',
        'price_id',
        'purchasable_id',
        'purchasable_type',
        'purchaser_id',
        'purchaser_type',
        'quantity',
        'amount',
        'amount_paid',
        'charge_id',
        'from',
        'until',
        'meta',
    ];

    protected $casts = [
        'status' => PurchaseStatus::class,
        'quantity' => 'integer',
        'amount' => 'integer',
        'amount_paid' => 'integer',
        'from' => 'datetime',
        'until' => 'datetime',
        'meta' => 'object',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('shop.tables.product_purchases', 'product_purchases'));
    }

    /**
     * What was sold/loaned/booked — usually a {@see Product} but anything
     * implementing {@see \Blax\Shop\Contracts\Purchasable} qualifies.
     *
     * @return MorphTo<Model, $this>
     */
    public function purchasable(): MorphTo
    {
        return $this->morphTo('purchasable');
    }

    /**
     * Who made the purchase (typically a User), polymorphic.
     *
     * @return MorphTo<Model, $this>
     */
    public function purchaser(): MorphTo
    {
        return $this->morphTo('purchaser');
    }

    /**
     * Convenience shortcut to a {@see Product} when the purchasable side IS
     * a product; resolves through `purchasable_id` for the JOIN.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(config('shop.models.product', Product::class));
    }

    /**
     * The price this purchase bills against (see HasLoanLifecycle::calculateCost).
     *
     * @return BelongsTo<ProductPrice, $this>
     */
    public function price(): BelongsTo
    {
        return $this->belongsTo(
            config('shop.models.product_price', ProductPrice::class),
            'price_id'
        );
    }

    /**
     * Resolve the purchaser as a User relation when the polymorphic type
     * matches the configured auth model; returns null otherwise so callers
     * can branch without an instanceof check on the resolved object.
     *
     * Note: returns a {@see MorphTo} (same instance as {@see self::purchaser()})
     * so the caller can `->first()` / eager-load uniformly.
     */
    public function user(): ?MorphTo
    {
        if ($this->purchaser_type === config('auth.providers.users.model', \Workbench\App\Models\User::class)) {
            return $this->purchaser();
        }
        return null;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFromCart(Builder $query, string $cartId): Builder
    {
        return $query->where('cart_id', $cartId);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInCart(Builder $query): Builder
    {
        return $query->where('status', PurchaseStatus::CART->value);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', PurchaseStatus::COMPLETED->value);
    }

    protected static function booted()
    {
        static::created(function ($productPurchase) {
            $product = ($productPurchase->purchasable instanceof Product)
                ? $productPurchase->purchasable
                : null;

            $product ??= ($productPurchase->purchasable instanceof ProductPrice)
                ? $productPurchase->purchasable?->product
                : $product;

            if ($productPurchase->status === PurchaseStatus::COMPLETED && $product) {
                $product->callActions('purchased', $productPurchase);
            }
        });

        // updated purchase from unpaid to paid
        static::updated(function ($productPurchase) {
            $product = ($productPurchase->purchasable instanceof Product)
                ? $productPurchase->purchasable
                : null;

            $product ??= ($productPurchase->purchasable instanceof ProductPrice)
                ? $productPurchase->purchasable?->product
                : $product;


            if ($productPurchase->status === PurchaseStatus::COMPLETED && $product) {
                $product->callActions('purchased', $productPurchase);
            }
        });
    }

    /**
     * Run-log of every {@see ProductAction} fired against the underlying
     * product for this purchase (welcome email, fulfilment webhook, etc.).
     *
     * @return HasManyThrough<ProductActionRun, ProductAction, $this>
     */
    public function actionRuns(): HasManyThrough
    {
        return $this->hasManyThrough(
            ProductActionRun::class,
            ProductAction::class,
            'product_id', // Foreign key on ProductAction table...
            'action_id', // Foreign key on ProductActionRun table...
            'purchasable_id', // Local key on ProductPurchase table...
            'id' // Local key on ProductAction table...
        );
    }

    /*
     * Lifecycle methods live in product-type traits:
     *   - HasBookingLifecycle  → isBooking, isBookingEnded, scopeBookings,
     *                            scopeEndedBookings   (booking products)
     *   - HasLoanLifecycle     → isReturned, isOverdue, getDomainStatus,
     *                            returnedAt, extensionsUsed, canExtend,
     *                            extend, markReturned, scopeActiveLoans,
     *                            scopeReturned, scopeOverdue  (loanable products)
     */
}
