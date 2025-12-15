<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Exceptions\InvalidPoolConfigurationException;

trait MayBePoolProduct
{
    /**
     * Check if this is a pool product
     */
    public function isPool(): bool
    {
        return $this->type === ProductType::POOL;
    }

    /**
     * Get the available quantity for this product
     * For pool products, returns the count of available single items
     * For regular products, returns available stock
     */
    public function getAvailableQuantity(\DateTimeInterface $from = null, \DateTimeInterface $until = null): int
    {
        if ($this->isPool()) {
            return $this->getPoolMaxQuantity($from, $until);
        }

        return $this->getAvailableStock();
    }

    /**
     * Get the maximum available quantity for a pool product based on single items
     */
    public function getPoolMaxQuantity(\DateTimeInterface $from = null, \DateTimeInterface $until = null): int
    {
        if (!$this->isPool()) {
            return $this->getAvailableStock();
        }

        $singleItems = $this->singleProducts;

        if ($singleItems->isEmpty()) {
            return 0;
        }

        // If no dates provided, sum up available stock from all single items
        if (!$from || !$until) {
            $hasUnlimitedItem = false;
            $total = 0;

            foreach ($singleItems as $item) {
                if (!$item->manage_stock) {
                    // Track if there's an unlimited item, but don't count it
                    $hasUnlimitedItem = true;
                    continue;
                }
                $total += $item->getAvailableStock();
            }

            // If ALL items are unlimited, pool is unlimited
            if ($hasUnlimitedItem && $total === 0) {
                return PHP_INT_MAX;
            }

            return $total;
        }

        // Check availability for each single item during the timespan and sum their available quantities
        $availableCount = 0;
        $hasUnlimitedItem = false;

        foreach ($singleItems as $item) {
            // Track unlimited items but don't count them
            if (!$item->manage_stock) {
                $hasUnlimitedItem = true;
                continue;
            }

            // For booking items, check how many units are available for the period
            if ($item->isBooking()) {
                $availableStock = $item->getAvailableStock();
                // Check if any quantity is available for booking
                for ($qty = $availableStock; $qty > 0; $qty--) {
                    if ($item->isAvailableForBooking($from, $until, $qty)) {
                        $availableCount += $qty;
                        break;
                    }
                }
            } else {
                // For non-booking items, just add their available stock
                $availableCount += $item->getAvailableStock();
            }
        }

        // If ALL items are unlimited, pool is unlimited
        if ($hasUnlimitedItem && $availableCount === 0) {
            return PHP_INT_MAX;
        }

        return $availableCount;
    }

    /**
     * Claim stock for a pool product
     * This will claim stock from the available single items
     * 
     * @param int $quantity Number of pool items to claim
     * @param mixed $reference Reference model
     * @param \DateTimeInterface|null $from Start date
     * @param \DateTimeInterface|null $until End date
     * @param string|null $note Optional note
     * @return array Array of claimed single item products
     * @throws \Exception
     */
    public function claimPoolStock(
        int $quantity,
        $reference = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $until = null,
        ?string $note = null
    ): array {
        if (!$this->isPool()) {
            throw new \Exception('This method is only for pool products');
        }

        $singleItems = $this->singleProducts;

        if ($singleItems->isEmpty()) {
            throw new \Exception('Pool product has no single items to claim');
        }

        // Get available single items for the period
        $availableItems = [];
        foreach ($singleItems as $item) {
            if ($item->isAvailableForBooking($from, $until, 1)) {
                $availableItems[] = $item;
            }

            if (count($availableItems) >= $quantity) {
                break;
            }
        }

        if (count($availableItems) < $quantity) {
            throw new \Exception("Only " . count($availableItems) . " items available, but {$quantity} requested");
        }

        // Claim stock from each selected single item
        $claimedItems = [];
        foreach (array_slice($availableItems, 0, $quantity) as $item) {
            $item->claimStock(1, $reference, $from, $until, $note);
            $claimedItems[] = $item;
        }

        return $claimedItems;
    }

    /**
     * Release pool stock claims
     * 
     * @param mixed $reference Reference model used when claiming
     * @return int Number of claims released
     */
    public function releasePoolStock($reference): int
    {
        if (!$this->isPool()) {
            throw new \Exception('This method is only for pool products');
        }

        $singleItems = $this->singleProducts;
        $released = 0;

        foreach ($singleItems as $item) {
            $referenceType = is_object($reference) ? get_class($reference) : null;
            $referenceId = is_object($reference) ? $reference->id : null;

            // Find and delete claims for this reference
            $claims = $item->stocks()
                ->where('type', StockType::CLAIMED->value)
                ->where('status', StockStatus::PENDING->value)
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->get();

            foreach ($claims as $claim) {
                $claim->release();
                $released++;
            }
        }

        return $released;
    }

    /**
     * Check if any single item in pool is a booking product
     */
    public function hasBookingSingleItems(): bool
    {
        if (!$this->isPool()) {
            return false;
        }

        return $this->singleProducts()->where('products.type', ProductType::BOOKING->value)->exists();
    }

    /**
     * Get the current price with pool product inheritance support
     */
    public function getPoolCurrentPrice(bool|null $sales_price = null): ?float
    {
        // If this is a pool product and it has no direct price, inherit from single items
        if ($this->isPool() && !$this->hasPrice()) {
            return $this->getInheritedPoolPrice($sales_price);
        }

        // If pool has a direct price, use it
        if ($this->isPool() && $this->hasPrice()) {
            return $this->defaultPrice()->first()?->getCurrentPrice($sales_price ?? $this->isOnSale());
        }

        return null;
    }

    /**
     * Get inherited price from single items based on pricing strategy
     */
    protected function getInheritedPoolPrice(bool|null $sales_price = null): ?float
    {
        if (!$this->isPool()) {
            return null;
        }

        $strategy = $this->getPoolPricingStrategy();

        $singleItems = $this->singleProducts;

        if ($singleItems->isEmpty()) {
            return null;
        }

        $prices = $singleItems->map(function ($item) use ($sales_price) {
            return $item->defaultPrice()->first()?->getCurrentPrice($sales_price ?? $item->isOnSale());
        })->filter()->values();

        if ($prices->isEmpty()) {
            return null;
        }

        return match ($strategy) {
            'lowest' => $prices->min(),
            'highest' => $prices->max(),
            'average' => round($prices->avg()),
            default => round($prices->avg()), // Default to average
        };
    }

    /**
     * Get the pool pricing strategy from metadata
     */
    public function getPoolPricingStrategy(): string
    {
        if (!$this->isPool()) {
            return 'average';
        }

        $meta = $this->getMeta();
        return $meta->pricing_strategy ?? 'average';
    }

    /**
     * Set the pool pricing strategy
     */
    public function setPoolPricingStrategy(string $strategy): void
    {
        if (!$this->isPool()) {
            throw new \Exception('This method is only for pool products');
        }

        if (!in_array($strategy, ['average', 'lowest', 'highest'])) {
            throw new \InvalidArgumentException("Invalid pricing strategy: {$strategy}");
        }

        $this->updateMetaKey('pricing_strategy', $strategy);
        $this->save();
    }

    /**
     * Attach single items to this pool product
     * Also creates reverse POOL relation from single items back to this pool
     * 
     * @param array|int|string $singleItemIds Single product ID(s) to attach
     * @param array $attributes Additional pivot attributes
     * @return void
     */
    public function attachSingleItems(array|int|string $singleItemIds, array $attributes = []): void
    {
        if (!$this->isPool()) {
            throw new \Exception('This method is only for pool products');
        }

        $ids = is_array($singleItemIds) ? $singleItemIds : [$singleItemIds];

        // Attach single items to pool with SINGLE type
        $this->productRelations()->attach(
            array_fill_keys($ids, array_merge(['type' => ProductRelationType::SINGLE->value], $attributes))
        );

        // Attach reverse POOL relation from each single item back to this pool
        foreach ($ids as $singleItemId) {
            $singleItem = static::find($singleItemId);
            if ($singleItem) {
                $singleItem->productRelations()->attach(
                    $this->id,
                    array_merge(['type' => ProductRelationType::POOL->value], $attributes)
                );
            }
        }
    }

    /**
     * Get the lowest price from single items
     */
    public function getLowestPoolPrice(): ?float
    {
        if (!$this->isPool()) {
            return null;
        }

        $singleItems = $this->singleProducts;

        if ($singleItems->isEmpty()) {
            return null;
        }

        $prices = $singleItems->map(function ($item) {
            return $item->defaultPrice()->first()?->getCurrentPrice($item->isOnSale());
        })->filter()->values();

        return $prices->isEmpty() ? null : $prices->min();
    }

    /**
     * Get the highest price from single items
     */
    public function getHighestPoolPrice(): ?float
    {
        if (!$this->isPool()) {
            return null;
        }

        $singleItems = $this->singleProducts;

        if ($singleItems->isEmpty()) {
            return null;
        }

        $prices = $singleItems->map(function ($item) {
            return $item->defaultPrice()->first()?->getCurrentPrice($item->isOnSale());
        })->filter()->values();

        return $prices->isEmpty() ? null : $prices->max();
    }

    /**
     * Get the price range for pool products
     */
    public function getPoolPriceRange(): ?array
    {
        if (!$this->isPool()) {
            return null;
        }

        $lowest = $this->getLowestPoolPrice();
        $highest = $this->getHighestPoolPrice();

        if ($lowest === null || $highest === null) {
            return null;
        }

        return [
            'min' => $lowest,
            'max' => $highest,
        ];
    }

    /**
     * Validate pool product configuration and provide helpful error messages
     * 
     * @throws InvalidPoolConfigurationException
     */
    public function validatePoolConfiguration(bool $throwOnWarnings = false): array
    {
        $errors = [];
        $warnings = [];

        if (!$this->isPool()) {
            throw InvalidPoolConfigurationException::notAPoolProduct($this->name);
        }

        $singleItems = $this->singleProducts;

        // Critical: No single items
        if ($singleItems->isEmpty()) {
            throw InvalidPoolConfigurationException::noSingleItems($this->name);
        }

        // Check for mixed product types
        $types = $singleItems->pluck('type')->unique();
        if ($types->count() > 1) {
            $warning = "Mixed single item types detected. This may cause unexpected behavior.";
            $warnings[] = $warning;
            if ($throwOnWarnings) {
                throw InvalidPoolConfigurationException::mixedSingleItemTypes($this->name);
            }
        }

        // Check stock management on single items
        $itemsWithoutStock = $singleItems->filter(fn($item) => !$item->manage_stock);
        if ($itemsWithoutStock->isNotEmpty()) {
            $itemNames = $itemsWithoutStock->pluck('name')->toArray();
            $errors[] = "Single items without stock management: " . implode(', ', $itemNames);
            throw InvalidPoolConfigurationException::singleItemsWithoutStock($this->name, $itemNames);
        }

        // Check for items with zero stock
        $itemsWithZeroStock = $singleItems->filter(fn($item) => $item->getAvailableStock() <= 0);
        if ($itemsWithZeroStock->isNotEmpty()) {
            $itemNames = $itemsWithZeroStock->pluck('name')->toArray();
            $warnings[] = "Single items with zero stock: " . implode(', ', $itemNames);
            if ($throwOnWarnings) {
                throw InvalidPoolConfigurationException::singleItemsWithZeroStock($this->name, $itemNames);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get pool availability calendar showing how many items are available on each date.
     * Returns an array with dates as keys and availability counts as values.
     * 
     * Example usage:
     * ```php
     * $pool = Product::find($id);
     * $availability = $pool->getPoolAvailabilityCalendar('2025-01-01', '2025-01-07', 2);
     * 
     * foreach ($availability as $date => $count) {
     *     echo "$date: $count items available\n";
     * }
     * // Output:
     * // 2025-01-01: 3 items available
     * // 2025-01-02: 2 items available
     * // 2025-01-03: 1 items available
     * ```
     * 
     * @param \DateTimeInterface|string $startDate Start date for availability check
     * @param \DateTimeInterface|string $endDate End date for availability check
     * @param int $quantity How many items are needed (default 1)
     * @return array Array with dates as keys and availability counts as values
     */
    public function getPoolAvailabilityCalendar($startDate, $endDate, int $quantity = 1): array
    {
        if (!$this->isPool()) {
            throw new \Exception('This method is only for pool products');
        }

        $start = $startDate instanceof \DateTimeInterface ? $startDate : \Carbon\Carbon::parse($startDate);
        $end = $endDate instanceof \DateTimeInterface ? $endDate : \Carbon\Carbon::parse($endDate);

        $calendar = [];
        $current = $start->copy();

        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $nextDay = $current->copy()->addDay();

            // Check availability for this single day
            $available = $this->getPoolMaxQuantity($current, $nextDay);
            $calendar[$dateStr] = $available === PHP_INT_MAX ? 'unlimited' : $available;

            $current->addDay();
        }

        return $calendar;
    }

    /**
     * Get detailed availability for each single item in the pool.
     * Shows which specific items are available and their quantities.
     * 
     * Example usage:
     * ```php
     * $pool = Product::find($id);
     * $items = $pool->getSingleItemsAvailability('2025-01-01', '2025-01-02');
     * 
     * foreach ($items as $item) {
     *     echo "{$item['name']}: {$item['available']} available\n";
     * }
     * ```
     * 
     * @param \DateTimeInterface|string|null $from Start date (optional)
     * @param \DateTimeInterface|string|null $until End date (optional)
     * @return array Array of single items with their availability
     */
    public function getSingleItemsAvailability($from = null, $until = null): array
    {
        if (!$this->isPool()) {
            throw new \Exception('This method is only for pool products');
        }

        $singleItems = $this->singleProducts;
        $availability = [];

        if ($from && $until) {
            $fromDate = $from instanceof \DateTimeInterface ? $from : \Carbon\Carbon::parse($from);
            $untilDate = $until instanceof \DateTimeInterface ? $until : \Carbon\Carbon::parse($until);
        }

        foreach ($singleItems as $item) {
            $available = 0;

            if (!$item->manage_stock) {
                $available = 'unlimited';
            } elseif (isset($fromDate) && isset($untilDate)) {
                // Check availability for the specific period
                if ($item->isBooking()) {
                    $availableStock = $item->getAvailableStock();
                    // Check maximum available quantity for this period
                    for ($qty = $availableStock; $qty > 0; $qty--) {
                        if ($item->isAvailableForBooking($fromDate, $untilDate, $qty)) {
                            $available = $qty;
                            break;
                        }
                    }
                } else {
                    $available = $item->getAvailableStock();
                }
            } else {
                // No dates specified, get general stock
                $available = $item->getAvailableStock();
            }

            $availability[] = [
                'id' => $item->id,
                'name' => $item->name,
                'type' => $item->type->value,
                'available' => $available,
                'manage_stock' => $item->manage_stock,
            ];
        }

        return $availability;
    }

    /**
     * Check if the pool is available for a specific date range and quantity.
     * A pool is NOT available if at least one single item is not available.
     * 
     * @param \DateTimeInterface $from Start date
     * @param \DateTimeInterface $until End date
     * @param int $quantity Required quantity
     * @return bool True if pool is available for the period
     */
    public function isPoolAvailable(\DateTimeInterface $from, \DateTimeInterface $until, int $quantity = 1): bool
    {
        if (!$this->isPool()) {
            throw new \Exception('This method is only for pool products');
        }

        $maxQuantity = $this->getPoolMaxQuantity($from, $until);

        // Unlimited availability
        if ($maxQuantity === PHP_INT_MAX) {
            return true;
        }

        return $maxQuantity >= $quantity;
    }

    /**
     * Get available date ranges for the pool with a specific quantity.
     * Returns periods where the pool has the required availability.
     * 
     * @param \DateTimeInterface|string $startDate Start of search period
     * @param \DateTimeInterface|string $endDate End of search period
     * @param int $quantity Required quantity
     * @param int $minConsecutiveDays Minimum consecutive days needed (default 1)
     * @return array Array of available periods
     */
    public function getPoolAvailablePeriods($startDate, $endDate, int $quantity = 1, int $minConsecutiveDays = 1): array
    {
        if (!$this->isPool()) {
            throw new \Exception('This method is only for pool products');
        }

        $start = $startDate instanceof \DateTimeInterface ? $startDate : \Carbon\Carbon::parse($startDate);
        $end = $endDate instanceof \DateTimeInterface ? $endDate : \Carbon\Carbon::parse($endDate);

        $calendar = $this->getPoolAvailabilityCalendar($start, $end, $quantity);
        $periods = [];
        $currentPeriod = null;

        foreach ($calendar as $date => $available) {
            $isAvailable = ($available === 'unlimited' || $available >= $quantity);

            if ($isAvailable) {
                if ($currentPeriod === null) {
                    $currentPeriod = [
                        'from' => $date,
                        'until' => $date,
                        'min_available' => $available,
                    ];
                } else {
                    $currentPeriod['until'] = $date;
                    if ($available !== 'unlimited' && $currentPeriod['min_available'] !== 'unlimited') {
                        $currentPeriod['min_available'] = min($currentPeriod['min_available'], $available);
                    }
                }
            } else {
                if ($currentPeriod !== null) {
                    // Check if period meets minimum days requirement
                    $from = \Carbon\Carbon::parse($currentPeriod['from']);
                    $until = \Carbon\Carbon::parse($currentPeriod['until']);
                    $days = $from->diffInDays($until) + 1; // +1 to include both start and end dates

                    if ($days >= $minConsecutiveDays) {
                        $periods[] = $currentPeriod;
                    }
                    $currentPeriod = null;
                }
            }
        }

        // Add final period if exists
        if ($currentPeriod !== null) {
            $from = \Carbon\Carbon::parse($currentPeriod['from']);
            $until = \Carbon\Carbon::parse($currentPeriod['until']);
            $days = $from->diffInDays($until) + 1;

            if ($days >= $minConsecutiveDays) {
                $periods[] = $currentPeriod;
            }
        }

        return $periods;
    }
}
