<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Exceptions\NotEnoughStockException;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * HasStocks Trait
 * 
 * Provides stock management functionality to Product models.
 * 
 * Key Features:
 * - Basic stock operations (increase, decrease, adjust)
 * - Stock claims for bookings/reservations
 * - Date-based availability checking
 * - Low stock detection
 * - Stock movement logging
 * 
 * Usage:
 * - Add 'manage_stock' boolean column to products table
 * - Set manage_stock = true to enable stock tracking
 * - Use increaseStock/decreaseStock for inventory changes
 * - Use claimStock for reservations/bookings
 * - Use availableOnDate for date-based availability
 * 
 * Stock Calculation:
 * - Physical Stock = Sum of all COMPLETED entries
 * - Available Stock = Physical Stock (accounts for pending claims via their DECREASE entries)
 * - Claimed Stock = Sum of PENDING claims
 * - Available on Date = Available Stock + All Claims - Claims Active on Date
 */
trait HasStocks
{
    /**
     * Get all available stock entries for this product
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(config('shop.models.product_stock', 'Blax\Shop\Models\ProductStock'));
    }

    /**
     * Get all stock entries for this product including unavailable ones
     */
    public function allStocks(): HasMany
    {
        return $this->hasMany(config('shop.models.product_stock', 'Blax\Shop\Models\ProductStock'))
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
            ->where('type', '!=', StockType::CLAIMED->value)
            ->willExpire()
            ->sum('quantity') ?? 0;
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
    public function decreaseStock(int $quantity = 1, Carbon|null $until = null): bool
    {
        if (!$this->manage_stock) {
            return true;
        }

        $available = $this->getAvailableStock();
        if ($available < $quantity) {
            return throw new NotEnoughStockException("Not enough stock available for product ID {$this->id}");
        }

        $this->stocks()->create([
            'quantity' => -$quantity,
            'type' => StockType::DECREASE,
            'status' => StockStatus::COMPLETED,
            'expires_at' => $until,
        ]);

        $this->logStockChange(-$quantity, 'decrease');

        $this->save();

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

        $this->stocks()->create([
            'quantity' => $quantity,
            'type' => StockType::INCREASE,
            'status' => StockStatus::COMPLETED,
        ]);

        $this->logStockChange($quantity, 'increase');

        $this->save();

        return true;
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
     * @param \DateTimeInterface|null $until Optional expiration date (when stock expires or claim ends)
     * @param \DateTimeInterface|null $from Optional start date (used for CLAIMED type, defaults to now())
     * @param StockStatus|null $status Optional status (defaults to COMPLETED, or PENDING for CLAIMED type)
     * @param string|null $note Optional note for documentation purposes
     * @param Model|null $referencable Optional polymorphic reference to related model
     * @return bool|\Blax\Shop\Models\ProductStock True if successful for non-CLAIMED types, ProductStock instance for CLAIMED type, false if stock management disabled
     * @throws NotEnoughStockException If insufficient stock available for DECREASE or CLAIMED types
     */
    public function adjustStock(
        StockType $type,
        int $quantity,
        \DateTimeInterface|null $until = null,
        \DateTimeInterface|null $from = null,
        ?StockStatus $status = null,
        string|null $note = null,
        Model|null $referencable = null
    ) {
        if (!$this->manage_stock) {
            return false;
        }

        // For CLAIMED type, delegate to claimStock which handles the two-entry pattern
        if ($type === StockType::CLAIMED) {
            return $this->claimStock(
                quantity: $quantity,
                reference: $referencable,
                from: $from,
                until: $until,
                note: $note
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
     * Claim stock for temporary use (reservation/booking)
     * 
     * This is different from decreaseStock - it:
     * 1. Removes stock from available inventory (via DECREASE entry)
     * 2. Tracks it as a claim (via CLAIMED entry with PENDING status)
     * 3. Can be released back later (changes CLAIMED to COMPLETED)
     * 4. Supports date ranges for bookings (claimed_from to expires_at)
     * 
     * Use cases:
     * - Hotel room bookings (claimed_from = check-in, expires_at = check-out)
     * - Equipment rentals (claimed_from = rental start, expires_at = return date)
     * - Cart reservations (no claimed_from, expires_at = cart expiry)
     * 
     * @param int $quantity Amount to claim
     * @param mixed $reference Optional reference model (Order, Booking, Cart, etc.)
     * @param \DateTimeInterface|null $from When claim starts (null = immediately)
     * @param \DateTimeInterface|null $until When claim expires (null = permanent)
     * @param string|null $note Optional note about the claim
     * @return \Blax\Shop\Models\ProductStock|null The claim entry, or null if insufficient stock
     */
    public function claimStock(
        int $quantity,
        $reference = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $until = null,
        ?string $note = null
    ): ?\Blax\Shop\Models\ProductStock {

        if (!$this->manage_stock) {
            return null;
        }

        $stockModel = config('shop.models.product_stock', 'Blax\Shop\Models\ProductStock');

        return $stockModel::claim(
            $this,
            $quantity,
            $reference,
            $from,
            $until,
            $note
        );
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
    public function getAvailableStock(?\DateTimeInterface $date = null): int
    {
        if (!$this->manage_stock) {
            return PHP_INT_MAX;
        }

        $date = $date ?? now();

        // Base stock: all COMPLETED entries except CLAIMED, filtered using the provided date
        $baseStock = $this->stocks()
            ->withoutGlobalScope('willExpire')
            ->where('status', StockStatus::COMPLETED->value)
            ->where('type', '!=', StockType::CLAIMED->value)
            ->where(function ($query) use ($date) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $date);
            })
            ->sum('quantity');

        // Add back claims that should not reduce availability at the given date
        $inactiveClaims = $this->stocks()
            ->withoutGlobalScope('willExpire')
            ->where('type', StockType::CLAIMED->value)
            ->where('status', StockStatus::PENDING->value)
            ->where(function ($query) use ($date) {
                $query->where(function ($q) use ($date) {
                    // Claim has not started yet
                    $q->whereNotNull('claimed_from')
                        ->where('claimed_from', '>', $date);
                })->orWhere(function ($q) use ($date) {
                    // Claim expired before the date
                    $q->whereNotNull('expires_at')
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
        return abs($this->stocks()
            ->where('type', StockType::CLAIMED->value)
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
        return abs($this->stocks()
            ->where('type', StockType::CLAIMED->value)
            ->where('status', StockStatus::PENDING->value)
            ->willExpire()
            ->sum('quantity'));
    }

    /**
     * Get future claimed stock starting from a specific date or all where claimed_at is future
     * 
     * @param \DateTimeInterface|null $from Optional start date to filter claims
     * @return int Total future claimed quantity (always positive)
     */
    public function getFutureClaimedStock(?\DateTimeInterface $from = null): int
    {
        $query = $this->stocks()
            ->where('type', StockType::CLAIMED->value)
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

        return abs($query->sum('quantity'));
    }


    /**
     * Log a stock change to the audit log
     * 
     * @param int $quantityChange The change in quantity (positive or negative)
     * @param string $type The type of change (increase, decrease, adjust)
     */
    protected function logStockChange(int $quantityChange, string $type): void
    {
        DB::table('product_stock_logs')->insert([
            'product_id' => $this->id,
            'quantity_change' => $quantityChange,
            'quantity_after' => $this->getAvailableStock(),
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
     * Get active claims for this product
     * 
     * Returns query builder for PENDING claims that haven't expired yet.
     * Useful for:
     * - Viewing current reservations
     * - Checking what's claimed but not released
     * - Managing active bookings
     * 
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function claims()
    {
        $stockModel = config('shop.models.product_stock', 'Blax\Shop\Models\ProductStock');

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
     * @param \DateTimeInterface $date The date to check availability for
     * @return int Available stock on that date (PHP_INT_MAX if stock management disabled)
     */
    public function availableOnDate(\DateTimeInterface $date): int
    {
        if (!$this->manage_stock) {
            return PHP_INT_MAX;
        }

        return $this->getAvailableStock($date);
    }

    /**
     * Gets the available amounts per date range, with $from and $until specified
     * Returns associative array with keys 
     *  - 'max_available' => Shows the peak available stock in the date range
     *  - 'min_available' => Shows the lowest available stock in the date range
     *  - 'dates' => An array of dates with their respective available stock
     * 
     * @param \DateTimeInterface $from Start date of the range (optional, defaults to today)
     * @param \DateTimeInterface $until End date of the range (optional, defaults to 30 days)
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
                        ->where('type', '!=', StockType::CLAIMED->value);
                })->orWhere(function ($q) {
                    $q->where('status', StockStatus::PENDING->value)
                        ->where('type', StockType::CLAIMED->value);
                });
            })
            ->get();

        $dates = [];
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
            }

            // Remove exact duplicates
            $events = array_values(array_unique($events, SORT_REGULAR));

            $dayMin = PHP_INT_MAX;
            $dayMax = PHP_INT_MIN;

            // Check availability at each event timestamp to find min/max for the day
            foreach ($events as $eventTime) {
                $available = 0;
                foreach ($allStocks as $stock) {
                    if ($stock->status === StockStatus::COMPLETED && $stock->type !== StockType::CLAIMED) {
                        if (is_null($stock->expires_at) || $stock->expires_at > $eventTime) {
                            $available += $stock->quantity;
                        }
                    } elseif ($stock->status === StockStatus::PENDING && $stock->type === StockType::CLAIMED) {
                        // Add back if NOT active at this timestamp
                        $isNotStarted = $stock->claimed_from && $stock->claimed_from > $eventTime;
                        $isExpired = $stock->expires_at && $stock->expires_at <= $eventTime;
                        if ($isNotStarted || $isExpired) {
                            $available += $stock->quantity;
                        }
                    }
                }

                $available = max(0, $available);
                $dayMin = min($dayMin, $available);
                $dayMax = max($dayMax, $available);
            }

            $dates[$currentDate->toDateString()] = [
                'min' => $dayMin,
                'max' => $dayMax,
            ];

            $globalMin = min($globalMin, $dayMin);
            $globalMax = max($globalMax, $dayMax);

            $currentDate->addDay();
        }

        return [
            'max_available' => $globalMax === PHP_INT_MIN ? 0 : $globalMax,
            'min_available' => $globalMin === PHP_INT_MAX ? 0 : $globalMin,
            'dates' => $dates,
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
     * @param null|DateTimeInterface $date
     * @return array|int
     */
    public function dayAvailability(?DateTimeInterface $date = null)
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
     * @param \DateTimeInterface|null $from
     * @param \DateTimeInterface|null $until
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
            foreach ($firstAvailability['dates'] as $dateKey => $dayData) {
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
     * @param \DateTimeInterface|null $date
     * @return array
     */
    protected function getPoolDayAvailability(?DateTimeInterface $date = null): array
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
}
