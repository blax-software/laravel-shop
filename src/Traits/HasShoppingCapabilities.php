<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Models\Product;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

trait HasShoppingCapabilities
{
    /**
     * Get all purchases made by this entity
     */
    public function purchases(): MorphMany
    {
        return $this->morphMany(
            config('shop.models.product_purchase', ProductPurchase::class),
            'purchasable'
        );
    }

    /**
     * Get cart items (purchases with status 'cart')
     */
    public function cartItems(): MorphMany
    {
        return $this->purchases()->where('status', 'cart');
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
     * @param array $options Additional options (price_id, meta, etc.)
     * @return ProductPurchase
     * @throws \Exception
     */
    public function purchase(Product $product, int $quantity = 1, array $options = []): ProductPurchase
    {
        // Validate stock availability
        if ($product->manage_stock) {
            $available = $product->getAvailableStock();
            if ($available < $quantity) {
                throw new \Exception("Insufficient stock. Available: {$available}, Requested: {$quantity}");
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

        // Determine price
        $priceId = $options['price_id'] ?? null;
        $price = $this->determinePurchasePrice($product, $priceId);

        // Create purchase record
        $purchase = $this->purchases()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'status' => $options['status'] ?? 'completed',
            'meta' => array_merge([
                'price_id' => $priceId,
                'price' => $price,
                'amount' => $price * $quantity,
                'charge_id' => $options['charge_id'] ?? null,
            ], $options['meta'] ?? []),
        ]);

        // Trigger product actions
        $product->callActions('purchased', $purchase, [
            'purchaser' => $this,
            ...$options,
        ]);

        return $purchase;
    }

    /**
     * Add product to cart
     *
     * @param Product $product
     * @param int $quantity
     * @param array $options
     * @return ProductPurchase
     * @throws \Exception
     */
    public function addToCart(Product $product, int $quantity = 1, array $options = []): ProductPurchase
    {
        // Check if product already in cart
        $existingItem = $this->cartItems()
            ->where('product_id', $product->id)
            ->first();

        if ($existingItem) {
            return $this->updateCartQuantity($existingItem, $existingItem->quantity + $quantity);
        }

        // Validate stock
        if ($product->manage_stock && $product->getAvailableStock() < $quantity) {
            throw new \Exception("Insufficient stock available");
        }

        $priceId = $options['price_id'] ?? null;
        $price = $this->determinePurchasePrice($product, $priceId);

        return $this->purchases()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'status' => 'cart',
            'meta' => array_merge([
                'price_id' => $priceId,
                'price' => $price,
                'amount' => $price * $quantity,
            ], $options['meta'] ?? []),
        ]);
    }

    /**
     * Update cart item quantity
     *
     * @param ProductPurchase $cartItem
     * @param int $quantity
     * @return ProductPurchase
     * @throws \Exception
     */
    public function updateCartQuantity(ProductPurchase $cartItem, int $quantity): ProductPurchase
    {
        if ($cartItem->status !== 'cart') {
            throw new \Exception("Cannot update non-cart item");
        }

        $product = $cartItem->product;

        // Validate stock
        if ($product->manage_stock && $product->getAvailableStock() < $quantity) {
            throw new \Exception("Insufficient stock available");
        }

        $meta = (array) $cartItem->meta;
        $priceId = $meta['price_id'] ?? null;
        $price = $this->determinePurchasePrice($product, $priceId);

        $cartItem->update([
            'quantity' => $quantity,
            'meta' => array_merge($meta, [
                'price' => $price,
                'amount' => $price * $quantity,
            ]),
        ]);

        return $cartItem->fresh();
    }

    /**
     * Remove item from cart
     *
     * @param ProductPurchase $cartItem
     * @return bool
     * @throws \Exception
     */
    public function removeFromCart(ProductPurchase $cartItem): bool
    {
        if ($cartItem->status !== 'cart') {
            throw new \Exception("Cannot remove non-cart item");
        }

        return $cartItem->delete();
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
            $meta = (array) $item->meta;
            return $meta['amount'] ?? 0;
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
        $items = $this->cartItems()->with('product')->get();

        if ($items->isEmpty()) {
            throw new \Exception("Cart is empty");
        }

        // Validate stock for all items
        foreach ($items as $item) {
            $product = $item->product;
            if ($product->manage_stock && $product->getAvailableStock() < $item->quantity) {
                throw new \Exception("Insufficient stock for: {$product->getLocalized('name')}");
            }
        }

        // Process each item
        $completedPurchases = collect();
        foreach ($items as $item) {
            $product = $item->product;

            // Decrease stock
            if (!$product->decreaseStock($item->quantity)) {
                // Rollback previous purchases
                foreach ($completedPurchases as $purchase) {
                    $purchase->product->increaseStock($purchase->quantity);
                    $purchase->delete();
                }
                throw new \Exception("Unable to process checkout");
            }

            // Update status and store charge info in meta
            $meta = array_merge((array) $item->meta, [
                'charge_id' => $options['charge_id'] ?? null,
                'completed_at' => now()->toISOString(),
            ]);

            $item->update([
                'status' => 'completed',
                'meta' => $meta,
            ]);

            // Trigger actions
            $product->callActions('purchased', $item, [
                'purchaser' => $this,
                ...$options,
            ]);

            $completedPurchases->push($item);
        }

        return $completedPurchases;
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
