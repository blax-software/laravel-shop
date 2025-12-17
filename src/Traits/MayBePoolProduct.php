<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PricingStrategy;
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
            throw new \Exception('This method is only for pool products');
        }

        // Handle both string and enum inputs
        if (is_string($strategy)) {
            $strategyEnum = PricingStrategy::tryFrom($strategy);
            if (!$strategyEnum) {
                throw new \InvalidArgumentException("Invalid pricing strategy: {$strategy}");
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
            // Check if item is available
            $available = 0;

            if ($from && $until) {
                if ($item->isBooking()) {
                    // For booking items, calculate actual available quantity during the period
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

                        $available = max(0, $item->getAvailableStock() - abs($overlappingClaims));
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
     * Get next available pool price considering which specific price tiers are already in the cart
     * This is smarter than getNextAvailablePoolPrice because it tracks usage by price point
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
            $days = max(1, $from->diff($until)->days);
        }

        // Build usage map: price => quantity used
        $priceUsage = [];
        foreach ($cartItems as $item) {
            $pricePerDay = $item->price / $days;
            $priceKey = round($pricePerDay, 2); // Round to avoid floating point issues
            $priceUsage[$priceKey] = ($priceUsage[$priceKey] ?? 0) + $item->quantity;
        }

        // Build available items list
        $availableItems = [];
        foreach ($singleItems as $item) {
            $available = 0;

            if ($from && $until) {
                if ($item->isBooking()) {
                    if (!$item->manage_stock) {
                        $available = PHP_INT_MAX;
                    } else {
                        // Calculate overlapping claims
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

                        $available = max(0, $item->getAvailableStock() - abs($overlappingClaims));
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

            if ($available > 0) {
                $price = $item->defaultPrice()->first()?->getCurrentPrice($sales_price ?? $item->isOnSale());

                if ($price === null && $this->hasPrice()) {
                    $price = $this->defaultPrice()->first()?->getCurrentPrice($sales_price ?? $this->isOnSale());
                }

                if ($price !== null) {
                    $priceRounded = round($price, 2);

                    // Subtract quantity already used in cart at this price
                    $usedAtThisPrice = $priceUsage[$priceRounded] ?? 0;
                    $availableAtThisPrice = $available - $usedAtThisPrice;

                    if ($availableAtThisPrice > 0) {
                        $availableItems[] = [
                            'price' => $price,
                            'quantity' => $availableAtThisPrice,
                            'item' => $item,
                        ];
                    }
                }
            }
        }

        // Also add pool's direct price if it has one
        if ($this->hasPrice()) {
            $poolPrice = $this->defaultPrice()->first()?->getCurrentPrice($sales_price ?? $this->isOnSale());
            if ($poolPrice !== null) {
                $poolPriceRounded = round($poolPrice, 2);
                $usedAtPoolPrice = $priceUsage[$poolPriceRounded] ?? 0;

                // Pool price is typically unlimited (doesn't manage stock)
                if (!$this->manage_stock) {
                    $availableItems[] = [
                        'price' => $poolPrice,
                        'quantity' => PHP_INT_MAX,
                        'item' => $this,
                    ];
                }
            }
        }

        if (empty($availableItems)) {
            return null;
        }

        // For AVERAGE strategy, calculate weighted average of available items
        if ($strategy === \Blax\Shop\Enums\PricingStrategy::AVERAGE) {
            $totalPrice = 0;
            $totalQuantity = 0;
            foreach ($availableItems as $item) {
                $qty = $item['quantity'] === PHP_INT_MAX ? 1 : $item['quantity'];
                $totalPrice += $item['price'] * $qty;
                $totalQuantity += $qty;
            }
            return $totalQuantity > 0 ? $totalPrice / $totalQuantity : null;
        }

        // Sort by strategy
        usort($availableItems, function ($a, $b) use ($strategy) {
            return match ($strategy) {
                \Blax\Shop\Enums\PricingStrategy::LOWEST => $a['price'] <=> $b['price'],
                \Blax\Shop\Enums\PricingStrategy::HIGHEST => $b['price'] <=> $a['price'],
                \Blax\Shop\Enums\PricingStrategy::AVERAGE => 0,
            };
        });

        // Return the first available item's price
        return $availableItems[0]['price'] ?? null;
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
