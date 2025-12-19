<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Exceptions\MultiplePurchaseOptions;
use Blax\Shop\Exceptions\NotEnoughStockException;
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
     * For booking and pool products, stock is NOT claimed at add-to-cart time.
     * Instead, availability is validated and stock is claimed at checkout time.
     * This allows adding items to cart for future dates even if currently unavailable.
     *
     * For regular products with manage_stock, stock is claimed immediately to
     * prevent overselling.
     *
     * @param Product|ProductPrice $product_or_price
     * @param int $quantity
     * @param array $parameters Optional parameters including 'from' and 'until' for booking dates
     * @return CartItem
     * @throws \Exception
     */
    public function addToCart(Product|ProductPrice $product_or_price, int $quantity = 1, array $parameters = []): CartItem
    {
        $product = $product_or_price instanceof ProductPrice
            ? $product_or_price->purchasable
            : $product_or_price;

        if ($product instanceof Product) {
            // For booking/pool products, do NOT claim stock at add-to-cart time
            // Stock will be validated and claimed at checkout based on the booking dates
            $isBookingOrPool = $product->isBooking() || $product->isPool();

            if (!$isBookingOrPool) {
                // For regular products, claim stock immediately to prevent overselling
                $product->claimStock($quantity);
            }

            // Skip default price validation for pool products without direct prices
            // (they inherit pricing from single items and are validated in validatePricing())
            $isPoolWithInheritedPricing = $product->isPool() && !$product->prices()->exists();

            if (!$isPoolWithInheritedPricing) {
                $default_prices = $product->defaultPrice()->count();

                if ($default_prices === 0) {
                    throw new NotPurchasable("Product has no default price");
                }

                if ($default_prices > 1) {
                    throw new MultiplePurchaseOptions("Product has multiple default prices, please specify a price to add to cart");
                }
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
            throw new NotEnoughStockException("Insufficient stock available");
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
