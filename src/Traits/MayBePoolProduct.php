<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PricingStrategy;
use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Exceptions\InvalidPoolConfigurationException;
use Blax\Shop\Exceptions\InvalidPricingStrategyException;
use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Exceptions\NotPoolProductException;
use Blax\Shop\Exceptions\PoolHasNoItemsException;

trait MayBePoolProduct
{
    use HasBookingPriceCalculation;
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
                // Get available stock at the START of the booking period
                // This ensures we don't count claims that will be released before the booking starts
                $availableStock = $item->getAvailableStock($from);
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
     * This will claim stock from the available single items, respecting the pricing strategy
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
            throw new NotPoolProductException();
        }

        $singleItems = $this->singleProducts;

        if ($singleItems->isEmpty()) {
            throw new PoolHasNoItemsException();
        }

        // Get pricing strategy
        $strategy = $this->getPricingStrategy();

        // Build list of available single items with their prices
        // IMPORTANT: Collect ALL available items first, then sort, to ensure correct pricing strategy order
        $availableItems = [];
        foreach ($singleItems as $item) {
            if ($item->isAvailableForBooking($from, $until, 1)) {
                // Get the price for this item
                $price = $item->defaultPrice()->first()?->getCurrentPrice($item->isOnSale());

                // If item has no price, use pool's fallback price
                if ($price === null && $this->hasPrice()) {
                    $price = $this->defaultPrice()->first()?->getCurrentPrice($this->isOnSale());
                }

                $availableItems[] = [
                    'item' => $item,
                    'price' => $price ?? PHP_FLOAT_MAX, // Items without prices go last
                ];
            }
        }

        if (count($availableItems) < $quantity) {
            throw new NotEnoughStockException("Only " . count($availableItems) . " items available, but {$quantity} requested");
        }

        // Sort by pricing strategy
        usort($availableItems, function ($a, $b) use ($strategy) {
            return match ($strategy) {
                \Blax\Shop\Enums\PricingStrategy::LOWEST => $a['price'] <=> $b['price'],
                \Blax\Shop\Enums\PricingStrategy::HIGHEST => $b['price'] <=> $a['price'],
                \Blax\Shop\Enums\PricingStrategy::AVERAGE => 0, // Keep original order for average
            };
        });

        // Claim stock from selected items in order
        $claimedItems = [];
        foreach (array_slice($availableItems, 0, $quantity) as $itemData) {
            $item = $itemData['item'];
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
            throw new NotPoolProductException();
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
     * Calculate available quantity for a single item considering booking dates
     * This is a DRY helper method used by multiple pool pricing methods
     * 
     * @param Product $item The single item to check
     * @param \DateTimeInterface|null $from Start date for availability check
     * @param \DateTimeInterface|null $until End date for availability check
     * @return int Available quantity (PHP_INT_MAX for unlimited)
     */
    protected function calculateSingleItemAvailability(
        $item,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $until = null
    ): int {
        $available = 0;

        if ($from && $until) {
            if ($item->isBooking()) {
                if (!$item->manage_stock) {
                    $available = PHP_INT_MAX;
                } else {
                    // Calculate overlapping claims for this specific period
                    $overlappingClaims = $item->stocks()
                        ->where('type', \Blax\Shop\Enums\StockType::CLAIMED->value)
                        ->where('status', \Blax\Shop\Enums\StockStatus::PENDING->value)
                        ->where(function ($query) use ($from, $until) {
                            $query->where(function ($q) use ($from, $until) {
                                $q->whereBetween('claimed_from', [$from, $until]);
                            })->orWhere(function ($q) use ($from, $until) {
                                $q->whereBetween('expires_at', [$from, $until]);
                            })->orWhere(function ($q) use ($from, $until) {
                                $q->where('claimed_from', '<=', $from)
                                    ->where('expires_at', '>=', $until);
                            })->orWhere(function ($q) use ($from, $until) {
                                $q->whereNull('claimed_from')
                                    ->where(function ($subQ) use ($from, $until) {
                                        $subQ->whereNull('expires_at')
                                            ->orWhere('expires_at', '>=', $from);
                                    });
                            });
                        })
                        ->sum('quantity');

                    // Get available stock at the START of the booking period
                    // This ensures claims that will expire before the booking starts don't reduce availability
                    $available = max(0, $item->getAvailableStock($from) - abs($overlappingClaims));
                }
            } elseif (!$item->isBooking()) {
                $available = $item->getAvailableStock();
            }
        } else {
            if ($item->manage_stock) {
                $available = $item->getAvailableStock();
            } else {
                $available = PHP_INT_MAX;
            }
        }

        return $available;
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
     * Gets prices from available (not yet claimed) single items
     */
    public function getInheritedPoolPrice(bool|null $sales_price = null, ?\DateTimeInterface $from = null, ?\DateTimeInterface $until = null): ?float
    {
        if (!$this->isPool()) {
            return null;
        }

        $strategy = $this->getPricingStrategy();

        $singleItems = $this->singleProducts;

        if ($singleItems->isEmpty()) {
            // No single items, fall back to pool's direct price if available
            if ($this->hasPrice()) {
                return $this->defaultPrice()->first()?->getCurrentPrice($sales_price ?? $this->isOnSale());
            }
            return null;
        }

        // Get available prices from single items (filtering out claimed items)
        $prices = $singleItems->map(function ($item) use ($sales_price, $from, $until) {
            // Only get price if the item is available
            if ($from && $until) {
                if (!$item->isAvailableForBooking($from, $until, 1)) {
                    return null;
                }
            } else {
                if ($item->getAvailableStock() <= 0 && $item->manage_stock) {
                    return null;
                }
            }

            return $item->defaultPrice()->first()?->getCurrentPrice($sales_price ?? $item->isOnSale());
        })->filter()->values();

        if ($prices->isEmpty()) {
            // Single items exist but either:
            // 1. None are available (sold out) - return null
            // 2. None have prices configured - fall back to pool's direct price

            // Check if any items are available but just missing prices
            $hasAvailableItemsWithoutPrices = $singleItems->contains(function ($item) use ($from, $until) {
                if ($from && $until) {
                    return $item->isAvailableForBooking($from, $until, 1);
                }
                return $item->getAvailableStock() > 0 || !$item->manage_stock;
            });

            // If items are available but have no prices, use pool's direct price as fallback
            if ($hasAvailableItemsWithoutPrices && $this->hasPrice()) {
                return $this->defaultPrice()->first()?->getCurrentPrice($sales_price ?? $this->isOnSale());
            }

            // Items are sold out or pool has no fallback price
            return null;
        }

        return match ($strategy) {
            PricingStrategy::LOWEST => $prices->min(),
            PricingStrategy::HIGHEST => $prices->max(),
            PricingStrategy::AVERAGE => round($prices->avg()),
        };
    }

    /**
     * Get the lowest price from available single items
     */
    public function getLowestAvailablePoolPrice(?\DateTimeInterface $from = null, ?\DateTimeInterface $until = null, mixed $cart = null): ?float
    {
        if (!$this->isPool()) {
            return null;
        }

        // If no cart provided, try to get the current user's cart
        if (!$cart && auth()->check()) {
            $cart = auth()->user()->currentCart();
        }

        // If cart is provided, use dynamic pricing based on cart state
        if ($cart) {
            $currentQuantityInCart = $cart->items()
                ->where('purchasable_id', $this->getKey())
                ->where('purchasable_type', get_class($this))
                ->sum('quantity');

            return $this->getNextAvailablePoolPrice($currentQuantityInCart, null, $from, $until);
        }

        $singleItems = $this->singleProducts;

        if ($singleItems->isEmpty()) {
            return null;
        }

        $prices = $singleItems->map(function ($item) use ($from, $until) {
            // Only get price if the item is available
            if ($from && $until) {
                if (!$item->isAvailableForBooking($from, $until, 1)) {
                    return null;
                }
            } else {
                if ($item->getAvailableStock() <= 0 && $item->manage_stock) {
                    return null;
                }
            }

            return $item->defaultPrice()->first()?->getCurrentPrice($item->isOnSale());
        })->filter()->values();

        return $prices->isEmpty() ? null : $prices->min();
    }

    /**
     * Get the highest price from available single items
     */
    public function getHighestAvailablePoolPrice(?\DateTimeInterface $from = null, ?\DateTimeInterface $until = null, mixed $cart = null): ?float
    {
        if (!$this->isPool()) {
            return null;
        }

        // If no cart provided, try to get the current user's cart
        if (!$cart && auth()->check()) {
            $cart = auth()->user()->currentCart();
        }

        // If cart is provided, get the highest price from remaining available items
        if ($cart) {
            $currentQuantityInCart = $cart->items()
                ->where('purchasable_id', $this->getKey())
                ->where('purchasable_type', get_class($this))
                ->sum('quantity');

            // Get the pool's actual pricing strategy to determine allocation order
            $strategy = $this->getPricingStrategy();

            // Get available items
            $singleItems = $this->singleProducts;

            if ($singleItems->isEmpty()) {
                return null;
            }

            // Build a list of all available item prices with their quantities
            $availableItems = [];

            foreach ($singleItems as $item) {
                // Check if item is available
                $available = 0;

                if ($from && $until) {
                    if ($item->isBooking() && $item->isAvailableForBooking($from, $until, 1)) {
                        $available = $item->getAvailableStock();
                    } elseif (!$item->isBooking()) {
                        $available = $item->getAvailableStock();
                    }
                } else {
                    if ($item->manage_stock) {
                        $available = $item->getAvailableStock();
                    } else {
                        $available = PHP_INT_MAX;
                    }
                }

                if ($available > 0) {
                    $price = $item->defaultPrice()->first()?->getCurrentPrice($item->isOnSale());

                    // If no price on single item but pool has direct price, use pool's price
                    if ($price === null && $this->hasPrice()) {
                        $price = $this->defaultPrice()->first()?->getCurrentPrice($this->isOnSale());
                    }

                    if ($price !== null) {
                        $availableItems[] = [
                            'price' => $price,
                            'quantity' => $available,
                        ];
                    }
                }
            }

            if (empty($availableItems)) {
                return null;
            }

            // Sort items based on the pool's actual pricing strategy to determine allocation order
            usort($availableItems, function ($a, $b) use ($strategy) {
                return match ($strategy) {
                    PricingStrategy::LOWEST => $a['price'] <=> $b['price'],
                    PricingStrategy::HIGHEST => $b['price'] <=> $a['price'],
                    PricingStrategy::AVERAGE => $a['price'] <=> $b['price'],
                };
            });

            // Skip through items based on allocation order, then get highest of remaining
            $skipped = 0;
            $remainingItems = [];

            foreach ($availableItems as $item) {
                if ($skipped >= $currentQuantityInCart) {
                    // All cart items have been accounted for, these are remaining
                    $remainingItems[] = $item;
                } else {
                    $skipFromThis = min($item['quantity'], $currentQuantityInCart - $skipped);
                    $skipped += $skipFromThis;

                    // If there are items left in this batch after skipping
                    if ($item['quantity'] > $skipFromThis) {
                        $remainingItems[] = [
                            'price' => $item['price'],
                            'quantity' => $item['quantity'] - $skipFromThis,
                        ];
                    }
                }
            }

            // Return the highest price from remaining items
            if (empty($remainingItems)) {
                return null;
            }

            return max(array_column($remainingItems, 'price'));
        }

        $singleItems = $this->singleProducts;

        if ($singleItems->isEmpty()) {
            return null;
        }

        $prices = $singleItems->map(function ($item) use ($from, $until) {
            // Only get price if the item is available
            if ($from && $until) {
                if (!$item->isAvailableForBooking($from, $until, 1)) {
                    return null;
                }
            } else {
                if ($item->getAvailableStock() <= 0 && $item->manage_stock) {
                    return null;
                }
            }

            return $item->defaultPrice()->first()?->getCurrentPrice($item->isOnSale());
        })->filter()->values();

        return $prices->isEmpty() ? null : $prices->max();
    }

    /**
     * Set the pool pricing strategy (for backwards compatibility)
     */
    public function setPoolPricingStrategy(string|PricingStrategy $strategy): void
    {
        if (!$this->isPool()) {
            throw new NotPoolProductException();
        }

        // Handle both string and enum inputs
        if (is_string($strategy)) {
            $strategyEnum = PricingStrategy::tryFrom($strategy);
            if (!$strategyEnum) {
                throw new InvalidPricingStrategyException($strategy);
            }
            $strategy = $strategyEnum;
        }

        $this->setPricingStrategy($strategy);
    }

    /**
     * Get the price for the next available item from the pool
     * considering how many items have already been allocated/claimed
     * 
     * This method simulates "picking" items from the pool in order of the pricing strategy
     * and returns the price of the Nth item
     * 
     * @param int $skipQuantity How many items to skip (already allocated)
     * @param bool|null $sales_price Whether to get sale price
     * @param \DateTimeInterface|null $from Start date for availability check
     * @param \DateTimeInterface|null $until End date for availability check
     * @return float|null Price of the next available item
     */
    public function getNextAvailablePoolPrice(
        int $skipQuantity = 0,
        bool|null $sales_price = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $until = null
    ): ?float {
        if (!$this->isPool()) {
            return null;
        }

        $strategy = $this->getPricingStrategy();
        $singleItems = $this->singleProducts;

        if ($singleItems->isEmpty()) {
            return null;
        }

        // Build a list of all available item prices with their quantities
        $availableItems = [];

        foreach ($singleItems as $item) {
            // Check if item is available using DRY helper method
            $available = $this->calculateSingleItemAvailability($item, $from, $until);

            if ($available > 0) {
                $price = $item->defaultPrice()->first()?->getCurrentPrice($sales_price ?? $item->isOnSale());

                // If no price on single item but pool has direct price, use pool's price
                if ($price === null && $this->hasPrice()) {
                    $price = $this->defaultPrice()->first()?->getCurrentPrice($sales_price ?? $this->isOnSale());
                }

                if ($price !== null) {
                    $availableItems[] = [
                        'price' => $price,
                        'quantity' => $available,
                        'item' => $item,
                    ];
                }
            }
        }

        if (empty($availableItems)) {
            return null;
        }

        // For AVERAGE strategy, return the average price of all available items
        if ($strategy === PricingStrategy::AVERAGE) {
            $totalPrice = 0;
            $totalQuantity = 0;
            foreach ($availableItems as $item) {
                $totalPrice += $item['price'] * $item['quantity'];
                $totalQuantity += $item['quantity'];
            }
            return $totalQuantity > 0 ? $totalPrice / $totalQuantity : null;
        }

        // Sort items based on pricing strategy (for LOWEST and HIGHEST)
        usort($availableItems, function ($a, $b) use ($strategy) {
            return match ($strategy) {
                PricingStrategy::LOWEST => $a['price'] <=> $b['price'],
                PricingStrategy::HIGHEST => $b['price'] <=> $a['price'],
                PricingStrategy::AVERAGE => 0, // Already handled above
            };
        });

        // Skip through items based on $skipQuantity
        $skipped = 0;
        foreach ($availableItems as $item) {
            if ($skipped + $item['quantity'] > $skipQuantity) {
                // This is the item we want
                return $item['price'];
            }
            $skipped += $item['quantity'];
        }

        // If we've skipped past all items, return null
        return null;
    }

    /**
     * Get next available pool item with price considering which specific price tiers are already in the cart
     * This is smarter than getNextAvailablePoolPrice because it tracks usage by price point
     * 
     * @param \Blax\Shop\Models\Cart $cart The cart to check
     * @param bool|null $sales_price Whether to get sale price
     * @param \DateTimeInterface|null $from Start date for availability check
     * @param \DateTimeInterface|null $until End date for availability check
     * @param string|int|null $excludeCartItemId Cart item ID to exclude from usage calculation (for date updates)
     * @return array|null ['price' => float, 'item' => Product, 'price_id' => string|null]
     */
    public function getNextAvailablePoolItemWithPrice(
        \Blax\Shop\Models\Cart $cart,
        bool|null $sales_price = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $until = null,
        string|int|null $excludeCartItemId = null
    ): ?array {
        if (!$this->isPool()) {
            return null;
        }

        $strategy = $this->getPricingStrategy();
        $singleItems = $this->singleProducts;

        if ($singleItems->isEmpty()) {
            return null;
        }

        // Get cart items for this pool
        $cartItems = $cart->items()
            ->where('purchasable_id', $this->getKey())
            ->where('purchasable_type', get_class($this))
            ->get();

        // If no dates provided, try to extract from cart items
        if (!$from && !$until) {
            $firstItemWithDates = $cartItems->first(fn($item) => $item->from && $item->until);
            if ($firstItemWithDates) {
                $from = $firstItemWithDates->from;
                $until = $firstItemWithDates->until;
            }
        }

        // Calculate days for price normalization
        $days = 1;
        if ($from && $until) {
            $days = $this->calculateBookingDays($from, $until);
        }

        // Build usage map: track which single items have been allocated
        // Use allocated_single_item_id from meta to track actual single item usage
        // ONLY count items that overlap with the current booking period
        // Exclude the specified cart item (if updating dates on existing item)
        $singleItemUsage = []; // item_id => quantity used
        foreach ($cartItems as $item) {
            // Skip the cart item being updated (if applicable)
            if ($excludeCartItemId && $item->id === $excludeCartItemId) {
                continue;
            }

            // Logic for counting cart items:
            // 1. If we're checking for specific dates ($from && $until): only count items with dates that overlap
            // 2. If we're checking without dates (for progressive pricing): count all items for pricing purposes

            if ($from && $until) {
                // Checking for specific booking dates: skip items without dates (not allocated to timeframe)
                if (!$item->from || !$item->until) {
                    continue;
                }

                // Check if the cart item's booking period overlaps with the current period
                // No overlap if: cart item ends before current starts, or cart item starts after current ends
                $overlaps = !(
                    $item->until < $from || // Cart item ends before current booking starts
                    $item->from > $until    // Cart item starts after current booking ends
                );

                if (!$overlaps) {
                    continue;
                }
            }
            // else: no dates provided, count all items for progressive pricing

            $meta = $item->getMeta();
            $allocatedItemId = $meta->allocated_single_item_id ?? null;

            if ($allocatedItemId) {
                $singleItemUsage[$allocatedItemId] = ($singleItemUsage[$allocatedItemId] ?? 0) + $item->quantity;
            }
        }

        // Build available items list
        $availableItems = [];
        foreach ($singleItems as $item) {
            // Check if item is available using DRY helper method
            $available = $this->calculateSingleItemAvailability($item, $from, $until);

            if ($available > 0) {
                $priceModel = $item->defaultPrice()->first();
                $price = $priceModel?->getCurrentPrice($sales_price ?? $item->isOnSale());

                // If single item has no price, use pool's price as fallback
                if ($price === null && $this->hasPrice()) {
                    $priceModel = $this->defaultPrice()->first();
                    $price = $priceModel?->getCurrentPrice($sales_price ?? $this->isOnSale());
                }

                if ($price !== null) {
                    // Subtract quantity already allocated from THIS specific single item
                    $usedFromThisItem = $singleItemUsage[$item->id] ?? 0;
                    $availableFromThisItem = $available === PHP_INT_MAX
                        ? PHP_INT_MAX
                        : max(0, $available - $usedFromThisItem);

                    if ($availableFromThisItem > 0) {
                        $availableItems[] = [
                            'price' => $price,
                            'quantity' => $availableFromThisItem,
                            'item' => $item,
                            'price_id' => $priceModel?->id,
                        ];
                    }
                }
            }
        }

        // Note: Pool's own price is ONLY used as fallback for single items without prices.
        // We do NOT add the pool itself as a separate "unlimited" item.
        // This ensures total stock is limited to the sum of single item stocks.
        // The fallback logic is already handled above (lines 768-771) where single items
        // without prices use the pool's price instead.

        if (empty($availableItems)) {
            return null;
        }

        // For AVERAGE strategy, we need to return a representative item
        // In this case, we'll return the first available item for simplicity
        // since all items contribute to the average price equally
        if ($strategy === \Blax\Shop\Enums\PricingStrategy::AVERAGE) {
            $totalPrice = 0;
            $totalQuantity = 0;
            foreach ($availableItems as $item) {
                $qty = $item['quantity'] === PHP_INT_MAX ? 1 : $item['quantity'];
                $totalPrice += $item['price'] * $qty;
                $totalQuantity += $qty;
            }
            $averagePrice = $totalQuantity > 0 ? $totalPrice / $totalQuantity : null;

            if ($averagePrice === null) {
                return null;
            }

            // Return the first item but with average price
            // Note: price_id should still be from the actual item being allocated
            return [
                'price' => $averagePrice,
                'item' => $availableItems[0]['item'],
                'price_id' => $availableItems[0]['price_id'],
            ];
        }

        // Sort by strategy
        usort($availableItems, function ($a, $b) use ($strategy) {
            return match ($strategy) {
                \Blax\Shop\Enums\PricingStrategy::LOWEST => $a['price'] <=> $b['price'],
                \Blax\Shop\Enums\PricingStrategy::HIGHEST => $b['price'] <=> $a['price'],
                \Blax\Shop\Enums\PricingStrategy::AVERAGE => 0,
            };
        });

        // Return the first available item with its price and price_id
        return [
            'price' => $availableItems[0]['price'],
            'item' => $availableItems[0]['item'],
            'price_id' => $availableItems[0]['price_id'],
        ];
    }

    /**
     * Get next available pool price considering which specific price tiers are already in the cart
     * This method wraps getNextAvailablePoolItemWithPrice for backwards compatibility
     * 
     * @param \Blax\Shop\Models\Cart $cart The cart to check
     * @param bool|null $sales_price Whether to get sale price
     * @param \DateTimeInterface|null $from Start date for availability check
     * @param \DateTimeInterface|null $until End date for availability check
     * @return float|null
     */
    public function getNextAvailablePoolPriceConsideringCart(
        \Blax\Shop\Models\Cart $cart,
        bool|null $sales_price = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $until = null,
        string|int|null $excludeCartItemId = null
    ): ?float {
        $result = $this->getNextAvailablePoolItemWithPrice($cart, $sales_price, $from, $until, $excludeCartItemId);
        return $result['price'] ?? null;
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
            throw new NotPoolProductException();
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
     * Get the price range for pool products (from available items)
     */
    public function getPoolPriceRange(?\DateTimeInterface $from = null, ?\DateTimeInterface $until = null): ?array
    {
        if (!$this->isPool()) {
            return null;
        }

        $lowest = $this->getLowestAvailablePoolPrice($from, $until);
        $highest = $this->getHighestAvailablePoolPrice($from, $until);

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

        // Critical: Pool products should NEVER manage stock themselves
        // Stock is managed by individual single items only
        if ($this->manage_stock) {
            throw new InvalidPoolConfigurationException(
                "Pool product '{$this->name}' has manage_stock=true. Pool products should never manage stock directly - only their single items manage stock."
            );
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

        // Note: Single items may or may not manage stock
        // Items without stock management are treated as having unlimited availability
        // This is acceptable - the pool just checks availability from each single item

        // Check for items with zero stock (only for items that manage stock)
        $itemsWithZeroStock = $singleItems
            ->filter(fn($item) => $item->manage_stock) // Only check items that manage stock
            ->filter(fn($item) => $item->getAvailableStock() <= 0);
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
            throw new NotPoolProductException();
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
            throw new NotPoolProductException();
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
            throw new NotPoolProductException();
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
            throw new NotPoolProductException();
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
