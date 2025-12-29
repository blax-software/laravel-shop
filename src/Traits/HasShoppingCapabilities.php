<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Contracts\Purchasable;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Exceptions\MultiplePurchaseOptions;
use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Exceptions\NotPurchasable;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Models\ProductPurchase;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

trait HasShoppingCapabilities
{
    use HasCart;
    use HasChargingOptions;
    use HasOrders;

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
     * Get completed purchases
     */
    public function completedPurchases(): MorphMany
    {
        return $this->purchases()->where('status', PurchaseStatus::COMPLETED->value);
    }

    /**
     * Purchase a product
     *
     * @param Product|Product $product_or_price
     * @param int $quantity
     * @param array|object|null $meta
     * @param \DateTimeInterface|null $from Booking start date (for booking products)
     * @param \DateTimeInterface|null $until Booking end date (for booking products)
     * 
     * @return ProductPurchase
     * @throws \Exception
     */
    public function purchase(
        ProductPrice|Product $product_or_price,
        int $quantity = 1,
        array|object|null $meta = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $until = null
    ): ProductPurchase {

        if ($product_or_price instanceof Product) {
            $default_prices = $product_or_price->defaultPrice()->count();

            if ($default_prices === 0) {
                throw new NotPurchasable("Product has no default price");
            }

            if ($default_prices > 1) {
                throw new MultiplePurchaseOptions("Product has multiple default prices, please specify a price to purchase");
            }

            $price = $product_or_price->defaultPrice()->first();
        }

        if (!@$price) {
            $price = ($product_or_price instanceof ProductPrice)
                ? $product_or_price
                : throw new NotPurchasable;
        }

        if (!$price?->purchasable?->id) {
            throw new \Exception("Price does not belong to the specified product");
        }

        $product = $price->purchasable;

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

        // Handle booking products
        $isBooking = $product->type === ProductType::BOOKING;

        if ($isBooking && (!$from || !$until)) {
            throw new \Exception("Booking products require 'from' and 'until' dates");
        }

        // Decrease stock (for bookings, pass the until date as expiry so stock returns after booking ends)
        if (!$product->decreaseStock($quantity, $isBooking ? $until : null)) {
            throw new \Exception("Unable to decrease stock");
        }

        // Create purchase record
        $purchase = $this->purchases()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'purchaser_id' => $this->getKey(),
            'purchaser_type' => get_class($this),
            'quantity' => $quantity,
            'status' => PurchaseStatus::UNPAID,
            'from' => $from,
            'until' => $until,
            'meta' => $meta,
            'amount' => $price->unit_amount * $quantity,
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
     * Checkout cart - convert cart items to completed purchases
     *
     * @param string|null $cartId (deprecated - not used)
     * @param array $options
     * @return Cart
     * @throws \Exception
     */
    public function checkoutCart(?string $cartId = null): Cart
    {
        $cart = Cart::where('id', $cartId)
            ->where('customer_id', $this->getKey())
            ->where('customer_type', get_class($this))
            ->first();

        $cart ??= $this->currentCart();

        return $cart->checkout();
    }

    /**
     * Check if entity has purchased a product
     *
     * @param Purchasable|int $product
     * @return bool
     */
    public function hasPurchased($purchasable): bool
    {
        return $this->completedPurchases()
            ->where('purchasable_id', $purchasable->id)
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
        if ($purchase->status !== PurchaseStatus::COMPLETED) {
            throw new \Exception("Can only refund completed purchases");
        }

        $product = $purchase->product;

        // Return stock
        $product->increaseStock($purchase->quantity);

        // Update purchase
        $purchase->update([
            'status' => PurchaseStatus::REFUNDED,
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
}
