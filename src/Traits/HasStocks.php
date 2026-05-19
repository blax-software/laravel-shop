<?php

declare(strict_types=1);

namespace Blax\Shop\Traits;

use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Events\StockBecameLow;
use Blax\Shop\Events\StockClaimed;
use Blax\Shop\Events\StockDecreased;
use Blax\Shop\Events\StockDepleted;
use Blax\Shop\Events\StockIncreased;
use Blax\Shop\Events\StockReplenished;
use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Models\ProductStock;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * HasStocks — stock management surface for Product-shaped models.
 *
 * Provides:
 *  - Basic stock operations: {@see self::increaseStock()}, {@see self::decreaseStock()},
 *    {@see self::adjustStock()}.
 *  - Reservation / booking claims via {@see self::claimStock()}.
 *  - Date-based availability: {@see self::availableOnDate()},
 *    {@see self::getAvailableForDateRange()}, {@see self::calendarAvailability()}.
 *  - Low-stock detection: {@see self::isLowStock()} and the
 *    {@see self::scopeLowStock()} query scope.
 *  - Audit log writes via {@see self::logStockChange()} → `product_stock_logs`.
 *
 * # Stock calculation
 *
 *  - **Physical stock**: sum of all COMPLETED entries (positive INCREASE +
 *    RETURN, negative DECREASE) that haven't expired.
 *  - **Available stock**: physical stock, with active CLAIMED entries netted
 *    out (their DECREASE side reduces availability while the PENDING claim
 *    sits open).
 *  - **Claimed stock**: sum of PENDING `CLAIMED` entries (returned as a
 *    positive number by the getters).
 *  - **Available on a given date**: physical stock at that date, minus claims
 *    whose window covers that date.
 *
 * # Host-model contract
 *
 * The trait is designed to be applied to {@see \Blax\Shop\Models\Product} and
 * its subclasses. It reads the columns declared below and the
 * `singleProducts` relation supplied by {@see MayBePoolProduct} (when the
 * host opts into pool support). Host models that don't manage stock should
 * set `manage_stock = false`; in that mode every read returns
 * `PHP_INT_MAX` and every mutation is a no-op returning `true`.
 *
 * @property string|int $id Primary key on the host model — used for cross-table FK writes.
 * @property bool $manage_stock When `false`, stock methods short-circuit (treated as infinite supply).
 * @property int|null $low_stock_threshold Threshold for {@see self::isLowStock()} / {@see self::scopeLowStock()}; null disables low-stock detection.
 * @property \Illuminate\Database\Eloquent\Collection<int, \Blax\Shop\Models\Product> $singleProducts Pool-product relation supplied by {@see MayBePoolProduct}; only consulted in pool aggregation paths.
 */
trait HasStocks
{
    /**
     * Get all available stock entries for this product.
     *
     * The foreign key is named explicitly so this trait works on Product
     * subclasses too (e.g. a library Book extending Product) — Eloquent's
     * default convention would otherwise infer `{subclass}_id`.
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(
            config('shop.models.product_stock', ProductStock::class),
            'product_id'
        );
    }

    /**
     * Get all stock entries for this product including unavailable ones.
     */
    public function allStocks(): HasMany
    {
        return $this->hasMany(
            config('shop.models.product_stock', ProductStock::class),
            'product_id'
        )
            ->withExpired()
            ->where('status', 'LIKE', '%');
    }

    /**
     * Attribute accessor: Get available physical stock
     * 
     * Sums all COMPLETED stock entries that haven't expired.
     * This includes INCREASE (+), DECREASE (-), and released claims.
     * Does NOT include PENDING claims (they're tracked separately).
     */
    public function getAvailableStocksAttribute(): int
    {
        return $this->stocks()
            ->available()
            ->whereNotIn('type', StockType::claimTypeValues())
            ->willExpire()
            ->sum('quantity') ?? 0;
    }

    /**
     * Get max stock (the ceiling - total capacity as if no claims existed)
     * 
     * This shows the maximum possible stock by summing:
     * - INCREASE entries (stock added)
     * - RETURN entries (stock returned)
     * And ignoring DECREASE and CLAIMED entries entirely.
     * 
     * @return int Maximum capacity (PHP_INT_MAX if stock management disabled)
     */
    public function getMaxStocksAttribute(): int
    {
        if ($this->manage_stock === false) {
            return PHP_INT_MAX;
        }

        // Sum only INCREASE and RETURN entries to get the "ceiling"
        return (int) $this->stocks()
            ->withoutGlobalScope('willExpire')
            ->where('status', StockStatus::COMPLETED->value)
            ->whereIn('type', [StockType::INCREASE->value, StockType::RETURN->value])
            ->sum('quantity');
    }

    /**
     * Check if product is in stock
     *
     * @return bool True if stock management is disabled OR available stock > 0
     */
    public function isInStock(): bool
    {
        if (!$this->manage_stock) {
            return true;
        }

        return $this->getAvailableStock() > 0;
    }

    /**
     * Physical inventory — how many units the business still owns right now,
     * regardless of whether they're temporarily out (on loan, claimed by a
     * cart/booking) or sitting on the shelf.
     *
     *   physical = available + currentlyClaimed
     *
     * Why only two terms? Both CLAIMED and PHYSICALLY_CLAIMED rows count
     * toward `currentlyClaimed` (see {@see StockType::claimTypeValues()}),
     * so cart reservations, bookings, AND loans all flow through the same
     * claim machinery. Loans no longer need a separate `activeLoans` term —
     * the PHYSICALLY_CLAIMED stock row IS the active loan.
     *
     * Worked examples:
     *  - Tomato shop: bought 10, sold 3 → DECREASE -3 is permanent (no
     *    claim/loan to offset). Physical = 7. Available = 7.
     *  - Library:     bought 5,  loaned 1 → one PHYSICALLY_CLAIMED row.
     *    Available = 4, currentlyClaimed = 1, physical = 5.
     *  - Hotel:       1 room, future booking → CLAIMED row, claimed_from
     *    in the future. Available = 1 today, currentlyClaimed = 0 today,
     *    physical = 1.
     *
     * Distinct from {@see self::getMaxStocksAttribute} (which sums every
     * INCREASE/RETURN row ever written and so inflates after every loan
     * return cycle) and from
     * {@see \Blax\Shop\Traits\MayBeLoanableProduct::getTotalQuantityAttribute}
     * (which is loanable-only). This one works for every Product type.
     */
    public function getPhysicalStockAttribute(): int
    {
        if (!$this->manage_stock) {
            return PHP_INT_MAX;
        }

        return $this->getAvailableStock() + $this->getCurrentlyClaimedStock();
    }

    /**
     * Convenience method form so callers reading dynamically can pick either
     * `$product->physical_stock` or `$product->getPhysicalStock()`.
     */
    public function getPhysicalStock(): int
    {
        return $this->getPhysicalStockAttribute();
    }

    /**
     * Decrease physical stock (inventory reduction)
     * 
     * Creates a DECREASE entry with negative quantity and COMPLETED status.
     * This represents actual stock leaving the inventory (sold, damaged, etc.).
     * 
     * @param int $quantity Amount to decrease
     * @param Carbon|null $until Optional expiration (for temporary decreases)
     * @return bool True if successful
     * @throws NotEnoughStockException If insufficient stock available
     */
    public function decreaseStock(int $quantity = 1, ?Carbon $until = null): bool
    {
        if (!$this->manage_stock) {
            return true;
        }

        $available = $this->getAvailableStock();
        if ($available < $quantity) {
            return throw new NotEnoughStockException("Not enough stock available for product ID {$this->id}");
        }

        $entry = $this->stocks()->create([
            'quantity' => -$quantity,
            'type' => StockType::DECREASE,
            'status' => StockStatus::COMPLETED,
            'expires_at' => $until,
        ]);

        // Pass pre-calculated quantity to avoid extra query
        $this->logStockChange(-$quantity, 'decrease', $available - $quantity);

        $this->save();

        $availableAfter = $this->getAvailableStock();
        event(new StockDecreased($this, $entry, $availableAfter));
        $this->dispatchStockTransitions($available, $availableAfter);

        return true;
    }

    /**
     * Increase physical stock (inventory addition)
     * 
     * Creates an INCREASE entry with positive quantity and COMPLETED status.
     * This represents stock being added to inventory (purchased, returned, etc.).
     * 
     * @param int $quantity Amount to increase
     * @return bool True if successful, false if stock management disabled
     */
    public function increaseStock(int $quantity = 1): bool
    {
        if (!$this->manage_stock) {
            return false;
        }

        $availableBefore = $this->getAvailableStock();

        $entry = $this->stocks()->create([
            'quantity' => $quantity,
            'type' => StockType::INCREASE,
            'status' => StockStatus::COMPLETED,
        ]);

        // Log stock change - getAvailableStock will be called by logStockChange
        // This is acceptable since we need the accurate quantity after
        $this->logStockChange($quantity, 'increase');

        $this->save();

        $availableAfter = $this->getAvailableStock();
        event(new StockIncreased($this, $entry, $availableAfter));
        $this->dispatchStockTransitions($availableBefore, $availableAfter);

        return true;
    }

    /**
     * Compare pre/post available counts and dispatch the boundary-crossing
     * stock events (depleted, replenished, became-low, fully-available).
     * Called from increase/decrease/claim paths to give listeners a single
     * place to react to inventory thresholds without re-querying.
     */
    protected function dispatchStockTransitions(int $before, int $after): void
    {
        if ($before > 0 && $after === 0) {
            event(new StockDepleted($this));
        } elseif ($before === 0 && $after > 0) {
            event(new StockReplenished($this, $after));
        }

        $threshold = (int) ($this->low_stock_threshold ?? 0);
        if ($threshold > 0 && $before > $threshold && $after <= $threshold && $after > 0) {
            event(new StockBecameLow($this, $after, $threshold));
        }

        // StockFullyAvailable is intentionally NOT auto-dispatched here:
        // getMaxStocksAttribute() sums every INCREASE/RETURN entry over time,
        // so it grows whenever new stock arrives or claims release — meaning
        // `available === max` collapses to "did we just add inventory?" and
        // overlaps with StockIncreased. Hosts that need a domain-meaningful
        // "back at full capacity" signal should dispatch the event themselves
        // against whatever ceiling they consider canonical (e.g. "physical
        // copies on hand" for a library, "max concurrent bookings" for a
        // venue).
    }

    /**
     * Adjust stock with custom type and status
     * 
     * More flexible than increaseStock/decreaseStock, allows:
     * - Custom stock type (INCREASE, DECREASE, RETURN, CLAIMED)
     * - Custom status (defaults to COMPLETED)
     * - Optional expiration date
     * - Optional claim start date (for CLAIMED type)
     * - Optional note for documentation
     * - Optional reference to related model (Order, Cart, Booking, etc.)
     * 
     * Note: CLAIMED type creates two entries like claimStock() for consistency:
     * 1. DECREASE entry (COMPLETED) - reduces available stock
     * 2. CLAIMED entry (PENDING) - tracks the claim
     * 
     * @param StockType $type The type of adjustment (INCREASE/RETURN add stock, DECREASE/CLAIMED remove stock)
     * @param int $quantity Amount to adjust (always positive, type determines direction)
     * @param DateTimeInterface|null $until Optional expiration date (when stock expires or claim ends)
     * @param DateTimeInterface|null $from Optional start date (used for CLAIMED type, defaults to now())
     * @param StockStatus|null $status Optional status (defaults to COMPLETED, or PENDING for CLAIMED type)
     * @param string|null $note Optional note for documentation purposes
     * @param Model|null $referencable Optional polymorphic reference to related model
     * @return bool|\Blax\Shop\Models\ProductStock True if successful for non-CLAIMED types, ProductStock instance for CLAIMED type, false if stock management disabled
     * @throws NotEnoughStockException If insufficient stock available for DECREASE or CLAIMED types
     */
    public function adjustStock(
        StockType $type,
        int $quantity,
        ?DateTimeInterface $until = null,
        ?DateTimeInterface $from = null,
        ?StockStatus $status = null,
        ?string $note = null,
        ?Model $referencable = null
    ): bool|\Blax\Shop\Models\ProductStock {
        if (!$this->manage_stock) {
            return false;
        }

        // For claim-style types, delegate to claimStock which handles the
        // two-entry pattern (DECREASE + PENDING claim row).
        if ($type->isClaim()) {
            return $this->claimStock(
                quantity: $quantity,
                reference: $referencable,
                from: $from,
                until: $until,
                note: $note,
                type: $type
            );
        }

        // Validate stock availability for types that reduce inventory
        $isPositive = in_array($type, [StockType::INCREASE, StockType::RETURN]);
        if (!$isPositive) {
            // Only validate for COMPLETED status (PENDING doesn't affect available stock)
            $effectiveStatus = $status ?? StockStatus::COMPLETED;
            if ($effectiveStatus === StockStatus::COMPLETED && $this->getAvailableStock() < $quantity) {
                throw new NotEnoughStockException("Not enough stock available for product ID {$this->id}");
            }
        }

        $adjustedQuantity = $isPositive ? $quantity : -$quantity;

        $this->stocks()->create([
            'quantity' => $adjustedQuantity,
            'type' => $type,
            'status' => $status ?? StockStatus::COMPLETED,
            'expires_at' => $until,
            'note' => $note,
            'reference_type' => $referencable ? get_class($referencable) : null,
            'reference_id' => $referencable ? $referencable->id : null,
        ]);

        $this->logStockChange($adjustedQuantity, 'adjust');

        $this->save();

        return true;
    }

    /**
     * Claim stock for temporary use (reservation/booking/loan).
     *
     * Different from decreaseStock — it:
     * 1. Removes stock from available inventory (via DECREASE entry)
     * 2. Tracks it as a claim (via CLAIMED/PHYSICALLY_CLAIMED entry with PENDING status)
     * 3. Can be released later (status PENDING → COMPLETED + paired RETURN)
     * 4. Supports date ranges (claimed_from → expires_at)
     *
     * Use cases:
     * - Hotel room bookings  → CLAIMED, expires_at = check-out (auto-releases).
     * - Cart reservations    → CLAIMED, expires_at = cart expiry (auto-releases).
     * - Library loans        → PHYSICALLY_CLAIMED, expires_at = due date
     *                          (overdue tracking only — does not auto-release).
     *
     * @param  StockType  $type  CLAIMED (default, auto-release) or
     *         PHYSICALLY_CLAIMED (manual release only — used by loans).
     * @return \Blax\Shop\Models\ProductStock|null The claim entry, or null if insufficient stock
     */
    public function claimStock(
        int $quantity,
        $reference = null,
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $until = null,
        ?string $note = null,
        StockType $type = StockType::CLAIMED,
    ): ?\Blax\Shop\Models\ProductStock {

        if (!$this->manage_stock) {
            return null;
        }

        $stockModel = config('shop.models.product_stock', ProductStock::class);

        $availableBefore = $this->getAvailableStock();

        $claim = $stockModel::claim(
            $this,
            $quantity,
            $reference,
            $from,
            $until,
            $note,
            $type,
        );

        if ($claim) {
            event(new StockClaimed($this, $claim));
            $this->dispatchStockTransitions($availableBefore, $this->getAvailableStock());
        }

        return $claim;
    }

    /**
     * Get currently available stock
     * 
     * This is the stock available for new orders/claims.
     * Calculation:
     * 1. Sum all COMPLETED stock entries (INCREASE, DECREASE, RETURN) that haven't expired
     * 2. Add back expired CLAIMED stocks (their DECREASE entries are negated when claims expire)
     * 
     * CLAIMED entries are excluded from the main sum as they track claims, not physical inventory.
     * 
     * @return int Available quantity (PHP_INT_MAX if stock management disabled)
     */
    public function getAvailableStock(?DateTimeInterface $date = null): int
    {
        if (!$this->manage_stock) {
            return PHP_INT_MAX;
        }

        $date = $date ?? now();

        // Base stock: all COMPLETED entries except CLAIMED, filtered using
        // the provided date. This intentionally does NOT gate by the ledger
        // row's created_at — callers like {@see ProductStock::claim} pass a
        // booking-window start (which can predate the seed row by seconds)
        // and rightly expect the current physical inventory back. For
        // historical "as of date X, ignoring later changes" queries, use
        // {@see self::availableOnDate()} instead.
        $baseStock = $this->stocks()
            ->withoutGlobalScope('willExpire')
            ->where('status', StockStatus::COMPLETED->value)
            ->whereNotIn('type', StockType::claimTypeValues())
            ->where(function ($query) use ($date) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $date);
            })
            ->sum('quantity');

        // Add back claims that should not reduce availability at the given date
        $inactiveClaims = $this->stocks()
            ->withoutGlobalScope('willExpire')
            ->whereIn('type', StockType::claimTypeValues())
            ->where('status', StockStatus::PENDING->value)
            ->where(function ($query) use ($date) {
                $query->where(function ($q) use ($date) {
                    // Claim has not started yet — applies to both claim types.
                    $q->whereNotNull('claimed_from')
                        ->where('claimed_from', '>', $date);
                })->orWhere(function ($q) use ($date) {
                    // Claim expired before the date — only for CLAIMED, which
                    // auto-releases at expires_at. PHYSICALLY_CLAIMED (loans)
                    // stays reserved until manually returned; expires_at is
                    // informational/overdue-tracking only.
                    $q->where('type', StockType::CLAIMED->value)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', $date);
                });
            })
            ->sum('quantity');

        return max(0, $baseStock + $inactiveClaims);
    }

    /**
     * Get total currently claimed stock
     * 
     * Sum of all active (PENDING) claims that haven't expired yet.
     * This stock is unavailable but tracked separately from physical inventory.
     * Returns absolute value to always show positive quantity.
     * 
     * @return int Total claimed quantity (always positive)
     */
    public function getCurrentlyClaimedStock(): int
    {
        // SQL SUM comes back as a numeric string under PDO mysql; cast
        // before abs() so strict types accept it.
        return abs((int) $this->stocks()
            ->whereIn('type', StockType::claimTypeValues())
            ->where('status', StockStatus::PENDING->value)
            ->willExpire()
            ->where(function ($query) {
                $query->whereNull('claimed_from')
                    ->orWhere('claimed_from', '<=', now());
            })
            ->sum('quantity'));
    }

    /**
     * Get total current and planned claimed stock
     * 
     * Includes all PENDING claims, regardless of start date.
     * Useful for understanding total reservations including future bookings.
     * @return int Total current and future claimed quantity (always positive)
     */
    public function getActiveAndPlannedClaimedStock(): int
    {
        return abs((int) $this->stocks()
            ->whereIn('type', StockType::claimTypeValues())
            ->where('status', StockStatus::PENDING->value)
            ->willExpire()
            ->sum('quantity'));
    }

    /**
     * Get future claimed stock starting from a specific date or all where claimed_at is future
     * 
     * @param DateTimeInterface|null $from Optional start date to filter claims
     * @return int Total future claimed quantity (always positive)
     */
    public function getFutureClaimedStock(?DateTimeInterface $from = null): int
    {
        $query = $this->stocks()
            ->whereIn('type', StockType::claimTypeValues())
            ->where('status', StockStatus::PENDING->value)
            ->willExpire();

        if ($from) {
            $query->where('claimed_from', '>=', $from);
        } else {
            $query->where(function ($q) {
                $q->whereNotNull('claimed_from')
                    ->where('claimed_from', '>', now());
            });
        }

        return abs((int) $query->sum('quantity'));
    }


    /**
     * Log a stock change to the audit log
     * 
     * @param int $quantityChange The change in quantity (positive or negative)
     * @param string $type The type of change (increase, decrease, adjust)
     * @param int|null $quantityAfter Optional pre-calculated quantity after change (avoids extra query)
     */
    protected function logStockChange(int $quantityChange, string $type, ?int $quantityAfter = null): void
    {
        DB::table('product_stock_logs')->insert([
            'product_id' => $this->id,
            'quantity_change' => $quantityChange,
            'quantity_after' => $quantityAfter ?? $this->getAvailableStock(),
            'type' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Query scope: Get products that are in stock
     * 
     * Includes products with:
     * - Stock management disabled (always in stock), OR
     * - Stock management enabled AND available stock > 0
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeInStock($query)
    {
        return $query->where(function ($q) {
            $q->where('manage_stock', false)
                ->orWhere(function ($q2) {
                    $q2->where('manage_stock', true)
                        ->whereRaw("(SELECT SUM(quantity) FROM " . config('shop.tables.product_stocks', 'product_stocks') . " WHERE product_id = " . config('shop.tables.products', 'products') . ".id) > 0");
                });
        });
    }

    /**
     * Query scope: Get products with low stock
     * 
     * Returns products where:
     * - Stock management is enabled
     * - low_stock_threshold is set
     * - Available stock <= threshold
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeLowStock($query)
    {
        $stockTable = config('shop.tables.product_stocks', 'product_stocks');
        $productTable = config('shop.tables.products', 'products');

        return $query->where('manage_stock', true)
            ->whereNotNull('low_stock_threshold')
            ->whereRaw("(SELECT COALESCE(SUM(quantity), 0) FROM {$stockTable} WHERE {$stockTable}.product_id = {$productTable}.id AND {$stockTable}.status = 'completed' AND ({$stockTable}.expires_at IS NULL OR {$stockTable}.expires_at > ?)) <= {$productTable}.low_stock_threshold", [
                now()
            ]);
    }

    /**
     * Check if product stock is low
     * 
     * @return bool True if stock management enabled, threshold set, and stock <= threshold
     */
    public function isLowStock(): bool
    {
        if (!$this->manage_stock || !$this->low_stock_threshold) {
            return false;
        }

        return $this->getAvailableStock() <= $this->low_stock_threshold;
    }

    /**
     * When does the next unit become available? Returns null when stock is
     * already free right now.
     *
     * Considers both ends of the package's two-track availability model:
     *   - Active loans / bookings — earliest `until` on a not-yet-returned
     *     {@see \Blax\Shop\Models\ProductPurchase} via the HasLoanLifecycle
     *     `activeLoans()` scope.
     *   - Active stock claims — earliest `expires_at` on a PENDING/CLAIMED
     *     {@see \Blax\Shop\Models\ProductStock} that hasn't already lapsed.
     *
     * The minimum of those candidates is when *something* frees up. Hosts can
     * render this directly (`$product->nextAvailableAt()?->toIso8601String()`)
     * instead of redefining the same query in every Resource.
     */
    public function nextAvailableAt(): ?Carbon
    {
        if ($this->getAvailableStock() > 0) {
            return null;
        }

        $candidates = [];

        $nextLoanEnd = $this->purchases()->activeLoans()->min('until');
        if ($nextLoanEnd) {
            $candidates[] = Carbon::parse($nextLoanEnd);
        }

        $nextClaimEnd = $this->stocks()
            ->withoutGlobalScope('willExpire')
            ->whereIn('type', StockType::claimTypeValues())
            ->where('status', StockStatus::PENDING->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->min('expires_at');
        if ($nextClaimEnd) {
            $candidates[] = Carbon::parse($nextClaimEnd);
        }

        return empty($candidates) ? null : Carbon::parse(min($candidates));
    }

    /**
     * Get active claims for this product
     * 
     * Returns query builder for PENDING claims that haven't expired yet.
     * Useful for:
     * - Viewing current reservations
     * - Checking what's claimed but not released
     * - Managing active bookings
     * 
     * @return \Illuminate\Database\Eloquent\Builder<\Blax\Shop\Models\ProductStock>
     */
    public function claims(): \Illuminate\Database\Eloquent\Builder
    {
        $stockModel = config('shop.models.product_stock', ProductStock::class);

        return $stockModel::claims()
            ->willExpire()
            ->where('product_id', $this->id);
    }

    /**
     * Get available stock on a specific date
     * 
     * This is crucial for booking/rental systems where you need to know:
     * "How many units are available on date X?"
     * 
     * Calculation:
     * 1. Start with current available stock
     * 2. Add back all currently pending claims (they reduce available stock)
     * 3. Subtract only the claims that are active on the specific date
     * 
     * Example with 100 units:
     * - Claim 1: 20 units, days 5-10
     * - Claim 2: 30 units, days 8-15
     * - Current available: 50 (100 - 20 - 30)
     * - Available on day 3: 100 (no claims active)
     * - Available on day 6: 80 (only claim 1 active)
     * - Available on day 9: 50 (both claims active)
     * - Available on day 12: 70 (only claim 2 active)
     * - Available on day 20: 100 (no claims active)
     * 
     * @param DateTimeInterface $date The date to check availability for
     * @return int Available stock on that date (PHP_INT_MAX if stock management disabled)
     */
    public function availableOnDate(DateTimeInterface $date): int
    {
        if (!$this->manage_stock) {
            return PHP_INT_MAX;
        }

        // Historically-aware variant of {@see self::getAvailableStock()}:
        // ledger rows created AFTER $date are excluded, so a DECREASE placed
        // today doesn't retroactively reduce availability on a prior day.
        // Day-level comparison (against end of $date's day) so a row seeded
        // mid-day still counts for queries on that same day.
        $dateDayEnd = Carbon::instance($date)->copy()->endOfDay();

        $baseStock = $this->stocks()
            ->withoutGlobalScope('willExpire')
            ->where('status', StockStatus::COMPLETED->value)
            ->whereNotIn('type', StockType::claimTypeValues())
            ->where('created_at', '<=', $dateDayEnd)
            ->where(function ($query) use ($date) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $date);
            })
            ->sum('quantity');

        // Add back claims that should not reduce availability at the given date.
        // Same asymmetry as getAvailableStock: PHYSICALLY_CLAIMED rows stay
        // reserved past expires_at (overdue loan still in borrower's hands).
        $inactiveClaims = $this->stocks()
            ->withoutGlobalScope('willExpire')
            ->whereIn('type', StockType::claimTypeValues())
            ->where('status', StockStatus::PENDING->value)
            ->where(function ($query) use ($date) {
                $query->where(function ($q) use ($date) {
                    $q->whereNotNull('claimed_from')
                        ->where('claimed_from', '>', $date);
                })->orWhere(function ($q) use ($date) {
                    $q->where('type', StockType::CLAIMED->value)
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', $date);
                });
            })
            ->sum('quantity');

        return max(0, $baseStock + $inactiveClaims);
    }

    /**
     * Gets the available amounts per date range, with $from and $until specified
     * Returns associative array with keys 
     *  - 'max_available' => Shows the peak available stock in the date range
     *  - 'min_available' => Shows the lowest available stock in the date range
     *  - 'dates' => An array of dates with their respective available stock
     * 
     * @param DateTimeInterface $from Start date of the range (optional, defaults to today)
     * @param DateTimeInterface $until End date of the range (optional, defaults to 30 days)
     * @return array Associative array with 'max_available', 'min_available', and 'dates'
     */
    public function calendarAvailability(
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $until = null
    ): array {
        // For pool products, aggregate availability from all single items
        if (method_exists($this, 'isPool') && $this->isPool()) {
            return $this->getPoolCalendarAvailability($from, $until);
        }

        if ($this->manage_stock === false) {
            return [
                'max_available' => PHP_INT_MAX,
                'min_available' => PHP_INT_MAX,
                'dates' => [],
            ];
        }

        $fromDate = Carbon::parse($from ?? now())->startOfDay();
        $untilDate = Carbon::parse($until ?? $fromDate->copy()->addDays(30))->endOfDay();

        // Fetch all relevant stocks once for performance
        $allStocks = $this->stocks()
            ->withoutGlobalScope('willExpire')
            ->where(function ($query) {
                // Group conditions with OR to keep them within the product_id scope
                $query->where(function ($q) {
                    $q->where('status', StockStatus::COMPLETED->value)
                        ->whereNotIn('type', StockType::claimTypeValues());
                })->orWhere(function ($q) {
                    $q->where('status', StockStatus::PENDING->value)
                        ->whereIn('type', StockType::claimTypeValues());
                });
            })
            ->get();

        $dates = [];
        // Per-day intraday transitions, keyed by `YYYY-MM-DD`. Kept as a
        // SEPARATE top-level field — rather than nesting it inside each
        // `$dates[$iso]` row — so that consumers asserting strict
        // equality against the day row (`['min' => x, 'max' => y]`)
        // keep passing. Only populated for days where availability
        // varies within the day (min < max); fully uniform days don't
        // need transitions and the absent key carries that semantic.
        $transitionsByDay = [];
        $globalMax = PHP_INT_MIN;
        $globalMin = PHP_INT_MAX;

        $currentDate = $fromDate->copy();
        while ($currentDate->lte($untilDate)) {
            $dayStart = $currentDate->copy()->startOfDay();
            $dayEnd = $currentDate->copy()->endOfDay();

            // Find all "event" timestamps for this day where availability might change
            $events = [$dayStart, $dayEnd->startOfSecond()]; // Normalize dayEnd to remove microseconds
            foreach ($allStocks as $stock) {
                if ($stock->claimed_from && $stock->claimed_from->between($dayStart, $dayEnd)) {
                    $events[] = Carbon::parse($stock->claimed_from);
                }
                if ($stock->expires_at && $stock->expires_at->between($dayStart, $dayEnd)) {
                    $events[] = Carbon::parse($stock->expires_at);
                }
                // The moment a COMPLETED entry becomes effective is itself a
                // transition point — without sampling here, a DECREASE at
                // 13:32 followed by an INCREASE at 17:00 (or vice versa)
                // would be invisible to min/max if we only inspected day
                // boundaries.
                if ($stock->created_at && $stock->created_at->between($dayStart, $dayEnd)) {
                    $events[] = Carbon::parse($stock->created_at);
                }
            }

            // Remove exact duplicates
            $events = array_values(array_unique($events, SORT_REGULAR));

            $dayMin = PHP_INT_MAX;
            $dayMax = PHP_INT_MIN;
            // Time-ordered series of (HH:MM => available units) for the
            // events visited in this day. We surface it only when the day
            // is non-uniform (min < max) so downstream consumers — the
            // checkout calendar's partial-day hint, in particular — can
            // describe WHEN the item is bookable inside the day.
            $dayTransitions = [];

            // Check availability at each event timestamp to find min/max for the day
            $eventDayEnd = $dayEnd->copy();
            foreach ($events as $eventTime) {
                $available = 0;
                foreach ($allStocks as $stock) {
                    $isClaim = $stock->type instanceof StockType && $stock->type->isClaim();
                    if ($stock->status === StockStatus::COMPLETED && ! $isClaim) {
                        // A COMPLETED entry only contributes from the day it
                        // was created — without this gate, a DECREASE from a
                        // loan placed today would retroactively reduce
                        // availability on every prior day in the grid.
                        // Compared at day granularity (against end-of-day of
                        // the rendered day) so a stock seeded mid-day still
                        // contributes for every event in that same day.
                        $hasStarted = $stock->created_at === null || $stock->created_at <= $eventDayEnd;
                        $notExpired = is_null($stock->expires_at) || $stock->expires_at > $eventTime;
                        if ($hasStarted && $notExpired) {
                            $available += $stock->quantity;
                        }
                    } elseif ($stock->status === StockStatus::PENDING && $isClaim) {
                        // Add back if NOT active at this timestamp. For
                        // CLAIMED (auto-release booking) we also add back
                        // past-expires_at — the booking window is over so the
                        // unit is free again. PHYSICALLY_CLAIMED (loans)
                        // stays reserved past expires_at because the
                        // borrower physically has the item until they return
                        // it — the calendar should show "unavailable" even
                        // for overdue loans.
                        $isNotStarted = $stock->claimed_from && $stock->claimed_from > $eventTime;
                        $isExpired = $stock->type === StockType::CLAIMED
                            && $stock->expires_at
                            && $stock->expires_at <= $eventTime;
                        if ($isNotStarted || $isExpired) {
                            $available += $stock->quantity;
                        }
                    }
                }

                $available = max(0, $available);
                $dayMin = min($dayMin, $available);
                $dayMax = max($dayMax, $available);
                $dayTransitions[] = [
                    'time' => $eventTime->format('H:i'),
                    'available' => $available,
                ];
            }

            $iso = $currentDate->toDateString();
            $dates[$iso] = [
                'min' => $dayMin,
                'max' => $dayMax,
            ];
            if ($dayMin < $dayMax && !empty($dayTransitions)) {
                // Sort time-ascending and collapse same-minute duplicates
                // by keeping the LAST sample (later transitions on the same
                // minute reflect the post-event state — e.g. a booking
                // that starts at 16:00 leaves "available@16:00" as the
                // already-reduced count, which is what the customer needs
                // to see).
                usort($dayTransitions, fn ($a, $b) => strcmp($a['time'], $b['time']));
                $deduped = [];
                foreach ($dayTransitions as $t) {
                    $deduped[$t['time']] = $t['available'];
                }
                $transitionsOut = [];
                foreach ($deduped as $time => $available) {
                    $transitionsOut[] = ['time' => $time, 'available' => $available];
                }
                $transitionsByDay[$iso] = $transitionsOut;
            }

            $globalMin = min($globalMin, $dayMin);
            $globalMax = max($globalMax, $dayMax);

            $currentDate->addDay();
        }

        return [
            'max_available' => $globalMax === PHP_INT_MIN ? 0 : $globalMax,
            'min_available' => $globalMin === PHP_INT_MAX ? 0 : $globalMin,
            'dates' => $dates,
            'transitions' => $transitionsByDay,
        ];
    }

    public function calendarAvailabilityDates(
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $until = null
    ): array {
        $availability = $this->calendarAvailability($from, $until);
        return $availability['dates'];
    }

    /**
     * Gets the availability on the day by time. 00:00 shows the availables at the start of the day.
     * Every other timestamp shows what total current availability is at that time.
     * 
     * @param  null|DateTimeInterface  $date
     * @return array<string, int>|int Map of HH:MM → available units, or PHP_INT_MAX when stock management is disabled.
     */
    public function dayAvailability(?DateTimeInterface $date = null): array|int
    {
        // For pool products, aggregate availability from all single items
        if (method_exists($this, 'isPool') && $this->isPool()) {
            return $this->getPoolDayAvailability($date);
        }

        if ($this->manage_stock === false) {
            return PHP_INT_MAX;
        }

        $date = Carbon::parse($date ?? now());
        $startOfDay = $date->copy()->startOfDay();
        $endOfDay = $date->copy()->endOfDay();

        $availability = [
            '00:00' => $this->availableOnDate($startOfDay),
        ];

        $stocks = $this->stocks()
            ->withoutGlobalScope('willExpire')
            ->where(function ($query) use ($startOfDay, $endOfDay) {
                $query->where(function ($q) use ($startOfDay, $endOfDay) {
                    $q->whereNotNull('claimed_from')
                        ->whereBetween('claimed_from', [$startOfDay, $endOfDay]);
                })->orWhere(function ($q) use ($startOfDay, $endOfDay) {
                    $q->whereNotNull('expires_at')
                        ->whereBetween('expires_at', [$startOfDay, $endOfDay]);
                });
            })
            ->get();

        foreach ($stocks as $stock) {
            if ($stock->claimed_from && $stock->claimed_from->isSameDay($startOfDay)) {
                $timeKey = $stock->claimed_from->format('H:i');
                if (!isset($availability[$timeKey])) {
                    $availability[$timeKey] = $this->availableOnDate($stock->claimed_from);
                }
            }

            if ($stock->expires_at && $stock->expires_at->isSameDay($startOfDay)) {
                $timeKey = $stock->expires_at->format('H:i');
                if (!isset($availability[$timeKey])) {
                    $availability[$timeKey] = $this->availableOnDate($stock->expires_at);
                }
            }
        }

        ksort($availability);

        return $availability;
    }

    /**
     * Get calendar availability for pool products by aggregating all single items
     * 
     * @param DateTimeInterface|null $from
     * @param DateTimeInterface|null $until
     * @return array
     */
    protected function getPoolCalendarAvailability(
        ?DateTimeInterface $from = null,
        ?DateTimeInterface $until = null
    ): array {
        // Eager load single products if not already loaded
        if (!$this->relationLoaded('singleProducts')) {
            $this->load('singleProducts');
        }

        $singleItems = $this->singleProducts;

        if ($singleItems->isEmpty()) {
            $fromDate = Carbon::parse($from ?? now())->startOfDay();
            $untilDate = Carbon::parse($until ?? $fromDate->copy()->addDays(30))->endOfDay();

            $dates = [];
            $currentDate = $fromDate->copy();
            while ($currentDate->lte($untilDate)) {
                $dates[$currentDate->toDateString()] = ['min' => 0, 'max' => 0];
                $currentDate->addDay();
            }

            return [
                'max_available' => 0,
                'min_available' => 0,
                'dates' => $dates,
            ];
        }

        // Filter to only include singles that manage stock
        // Unmanaged singles have unlimited availability and don't need to be counted
        $managedSingles = $singleItems->filter(fn($single) => $single->manage_stock);

        if ($managedSingles->isEmpty()) {
            // If no singles manage stock, the pool has unlimited availability
            return [
                'max_available' => PHP_INT_MAX,
                'min_available' => PHP_INT_MAX,
                'dates' => [],
            ];
        }

        // Get availability for each managed single item
        $singleAvailabilities = [];
        foreach ($managedSingles as $single) {
            $singleAvailabilities[] = $single->calendarAvailability($from, $until);
        }

        // Aggregate the availabilities
        $dates = [];
        $globalMin = PHP_INT_MAX;
        $globalMax = PHP_INT_MIN;

        // Get all date keys from first single (they should all have the same dates)
        if (!empty($singleAvailabilities)) {
            $firstAvailability = $singleAvailabilities[0];
            foreach (array_keys($firstAvailability['dates']) as $dateKey) {
                $dayMin = 0;
                $dayMax = 0;

                // Sum up min and max from all singles for this date
                foreach ($singleAvailabilities as $singleAvail) {
                    if (isset($singleAvail['dates'][$dateKey])) {
                        $dayMin += $singleAvail['dates'][$dateKey]['min'];
                        $dayMax += $singleAvail['dates'][$dateKey]['max'];
                    }
                }

                $dates[$dateKey] = [
                    'min' => $dayMin,
                    'max' => $dayMax,
                ];

                $globalMin = min($globalMin, $dayMin);
                $globalMax = max($globalMax, $dayMax);
            }
        }

        return [
            'max_available' => $globalMax === PHP_INT_MIN ? 0 : $globalMax,
            'min_available' => $globalMin === PHP_INT_MAX ? 0 : $globalMin,
            'dates' => $dates,
        ];
    }

    /**
     * Get day availability for pool products by aggregating all single items
     * 
     * @param  DateTimeInterface|null  $date
     * @return array<string, int>|int Map of HH:MM → available units across all single items, or PHP_INT_MAX when no managed single items exist.
     */
    protected function getPoolDayAvailability(?DateTimeInterface $date = null): array|int
    {
        // Eager load single products if not already loaded
        if (!$this->relationLoaded('singleProducts')) {
            $this->load('singleProducts');
        }

        $singleItems = $this->singleProducts;

        if ($singleItems->isEmpty()) {
            return ['00:00' => 0];
        }

        // Filter to only include singles that manage stock
        $managedSingles = $singleItems->filter(fn($single) => $single->manage_stock);

        if ($managedSingles->isEmpty()) {
            return PHP_INT_MAX; // Unlimited availability
        }

        // Get day availability for each managed single item
        $singleDayAvailabilities = [];
        foreach ($managedSingles as $single) {
            $singleDayAvailabilities[] = $single->dayAvailability($date);
        }

        // Collect all unique timestamps
        $allTimestamps = [];
        foreach ($singleDayAvailabilities as $singleAvail) {
            // dayAvailability can return PHP_INT_MAX for unmanaged stock
            if (is_array($singleAvail)) {
                $allTimestamps = array_merge($allTimestamps, array_keys($singleAvail));
            }
        }
        $allTimestamps = array_unique($allTimestamps);
        sort($allTimestamps);

        // Aggregate availability for each timestamp
        $aggregated = [];
        foreach ($allTimestamps as $timestamp) {
            $total = 0;
            foreach ($singleDayAvailabilities as $singleAvail) {
                // Find the most recent timestamp <= current timestamp
                $applicableValue = 0;
                foreach ($singleAvail as $time => $value) {
                    if ($time <= $timestamp) {
                        $applicableValue = $value;
                    } else {
                        break;
                    }
                }
                $total += $applicableValue;
            }
            $aggregated[$timestamp] = $total;
        }

        return $aggregated;
    }

    /**
     * Get remaining available stock that can be added to cart
     * 
     * This method calculates how many more units can be added to a cart:
     * - For pool products: total capacity minus cart items (NOT date-restricted)
     * - For booking products: total stock minus cart items (NOT date-restricted)
     * - The idea is that users can add items freely and adjust dates later
     * - Date-based validation happens at checkout, not when adding to cart
     * 
     * @param  \Blax\Shop\Models\Cart|null  $cart Optional cart to subtract items from
     * @return int Available quantity (PHP_INT_MAX if unlimited)
     */
    public function getHasMore(?\Blax\Shop\Models\Cart $cart = null): int
    {
        // Try to get current cart from facade if not provided
        if ($cart === null) {
            try {
                $cart = \Blax\Shop\Facades\Cart::current();
            } catch (\Exception) {
                // No cart available, that's fine
                $cart = null;
            }
        }

        if (method_exists($this, 'isPool') && $this->isPool()) {
            return $this->getPoolHasMore($cart);
        }

        if ($this->manage_stock === false) {
            return PHP_INT_MAX;
        }

        // Get total stock capacity (not date-restricted)
        // This allows users to add items and adjust dates later
        $baseAvailable = $this->getAvailableStock();

        // Subtract items already in cart for this product
        if ($cart) {
            $inCart = $cart->items()
                ->where('purchasable_id', $this->getKey())
                ->where('purchasable_type', get_class($this))
                ->sum('quantity');

            $baseAvailable = max(0, $baseAvailable - $inCart);
        }

        return $baseAvailable;
    }

    /**
     * Get remaining availability for pool products
     * 
     * Returns total pool capacity minus items already in cart.
     * Does NOT consider date-based availability - that's validated at checkout.
     * 
     * @param  \Blax\Shop\Models\Cart|null  $cart
     */
    protected function getPoolHasMore(?\Blax\Shop\Models\Cart $cart = null): int
    {
        // Get total pool capacity (NOT date-restricted)
        if (method_exists($this, 'getPoolTotalCapacity')) {
            $totalCapacity = $this->getPoolTotalCapacity();
        } else {
            // Fallback if method doesn't exist
            if (!$this->relationLoaded('singleProducts')) {
                $this->load('singleProducts');
            }

            $totalCapacity = 0;
            foreach ($this->singleProducts as $single) {
                if (!$single->manage_stock) {
                    return PHP_INT_MAX;
                }
                $totalCapacity += $single->getAvailableStock();
            }
        }

        if ($totalCapacity === PHP_INT_MAX) {
            return PHP_INT_MAX;
        }

        // Subtract pool items already in cart
        if ($cart) {
            $inCart = $cart->items()
                ->where('purchasable_id', $this->getKey())
                ->where('purchasable_type', get_class($this))
                ->sum('quantity');

            $totalCapacity = max(0, $totalCapacity - $inCart);
        }

        return $totalCapacity;
    }

    /**
     * Get available stock for a specific date range
     * 
     * Use this method when you need to check date-based availability
     * (e.g., for showing a calendar, or at checkout validation)
     * 
     * @param DateTimeInterface $from
     * @param DateTimeInterface $until
     * @param  \Blax\Shop\Models\Cart|null  $cart Optional cart to subtract items from
     */
    public function getAvailableForDateRange(
        DateTimeInterface $from,
        DateTimeInterface $until,
        ?\Blax\Shop\Models\Cart $cart = null
    ): int {
        if ($this->manage_stock === false) {
            return PHP_INT_MAX;
        }

        if (method_exists($this, 'isPool') && $this->isPool()) {
            // For pools, get min availability across all singles for the date range
            if (method_exists($this, 'getPoolMaxQuantity')) {
                $available = $this->getPoolMaxQuantity($from, $until);
            } else {
                $available = $this->getMinAvailableInRange($from, $until);
            }
        } else {
            $available = $this->getMinAvailableInRange($from, $until);
        }

        // Subtract items already in cart for this product
        if ($cart) {
            $inCart = $cart->items()
                ->where('purchasable_id', $this->getKey())
                ->where('purchasable_type', get_class($this))
                ->sum('quantity');

            $available = max(0, $available - $inCart);
        }

        return $available;
    }

    /**
     * Get minimum available stock across a date range
     * 
     * @param DateTimeInterface $from
     * @param DateTimeInterface $until
     * @return int
     */
    protected function getMinAvailableInRange(DateTimeInterface $from, DateTimeInterface $until): int
    {
        $availability = $this->calendarAvailability($from, $until);

        if (empty($availability['dates'])) {
            return $availability['min_available'] ?? 0;
        }

        $minAvailable = PHP_INT_MAX;
        foreach ($availability['dates'] as $dayData) {
            $minAvailable = min($minAvailable, $dayData['min'] ?? 0);
        }

        return $minAvailable === PHP_INT_MAX ? 0 : $minAvailable;
    }

    /**
     * Attribute accessor for has_more
     * 
     * Returns available stock that can still be added to cart:
     * - Total capacity minus items already in cart
     * - Does NOT consider date-based restrictions
     * - Date validation happens at checkout
     * 
     * @return int Available quantity (PHP_INT_MAX if unlimited)
     */
    public function getHasMoreAttribute(): int
    {
        return $this->getHasMore();
    }
}
