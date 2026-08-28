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
        'cost_amount',
        'license_percent',
        'license_min_amount',
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
        'cost_amount' => 'float',
        'license_percent' => 'float',
        'license_min_amount' => 'integer',
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
     * The royalty / minimum licence fee owed for ONE unit sold at this price for
     * ONE licence-term period (cents): `max(license_percent% of unit_amount,
     * license_min_amount)`. Follows the Aircademy-style rule — a percentage of
     * the net list price, floored at a term minimum. 0 when the price carries no
     * licence rule. Amortized to a run-rate by the caller (see the shop's
     * subscription metrics), so it is charged once per term, not per billing
     * cycle.
     */
    public function licenseFeePerPeriod(): int
    {
        $percent = $this->license_percent;
        $min = (int) ($this->license_min_amount ?? 0);

        if ($percent === null && $min === 0) {
            return 0;
        }

        $percentFee = $percent !== null
            ? (int) round(((float) $percent / 100) * (float) $this->unit_amount)
            : 0;

        return max($percentFee, $min);
    }

    /**
     * The conditional-pricing requirement carried in `meta.requires`, or null
     * when this is an unconditional (standalone) price.
     *
     * Shape: a map of one prerequisite, e.g. `['role' => 'seat']` or
     * `['product' => 'full-seat']`, optionally with a display-only `label`
     * ("8€ with Full Seat"). `meta` is cast to `object`, so this normalizes the
     * `stdClass` back to an array for callers ({@see EntitlementChecker}).
     *
     * @return array<string,mixed>|null
     */
    public function requires(): ?array
    {
        $requires = $this->meta->requires ?? null;

        if ($requires === null) {
            return null;
        }

        // Normalize stdClass|array -> array (meta cast is `object`).
        $normalized = json_decode(json_encode($requires), true);

        return is_array($normalized) && $normalized !== [] ? $normalized : null;
    }

    /**
     * Whether this price only applies to buyers who satisfy a prerequisite
     * (i.e. it carries `meta.requires`).
     */
    public function isConditional(): bool
    {
        return $this->requires() !== null;
    }

    /**
     * Display label for the requirement ("8€ with Full Seat"), if authored.
     */
    public function requiresLabel(): ?string
    {
        $label = $this->requires()['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    /**
     * Structural satisfaction check against an already-resolved set of "owned"
     * signal strings, e.g. `['role:seat', 'product:full-seat']`.
     *
     * Used for the hypothetical "if you owned product A" case (no runtime
     * buyer) — the host resolves slugs/ids into signal strings and passes them
     * here. A non-conditional price is always satisfied. A conditional price is
     * satisfied when ANY of its (non-label) requirement entries matches a
     * signal — a single `requires` map with one key is the common case.
     *
     * @param  array<int,string>  $ownedSignals
     */
    public function requiresSatisfiedBy(array $ownedSignals): bool
    {
        $requires = $this->requires();

        if ($requires === null) {
            return true;
        }

        foreach ($requires as $type => $value) {
            if ($type === 'label') {
                continue;
            }

            if (is_scalar($value) && in_array($type . ':' . $value, $ownedSignals, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The percentage discount this price applies (0 < pct <= 100) from
     * `meta.percent_off`, or null when it is a fixed-amount price.
     *
     * A percentage price has no meaningful `unit_amount` of its own — its
     * effective charge is computed against a base (the buyer's fallback
     * standalone price) by {@see self::effectiveAmount()}.
     */
    public function percentOff(): ?float
    {
        $pct = $this->meta->percent_off ?? null;

        if ($pct === null || ! is_numeric($pct)) {
            return null;
        }

        $pct = (float) $pct;

        return ($pct > 0 && $pct <= 100) ? $pct : null;
    }

    /**
     * Whether this price is expressed as a percentage discount rather than a
     * fixed amount.
     */
    public function isPercentage(): bool
    {
        return $this->percentOff() !== null;
    }

    /**
     * The amount (in cents) this price effectively charges.
     *
     * Fixed price → its own `unit_amount`. Percentage price → `$base` reduced
     * by the percent, where `$base` is the buyer's fallback (cheapest
     * standalone) price for the same product, supplied by the caller
     * ({@see \Blax\Shop\Traits\HasPrices::resolvePriceFor()}). Returns null when
     * a percentage price has no base to discount — there is nothing to offer.
     */
    public function effectiveAmount(?float $base = null): ?float
    {
        $pct = $this->percentOff();

        if ($pct !== null) {
            return $base === null ? null : round($base * (100 - $pct) / 100);
        }

        return $this->unit_amount;
    }

    /**
     * Author this price's conditional-pricing meta in one merge-safe call —
     * the reusable seam host admin UIs should use instead of hand-poking meta.
     *
     * @param  array<string,mixed>|null  $requires  Prerequisite the buyer must
     *         hold, e.g. `['product' => 'full-seat']` or `['role' => 'seat']`;
     *         null/empty clears it (makes the price unconditional).
     * @param  float|null  $percentOff  Percentage discount (0 < pct <= 100)
     *         applied to the product's standalone price; null makes this a
     *         fixed-amount price (the row's `unit_amount` is charged as-is).
     * @param  string|null  $label  Display label ("8€ with Full Seat"); stored
     *         inside the requires map so {@see self::requiresLabel()} finds it.
     *
     * Does not persist — call `->save()` after.
     */
    public function setConditionalPricing(?array $requires, ?float $percentOff = null, ?string $label = null): static
    {
        // Normalize meta (cast is `object`) to a plain array for editing.
        $meta = json_decode(json_encode($this->meta ?? []), true);
        $meta = is_array($meta) ? $meta : [];

        if ($requires === null || $requires === []) {
            unset($meta['requires']);
        } else {
            if ($label !== null && $label !== '') {
                $requires['label'] = $label;
            }
            $meta['requires'] = $requires;
        }

        if ($percentOff === null) {
            unset($meta['percent_off']);
        } else {
            $meta['percent_off'] = (float) $percentOff;
        }

        $this->meta = $meta;

        return $this;
    }

    /**
     * Only conditional prices (carry a `meta.requires`). Uses a JSON-string
     * LIKE so it works whether `meta` is a JSON or TEXT column.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeConditional(Builder $query): Builder
    {
        return $query->where('meta', 'like', '%"requires"%');
    }

    /**
     * Only standalone (unconditional) prices.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStandalone(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('meta')->orWhere('meta', 'not like', '%"requires"%');
        });
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
