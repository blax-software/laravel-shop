<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

trait HasShoppingCapabilities
{
    public function cart(): MorphMany
    {
        return $this->morphMany(
            config('shop.models.cart', \Blax\Shop\Models\Cart::class),
            'customer'
        );
    }

    /**
     * Get all purchases made by this entity
     */
    public function purchases(): MorphMany
    {
        // This morph represents the purchaser (e.g. User), not the product.
        return $this->morphMany(
            config('shop.models.product_purchase', ProductPurchase::class),
            'purchaser'
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
     * Get completed purchases
     */
    public function completedPurchases(): MorphMany
    {
        return $this->purchases()->where('status', 'completed');
    }

    /**
     * Purchase a product
     *
     * @param Product $product
     * @param int $quantity
     * 
     * @return ProductPurchase
     * @throws \Exception
     */
    public function purchase(
        ProductPrice|string $productPrice,
        int $quantity = 1,
    ): ProductPurchase {

        $productPrice = ($productPrice instanceof ProductPrice)
            ? $productPrice
            : ProductPrice::findOrFail($productPrice);

        if (!$productPrice?->purchasable?->id) {
            throw new \Exception("Price does not belong to the specified product");
        }

        $product = $productPrice->purchasable;
        
        // product must have interface Purchasable
        if (!in_array('Blax\Shop\Contracts\Purchasable', class_implements($product))) {
            throw new \Exception("The product is not purchasable");
        }

        // Validate stock availability
        if ($product->manage_stock) {
            $available = $product->getAvailableStock();
            if ($available < $quantity) {
                throw new NotEnoughStockException("Insufficient stock. Available: {$available}, Requested: {$quantity}");
            }
        }

        // Check if product is visible
        if (!$product->isVisible()) {
            throw new \Exception("Product is not available for purchase");
        }

        // Decrease stock
        if (!$product->decreaseStock($quantity)) {
            throw new \Exception("Unable to decrease stock");
        }

        // Create purchase record
        $purchase = $this->purchases()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'purchaser_id' => $this->getKey(),
            'purchaser_type' => get_class($this),
            'quantity' => $quantity,
            'status' => 'unpaid',
            'meta' => array_merge([
                'price_id' => $productPrice->id,
                'price' => $productPrice->price,
                'amount' => $productPrice->price * $quantity,
            ]),
        ]);

        // Trigger product actions
        $product->callActions('purchased', $purchase, [
            'purchaser' => $this,
        ]);

        $purchase->fresh();

        if (!$purchase) {
            throw new \Exception("Unable to create purchase record");
        }

        if (!$purchase->purchasable || $purchase->purchasable->id !== $product->id) {
            throw new \Exception("Purchase record does not match the product");
        }

        return $purchase;
    }

    /**
     * Add product to cart
     *
     * @param Product|ProductPrice $price
     * @param int $quantity
     * @param array $options
     * @return CartItem
     * @throws \Exception
     */
    public function addToCart(Product|ProductPrice $price, int $quantity = 1, array $parameters = []): CartItem
    {       
        return $this->cart()->latest()->firstOrCreate()->addToCart(
            $price,
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
            dump('getCurrentPrice',get_class($item->purchasable),$item->purchasable->getCurrentPrice());
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
     * Checkout cart - convert cart items to completed purchases
     *
     * @param string|null $cartId (deprecated - not used)
     * @param array $options
     * @return Collection
     * @throws \Exception
     */
    public function checkout(?string $cartId = null, array $options = []): Collection
    {
        $items = $this->cartItems()
            ->with('purchasable')
            ->get();

        if ($items->isEmpty()) {
            throw new \Exception("Cart is empty");
        }

        $purchases = collect();

        // Create ProductPurchase for each cart item
        foreach ($items as $item) {
            $product = $item->purchasable;
            $quantity = $item->quantity;
            
            $purchase = $this->purchase(
                $product->prices()->first(),
                $quantity
            );

            $purchases->push($purchase);

            // Remove item from cart
            $item->delete();
        }

        return $purchases;
    }

    /**
     * Check if entity has purchased a product
     *
     * @param Product|int $product
     * @return bool
     */
    public function hasPurchased($product): bool
    {
        $productId = $product instanceof Product ? $product->id : $product;

        return $this->completedPurchases()
            ->where('product_id', $productId)
            ->exists();
    }

    /**
     * Get purchase history for a product
     *
     * @param Product|int $product
     * @return Collection
     */
    public function getPurchaseHistory($product): Collection
    {
        $productId = $product instanceof Product ? $product->id : $product;

        return $this->purchases()
            ->where('product_id', $productId)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Refund a purchase
     *
     * @param ProductPurchase $purchase
     * @param array $options
     * @return bool
     * @throws \Exception
     */
    public function refundPurchase(ProductPurchase $purchase, array $options = []): bool
    {
        if ($purchase->status !== 'completed') {
            throw new \Exception("Can only refund completed purchases");
        }

        $product = $purchase->product;

        // Return stock
        $product->increaseStock($purchase->quantity);

        // Update purchase
        $purchase->update([
            'status' => 'refunded',
        ]);

        // Trigger refund actions
        $product->callActions('refunded', $purchase, [
            'purchaser' => $this,
            ...$options,
        ]);

        return true;
    }

    /**
     * Get total spent
     *
     * @return float
     */
    public function getTotalSpent(): float
    {
        return $this->completedPurchases()->sum('amount') ?? 0;
    }

    /**
     * Get purchase statistics
     *
     * @return array
     */
    public function getPurchaseStats(): array
    {
        return [
            'total_purchases' => $this->completedPurchases()->count(),
            'total_spent' => $this->getTotalSpent(),
            'total_items' => $this->completedPurchases()->sum('quantity'),
            'cart_items' => $this->getCartItemsCount(),
            'cart_total' => $this->getCartTotal(),
        ];
    }

    /**
     * Determine purchase price for a product
     *
     * @param Product $product
     * @param string|null $priceId
     * @return float
     */
    protected function determinePurchasePrice(Product $product, ?string $priceId = null): float
    {
        if ($priceId) {
            $productPrice = $product->prices()->find($priceId);
            if ($productPrice) {
                return $productPrice->price;
            }
        }

        return $product->getCurrentPrice();
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
