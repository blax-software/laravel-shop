<?php

namespace Blax\Shop\Models;

use Blax\Shop\Contracts\Cartable;
use Blax\Shop\Enums\CartStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Workkit\Traits\HasExpiration;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Cart extends Model
{
    use HasUuids, HasExpiration, HasFactory;

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
    ];

    protected $casts = [
        'status' => CartStatus::class,
        'expires_at' => 'datetime',
        'converted_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'meta' => 'object',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('shop.tables.carts', 'carts');
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
        return $this->items->sum(function ($item) {
            return $item->subtotal;
        });
    }

    public function getTotalItems(): int
    {
        return $this->items->sum('quantity');
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

    protected static function booted()
    {
        static::deleting(function ($cart) {
            $cart->items()->delete();
        });
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
            throw new \Exception("Item must implement the Cartable interface.");
        }

        // Validate Product-specific requirements
        if ($cartable instanceof Product) {
            // Validate pricing before adding to cart
            $cartable->validatePricing(throwExceptions: true);

            // Validate dates if both are provided (optional for cart, required at checkout)
            if ($from && $until) {
                // Validate from is before until
                if ($from >= $until) {
                    throw new \Exception("The 'from' date must be before the 'until' date. Got from: {$from->format('Y-m-d H:i:s')}, until: {$until->format('Y-m-d H:i:s')}");
                }

                // Check booking product availability if dates are provided
                if ($cartable->isBooking() && !$cartable->isAvailableForBooking($from, $until, $quantity)) {
                    throw new \Blax\Shop\Exceptions\NotEnoughStockException(
                        "Product '{$cartable->name}' is not available for the requested period ({$from->format('Y-m-d')} to {$until->format('Y-m-d')})."
                    );
                }

                // Check pool product availability if dates are provided
                if ($cartable->isPool()) {
                    $maxQuantity = $cartable->getPoolMaxQuantity($from, $until);
                    if ($quantity > $maxQuantity) {
                        throw new \Blax\Shop\Exceptions\NotEnoughStockException(
                            "Pool product '{$cartable->name}' has only {$maxQuantity} items available for the requested period ({$from->format('Y-m-d')} to {$until->format('Y-m-d')}). Requested: {$quantity}"
                        );
                    }
                }
            } elseif ($from || $until) {
                // If only one date is provided, it's an error
                throw new \Exception("Both 'from' and 'until' dates must be provided together, or both omitted.");
            }
        }

        // Check if item already exists in cart with same parameters and dates
        $existingItem = $this->items()
            ->where('purchasable_id', $cartable->getKey())
            ->where('purchasable_type', get_class($cartable))
            ->get()
            ->first(function ($item) use ($parameters, $from, $until) {
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

                return $paramsMatch && $datesMatch;
            });

        // Calculate price per day (base price)
        $pricePerDay = $cartable->getCurrentPrice();
        $regularPricePerDay = $cartable->getCurrentPrice(false) ?? $pricePerDay;

        // Ensure prices are not null
        if ($pricePerDay === null) {
            throw new \Exception("Product '{$cartable->name}' has no valid price.");
        }

        // Calculate days if booking dates provided
        $days = 1;
        if ($from && $until) {
            $days = max(1, $from->diff($until)->days);
        }

        // Calculate price per unit for the entire period
        $pricePerUnit = $pricePerDay * $days;
        $regularPricePerUnit = $regularPricePerDay * $days;

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

        // Create new cart item
        $cartItem = $this->items()->create([
            'purchasable_id' => $cartable->getKey(),
            'purchasable_type' => get_class($cartable),
            'quantity' => $quantity,
            'price' => $pricePerUnit,  // Price per unit for the period
            'regular_price' => $regularPricePerUnit,
            'subtotal' => $totalPrice,  // Total for all units
            'parameters' => $parameters,
            'from' => $from,
            'until' => $until,
        ]);

        return $cartItem->fresh();
    }

    public function removeFromCart(
        Model $cartable,
        int $quantity = 1,
        array $parameters = []
    ): CartItem|true {
        $item = $this->items()
            ->where('purchasable_id', $cartable->getKey())
            ->where('purchasable_type', get_class($cartable))
            ->get()
            ->first(function ($item) use ($parameters) {
                $existingParams = is_array($item->parameters)
                    ? $item->parameters
                    : (array) $item->parameters;
                ksort($existingParams);
                ksort($parameters);
                return $existingParams === $parameters;
            });

        if ($item) {
            if ($item->quantity > $quantity) {
                // Decrease quantity
                $newQuantity = $item->quantity - $quantity;
                $item->update([
                    'quantity' => $newQuantity,
                    'subtotal' => ($cartable->getCurrentPrice()) * $newQuantity,
                ]);
            } else {
                // Remove item from cart
                $item->delete();
            }
        }

        return $item ?? true;
    }

    public function checkout(): static
    {
        $items = $this->items()
            ->with('purchasable')
            ->get();

        if ($items->isEmpty()) {
            throw new \Exception("Cart is empty");
        }

        // Create ProductPurchase for each cart item
        foreach ($items as $item) {
            $product = $item->purchasable;
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
                        $claimedItems = $product->claimPoolStock(
                            $quantity,
                            $this,
                            $from,
                            $until,
                            "Checkout from cart {$this->id}"
                        );

                        // Store claimed items info in purchase meta
                        $item->updateMetaKey('claimed_single_items', array_map(fn($i) => $i->id, $claimedItems));
                        $item->save();
                    } catch (\Exception $e) {
                        throw new \Exception("Failed to checkout pool product '{$product->name}': " . $e->getMessage());
                    }
                }
            }

            // Validate booking products have required dates
            if ($product instanceof Product && $product->isBooking() && (!$from || !$until)) {
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
    }
}
