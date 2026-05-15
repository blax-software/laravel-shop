<?php

declare(strict_types=1);

namespace Blax\Shop\Models;

use Blax\Shop\Contracts\Cartable;
use Blax\Shop\Contracts\Purchasable;
use Blax\Shop\Enums\BillingScheme;
use Blax\Shop\Enums\PriceType;
use Blax\Shop\Enums\RecurringInterval;
use Blax\Workkit\Traits\HasMetaTranslation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A price record attached to a {@see Purchasable} (usually a {@see Product},
 * but anything can carry prices via the polymorphic `purchasable_*` columns).
 *
 * A single purchasable can carry several prices — one default, plus
 * alternative tiers (bulk, region, customer-segment). Pricing follows the
 * package-wide rule: amounts are integer cents, currency lives in a
 * separate `currency` column, never inferred from the amount.
 *
 * @property string $id
 * @property string $purchasable_type
 * @property string $purchasable_id
 * @property string|null $stripe_price_id
 * @property string|null $name
 * @property \Blax\Shop\Enums\PriceType $type
 * @property string $currency  ISO 4217.
 * @property float $unit_amount       Per-unit price in the smallest currency unit (cents). Cast to float for math.
 * @property float|null $sale_unit_amount Sale price; defaults to `unit_amount` when unset.
 * @property bool $is_default
 * @property bool $active
 * @property \Blax\Shop\Enums\BillingScheme $billing_scheme
 * @property \Blax\Shop\Enums\RecurringInterval|null $interval
 * @property int|null $interval_count
 * @property int|null $trial_period_days
 * @property \stdClass $meta
 *
 * @property-read Model $purchasable
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ProductPriceTier> $tiers
 */
class ProductPrice extends Model implements Cartable
{
    use HasFactory, HasUuids, HasMetaTranslation;

    protected $fillable = [
        'purchasable_type',
        'purchasable_id',
        'stripe_price_id',
        'name',
        'type',
        'currency',
        'unit_amount',
        'sale_unit_amount',
        'is_default',
        'active',
        'billing_scheme',
        'interval',
        'interval_count',
        'trial_period_days',
        'meta',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'active' => 'boolean',
        'type' => PriceType::class,
        'billing_scheme' => BillingScheme::class,
        'interval' => RecurringInterval::class,
        'meta' => 'object',
        'unit_amount' => 'float',
        'sale_unit_amount' => 'float',
        'interval_count' => 'integer',
        'trial_period_days' => 'integer',
    ];

    /**
     * The {@see Purchasable} this price belongs to (usually a {@see Product}).
     *
     * @return MorphTo<Model, $this>
     */
    public function purchasable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Filter to only currently-active prices (default scope alternative).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeIsActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * Resolve the unit price this record currently sells at.
     *
     * Returns the sale price when `$sale_price` is true *and* a
     * `sale_unit_amount` is configured; otherwise the regular `unit_amount`.
     */
    public function getCurrentPrice(?bool $sale_price = null): float
    {
        if ($sale_price) {
            return $this->sale_unit_amount ?? $this->unit_amount;
        }

        return $this->unit_amount;
    }

    /**
     * Tier ladder used when {@see $billing_scheme} is `tiered`. Each tier
     * applies up to its `up_to` mark; the last tier (up_to = null) extends
     * to infinity. See {@see calculateForUsage()} for the walker.
     *
     * @return HasMany<ProductPriceTier, $this>
     */
    public function tiers(): HasMany
    {
        return $this->hasMany(
            config('shop.models.product_price_tier', ProductPriceTier::class),
            'price_id'
        )->orderBy('sort_order')->orderByRaw('up_to IS NULL, up_to ASC');
    }

    /**
     * Compute the total charge in cents for `$usage` units (e.g. days of
     * loan, GB consumed, API calls). Walks the {@see $tiers} ladder Stripe-
     * style: each tier covers usage from the previous tier's `up_to` up to
     * its own `up_to` (or infinity for the last tier), at `unit_amount`
     * cents per unit, plus an optional `flat_amount` if the tier is entered.
     *
     * Falls back to `unit_amount * usage` when billing_scheme is not tiered
     * or no tiers are configured — so a price with billing_scheme=per_unit
     * still computes a sensible total here.
     */
    public function calculateForUsage(float $usage): int
    {
        if ($usage <= 0) {
            return 0;
        }

        $isTiered = $this->billing_scheme === BillingScheme::TIERED;
        $tiers = $isTiered ? $this->tiers : null;

        if (! $isTiered || $tiers === null || $tiers->isEmpty()) {
            return (int) round($this->unit_amount * $usage);
        }

        $cost = 0.0;
        $consumed = 0.0;

        foreach ($tiers as $tier) {
            $upTo = $tier->up_to;
            $tierCap = $upTo === null ? INF : (float) $upTo;

            if ($consumed >= $tierCap) {
                // Past this tier's ceiling (shouldn't happen with sorted
                // tiers, but guards against bad data).
                continue;
            }

            $unitsInTier = min($usage, $tierCap) - $consumed;
            if ($unitsInTier <= 0) {
                break;
            }

            $cost += $unitsInTier * (float) $tier->unit_amount;
            $cost += (float) ($tier->flat_amount ?? 0);
            $consumed += $unitsInTier;

            if ($consumed >= $usage) {
                break;
            }
        }

        return (int) round($cost);
    }
}
