<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Exceptions\MultiplePurchaseOptions;
use Blax\Shop\Exceptions\NotPurchasable;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasCart
{
    public function cart(): MorphMany
    {
        return $this->morphMany(
            config('shop.models.cart', Cart::class),
            'customer'
        );
    }

    /**
     * Get cart items (purchases with status 'cart')
     */
    public function cartItems(): HasMany
    {
        return $this->cart()->latest()->firstOrCreate()->items();
    }


    /**
     * Get or create the current cart for the entity
     * 
     * @return Cart
     */
    public function currentCart(): Cart
    {
        return $this->cart()
            ->whereNull('converted_at')
            ->latest()
            ->firstOrCreate();
    }

    /**
     * Add product to cart
     *
     * @param Product|ProductPrice $product_or_price
     * @param int $quantity
     * @param array $options
     * @return CartItem
     * @throws \Exception
     */
    public function addToCart(Product|ProductPrice $product_or_price, int $quantity = 1, array $parameters = []): CartItem
    {
        if ($product_or_price instanceof ProductPrice) {
            $product = $product_or_price->purchasable;

            if ($product instanceof Product) {
                $product->claimStock($quantity);
            }
        }

        if ($product_or_price instanceof Product) {
            $product_or_price->claimStock($quantity);

            $default_prices = $product_or_price->defaultPrice()->count();

            if ($default_prices === 0) {
                throw new NotPurchasable("Product has no default price");
            }

            if ($default_prices > 1) {
                throw new MultiplePurchaseOptions("Product has multiple default prices, please specify a price to add to cart");
            }
        }

        return $this->currentCart()->addToCart(
            $product_or_price,
            $quantity,
            $parameters
        );
    }

    /**
     * Update cart item quantity
     *
     * @param CartItem $cartItem
     * @param int $quantity
     * @return CartItem
     * @throws \Exception
     */
    public function updateCartQuantity(CartItem $cartItem, int $quantity): CartItem
    {
        $product = $cartItem->purchasable;

        // Validate stock
        if ($product->manage_stock && $product->getAvailableStock() < $quantity) {
            throw new \Exception("Insufficient stock available");
        }

        $meta = (array) $cartItem->meta;

        $cartItem->update([
            'quantity' => $quantity,
        ]);

        return $cartItem->fresh();
    }

    /**
     * Remove item from cart
     *
     * @param CartItem $cartItem
     * @return bool
     * @throws \Exception
     */
    public function removeFromCart(CartItem $cartItem): bool
    {
        return $cartItem->forceDelete();
    }

    /**
     * Clear all cart items
     *
     * @param string|null $cartId (deprecated - not used)
     * @return int Number of items removed
     */
    public function clearCart(?string $cartId = null): int
    {
        return $this->cartItems()->delete();
    }

    /**
     * Get cart total
     *
     * @param string|null $cartId (deprecated - not used)
     * @return float
     */
    public function getCartTotal(?string $cartId = null): float
    {
        return $this->cartItems()->get()->sum(function ($item) {
            return ($item->purchasable->getCurrentPrice() ?? 0) * $item->quantity;
        });
    }

    /**
     * Get cart items count
     *
     * @param string|null $cartId (deprecated - not used)
     * @return int
     */
    public function getCartItemsCount(?string $cartId = null): int
    {
        return $this->cartItems()->sum('quantity') ?? 0;
    }


    /**
     * Get or generate current cart ID
     *
     * @return string
     */
    protected function getCurrentCartId(): string
    {
        // Override this method if you need custom cart ID logic
        return 'cart_' . $this->getKey();
    }
}
