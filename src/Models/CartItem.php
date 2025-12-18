<?php

namespace Blax\Shop\Models;

use Blax\Shop\Exceptions\InvalidDateRangeException;
use Blax\Shop\Traits\HasBookingPriceCalculation;
use Blax\Workkit\Traits\HasMeta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    use HasUuids, HasMeta, HasBookingPriceCalculation;

    protected $fillable = [
        'cart_id',
        'purchasable_id',
        'purchasable_type',
        'price_id',
        'quantity',
        'price',
        'regular_price',
        'subtotal',
        'parameters',
        'purchase_id',
        'meta',
        'from',
        'until',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'integer',
        'regular_price' => 'integer',
        'subtotal' => 'integer',
        'parameters' => 'array',
        'meta' => 'array',
        'from' => 'datetime',
        'until' => 'datetime',
    ];

    protected $appends = [
        'is_booking',
        'is_ready_to_checkout',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('shop.tables.cart_items', 'cart_items');
    }

    protected static function boot()
    {
        parent::boot();

        // Auto-calculate subtotal before saving
        static::creating(function ($cartItem) {
            if (!isset($cartItem->subtotal)) {
                $cartItem->subtotal = $cartItem->quantity * $cartItem->price;
            }
        });

        static::updating(function ($cartItem) {
            if ($cartItem->isDirty(['quantity', 'price'])) {
                $cartItem->subtotal = $cartItem->quantity * $cartItem->price;
            }
        });
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(config('shop.models.cart'), 'cart_id');
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(config('shop.models.product_price', ProductPrice::class), 'price_id');
    }

    public function purchasable()
    {
        return $this->morphTo('purchasable');
    }

    public function purchase()
    {
        return $this->hasOne(
            config('shop.models.product_purchase', ProductPurchase::class),
            'id',
            'purchase_id'
        );
    }

    public function product(): BelongsTo|null
    {
        if ($this->purchasable_type === config('shop.models.product', Product::class)) {
            return $this->belongsTo(config('shop.models.product'), 'purchasable_id');
        }

        return null;
    }

    public function getSubtotal(): float
    {
        return $this->quantity * $this->price;
    }

    public function scopeForCart($query, $cartId)
    {
        return $query->where('cart_id', $cartId);
    }

    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    /**
     * Check if this cart item is for a booking product
     */
    public function getIsBookingAttribute(): bool
    {
        if (!$this->price_id) {
            // Fallback: check purchasable directly if no price_id
            if ($this->purchasable_type === config('shop.models.product', Product::class)) {
                $product = $this->purchasable;
                return $product && $product->isBooking();
            }
            return false;
        }

        // Use the relationship method, not property access
        $price = $this->price()->first();
        if (!$price) {
            return false;
        }

        $product = $price->purchasable;
        if (!$product || !($product instanceof Product)) {
            return false;
        }

        return $product->isBooking();
    }

    /**
     * Check if this cart item is ready for checkout.
     * Uses effective dates (item's own dates or cart's dates as fallback).
     * 
     * Returns true if:
     * - For booking products: has valid dates and stock is available
     * - For pool products with booking items: has valid dates and stock is available
     * - For other products: stock is available
     * 
     * @return bool
     */
    public function getIsReadyToCheckoutAttribute(): bool
    {
        // Only check if purchasable is a Product
        if ($this->purchasable_type !== config('shop.models.product', Product::class)) {
            return true; // Non-product items are always ready
        }

        $product = $this->purchasable;

        if (!$product) {
            return false;
        }

        // Check if dates are required (for booking products or pools with booking items)
        $requiresDates = $product->isBooking() ||
            ($product->isPool() && $product->hasBookingSingleItems());

        if ($requiresDates) {
            // Get effective dates (item-specific or cart fallback)
            $effectiveFrom = $this->getEffectiveFromDate();
            $effectiveUntil = $this->getEffectiveUntilDate();

            // Must have both dates (either from item or cart)
            if (is_null($effectiveFrom) || is_null($effectiveUntil)) {
                return false;
            }

            // Dates must be valid (from < until)
            if ($effectiveFrom >= $effectiveUntil) {
                return false;
            }

            // Check stock availability for the booking period
            if ($product->isBooking()) {
                if (!$product->isAvailableForBooking($effectiveFrom, $effectiveUntil, $this->quantity)) {
                    return false;
                }
            }

            // Check pool availability with dates
            if ($product->isPool()) {
                $available = $product->getPoolMaxQuantity($effectiveFrom, $effectiveUntil);

                // Get current quantity in cart for this product (excluding this item)
                $cartQuantity = 0;
                if ($this->cart) {
                    $cartQuantity = $this->cart->items()
                        ->where('purchasable_id', $product->getKey())
                        ->where('purchasable_type', get_class($product))
                        ->where('id', '!=', $this->id)
                        ->sum('quantity');
                }

                if ($available !== PHP_INT_MAX && ($cartQuantity + $this->quantity) > $available) {
                    return false;
                }
            }
        } else {
            // For non-booking products, just check stock availability
            if ($product->isPool()) {
                $available = $product->getPoolMaxQuantity();

                // Get current quantity in cart for this product (excluding this item)
                $cartQuantity = 0;
                if ($this->cart) {
                    $cartQuantity = $this->cart->items()
                        ->where('purchasable_id', $product->getKey())
                        ->where('purchasable_type', get_class($product))
                        ->where('id', '!=', $this->id)
                        ->sum('quantity');
                }

                if ($available !== PHP_INT_MAX && ($cartQuantity + $this->quantity) > $available) {
                    return false;
                }
            } elseif ($product->manage_stock) {
                // Check regular stock - sum all stocks for this product
                $totalStock = $product->stocks()->sum('quantity');

                // If no stock records exist and manage_stock is true, product is not ready
                // (stock records must be created explicitly)
                if ($totalStock === 0 && $product->stocks()->count() > 0) {
                    // Has stock records but quantity is 0
                    return false;
                }

                // If stock records exist, check cart quantity against stock
                if ($product->stocks()->count() > 0) {
                    // Get current quantity in cart for this product (including ALL items of this product)
                    $cartQuantity = 0;
                    if ($this->cart) {
                        $cartQuantity = $this->cart->items()
                            ->where('purchasable_id', $product->getKey())
                            ->where('purchasable_type', get_class($product))
                            ->sum('quantity');
                    }

                    if ($cartQuantity > $totalStock) {
                        return false;
                    }
                }
                // If no stock records exist, assume product is available (legacy behavior)
            }
        }

        return true;
    }

    /**
     * Get required adjustments for this cart item before checkout.
     * 
     * Returns an array of fields that need to be set, with suggested field names.
     * For booking products and pools with booking items, dates are required.
     * 
     * This method is useful for:
     * - Validating cart items before checkout
     * - Displaying missing information to users
     * - Checking if a cart item needs additional user input
     * 
     * Example usage:
     * ```php
     * // Check if cart item needs adjustments
     * $adjustments = $cartItem->requiredAdjustments();
     * 
     * if (!empty($adjustments)) {
     *     // Item needs dates before checkout
     *     // $adjustments = ['from' => 'datetime', 'until' => 'datetime']
     *     echo "Please select booking dates";
     * }
     * 
     * // Check all cart items before checkout
     * foreach ($cart->items as $item) {
     *     $required = $item->requiredAdjustments();
     *     if (!empty($required)) {
     *         // Handle missing information
     *     }
     * }
     * ```
     * 
     * @return array Array of required field adjustments, e.g., ['from' => 'datetime', 'until' => 'datetime']
     */
    public function requiredAdjustments(): array
    {
        $adjustments = [];

        // Only check if purchasable is a Product
        if ($this->purchasable_type !== config('shop.models.product', Product::class)) {
            return $adjustments;
        }

        $product = $this->purchasable;

        if (!$product) {
            return $adjustments;
        }

        // Check if dates are required (for booking products or pools with booking items)
        $requiresDates = $product->isBooking() ||
            ($product->isPool() && $product->hasBookingSingleItems());

        if ($requiresDates) {
            if (is_null($this->from)) {
                $adjustments['from'] = 'datetime';
            }

            if (is_null($this->until)) {
                $adjustments['until'] = 'datetime';
            }
        }

        return $adjustments;
    }

    /**
     * Get the effective 'from' date for this cart item.
     * Returns the item's specific date if set, otherwise falls back to the cart's from_date.
     * 
     * @return \Carbon\Carbon|null
     */
    public function getEffectiveFromDate(): ?\Carbon\Carbon
    {
        if ($this->from) {
            return $this->from;
        }

        return $this->cart?->from_date;
    }

    /**
     * Get the effective 'until' date for this cart item.
     * Returns the item's specific date if set, otherwise falls back to the cart's until_date.
     * 
     * @return \Carbon\Carbon|null
     */
    public function getEffectiveUntilDate(): ?\Carbon\Carbon
    {
        if ($this->until) {
            return $this->until;
        }

        return $this->cart?->until_date;
    }

    /**
     * Check if this item has effective dates (either its own or from cart).
     * 
     * @return bool
     */
    public function hasEffectiveDates(): bool
    {
        return $this->getEffectiveFromDate() !== null && $this->getEffectiveUntilDate() !== null;
    }

    /**
     * Update the booking dates for this cart item.
     * Automatically recalculates price based on the new date range.
     * 
     * IMPORTANT: This method uses cart-aware pricing!
     * For pool products, it automatically considers which price tiers are already
     * used in the cart to determine the next available price based on the pricing
     * strategy (LOWEST, HIGHEST, AVERAGE).
     * 
     * The method passes the NEW dates to getCurrentPrice() to ensure accurate
     * pricing calculations. Without passing dates, the pricing logic would use
     * stale dates from the cart item before the update, potentially selecting
     * the wrong price tier.
     * 
     * NOTE: This method allows setting any dates, even if they're not available.
     * Use the is_ready_to_checkout attribute to check if the dates are valid.
     * 
     * @param \DateTimeInterface|string|null $from Start date (DateTimeInterface or parsable string)
     * @param \DateTimeInterface|string|null $until End date (DateTimeInterface or parsable string)
     * @return $this
     * @throws \Exception If dates are invalid
     */
    public function updateDates(
        \DateTimeInterface|string|null $from = null,
        \DateTimeInterface|string|null $until = null
    ): self {
        // Parse string dates using Carbon
        if (is_string($from)) {
            $from = \Carbon\Carbon::parse($from);
        }
        if (is_string($until)) {
            $until = \Carbon\Carbon::parse($until);
        }

        // Validate that both dates are provided
        if (!$from || !$until) {
            throw new \Exception("Both 'from' and 'until' dates are required.");
        }

        // Validate date order
        if ($from >= $until) {
            throw new \Exception("The 'from' date must be before the 'until' date.");
        }

        $product = $this->purchasable;

        if (!$product || !($product instanceof Product)) {
            throw new \Exception("Cannot update dates for non-product items.");
        }

        // Calculate days using per-minute precision
        $days = $this->calculateBookingDays($from, $until);

        // Get current price per day
        // Pass dates to ensure accurate pricing for pool products during date updates
        $pricePerDay = $product->getCurrentPrice(null, $this->cart, $from, $until);
        $regularPricePerDay = $product->getCurrentPrice(false, $this->cart, $from, $until) ?? $pricePerDay;

        // Calculate new prices and round to nearest cent for consistency
        $pricePerUnit = (int) round($pricePerDay * $days);
        $regularPricePerUnit = (int) round($regularPricePerDay * $days);

        $this->update([
            'from' => $from,
            'until' => $until,
            'price' => $pricePerUnit,
            'regular_price' => $regularPricePerUnit,
            'subtotal' => $pricePerUnit * $this->quantity,
        ]);

        // Note: is_ready_to_checkout will automatically reflect if these dates are available
        return $this->fresh();
    }

    /**
     * Set the 'from' date for this cart item.
     * 
     * @param \DateTimeInterface|string $from Start date (DateTimeInterface or parsable string)
     * @return $this
     * @throws InvalidDateRangeException
     */
    public function setFromDate(\DateTimeInterface|string|int|float $from): self
    {
        // Parse string dates using Carbon
        if (is_string($from) || is_numeric($from)) {
            $from = \Carbon\Carbon::parse($from);
        }

        // Refresh to get current state
        $this->refresh();

        if ($this->until && $from >= $this->until) {
            throw new InvalidDateRangeException();
        }

        // Get the current until date before updating
        $currentUntil = $this->until;

        // If both dates are set, use updateDates to recalculate pricing
        if ($currentUntil) {
            return $this->updateDates($from, $currentUntil);
        }

        // Otherwise just update the from date
        $this->update(['from' => $from]);
        return $this->fresh();
    }

    /**
     * Set the 'until' date for this cart item.
     * 
     * @param \DateTimeInterface|string $until End date (DateTimeInterface or parsable string)
     * @return $this
     * @throws InvalidDateRangeException
     */
    public function setUntilDate(\DateTimeInterface|string|int|float $until): self
    {
        // Parse string dates using Carbon
        if (is_string($until) || is_numeric($until)) {
            $until = \Carbon\Carbon::parse($until);
        }

        // Refresh to get current state
        $this->refresh();

        if ($this->from && $this->from >= $until) {
            throw new InvalidDateRangeException();
        }

        // Get the current from date before updating
        $currentFrom = $this->from;

        // If both dates are set, use updateDates to recalculate pricing
        if ($currentFrom) {
            return $this->updateDates($currentFrom, $until);
        }

        // Otherwise just update the until date
        $this->update(['until' => $until]);
        return $this->fresh();
    }
}
