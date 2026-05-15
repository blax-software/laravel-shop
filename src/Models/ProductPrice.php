<?php

namespace Blax\Shop\Models;

use Blax\Shop\Contracts\Cartable;
use Blax\Shop\Contracts\Purchasable;
use Blax\Shop\Enums\BillingScheme;
use Blax\Shop\Enums\PriceType;
use Blax\Shop\Enums\RecurringInterval;
use Blax\Workkit\Traits\HasMetaTranslation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function purchasable()
    {
        return $this->morphTo();
    }

    public function scopeIsActive($query)
    {
        return $query->where('active', true);
    }

    public function getCurrentPrice(bool|null $sale_price = null): float
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
