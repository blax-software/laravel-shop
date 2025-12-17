<?php

namespace Blax\Shop\Models;

use Blax\Shop\Contracts\Cartable;
use Blax\Shop\Enums\CartStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Services\CartService;
use Blax\Workkit\Traits\HasExpiration;
use Carbon\Carbon;
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
        return $this->items()->sum('subtotal');
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
            throw new \Exception("Item must implement the Cartable interface.");
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
            // Pre-validate that we have enough total availability
            // This prevents creating partial batches when stock is insufficient
            if ($from && $until) {
                $available = $cartable->getPoolMaxQuantity($from, $until);
                if ($available !== PHP_INT_MAX && $quantity > $available) {
                    throw new \Blax\Shop\Exceptions\NotEnoughStockException(
                        "Pool product '{$cartable->name}' has only {$available} items available for the requested period. Requested: {$quantity}"
                    );
                }
            } else {
                $available = $cartable->getPoolMaxQuantity();
                if ($available !== PHP_INT_MAX && $quantity > $available) {
                    throw new \Blax\Shop\Exceptions\NotEnoughStockException(
                        "Pool product '{$cartable->name}' has only {$available} items available. Requested: {$quantity}"
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
                    // Only validate if pool has limited availability
                    if ($maxQuantity !== PHP_INT_MAX && $quantity > $maxQuantity) {
                        throw new \Blax\Shop\Exceptions\NotEnoughStockException(
                            "Pool product '{$cartable->name}' has only {$maxQuantity} items available for the requested period ({$from->format('Y-m-d')} to {$until->format('Y-m-d')}). Requested: {$quantity}"
                        );
                    }
                }
            } elseif ($from || $until) {
                // If only one date is provided, it's an error
                throw new \Exception("Both 'from' and 'until' dates must be provided together, or both omitted.");
            } else {
                // Even without dates, check pool quantity limits
                if ($cartable->isPool()) {
                    $maxQuantity = $cartable->getPoolMaxQuantity();

                    // Skip validation if pool has unlimited availability
                    if ($maxQuantity !== PHP_INT_MAX) {
                        // Get current quantity in cart for this pool product
                        $currentQuantityInCart = $this->items()
                            ->where('purchasable_id', $cartable->getKey())
                            ->where('purchasable_type', get_class($cartable))
                            ->sum('quantity');

                        $totalQuantity = $currentQuantityInCart + $quantity;

                        if ($totalQuantity > $maxQuantity) {
                            throw new \Blax\Shop\Exceptions\NotEnoughStockException(
                                "Pool product '{$cartable->name}' has only {$maxQuantity} items available. Already in cart: {$currentQuantityInCart}, Requested: {$quantity}"
                            );
                        }
                    }
                }
            }
        }

        // For pool products, calculate current quantity in cart once to ensure consistency
        // Force fresh query to get latest cart state (important for recursive calls)
        $currentQuantityInCart = null;
        if ($cartable instanceof Product && $cartable->isPool()) {
            $this->unsetRelation('items'); // Clear cached relationship
            $currentQuantityInCart = $this->items()
                ->where('purchasable_id', $cartable->getKey())
                ->where('purchasable_type', get_class($cartable))
                ->sum('quantity');
        }

        // Check if item already exists in cart with same parameters, dates, AND price
        $existingItem = $this->items()
            ->where('purchasable_id', $cartable->getKey())
            ->where('purchasable_type', get_class($cartable))
            ->get()
            ->first(function ($item) use ($parameters, $from, $until, $cartable, $currentQuantityInCart) {
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

                // For pool products, check pricing strategy to determine merge behavior
                $priceMatch = true;
                if ($cartable instanceof Product && $cartable->isPool()) {
                    // For pools, use smart pricing that considers which tiers are used
                    $currentPrice = $cartable->getNextAvailablePoolPriceConsideringCart($this, null, $from, $until);
                    if (!$currentPrice) {
                        // Fallback to getCurrentPrice if method returns null
                        $currentPrice = $cartable->getCurrentPrice();
                    }
                    if ($from && $until) {
                        $days = max(1, $from->diff($until)->days);
                        $currentPrice *= $days;
                    }

                    // Compare prices - merge if prices match
                    $priceMatch = abs((float)$item->price - $currentPrice) < 0.01;
                }

                return $paramsMatch && $datesMatch && $priceMatch;
            });

        // Calculate price per day (base price)
        // For pool products, get price based on how many items are already in cart
        if ($cartable instanceof Product && $cartable->isPool()) {
            // Use smarter pricing that considers which price tiers are used
            $pricePerDay = $cartable->getNextAvailablePoolPriceConsideringCart($this, null, $from, $until);
            $regularPricePerDay = $cartable->getNextAvailablePoolPriceConsideringCart($this, false, $from, $until) ?? $pricePerDay;

            // If no price found from pool items, try the pool's direct price as fallback
            if ($pricePerDay === null && $cartable->hasPrice()) {
                $pricePerDay = $cartable->defaultPrice()->first()?->getCurrentPrice($cartable->isOnSale());
                $regularPricePerDay = $cartable->defaultPrice()->first()?->getCurrentPrice(false) ?? $pricePerDay;
            }
        } else {
            $pricePerDay = $cartable->getCurrentPrice();
            $regularPricePerDay = $cartable->getCurrentPrice(false) ?? $pricePerDay;
        }

        // Ensure prices are not null
        if ($pricePerDay === null) {
            $debugInfo = '';
            if ($cartable instanceof Product && $cartable->isPool()) {
                $debugInfo = " (Pool product, currentQuantityInCart: {$currentQuantityInCart}, hasPrice: " . ($cartable->hasPrice() ? 'yes' : 'no') . ")";
            }
            throw new \Exception("Product '{$cartable->name}' has no valid price.{$debugInfo}");
        }

        // Calculate days if booking dates provided
        $days = 1;
        if ($from && $until) {
            $days = max(1, $from->diff($until)->days);
        }

        // Calculate price per unit for the entire period
        $pricePerUnit = $pricePerDay * $days;
        $regularPricePerUnit = $regularPricePerDay * $days;

        // Defensive check - ensure pricePerUnit is not null
        if ($pricePerUnit === null) {
            throw new \Exception("Cart item price calculation resulted in null for '{$cartable->name}' (pricePerDay: {$pricePerDay}, days: {$days})");
        }

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
     * @throws \Exception
     */
    public function validateForCheckout(): void
    {
        $items = $this->items()
            ->with('purchasable')
            ->get();

        if ($items->isEmpty()) {
            throw new \Exception("Cart is empty");
        }

        // Validate that all items have required information before checkout
        foreach ($items as $item) {
            $adjustments = $item->requiredAdjustments();
            if (!empty($adjustments)) {
                $product = $item->purchasable;
                $productName = $product ? $product->name : 'Unknown Product';
                $missingFields = implode(', ', array_keys($adjustments));
                throw new \Exception("Cart item '{$productName}' is missing required information: {$missingFields}");
            }
        }
    }

    public function checkout(): static
    {
        // Validate cart before proceeding
        $this->validateForCheckout();

        $items = $this->items()
            ->with('purchasable')
            ->get();

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

    /**
     * Create a Stripe Checkout Session for this cart
     * 
     * This method:
     * - Validates the cart (doesn't convert it)
     * - Syncs products/prices to Stripe (creates them if they don't exist)
     * - Creates line items with descriptions including booking dates
     * - Returns the Stripe checkout session
     * 
     * @param array $options Optional session parameters (success_url, cancel_url, etc.)
     * @return \Stripe\Checkout\Session
     * @throws \Exception
     */
    public function checkoutSession(array $options = []): \Stripe\Checkout\Session
    {
        if (!config('shop.stripe.enabled')) {
            throw new \Exception('Stripe is not enabled');
        }

        // Ensure Stripe is initialized
        \Stripe\Stripe::setApiKey(config('services.stripe.secret'));

        // Validate cart before proceeding (doesn't convert it)
        $this->validateForCheckout();

        $syncService = new \Blax\Shop\Services\StripeSyncService();
        $lineItems = [];

        foreach ($this->items as $item) {
            $purchasable = $item->purchasable;

            // Get the price model
            if ($purchasable instanceof Product) {
                $price = $purchasable->defaultPrice()->first();
                $product = $purchasable;
            } elseif ($purchasable instanceof \Blax\Shop\Models\ProductPrice) {
                $price = $purchasable;
                $product = $purchasable->purchasable;
            } else {
                throw new \Exception("Item has no valid price");
            }

            if (!$price) {
                $name = $purchasable->name ?? 'Unknown item';
                throw new \Exception("Item '{$name}' has no default price");
            }

            // Sync product and price to Stripe
            $stripePriceId = $syncService->syncPrice($price, $product);

            // Build line item with description including booking dates if applicable
            $lineItem = [
                'price' => $stripePriceId,
                'quantity' => $item->quantity,
            ];

            // Add description with booking dates if available
            $description = null;
            if ($item->from && $item->until) {
                $days = max(1, $item->from->diffInDays($item->until));
                $fromFormatted = $item->from->format('M j, Y');
                $untilFormatted = $item->until->format('M j, Y');
                $description = "Period: {$fromFormatted} to {$untilFormatted} ({$days} day" . ($days > 1 ? 's' : '') . ")";
            }

            if ($description) {
                $lineItem['description'] = $description;
            }

            $lineItems[] = $lineItem;
        }

        // Prepare session parameters
        $sessionParams = [
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => $options['success_url'] ?? route('shop.stripe.success') . '?session_id={CHECKOUT_SESSION_ID}&cart_id=' . $this->id,
            'cancel_url' => $options['cancel_url'] ?? route('shop.stripe.cancel') . '?cart_id=' . $this->id,
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
}
