<?php

namespace Blax\Shop\Services;

use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Contracts\Cartable;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Exceptions\HasNoDefaultPriceException;
use Blax\Shop\Exceptions\HasNoPriceException;
use Blax\Shop\Exceptions\InvalidBookingConfigurationException;
use Blax\Shop\Exceptions\InvalidPoolConfigurationException;
use Blax\Shop\Exceptions\NotPurchasable;
use Blax\Shop\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;

class CartService
{
    /**
     * Get current authenticated user's cart
     * Throws exception if no user is authenticated
     *
     * @return Cart
     * @throws \Exception
     */
    public function current(): Cart
    {
        $user = auth()->user();

        if (!$user) {
            throw new \Exception('No authenticated user found. Use guest() for guest carts or provide a cart ID.');
        }

        return $user->currentCart();
    }

    /**
     * Get or create a guest cart by session ID
     * If no session ID provided, uses session()->getId()
     *
     * @param string|null $sessionId
     * @return Cart
     */
    public function guest(?string $sessionId = null): Cart
    {
        $sessionId = $sessionId ?? session()->getId();

        return Cart::firstOrCreate([
            'session_id' => $sessionId,
            'customer_id' => null,
            'customer_type' => null,
        ]);
    }

    /**
     * Get cart for specific user
     *
     * @param Authenticatable $user
     * @return Cart
     */
    public function forUser(Authenticatable $user): Cart
    {
        if (!method_exists($user, 'currentCart')) {
            throw new \Exception('User model must have shopping capabilities');
        }

        return $user->currentCart();
    }

    /**
     * Find cart by ID
     *
     * @param string $cartId
     * @return Cart|null
     */
    public function find(string $cartId): ?Cart
    {
        return Cart::find($cartId);
    }

    /**
     * Add item to current user's cart (throws exception if no user)
     * For guests, use guest() first: Cart::guest()->add($product)
     *
     * @param Model&Cartable $product
     * @param int $quantity
     * @param array $parameters
     * @return CartItem
     * @throws HasNoPriceException
     * @throws HasNoDefaultPriceException
     */
    public function add(Model $product, int $quantity = 1, array $parameters = []): CartItem
    {
        $user = auth()->user();

        if (!$user) {
            throw new \Exception('No authenticated user found. Use guest() for guest carts.');
        }

        // Validate pricing before adding to cart
        if ($product instanceof Product) {
            $product->validatePricing(throwExceptions: true);
        }

        return $user->addToCart($product, $quantity, $parameters);
    }

    /**
     * Remove item from current user's cart (throws exception if no user)
     * For guests, use guest() first: Cart::guest()->remove($product)
     *
     * @param Model&Cartable $product
     * @param int $quantity
     * @param array $parameters
     * @return CartItem|true
     */
    public function remove(Model $product, int $quantity = 1, array $parameters = [])
    {
        $user = auth()->user();

        if (!$user) {
            throw new \Exception('No authenticated user found. Use guest() for guest carts.');
        }

        return $user->currentCart()->removeFromCart($product, $quantity, $parameters);
    }

    /**
     * Update cart item quantity
     *
     * @param CartItem $cartItem
     * @param int $quantity
     * @return CartItem
     */
    public function update(CartItem $cartItem, int $quantity): CartItem
    {
        $cart = $cartItem->cart;
        $product = $cartItem->purchasable;

        if ($product && method_exists($product, 'getCurrentPrice')) {
            // Update quantity and subtotal
            $cartItem->update([
                'quantity' => $quantity,
                'subtotal' => $product->getCurrentPrice() * $quantity,
            ]);
        }

        return $cartItem->fresh();
    }

    /**
     * Clear all items from a cart
     * If no cart provided, clears current user's cart
     *
     * @param Cart|null $cart
     * @return int
     * @throws \Exception
     */
    public function clear(?Cart $cart = null): int
    {
        if (!$cart) {
            $user = auth()->user();

            if (!$user) {
                throw new \Exception('No authenticated user found. Provide a cart or use guest() for guest carts.');
            }

            $cart = $user->currentCart();
        }

        return $cart->items()->delete();
    }

    /**
     * Checkout a cart
     * If no cart provided, checkouts current user's cart
     *
     * @param Cart|null $cart
     * @return \Illuminate\Support\Collection
     * @throws \Exception
     */
    public function checkout(?Cart $cart = null)
    {
        if (!$cart) {
            $user = auth()->user();

            if (!$user) {
                throw new \Exception('Cannot checkout guest cart. Guest carts must be converted to orders manually.');
            }

            return $user->checkoutCart();
        }

        return $cart->checkout();
    }

    /**
     * Get total for a cart
     * If no cart provided, gets current user's cart total
     *
     * @param Cart|null $cart
     * @return float
     * @throws \Exception
     */
    public function total(?Cart $cart = null): float
    {
        if (!$cart) {
            return $this->current()->getTotal();
        }

        return $cart->getTotal();
    }

    /**
     * Get item count for a cart
     * If no cart provided, gets current user's cart item count
     *
     * @param Cart|null $cart
     * @return int
     * @throws \Exception
     */
    public function itemCount(?Cart $cart = null): int
    {
        if (!$cart) {
            return $this->current()->getTotalItems();
        }

        return $cart->getTotalItems();
    }

    /**
     * Get items for a cart
     * If no cart provided, gets current user's cart items
     *
     * @param Cart|null $cart
     * @return \Illuminate\Database\Eloquent\Collection
     * @throws \Exception
     */
    public function items(?Cart $cart = null)
    {
        if (!$cart) {
            return $this->current()->items()->get();
        }

        return $cart->items()->get();
    }

    /**
     * Check if cart is empty
     * If no cart provided, checks current user's cart
     *
     * @param Cart|null $cart
     * @return bool
     * @throws \Exception
     */
    public function isEmpty(?Cart $cart = null): bool
    {
        if (!$cart) {
            return $this->current()->items->isEmpty();
        }

        return $cart->items->isEmpty();
    }

    /**
     * Check if cart is expired
     *
     * @param Cart $cart
     * @return bool
     */
    public function isExpired(?Cart $cart = null): bool
    {
        if (!$cart) {
            return $this->current()->isExpired();
        }

        return $cart->isExpired();
    }

    /**
     * Check if cart is converted
     *
     * @param Cart|null $cart
     * @return bool
     */
    public function isConverted(?Cart $cart = null): bool
    {
        if (!$cart) {
            return $this->current()->isConverted();
        }

        return $cart->isConverted();
    }

    /**
     * Get unpaid amount in cart
     *
     * @param Cart|null $cart
     * @return float
     * @throws \Exception
     */
    public function unpaidAmount(?Cart $cart = null): float
    {
        if (!$cart) {
            return $this->current()->getUnpaidAmount();
        }

        return $cart->getUnpaidAmount();
    }

    /**
     * Get paid amount in cart
     *
     * @param Cart|null $cart
     * @return float
     * @throws \Exception
     */
    public function paidAmount(?Cart $cart = null): float
    {
        if (!$cart) {
            return $this->current()->getPaidAmount();
        }

        return $cart->getPaidAmount();
    }

    /**
     * Validate cart items for booking products
     * Checks if all booking products have valid timespans and stock availability
     *
     * @param Cart|null $cart
     * @return array Array of validation errors
     * @throws \Exception
     */
    public function validateBookings(?Cart $cart = null): array
    {
        if (!$cart) {
            $cart = $this->current();
        }

        $errors = [];

        foreach ($cart->items as $item) {
            $product = $item->purchasable;

            if (!$product instanceof Product) {
                continue;
            }

            // Check if booking product has timespan
            if ($product->isBooking() && (!$item->from || !$item->until)) {
                $errors[] = "Booking product '{$product->name}' requires a timespan (from/until dates).";
                continue;
            }

            // Check if pool product with booking items has timespan
            if ($product->isPool() && $product->hasBookingSingleItems()) {
                // If pool has a timespan, validate it
                if ($item->from && $item->until) {
                    // Check if quantity is available for the timespan
                    $maxQuantity = $product->getPoolMaxQuantity($item->from, $item->until);
                    if ($item->quantity > $maxQuantity) {
                        $errors[] = "Only {$maxQuantity} '{$product->name}' available for the selected period. You requested {$item->quantity}.";
                    }
                } else {
                    // Check if individual single items have timespans in meta
                    $meta = $item->getMeta();
                    $hasIndividualTimespans = $meta->individual_timespans ?? false;

                    if (!$hasIndividualTimespans) {
                        $errors[] = "Pool product '{$product->name}' with booking items requires either a timespan or individual timespans for each item.";
                    }
                }
            }

            // Validate stock availability for booking period
            if ($product->isBooking() && $item->from && $item->until) {
                if (!$product->isAvailableForBooking($item->from, $item->until, $item->quantity)) {
                    $errors[] = "'{$product->name}' is not available for the selected period (insufficient stock).";
                }
            }
        }

        return $errors;
    }

    /**
     * Check if cart has valid bookings
     *
     * @param Cart|null $cart
     * @return bool
     * @throws \Exception
     */
    public function hasValidBookings(?Cart $cart = null): bool
    {
        return empty($this->validateBookings($cart));
    }

    /**
     * Add a booking product to cart with timespan
     *
     * @param Model&Cartable $product
     * @param int $quantity
     * @param \DateTimeInterface $from
     * @param \DateTimeInterface $until
     * @param array $parameters
     * @return CartItem
     * @throws HasNoPriceException
     * @throws HasNoDefaultPriceException
     * @throws InvalidBookingConfigurationException
     * @throws InvalidPoolConfigurationException
     * @throws NotPurchasable
     */
    public function addBooking(
        Model $product,
        int $quantity,
        \DateTimeInterface $from,
        \DateTimeInterface $until,
        array $parameters = []
    ): CartItem {
        $user = auth()->user();

        if (!$user) {
            throw new \Exception('No authenticated user found. Use guest() for guest carts.');
        }

        // Validate timespan
        if ($from >= $until) {
            throw InvalidBookingConfigurationException::invalidTimespan($from, $until);
        }

        if ($from->lessThan(now())) {
            throw InvalidBookingConfigurationException::invalidTimespan($from, $until);
        }

        // Validate the product type and configuration
        if ($product instanceof Product) {
            if (!$product->isBooking() && !$product->isPool()) {
                throw new \Exception(
                    "Product '{$product->name}' is not a booking or pool type.\n\n" .
                        "For booking products:\n" .
                        Product::getBookingSetupInstructions() . "\n\n" .
                        "For pool products:\n" .
                        Product::getPoolSetupInstructions()
                );
            }

            // Validate pricing before adding to cart
            $product->validatePricing(throwExceptions: true);

            // Validate booking product configuration
            if ($product->isBooking()) {
                $product->validateBookingConfiguration();
            }

            // Validate pool product configuration
            if ($product->isPool()) {
                $product->validatePoolConfiguration();
            }
        }        // Check availability
        if ($product instanceof Product && $product->isBooking()) {
            if (!$product->isAvailableForBooking($from, $until, $quantity)) {
                $available = $product->getAvailableStock();
                throw InvalidBookingConfigurationException::notAvailableForPeriod(
                    $product->name,
                    $from,
                    $until,
                    $quantity,
                    $available
                );
            }
        }

        // Check pool product availability
        if ($product instanceof Product && $product->isPool()) {
            $maxQuantity = $product->getPoolMaxQuantity($from, $until);
            if ($quantity > $maxQuantity) {
                throw InvalidPoolConfigurationException::notEnoughAvailableItems(
                    $product->name,
                    $from,
                    $until,
                    $quantity,
                    $maxQuantity
                );
            }
        }

        // Add to cart with timespan
        $cart = $user->currentCart();

        $pricePerDay = $product->getCurrentPrice();

        // Calculate price based on days for booking products
        if ($product instanceof Product && ($product->isBooking() || $product->isPool())) {
            $days = $from->diff($until)->days;
            $pricePerUnit = $pricePerDay * $days;  // Price for one unit for the entire period
            $totalPrice = $pricePerUnit * $quantity;  // Total for all units
        } else {
            $pricePerUnit = $pricePerDay;
            $totalPrice = $pricePerDay * $quantity;
        }

        $cartItem = $cart->items()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => $quantity,
            'price' => $pricePerUnit,  // Price per unit for the period
            'subtotal' => $totalPrice,  // Total for all units
            'regular_price' => $pricePerDay,
            'parameters' => $parameters,
            'from' => $from,
            'until' => $until,
        ]);

        return $cartItem;
    }
}
