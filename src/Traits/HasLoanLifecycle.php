<?php

declare(strict_types=1);

namespace Blax\Shop\Traits;

use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Events\LoanExtended;
use Blax\Shop\Events\LoanReturned;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Models\ProductStock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Loan / rental lifecycle for a {@see \Blax\Shop\Models\ProductPurchase} row.
 *
 * Loans are modelled directly as ProductPurchase rows: `from` is when the
 * borrower checked the item out, `until` is the due date, and the `meta`
 * column carries two domain-specific keys:
 *
 *   meta.returned_at      ISO timestamp; null means the item is still out
 *   meta.extensions_used  int; counts how many times extend() was called
 *
 * The package's e-commerce status enum stays orthogonal to loan state:
 *   pending   → loan is in progress (default for new rows with no payment)
 *   completed → bookkeeping-final (set automatically on markReturned())
 *
 * Domain status (returned / overdue / active) is derived; see
 * {@see getDomainStatus()} and the corresponding scopes.
 *
 * The product-side counterpart is {@see MayBeLoanableProduct}, which exposes a
 * `loan()` helper to create a purchase row pre-filled for this lifecycle.
 *
 * # Host-model contract
 *
 * Designed for {@see \Blax\Shop\Models\ProductPurchase}; expects these
 * columns and accessors on the host:
 *
 * @property \Illuminate\Support\Carbon|null $from   Loan check-out timestamp.
 * @property \Illuminate\Support\Carbon|null $until  Loan due timestamp (mutated by {@see self::extend()}).
 * @property array<string, mixed>|\stdClass|null $meta Carries `returned_at` and `extensions_used` keys.
 * @property \Blax\Shop\Enums\PurchaseStatus $status Set to `COMPLETED` on {@see self::markReturned()}.
 * @property string|null $price_id        FK to {@see ProductPrice} used for cost calculation.
 * @property-read ProductPrice|null $price  Eager-loadable price relation.
 * @property-read Model|null $purchasable  The loaned item — typically a {@see MayBeLoanableProduct}-using model.
 */
trait HasLoanLifecycle
{
    /**
     * True once the borrower has returned the item.
     */
    public function isReturned(): bool
    {
        return $this->returnedAt() !== null;
    }

    /**
     * Has the due date passed without a return?
     */
    public function isOverdue(): bool
    {
        if ($this->isReturned() || $this->until === null) {
            return false;
        }

        return $this->until->isPast();
    }

    /**
     * Domain-flavoured status for resource serialisation:
     *   active   → loan is in progress, never extended
     *   extended → loan is in progress and has been extended at least once
     *   overdue  → past due_at, not returned (regardless of extensions)
     *   returned → borrower has handed it back (regardless of extensions)
     *
     * `overdue` and `returned` take precedence over `extended` — once a
     * loan is past due or handed back, the fact that it was extended is
     * less informative than its terminal state.
     */
    public function getDomainStatus(): string
    {
        if ($this->isReturned()) {
            return 'returned';
        }

        if ($this->isOverdue()) {
            return 'overdue';
        }

        return $this->extensionsUsed() > 0 ? 'extended' : 'active';
    }

    /**
     * Read meta.returned_at safely against either array or object meta cast.
     */
    public function returnedAt(): ?string
    {
        $meta = (array) ($this->meta ?? []);

        return $meta['returned_at'] ?? null;
    }

    /**
     * Number of extensions the borrower has consumed on this loan.
     */
    public function extensionsUsed(): int
    {
        $meta = (array) ($this->meta ?? []);

        return (int) ($meta['extensions_used'] ?? 0);
    }

    /**
     * Can this loan still be extended? Defaults to config('shop.loan.max_extensions').
     */
    public function canExtend(?int $max = null): bool
    {
        if ($this->isReturned() || $this->isOverdue()) {
            return false;
        }

        $max ??= (int) config('shop.loan.max_extensions', 2);

        return $this->extensionsUsed() < $max;
    }

    /**
     * Push the due date forward by the given week count (or
     * shop.loan.extension_weeks if null) and increment extensions_used.
     *
     * Callers should check canExtend() first — extend() is permissive and
     * does not enforce the cap, so it stays composable with custom policies.
     */
    public function extend(?int $weeks = null): self
    {
        $weeks ??= (int) config('shop.loan.extension_weeks', 1);

        if ($this->until !== null) {
            $this->until = $this->until->copy()->addWeeks($weeks);
        }

        $meta = (array) ($this->meta ?? []);
        $meta['extensions_used'] = (int) ($meta['extensions_used'] ?? 0) + 1;
        $this->meta = $meta;
        $this->save();

        // Push the paired physical claim's expires_at to match the new due
        // date so calendar/overdue checks at the stock level stay in sync
        // with the purchase's `until`. Loans created before this rewiring
        // (no linked claim) silently skip.
        if ($this->until !== null) {
            $this->loanClaimQuery()
                ->whereNotNull('expires_at')
                ->update(['expires_at' => $this->until]);
        }

        event(new LoanExtended($this, $weeks));

        return $this;
    }

    /**
     * Mark the item returned: stamp meta.returned_at, flip status to
     * COMPLETED, and release the paired PHYSICALLY_CLAIMED stock entry
     * (which automatically creates the offsetting RETURN row via
     * {@see ProductStock::release()}). All three operations happen inside
     * a single transaction so physical inventory is consistent at every
     * observable instant.
     *
     * Idempotent: a second call on an already-returned loan is a no-op —
     * the returned_at timestamp is not restamped and the claim is not
     * re-released.
     */
    public function markReturned(?\DateTimeInterface $at = null): self
    {
        if ($this->isReturned()) {
            return $this;
        }

        $at ??= now();

        DB::transaction(function () use ($at) {
            $meta = (array) ($this->meta ?? []);
            $meta['returned_at'] = Carbon::instance($at)->toIso8601String();
            $this->meta = $meta;
            $this->status = PurchaseStatus::COMPLETED;
            $this->save();

            // Release the paired physical claim. Each release() call
            // creates a RETURN entry that offsets the DECREASE written
            // alongside the original claim at checkout, so available stock
            // naturally restores. For loans created before this rewiring
            // (no linked claim), this short-circuits and the host is still
            // responsible for the increaseStock(1) — but new code paths
            // won't need to do anything.
            $this->loanClaimQuery()->each(fn (ProductStock $claim) => $claim->release());
        });

        event(new LoanReturned($this));

        return $this;
    }

    /**
     * Builder for the PHYSICALLY_CLAIMED stock row(s) created alongside
     * this loan at checkout. Used by {@see self::markReturned()} (to
     * release the claim) and {@see self::extend()} (to push expires_at).
     *
     * Polymorphic lookup uses the purchase's primary key directly — the
     * reference_type was stored as the concrete ProductPurchase class at
     * checkout time, but we look up by id alone since UUIDs are unique
     * across the table. Filters to PENDING + PHYSICALLY_CLAIMED so any
     * already-released or unrelated rows are skipped.
     *
     * @return \Illuminate\Database\Eloquent\Builder<ProductStock>
     */
    protected function loanClaimQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $stockModel = config('shop.models.product_stock', ProductStock::class);

        return $stockModel::query()
            ->where('reference_id', $this->getKey())
            ->where('type', StockType::PHYSICALLY_CLAIMED->value)
            ->where('status', StockStatus::PENDING->value);
    }

    /**
     * Scope: loans currently in the borrower's hands (not returned).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActiveLoans(Builder $query): Builder
    {
        return $query
            ->where('status', PurchaseStatus::PENDING->value)
            ->whereNull('meta->returned_at');
    }

    /**
     * Scope: loans that have been handed back.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeReturned(Builder $query): Builder
    {
        return $query->whereNotNull('meta->returned_at');
    }

    /**
     * Scope: loans past their due date and not yet returned.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->activeLoans()->where('until', '<', now());
    }

    /* ──────────────────────────────────────────────────────────────────────
     * Cost calculation
     *
     * A loan accrues cost based on the {@see ProductPrice} attached to the
     * purchase (`$this->price`). The price model owns the tier ladder, the
     * billing scheme (per-unit vs tiered), and the currency — so the math
     * here is simple: count fractional days between `from` and the relevant
     * end timestamp, then ask the price what that adds up to.
     *
     * Day count is fractional (minute precision / 1440), matching
     * {@see HasBookingPriceCalculation}.
     *
     * For returned loans, the end timestamp is `meta.returned_at` so the
     * cost stays stable post-return.
     * ────────────────────────────────────────────────────────────────────── */

    /**
     * Compute accrued loan cost in cents as of $asOf (defaults to now).
     *
     * If $price is omitted the purchase's attached price ($this->price_id)
     * is used. If no price is associated, the cost is 0 — the loan is free.
     */
    public function calculateCost(
        ?\DateTimeInterface $asOf = null,
        ?ProductPrice $price = null
    ): int {
        if ($this->from === null) {
            return 0;
        }

        $start = Carbon::instance($this->from);
        $returnedAt = $this->returnedAt();
        if ($returnedAt !== null) {
            $end = Carbon::parse($returnedAt);
        } else {
            $end = $asOf !== null ? Carbon::instance($asOf) : Carbon::now();
        }

        if ($end->lessThanOrEqualTo($start)) {
            return 0;
        }

        $totalDays = max(0.0, $start->diffInMinutes($end) / 1440.0);

        $price ??= $this->resolvePriceForCost();
        if ($price === null) {
            return 0;
        }

        return $price->calculateForUsage($totalDays);
    }

    /**
     * Convenience: accrued cost as of now, in cents. Useful inside resources
     * where no parameters are available.
     */
    public function accruedCost(): int
    {
        return $this->calculateCost();
    }

    /**
     * Find the price to bill this loan against. Order of resolution:
     *   1. The purchase's `price_id`        (explicit choice at checkout)
     *   2. The purchasable's default price  (Product->defaultPrice())
     * Returns null when neither is set — interpreted as a free loan.
     */
    protected function resolvePriceForCost(): ?ProductPrice
    {
        if ($this->price_id !== null) {
            $relation = $this->relationLoaded('price')
                ? $this->getRelation('price')
                : $this->price;
            if ($relation instanceof ProductPrice) {
                return $relation;
            }
        }

        $purchasable = $this->relationLoaded('purchasable')
            ? $this->getRelation('purchasable')
            : $this->purchasable;

        if ($purchasable !== null && method_exists($purchasable, 'defaultPrice')) {
            $default = $purchasable->defaultPrice()->first();
            if ($default instanceof ProductPrice) {
                return $default;
            }
        }

        return null;
    }
}
