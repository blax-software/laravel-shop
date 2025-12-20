<?php

namespace Blax\Shop\Models;

use Blax\Shop\Contracts\Cartable;
use Blax\Shop\Enums\CartStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Exceptions\CartableInterfaceException;
use Blax\Shop\Exceptions\CartAlreadyConvertedException;
use Blax\Shop\Exceptions\CartDatesRequiredException;
use Blax\Shop\Exceptions\CartEmptyException;
use Blax\Shop\Exceptions\CartItemMissingInformationException;
use Blax\Shop\Exceptions\InvalidDateRangeException;
use Blax\Shop\Exceptions\NotEnoughAvailableInTimespanException;
use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Exceptions\PriceCalculationException;
use Blax\Shop\Exceptions\ProductHasNoPriceException;
use Blax\Shop\Services\CartService;
use Blax\Shop\Traits\ChecksIfBooking;
use Blax\Shop\Traits\HasBookingPriceCalculation;
use Blax\Workkit\Traits\HasExpiration;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

class Cart extends Model
{
    use HasUuids, HasExpiration, HasFactory, HasBookingPriceCalculation, ChecksIfBooking;

    protected $fillable = [
        'session_id',
        'customer_type',
        'customer_id',
        'currency',
        'status',
        'last_activity_at',
        'expires_at',
        'converted_at',
        'meta',
        'from',
        'until',
    ];

    protected $casts = [
        'status' => CartStatus::class,
        'expires_at' => 'datetime',
        'converted_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'meta' => 'object',
        'from' => 'datetime',
        'until' => 'datetime',
    ];

    protected $appends = [
        'is_full_booking',
        'is_ready_to_checkout',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('shop.tables.carts', 'carts');
    }

    protected static function booted()
    {
        static::deleting(function ($cart) {
            $cart->items()->delete();
        });
    }

    public function customer(): MorphTo
    {
        return $this->morphTo();
    }

    // Alias for backward compatibility
    public function user()
    {
        return $this->customer();
    }

    public function items(): HasMany
    {
        return $this->hasMany(config('shop.models.cart_item'), 'cart_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(config('shop.models.product_purchase', \Blax\Shop\Models\ProductPurchase::class), 'cart_id');
    }

    public function getTotal(): float
    {
        return $this->items()->sum('subtotal');
    }

    public function getTotalItems(): int
    {
        return $this->items->sum('quantity');
    }

    /**
     * Check if all cart items are booking products
     */
    public function getIsFullBookingAttribute(): bool
    {
        if ($this->items->isEmpty()) {
            return false;
        }

        return $this->items->every(fn($item) => $item->is_booking);
    }

    /**
     * Check if the cart contains at least one booking item
     */
    public function isBooking(): bool
    {
        if ($this->items->isEmpty()) {
            return false;
        }

        return $this->items->contains(fn($item) => $item->is_booking);
    }

    /**
     * Get count of booking items in the cart
     */
    public function bookingItems(): int
    {
        return $this->items->filter(fn($item) => $item->is_booking)->count();
    }

    /**
     * Get array of stripe_price_id from each cart item's price.
     * Returns array with nulls for items without stripe_price_id.
     * 
     * @return array<string|null>
     */
    public function stripePriceIds(): array
    {
        return $this->items->map(function ($item) {
            if (!$item->price_id) {
                return null;
            }

            // Use the relationship method, not property access
            $price = $item->price()->first();
            return $price ? $price->stripe_price_id : null;
        })->toArray();
    }

    /**
     * Check if cart is ready for checkout.
     * 
     * Returns true if all cart items are ready for checkout.
     * 
     * @return bool
     */
    public function getIsReadyToCheckoutAttribute(): bool
    {
        if ($this->items->isEmpty()) {
            return false;
        }

        return $this->items->every(fn($item) => $item->is_ready_to_checkout);
    }

    /**
     * Get all cart items that require adjustments before checkout.
     * 
     * This method checks all cart items and returns a collection of items
     * that need additional information (like booking dates) before checkout.
     * 
     * Example usage:
     * ```php
     * $incompleteItems = $cart->getItemsRequiringAdjustments();
     * 
     * if ($incompleteItems->isNotEmpty()) {
     *     foreach ($incompleteItems as $item) {
     *         $adjustments = $item->requiredAdjustments();
     *         // Display what's needed: ['from' => 'datetime', 'until' => 'datetime']
     *     }
     * }
     * ```
     * 
     * @return \Illuminate\Support\Collection Collection of CartItem models requiring adjustments
     */
    public function getItemsRequiringAdjustments()
    {
        return $this->items->filter(function ($item) {
            return !empty($item->requiredAdjustments());
        });
    }

    /**
     * Check if cart is ready for checkout.
     * 
     * Returns true if all cart items have all required information set.
     * For booking products and pools with booking items, this means dates must be set.
     * 
     * @return bool True if ready for checkout, false if any items need adjustments
     */
    public function isReadyForCheckout(): bool
    {
        return $this->getItemsRequiringAdjustments()->isEmpty();
    }

    /**
     * Set the default date range for the cart.
     * Items without specific dates will use these as fallback.
     * 
     * @param \DateTimeInterface|string $from Start date (DateTimeInterface or parsable string)
     * @param \DateTimeInterface|string $until End date (DateTimeInterface or parsable string)
     * @param bool $validateAvailability Whether to validate product availability for the timespan
     * @return $this
     * @throws InvalidDateRangeException
     * @throws NotEnoughAvailableInTimespanException
     */
    public function setDates(
        \DateTimeInterface|string|int|float|null $from,
        \DateTimeInterface|string|int|float|null $until,
        bool $validateAvailability = true,
        bool $overwrite_item_dates = true
    ): self {
        // Parse string dates using Carbon
        if ($from !== null && (is_string($from) || is_numeric($from))) {
            $from = Carbon::parse($from);
        }
        if ($until !== null && (is_string($until) || is_numeric($until))) {
            $until = Carbon::parse($until);
        }

        // Always update cart dates with provided values
        $updateData = [];
        if ($from !== null) {
            $updateData['from'] = $from;
        }
        if ($until !== null) {
            $updateData['until'] = $until;
        }

        if (!empty($updateData)) {
            $this->update($updateData);
            $this->refresh();
        }

        // Get the current dates (may include one from database if only one was updated)
        $effectiveFrom = $from ?? $this->from;
        $effectiveUntil = $until ?? $this->until;

        // Only calculate/validate if BOTH dates are set
        if ($effectiveFrom && $effectiveUntil) {
            // For calculations, swap if dates are backwards
            $calcFrom = $effectiveFrom;
            $calcUntil = $effectiveUntil;
            if ($effectiveFrom > $effectiveUntil) {
                $calcFrom = $effectiveUntil;
                $calcUntil = $effectiveFrom;
            }

            if ($validateAvailability) {
                // Validate against the correctly ordered dates
                $this->validateDateAvailability($calcFrom, $calcUntil, $overwrite_item_dates);
            }

            // Update cart items with correctly ordered dates
            $this->applyDatesToItems(
                $validateAvailability,
                $overwrite_item_dates,
                $calcFrom,
                $calcUntil
            );
        }

        return $this->fresh();
    }

    /**
     * Set the 'from' date for the cart.
     * 
     * @param \DateTimeInterface|string $from Start date (DateTimeInterface or parsable string)
     * @param bool $validateAvailability Whether to validate product availability for the timespan
     * @return $this
     * @throws NotEnoughAvailableInTimespanException
     */
    public function setFromDate(
        \DateTimeInterface|string|int|float $from,
        bool $validateAvailability = true
    ): self {
        // Parse string dates using Carbon
        if (is_string($from) || is_numeric($from)) {
            $from = Carbon::parse($from);
        }

        // Always update the from date
        $this->update(['from' => $from]);
        $this->refresh();

        // Only calculate if both dates are set
        if ($this->until) {
            // For calculations, swap if dates are backwards
            $calcFrom = $from;
            $calcUntil = $this->until;
            if ($from > $this->until) {
                $calcFrom = $this->until;
                $calcUntil = $from;
            }

            if ($validateAvailability) {
                $this->validateDateAvailability($calcFrom, $calcUntil);
            }
        }

        return $this->fresh();
    }

    /**
     * Set the 'until' date for the cart.
     * 
     * @param \DateTimeInterface|string $until End date (DateTimeInterface or parsable string)
     * @param bool $validateAvailability Whether to validate product availability for the timespan
     * @return $this
     * @throws NotEnoughAvailableInTimespanException
     */
    public function setUntilDate(\DateTimeInterface|string|int|float $until, bool $validateAvailability = true): self
    {
        // Parse string dates using Carbon
        if (is_string($until) || is_numeric($until)) {
            $until = Carbon::parse($until);
        }

        // Always update the until date
        $this->update(['until' => $until]);
        $this->refresh();

        // Only calculate if both dates are set
        if ($this->from) {
            // For calculations, swap if dates are backwards
            $calcFrom = $this->from;
            $calcUntil = $until;
            if ($this->from > $until) {
                $calcFrom = $until;
                $calcUntil = $this->from;
            }

            if ($validateAvailability) {
                $this->validateDateAvailability($calcFrom, $calcUntil);
            }
        }

        return $this->fresh();
    }

    /**
     * Apply cart dates to all items that don't have their own dates set.
     * 
     * @param bool $validateAvailability Whether to validate product availability for the timespan
     * @param bool $overwrite If true, overwrites existing item dates. If false, only sets null fields.
     * @param \DateTimeInterface|null $from Optional from date (uses cart's from if not provided)
     * @param \DateTimeInterface|null $until Optional until date (uses cart's until if not provided)
     * @return $this
     * @throws NotEnoughAvailableInTimespanException
     */
    public function applyDatesToItems(
        bool $validateAvailability = true,
        bool $overwrite = false,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $until = null
    ): self {
        // Use provided dates or fall back to cart dates
        $fromDate = $from ?? $this->from;
        $untilDate = $until ?? $this->until;

        if (!$fromDate || !$untilDate) {
            return $this;
        }

        // First, reallocate pool items if pricing strategy suggests better allocation with new dates
        $this->reallocatePoolItems($fromDate, $untilDate, $overwrite);

        // Refresh items relationship to get updated meta values
        $this->load('items');

        // Track pool products to validate total allocation across all cart items
        $poolValidation = [];

        foreach ($this->items as $item) {
            // Only apply to booking items
            if ($item->is_booking) {
                // Determine which dates to apply based on overwrite setting
                $shouldApplyFrom = $overwrite || !$item->from;
                $shouldApplyUntil = $overwrite || !$item->until;

                if (!$shouldApplyFrom && !$shouldApplyUntil) {
                    continue;
                }

                $itemFrom = $shouldApplyFrom ? $fromDate : $item->from;
                $itemUntil = $shouldApplyUntil ? $untilDate : $item->until;

                if ($validateAvailability) {
                    $product = $item->purchasable;

                    // For pool products, check if allocated by reallocatePoolItems
                    if ($product instanceof Product && $product->isPool()) {
                        $meta = $item->getMeta();
                        $allocatedSingleItemId = $meta->allocated_single_item_id ?? null;

                        // If this item was NOT allocated (no single assigned), skip updateDates
                        // to preserve the null price set by reallocatePoolItems
                        if (empty($allocatedSingleItemId)) {
                            // Just update the dates without recalculating price
                            $item->update([
                                'from' => $itemFrom,
                                'until' => $itemUntil,
                            ]);
                            continue;
                        }

                        $poolKey = $product->id . '|' . $itemFrom->format('Y-m-d H:i:s') . '|' . $itemUntil->format('Y-m-d H:i:s');

                        if (!isset($poolValidation[$poolKey])) {
                            $poolValidation[$poolKey] = [
                                'product' => $product,
                                'from' => $itemFrom,
                                'until' => $itemUntil,
                                'requested' => 0,
                                'allocated' => 0,
                            ];
                        }

                        $poolValidation[$poolKey]['requested'] += $item->quantity;
                        $poolValidation[$poolKey]['allocated'] += $item->quantity;
                    } elseif ($product && !$product->isAvailableForBooking($itemFrom, $itemUntil, $item->quantity)) {
                        // Non-pool booking item is not available - mark as unavailable
                        // Don't throw exception - let user adjust dates freely
                        $item->update([
                            'from' => $itemFrom,
                            'until' => $itemUntil,
                            'price' => null,
                            'subtotal' => null,
                            'unit_amount' => null,
                        ]);
                        // Skip updateDates() since we already set the dates with null price
                        continue;
                    }
                }

                $item->updateDates($itemFrom, $itemUntil);
            }
        }

        // Pool validation is now handled by reallocatePoolItems() which marks
        // unallocated items with null price instead of throwing exceptions.
        // This allows users to freely adjust dates without exceptions.
        // Validation happens at checkout time via isReadyForCheckout().

        return $this->fresh();
    }

    /**
     * Reallocate pool items to optimize pricing when dates change.
     * 
     * When dates change, check if better-priced single items become available
     * according to the pool's pricing strategy (LOWEST, HIGHEST, etc.)
     * 
     * @param \DateTimeInterface $from New start date
     * @param \DateTimeInterface $until New end date
     * @param bool $overwrite Whether to apply to all items or only those without dates
     * @return void
     */
    protected function reallocatePoolItems(\DateTimeInterface $from, \DateTimeInterface $until, bool $overwrite = true): void
    {
        // Group cart items by pool product
        $poolItems = $this->items()->get()
            ->filter(function ($item) {
                $product = $item->purchasable;
                return $product instanceof Product && $product->isPool();
            })
            ->groupBy('purchasable_id');

        foreach ($poolItems as $poolId => $items) {
            $poolProduct = $items->first()->purchasable;

            if (!$poolProduct) {
                continue;
            }

            // Get all available single items for the new dates with their prices
            $strategy = $poolProduct->getPricingStrategy();
            // Eager load stocks relationship to ensure fresh data
            $singleItems = $poolProduct->singleProducts()->with('stocks')->get();

            if ($singleItems->isEmpty()) {
                continue;
            }

            // Build list of available items with prices for new dates
            $availableWithPrices = [];
            foreach ($singleItems as $single) {
                // Manually check if this single is available for the booking period
                $available = $single->getAvailableStock($from);

                // Check for overlapping claims - two periods overlap if:
                // claim.start < our.end AND claim.end > our.start
                $overlaps = $single->stocks()
                    ->where('type', \Blax\Shop\Enums\StockType::CLAIMED->value)
                    ->where('status', \Blax\Shop\Enums\StockStatus::PENDING->value)
                    ->where(function ($query) use ($from, $until) {
                        $query->where(function ($q) use ($from, $until) {
                            // Claim starts before our period ends
                            $q->where(function ($subQ) use ($until) {
                                $subQ->where('claimed_from', '<', $until)
                                    ->orWhereNull('claimed_from'); // No start = starts immediately
                            })
                                // AND claim ends after our period starts
                                ->where(function ($subQ) use ($from) {
                                    $subQ->where('expires_at', '>', $from)
                                        ->orWhereNull('expires_at'); // No end = never expires
                                });
                        });
                    })
                    ->exists();

                if ($available > 0 && !$overlaps) {
                    $priceModel = $single->defaultPrice()->first();
                    $price = $priceModel?->getCurrentPrice($single->isOnSale());

                    // Fallback to pool price if single has no price
                    if ($price === null && $poolProduct->hasPrice()) {
                        $priceModel = $poolProduct->defaultPrice()->first();
                        $price = $priceModel?->getCurrentPrice($poolProduct->isOnSale());
                    }

                    if ($price !== null) {
                        $availableWithPrices[] = [
                            'single' => $single,
                            'price' => $price,
                            'price_id' => $priceModel?->id,
                        ];
                    }
                }
            }

            if (empty($availableWithPrices)) {
                // No singles available for this period - mark ALL pool items as unavailable
                foreach ($items as $cartItem) {
                    // Only update if we should overwrite or item has no dates yet
                    if (!$overwrite && $cartItem->from && $cartItem->until) {
                        continue;
                    }

                    // Clear allocation and set price to null to indicate unavailable
                    $cartItem->updateMetaKey('allocated_single_item_id', null);
                    $cartItem->updateMetaKey('allocated_single_item_name', null);
                    $cartItem->update([
                        'price' => null,
                        'subtotal' => null,
                        'unit_amount' => null,
                    ]);
                }
                continue;
            }

            // Sort by pricing strategy
            usort($availableWithPrices, function ($a, $b) use ($strategy) {
                return match ($strategy) {
                    \Blax\Shop\Enums\PricingStrategy::LOWEST => $a['price'] <=> $b['price'],
                    \Blax\Shop\Enums\PricingStrategy::HIGHEST => $b['price'] <=> $a['price'],
                    \Blax\Shop\Enums\PricingStrategy::AVERAGE => 0,
                };
            });

            // Reallocate cart items to optimal singles
            // Each cart item gets one single - no single can be allocated twice
            $usedIndices = [];
            foreach ($items as $cartItem) {
                // Only reallocate if we should overwrite or item has no dates yet
                if (!$overwrite && $cartItem->from && $cartItem->until) {
                    continue;
                }

                // Find next unused single from available list
                $allocated = false;
                for ($i = 0; $i < count($availableWithPrices); $i++) {
                    if (!in_array($i, $usedIndices)) {
                        $allocation = $availableWithPrices[$i];

                        // Update cart item with new allocation
                        $cartItem->updateMetaKey('allocated_single_item_id', $allocation['single']->id);
                        $cartItem->updateMetaKey('allocated_single_item_name', $allocation['single']->name);

                        // Update price_id if changed
                        if ($allocation['price_id'] && $allocation['price_id'] !== $cartItem->price_id) {
                            $cartItem->update(['price_id' => $allocation['price_id']]);
                        }

                        $usedIndices[] = $i;
                        $allocated = true;
                        break;
                    }
                }

                // If we couldn't allocate (ran out of available singles), mark as unavailable
                if (!$allocated) {
                    // Clear allocation and set price to null to indicate unavailable
                    $cartItem->updateMetaKey('allocated_single_item_id', null);
                    $cartItem->updateMetaKey('allocated_single_item_name', null);
                    $cartItem->update([
                        'price' => null,
                        'subtotal' => null,
                        'unit_amount' => null,
                    ]);
                }
            }
        }
    }

    /**
     * Validate that all booking items in the cart are available for the given timespan.
     * 
     * @param \DateTimeInterface $from Start date
     * @param \DateTimeInterface $until End date
     * @return void
     * @throws NotEnoughAvailableInTimespanException
     */
    /**
     * Mark booking items as unavailable if they cannot be booked for the given dates.
     * Instead of throwing exceptions, this marks items with null price.
     *
     * @param \DateTimeInterface $from Start date
     * @param \DateTimeInterface $until End date
     * @param bool $useProvidedDates Whether to use provided dates or item's own dates
     * @return void
     */
    protected function validateDateAvailability(\DateTimeInterface $from, \DateTimeInterface $until, bool $useProvidedDates = false): void
    {
        foreach ($this->items as $item) {
            if (!$item->is_booking) {
                continue;
            }

            $product = $item->purchasable;
            if (!$product) {
                continue;
            }

            // Skip pool products - they are handled by reallocatePoolItems()
            if ($product->type === ProductType::POOL) {
                continue;
            }

            // Use provided dates when validating date overwrites, otherwise use item's specific dates
            $checkFrom = $useProvidedDates ? $from : ($item->from ?? $from);
            $checkUntil = $useProvidedDates ? $until : ($item->until ?? $until);

            if (!$product->isAvailableForBooking($checkFrom, $checkUntil, $item->quantity)) {
                // Mark item as unavailable instead of throwing exception
                // This allows users to freely adjust dates
                $item->update([
                    'price' => null,
                    'subtotal' => null,
                    'unit_amount' => null,
                ]);
            }
        }
    }

    /**
     * Scope to find abandoned carts
     * Carts that are active but haven't been updated recently
     */
    public function scopeAbandoned($query, $inactiveMinutes = 60)
    {
        return $query->where('status', CartStatus::ACTIVE)
            ->where('last_activity_at', '<', now()->subMinutes($inactiveMinutes));
    }

    public function getUnpaidAmount(): float
    {
        $paidAmount = $this->purchases()
            ->whereColumn('total_amount', '!=', 'amount_paid')
            ->sum('total_amount');

        return max(0, $this->getTotal() - $paidAmount);
    }

    public function getPaidAmount(): float
    {
        return $this->purchases()
            ->whereColumn('total_amount', '!=', 'amount_paid')
            ->sum('total_amount');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isConverted(): bool
    {
        return !is_null($this->converted_at);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('converted_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeForUser($query, $userOrId)
    {
        if (is_object($userOrId)) {
            return $query->where('customer_id', $userOrId->id)
                ->where('customer_type', get_class($userOrId));
        }

        // If just an ID is passed, try to determine the user model class
        $userModel = config('auth.providers.users.model', \Workbench\App\Models\User::class);
        return $query->where('customer_id', $userOrId)
            ->where('customer_type', $userModel);
    }

    public static function scopeUnpaid($query)
    {
        return $query->whereDoesntHave('purchases', function ($q) {
            $q->whereColumn('total_amount', '!=', 'amount_paid');
        });
    }

    /**
     * Store the cart ID in the session for retrieval across requests
     * 
     * @param Cart $cart
     * @return void
     */
    public static function setSession(Cart $cart): void
    {
        session([CartService::CART_SESSION_KEY => $cart->id]);
    }

    /**
     * Add an item to the cart or increase quantity if it already exists.
     *
     * @param Model&Cartable $cartable The item to add to cart
     * @param int $quantity The quantity to add
     * @param array<string, mixed> $parameters Additional parameters for the cart item
     * @param \DateTimeInterface|null $from Optional start date for bookings
     * @param \DateTimeInterface|null $until Optional end date for bookings
     * @return CartItem
     * @throws \Exception If the item doesn't implement Cartable interface
     */
    public function addToCart(
        Model $cartable,
        int $quantity = 1,
        array $parameters = [],
        \DateTimeInterface $from = null,
        \DateTimeInterface $until = null
    ): CartItem {
        // $cartable must implement Cartable
        if (! $cartable instanceof Cartable) {
            throw new CartableInterfaceException();
        }

        // Extract dates from parameters if not provided directly
        if (!$from && isset($parameters['from'])) {
            $from = is_string($parameters['from']) ? Carbon::parse($parameters['from']) : $parameters['from'];
        }
        if (!$until && isset($parameters['until'])) {
            $until = is_string($parameters['until']) ? Carbon::parse($parameters['until']) : $parameters['until'];
        }

        // For pool products with quantity > 1, add them one at a time to get progressive pricing
        if ($cartable instanceof Product && $cartable->isPool() && $quantity > 1) {
            // Validate availability if dates are provided
            if ($from && $until) {
                $available = $cartable->getPoolMaxQuantity($from, $until);

                // Subtract items already in cart for the same period
                $itemsInCart = $this->items()
                    ->where('purchasable_id', $cartable->getKey())
                    ->where('purchasable_type', get_class($cartable))
                    ->get()
                    ->filter(function ($item) use ($from, $until) {
                        // Only count items with overlapping dates
                        if (!$item->from || !$item->until) {
                            return false;
                        }
                        // Check for overlap: item overlaps if it doesn't end before period starts or start after period ends
                        return !($item->until < $from || $item->from > $until);
                    })
                    ->sum('quantity');

                $availableForThisRequest = $available === PHP_INT_MAX ? PHP_INT_MAX : max(0, $available - $itemsInCart);

                if ($availableForThisRequest !== PHP_INT_MAX && $quantity > $availableForThisRequest) {
                    throw new NotEnoughStockException(
                        "Pool product '{$cartable->name}' has only {$availableForThisRequest} items available for the requested period. Requested: {$quantity}"
                    );
                }
            } else {
                // When dates are not provided, validate against total pool capacity (not current availability)
                // This allows adding items even if currently claimed - dates will be validated later
                $totalCapacity = $cartable->getPoolTotalCapacity(); // Total capacity ignoring claims

                // Subtract items already in cart
                $itemsInCart = $this->items()
                    ->where('purchasable_id', $cartable->getKey())
                    ->where('purchasable_type', get_class($cartable))
                    ->sum('quantity');

                $availableForThisRequest = $totalCapacity === PHP_INT_MAX ? PHP_INT_MAX : max(0, $totalCapacity - $itemsInCart);

                if ($availableForThisRequest !== PHP_INT_MAX && $quantity > $availableForThisRequest) {
                    throw new NotEnoughStockException(
                        "Pool product '{$cartable->name}' has only {$availableForThisRequest} items available. Requested: {$quantity}"
                    );
                }
            }

            // Add items one at a time for progressive pricing
            $lastCartItem = null;
            for ($i = 0; $i < $quantity; $i++) {
                $lastCartItem = $this->addToCart($cartable, 1, $parameters, $from, $until);
            }
            return $lastCartItem;
        }

        // Validate Product-specific requirements
        if ($cartable instanceof Product) {
            // Validate pricing before adding to cart
            $cartable->validatePricing(throwExceptions: true);

            // Validate dates if both are provided
            if ($from && $until) {
                // Validate from is before until
                if ($from >= $until) {
                    throw new InvalidDateRangeException("The 'from' date must be before the 'until' date. Got from: {$from->format('Y-m-d H:i:s')}, until: {$until->format('Y-m-d H:i:s')}");
                }

                // Check booking product availability if dates are provided
                if ($cartable->isBooking() && !$cartable->isPool() && !$cartable->isAvailableForBooking($from, $until, $quantity)) {
                    throw new NotEnoughStockException(
                        "Product '{$cartable->name}' is not available for the requested period ({$from->format('Y-m-d')} to {$until->format('Y-m-d')})."
                    );
                }

                // Check pool product availability if dates are provided
                if ($cartable->isPool()) {
                    $maxQuantity = $cartable->getPoolMaxQuantity($from, $until);

                    // Subtract items already in cart for the same period
                    $itemsInCart = $this->items()
                        ->where('purchasable_id', $cartable->getKey())
                        ->where('purchasable_type', get_class($cartable))
                        ->get()
                        ->filter(function ($item) use ($from, $until) {
                            // Only count items with overlapping dates
                            if (!$item->from || !$item->until) {
                                return false;
                            }
                            // Check for overlap
                            return !($item->until < $from || $item->from > $until);
                        })
                        ->sum('quantity');

                    $availableForThisRequest = $maxQuantity === PHP_INT_MAX ? PHP_INT_MAX : max(0, $maxQuantity - $itemsInCart);

                    // Only validate if pool has limited availability AND quantity exceeds it
                    if ($availableForThisRequest !== PHP_INT_MAX && $quantity > $availableForThisRequest) {
                        throw new NotEnoughStockException(
                            "Pool product '{$cartable->name}' has only {$availableForThisRequest} items available for the requested period ({$from->format('Y-m-d')} to {$until->format('Y-m-d')}). Requested: {$quantity}"
                        );
                    }
                }
            } elseif ($from || $until) {
                // If only one date is provided, it's an error
                throw new CartDatesRequiredException();
            } else {
                // When adding pool items without dates, validate against total pool capacity
                // This allows adding items even if currently claimed - date-based validation happens later
                if ($cartable->isPool()) {
                    $totalCapacity = $cartable->getPoolTotalCapacity(); // Total capacity ignoring claims

                    // Subtract items already in cart (without dates or with any dates)
                    $itemsInCart = $this->items()
                        ->where('purchasable_id', $cartable->getKey())
                        ->where('purchasable_type', get_class($cartable))
                        ->sum('quantity');

                    $availableForThisRequest = $totalCapacity === PHP_INT_MAX ? PHP_INT_MAX : max(0, $totalCapacity - $itemsInCart);

                    if ($availableForThisRequest !== PHP_INT_MAX && $quantity > $availableForThisRequest) {
                        throw new NotEnoughStockException(
                            "Pool product '{$cartable->name}' has only {$availableForThisRequest} items available. Requested: {$quantity}"
                        );
                    }
                }
                // Items may be claimed now but available in the future
                // Full date-based validation will happen when dates are set via setDates() or at checkout
            }
        }

        // For pool products, calculate current quantity in cart once to ensure consistency
        // Force fresh query to get latest cart state (important for recursive calls)
        $currentQuantityInCart = null;
        $poolSingleItem = null;
        $poolPriceId = null;

        if ($cartable instanceof Product && $cartable->isPool()) {
            $this->unsetRelation('items'); // Clear cached relationship
            $currentQuantityInCart = $this->items()
                ->where('purchasable_id', $cartable->getKey())
                ->where('purchasable_type', get_class($cartable))
                ->sum('quantity');

            // Pre-calculate pool pricing info for use in merge logic
            $poolItemData = $cartable->getNextAvailablePoolItemWithPrice($this, null, $from, $until);
            if ($poolItemData) {
                $poolSingleItem = $poolItemData['item'];
                $poolPriceId = $poolItemData['price_id'];
            }
        }

        // Check if item already exists in cart with same parameters, dates, AND price
        $existingItem = $this->items()
            ->where('purchasable_id', $cartable->getKey())
            ->where('purchasable_type', get_class($cartable))
            ->get()
            ->first(function ($item) use ($parameters, $from, $until, $cartable, $poolPriceId) {
                $existingParams = is_array($item->parameters)
                    ? $item->parameters
                    : (array) $item->parameters;

                // Sort both arrays to ensure consistent comparison
                ksort($existingParams);
                ksort($parameters);

                // Check parameters match
                $paramsMatch = $existingParams === $parameters;

                // Check dates match (important for bookings)
                $datesMatch = true;
                if ($from || $until) {
                    $datesMatch = (
                        ($item->from?->format('Y-m-d H:i:s') === $from?->format('Y-m-d H:i:s')) &&
                        ($item->until?->format('Y-m-d H:i:s') === $until?->format('Y-m-d H:i:s'))
                    );
                }

                // For pool products, check if we should merge with existing items
                // Pool items can ONLY merge if they are from the SAME single item
                // This is critical because different single items have their own stock limits
                // even if they happen to share the same price (e.g., via pool fallback price)
                $priceMatch = true;
                if ($cartable instanceof Product && $cartable->isPool()) {
                    // Calculate expected price for this item
                    $poolItemData = $cartable->getNextAvailablePoolItemWithPrice($this, null, $from, $until);
                    $expectedPrice = $poolItemData['price'] ?? null;
                    $expectedSingleItemId = $poolItemData['item']?->id ?? null;

                    // Get the allocated single item ID from the existing cart item's meta
                    $existingMeta = $item->getMeta();
                    $existingAllocatedItemId = $existingMeta->allocated_single_item_id ?? null;

                    // Only merge if:
                    // 1. price_id matches (same price source)
                    // 2. actual price amount matches
                    // 3. allocated single item matches (CRITICAL: same single item being used)
                    $priceMatch = $poolPriceId && $item->price_id === $poolPriceId &&
                        $expectedPrice !== null && $item->unit_amount === (int) round($expectedPrice) &&
                        $expectedSingleItemId !== null && $existingAllocatedItemId === $expectedSingleItemId;
                }

                return $paramsMatch && $datesMatch && $priceMatch;
            });

        // Calculate price per day (base price)
        // For pool products, get price based on how many items are already in cart
        if ($cartable instanceof Product && $cartable->isPool()) {
            // Use smarter pricing that considers which price tiers are used
            $poolItemData = $cartable->getNextAvailablePoolItemWithPrice($this, null, $from, $until);

            if ($poolItemData) {
                $pricePerDay = $poolItemData['price'];
                $poolSingleItem = $poolItemData['item'];
                $poolPriceId = $poolItemData['price_id'];
            } else {
                $pricePerDay = null;
            }

            // Get regular price (non-sale) for comparison
            $regularPoolItemData = $cartable->getNextAvailablePoolItemWithPrice($this, false, $from, $until);
            $regularPricePerDay = $regularPoolItemData['price'] ?? $pricePerDay;

            // If no price found from pool items, try the pool's direct price as fallback
            if ($pricePerDay === null && $cartable->hasPrice()) {
                $priceModel = $cartable->defaultPrice()->first();
                $pricePerDay = $priceModel?->getCurrentPrice($cartable->isOnSale());
                $regularPricePerDay = $priceModel?->getCurrentPrice(false) ?? $pricePerDay;
                $poolPriceId = $priceModel?->id;
            }
        } else {
            $pricePerDay = $cartable->getCurrentPrice();
            $regularPricePerDay = $cartable->getCurrentPrice(false) ?? $pricePerDay;
        }

        // Ensure prices are not null
        if ($pricePerDay === null) {
            if ($cartable instanceof Product && $cartable->isPool()) {
                // For pool products, throw specific error when neither pool nor single items have prices
                throw \Blax\Shop\Exceptions\HasNoPriceException::poolProductNoPriceAndNoSingleItemPrices($cartable->name);
            }
            throw new ProductHasNoPriceException($cartable->name);
        }

        // Calculate days if booking dates provided
        $days = 1;
        if ($from && $until) {
            $days = $this->calculateBookingDays($from, $until);
        }

        // Calculate price per unit for the entire period and round to nearest cent for consistency
        $pricePerUnit = (int) round($pricePerDay * $days);
        $regularPricePerUnit = (int) round($regularPricePerDay * $days);

        // Defensive check - ensure pricePerUnit is not null
        if ($pricePerUnit === null) {
            throw new PriceCalculationException($cartable->name, $pricePerDay, $days);
        }

        // Store the base unit_amount (price for 1 quantity, 1 day) in cents
        $unitAmount = (int) round($pricePerDay);

        // Calculate total price
        $totalPrice = $pricePerUnit * $quantity;

        if ($existingItem) {
            // Update quantity and subtotal
            $newQuantity = $existingItem->quantity + $quantity;
            $existingItem->update([
                'quantity' => $newQuantity,
                'subtotal' => $pricePerUnit * $newQuantity,
            ]);

            return $existingItem->fresh();
        }

        // Determine price_id for the cart item
        $priceId = null;
        if ($cartable instanceof Product) {
            // For pool products, use the single item's price_id
            if ($cartable->isPool() && $poolPriceId) {
                $priceId = $poolPriceId;
            } else {
                // Get the default price for the product
                $defaultPrice = $cartable->defaultPrice()->first();
                $priceId = $defaultPrice?->id;
            }
        } elseif ($cartable instanceof \Blax\Shop\Models\ProductPrice) {
            // If adding a ProductPrice directly, use its ID
            $priceId = $cartable->id;
        }

        // Create new cart item
        $cartItem = $this->items()->create([
            'purchasable_id' => $cartable->getKey(),
            'purchasable_type' => get_class($cartable),
            'price_id' => $priceId,
            'quantity' => $quantity,
            'price' => $pricePerUnit,  // Price per unit for the period
            'regular_price' => $regularPricePerUnit,
            'unit_amount' => $unitAmount,  // Base price for 1 quantity, 1 day (in cents)
            'subtotal' => $totalPrice,  // Total for all units
            'parameters' => $parameters,
            'from' => $from,
            'until' => $until,
        ]);

        // For pool products, store which single item is being used in meta
        if ($cartable instanceof Product && $cartable->isPool() && $poolSingleItem) {
            $cartItem->updateMetaKey('allocated_single_item_id', $poolSingleItem->id);
            $cartItem->updateMetaKey('allocated_single_item_name', $poolSingleItem->name);
        }

        return $cartItem;
    }

    public function removeFromCart(
        Model $cartable,
        int $quantity = 1,
        array $parameters = []
    ): CartItem|true {
        // If a CartItem is passed directly, handle it
        if ($cartable instanceof CartItem) {
            $item = $cartable;

            if ($item->quantity > $quantity) {
                // Decrease quantity
                $newQuantity = $item->quantity - $quantity;
                $item->update([
                    'quantity' => $newQuantity,
                    'subtotal' => $item->price * $newQuantity,
                ]);
            } else {
                // Remove item from cart
                $item->delete();
            }

            return $item;
        }

        // Otherwise, find the cart item by purchasable
        $items = $this->items()
            ->where('purchasable_id', $cartable->getKey())
            ->where('purchasable_type', get_class($cartable))
            ->get()
            ->filter(function ($item) use ($parameters) {
                $existingParams = is_array($item->parameters)
                    ? $item->parameters
                    : (array) $item->parameters;
                ksort($existingParams);
                ksort($parameters);
                return $existingParams === $parameters;
            });

        if ($items->isEmpty()) {
            return true;
        }

        // For pool products with multiple cart items at different prices,
        // remove from the highest-priced item first (LIFO behavior)
        $item = $items->sortByDesc('price')->first();

        if ($item) {
            if ($item->quantity > $quantity) {
                // Decrease quantity
                $newQuantity = $item->quantity - $quantity;
                $item->update([
                    'quantity' => $newQuantity,
                    'subtotal' => $item->price * $newQuantity,
                ]);
            } else {
                // Remove item from cart
                $item->delete();
            }
        }

        return $item ?? true;
    }

    /**
     * Validate cart for checkout without converting it
     * 
     * Checks:
     * 1. Cart is not already converted
     * 2. Cart is not empty
     * 3. All items have required information
     * 4. Stock is available for all items (for booking/pool products with dates)
     * 
     * @throws \Exception
     */
    public function validateForCheckout(bool $throws = true): bool
    {
        // Check if cart is already converted
        if ($this->isConverted()) {
            if ($throws) {
                throw new CartAlreadyConvertedException();
            } else {
                return false;
            }
        }

        $items = $this->items()
            ->with('purchasable')
            ->get();

        if ($items->isEmpty()) {
            if ($throws) {
                throw new CartEmptyException();
            } else {
                return false;
            }
        }

        // Validate that all items have required information before checkout
        foreach ($items as $item) {
            $adjustments = $item->requiredAdjustments();
            if (!empty($adjustments)) {
                $product = $item->purchasable;
                $productName = $product ? $product->name : 'Unknown Product';
                $missingFields = implode(', ', array_keys($adjustments));

                if ($throws) {
                    throw new CartItemMissingInformationException($productName, $missingFields);
                } else {
                    return false;
                }
            }
        }

        // Validate stock availability for all items
        foreach ($items as $item) {
            $product = $item->purchasable;

            if (!($product instanceof Product)) {
                continue;
            }

            // Use effective dates (item-specific or cart fallback)
            $from = $item->getEffectiveFromDate();
            $until = $item->getEffectiveUntilDate();

            // For pool products, check pool availability
            if ($product->isPool()) {
                if ($from && $until) {
                    // Get available quantity considering existing cart items and pending purchases
                    $available = $product->getPoolMaxQuantity($from, $until);

                    // Calculate how much of this cart's items are already counted
                    // We need to check if there's still enough stock for what's in this cart
                    $cartItemsForPool = $items->filter(
                        fn($i) =>
                        $i->purchasable_id === $product->id &&
                            $i->purchasable_type === get_class($product)
                    );
                    $totalInCart = $cartItemsForPool->sum('quantity');

                    if ($available !== PHP_INT_MAX && $totalInCart > $available) {
                        if ($throws) {
                            throw new NotEnoughStockException(
                                "Pool product '{$product->name}' has only {$available} items available for the period " .
                                    "{$from->format('Y-m-d')} to {$until->format('Y-m-d')}. Cart has: {$totalInCart}"
                            );
                        } else {
                            return false;
                        }
                    }
                } else {
                    // Without dates, check general pool availability
                    $available = $product->getPoolMaxQuantity();
                    $totalInCart = $items->filter(
                        fn($i) =>
                        $i->purchasable_id === $product->id &&
                            $i->purchasable_type === get_class($product)
                    )->sum('quantity');

                    if ($available !== PHP_INT_MAX && $totalInCart > $available) {
                        if ($throws) {
                            throw new NotEnoughStockException(
                                "Pool product '{$product->name}' has only {$available} items available. Cart has: {$totalInCart}"
                            );
                        } else {
                            return false;
                        }
                    }
                }
            } elseif ($product->isBooking() && $product->manage_stock) {
                // For booking products with managed stock
                if ($from && $until) {
                    if (!$product->isAvailableForBooking($from, $until, $item->quantity)) {
                        if ($throws) {
                            throw new NotEnoughStockException(
                                "Booking product '{$product->name}' is not available for the period " .
                                    "{$from->format('Y-m-d')} to {$until->format('Y-m-d')}. Requested: {$item->quantity}"
                            );
                        } else {
                            return false;
                        }
                    }
                }
            } elseif ($product->manage_stock) {
                // For regular products with managed stock
                $available = $product->getAvailableStock();
                if ($item->quantity > $available) {
                    if ($throws) {
                        throw new NotEnoughStockException(
                            "Product '{$product->name}' has only {$available} items in stock. Requested: {$item->quantity}"
                        );
                    } else {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    public function checkout(): static
    {
        return DB::transaction(function () {
            // Lock the cart to prevent concurrent checkouts
            $this->lockForUpdate();

            // Validate cart before proceeding
            $this->validateForCheckout();

            $items = $this->items()
                ->with('purchasable')
                ->get();

            // Create ProductPurchase for each cart item
            foreach ($items as $item) {
                $product = $item->purchasable;

                // Lock the product to prevent race conditions on stock
                if ($product instanceof Product && method_exists($product, 'lockForUpdate')) {
                    $product = $product->lockForUpdate()->find($product->id);
                }

                $quantity = $item->quantity;

                // Get booking dates from cart item directly (preferred) or from parameters (legacy)
                $from = $item->from;
                $until = $item->until;

                if (!$from || !$until) {
                    if (($product->type === ProductType::BOOKING || $product->type === ProductType::POOL) && $item->parameters) {
                        $params = is_array($item->parameters) ? $item->parameters : (array) $item->parameters;
                        $from = $params['from'] ?? null;
                        $until = $params['until'] ?? null;

                        // Convert to Carbon instances if they're strings
                        if ($from && is_string($from)) {
                            $from = \Carbon\Carbon::parse($from);
                        }
                        if ($until && is_string($until)) {
                            $until = \Carbon\Carbon::parse($until);
                        }
                    }
                }

                // Handle pool products with booking single items
                if ($product instanceof Product && $product->isPool()) {
                    // Check if pool with booking items requires timespan
                    if ($product->hasBookingSingleItems() && (!$from || !$until)) {
                        throw new \Exception("Pool product '{$product->name}' with booking items requires a timespan (from/until dates).");
                    }

                    // If pool has timespan and has booking single items, claim stock from single items
                    if ($from && $until && $product->hasBookingSingleItems()) {
                        try {
                            // Check if we have pre-allocated single items from reallocation
                            $meta = $item->getMeta();
                            $allocatedSingleId = $meta->allocated_single_item_id ?? null;

                            if ($allocatedSingleId) {
                                // Use the pre-allocated single item
                                $singleItem = Product::find($allocatedSingleId);
                                if (!$singleItem) {
                                    throw new \Exception("Allocated single item not found: {$allocatedSingleId}");
                                }

                                // Claim stock for this specific item
                                $singleItem->claimStock($quantity, $this, $from, $until, "Checkout from cart {$this->id}");
                                $claimedItems = [$singleItem];
                            } else {
                                // No pre-allocation, use standard pool claiming logic
                                $claimedItems = $product->claimPoolStock(
                                    $quantity,
                                    $this,
                                    $from,
                                    $until,
                                    "Checkout from cart {$this->id}"
                                );
                            }

                            // Store claimed items info in purchase meta
                            $item->updateMetaKey('claimed_single_items', array_map(fn($i) => $i->id, $claimedItems));
                            $item->save();
                        } catch (\Exception $e) {
                            throw new \Exception("Failed to checkout pool product '{$product->name}': " . $e->getMessage());
                        }
                    }
                }

                // Validate booking products have required dates
                if ($product instanceof Product && $product->isBooking() && !$product->isPool() && (!$from || !$until)) {
                    throw new \Exception("Booking product '{$product->name}' requires a timespan (from/until dates).");
                }

                $purchase = $this->customer->purchase(
                    $product->prices()->first(),
                    $quantity,
                    null,
                    $from,
                    $until
                );

                $purchase->update([
                    'cart_id' => $item->cart_id,
                ]);

                // Remove item from cart
                $item->update([
                    'purchase_id' => $purchase->id,
                ]);
            }

            $this->update([
                'converted_at' => now(),
            ]);

            return $this;
        });
    }

    /**
     * Create a Stripe Checkout Session for this cart
     * 
     * This method:
     * - Validates the cart (doesn't convert it)
     * - Creates ProductPurchase records for each cart item (with PENDING status)
     * - Uses dynamic price_data for each cart item (no pre-created Stripe prices needed)
     * - Creates line items with descriptions including booking dates
     * - Returns the Stripe checkout session
     * 
     * @param array $options Optional session parameters (success_url, cancel_url, etc.)
     * @param string|null $url Optional fullPath URL for success and cancel URLs
     * 
     * @return mixed Stripe\Checkout\Session instance
     * @throws \Exception
     */
    public function checkoutSession(array $options = [], ?string $url = null)
    {
        if (!config('shop.stripe.enabled')) {
            throw new \Exception('Stripe is not enabled');
        }

        // Ensure Stripe is initialized
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

        // Validate cart before proceeding (doesn't convert it)
        $this->validateForCheckout();

        // Create ProductPurchase records for each cart item
        DB::transaction(function () {
            foreach ($this->items as $item) {
                // Skip if purchase already exists
                if ($item->purchase_id) {
                    continue;
                }

                $product = $item->purchasable;
                $from = $item->from;
                $until = $item->until;

                // Create purchase record with PENDING status
                $purchase = ProductPurchase::create([
                    'cart_id' => $this->id,
                    'price_id' => $item->price_id,
                    'purchasable_id' => $product->id,
                    'purchasable_type' => get_class($product),
                    'purchaser_id' => $this->customer_id,
                    'purchaser_type' => $this->customer_type,
                    'quantity' => $item->quantity,
                    'amount' => $item->subtotal,
                    'amount_paid' => 0,
                    'status' => PurchaseStatus::PENDING,
                    'from' => $from,
                    'until' => $until,
                    'meta' => $item->meta,
                ]);

                // Link purchase to cart item
                $item->update(['purchase_id' => $purchase->id]);
            }
        });

        $lineItems = [];

        foreach ($this->items as $item) {
            $product = $item->purchasable;

            // Get product name (use short_description if available, otherwise name)
            $productName = $product->short_description ?? $product->name ?? 'Product';

            // Build description with booking dates if available
            if ($item->from && $item->until) {
                $fromFormatted = $item->from->format('M j, Y H:i');
                $untilFormatted = $item->until->format('M j, Y H:i');
                $productName .= " from {$fromFormatted} to {$untilFormatted}";
            }

            // Price is already stored in cents, Stripe expects smallest currency unit
            $unitAmountCents = (int) $item->price;

            // Build line item using price_data for dynamic pricing
            $lineItem = [
                'price_data' => [
                    'currency' => config('shop.currency', 'usd'),
                    'product_data' => [
                        'name' => $productName,
                    ],
                    'unit_amount' => $unitAmountCents,
                ],
                'quantity' => $item->quantity,
            ];

            $lineItems[] = $lineItem;
        }

        $success_url = $url ?? $options['success_url'] ?? route('shop.stripe.success');
        $cancel_url = $url ?? $options['cancel_url'] ?? route('shop.stripe.cancel');

        $success_url = (strpos($success_url, '?'))
            ? $success_url . '&session_id={CHECKOUT_SESSION_ID}&cart_id=' . $this->id
            : $success_url . '?session_id={CHECKOUT_SESSION_ID}&cart_id=' . $this->id;

        $cancel_url = (strpos($cancel_url, '?'))
            ? $cancel_url . '&cart_id=' . $this->id
            : $cancel_url . '?cart_id=' . $this->id;

        // Prepare session parameters
        $sessionParams = [
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => $success_url,
            'cancel_url' => $cancel_url,
            'client_reference_id' => $this->id,
            'metadata' => array_merge([
                'cart_id' => $this->id,
            ], $options['metadata'] ?? []),
        ];

        // Add customer email if available
        if ($this->customer) {
            if (method_exists($this->customer, 'email')) {
                $sessionParams['customer_email'] = $this->customer->email;
            } elseif (isset($this->customer->email)) {
                $sessionParams['customer_email'] = $this->customer->email;
            }
        }

        // Allow custom session parameters
        if (isset($options['session_params'])) {
            $sessionParams = array_merge($sessionParams, $options['session_params']);
        }

        try {
            $session = \Stripe\Checkout\Session::create($sessionParams);

            // Store session ID in cart meta
            $meta = $this->meta ?? (object)[];
            if (is_array($meta)) {
                $meta = (object)$meta;
            }
            $meta->stripe_session_id = $session->id;
            $this->update(['meta' => $meta]);

            \Illuminate\Support\Facades\Log::info('Stripe checkout session created', [
                'cart_id' => $this->id,
                'session_id' => $session->id,
            ]);

            return $session;
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Stripe checkout session creation failed', [
                'cart_id' => $this->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get the checkout session link for this cart.
     * 
     * This method returns:
     * - string: The checkout session URL if a session exists and is valid
     * - null: If no session exists or Stripe is not enabled
     * - false: If an error occurred while retrieving the session
     * 
     * @return string|null|false
     */
    public function checkoutSessionLink(array $option = [], ?string $url = null): string|null|false
    {
        // Validate cart - throw exceptions if validation fails
        // This ensures users know what's wrong instead of silently returning null
        $this->validateForCheckout();

        $checkoutSession = $this->checkoutSession($option, $url);

        if ($checkoutSession) {
            if (
                isset($checkoutSession->url)
                && !empty($checkoutSession->url)
            ) {
                return $checkoutSession->url;
            }

            return false;
        }

        return null;
    }
}
