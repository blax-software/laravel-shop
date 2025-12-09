<?php

namespace Blax\Shop\Services;

use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Contracts\Cartable;
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
     */
    public function add(Model $product, int $quantity = 1, array $parameters = []): CartItem
    {
        $user = auth()->user();

        if (!$user) {
            throw new \Exception('No authenticated user found. Use guest() for guest carts.');
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
}
