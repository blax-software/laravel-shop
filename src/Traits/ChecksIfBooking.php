<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;

/**
 * Trait to provide a unified way to check if something is booking-related.
 * 
 * This trait provides the DRY principle for checking booking status across
 * Product, CartItem, Cart, and ProductPurchase models.
 * 
 * The rule is:
 * - For regular products: is booking if type === ProductType::BOOKING
 * - For pool products: is booking if at least one single item is a booking product
 * - For cart items: is booking if the product is booking
 * - For carts: is booking if at least one item is booking
 * - For purchases: is booking if has from/until dates
 */
trait ChecksIfBooking
{
    /**
     * Check if a Product is a booking product.
     * For pool products, checks if at least one single item is a booking product.
     * 
     * @param Product $product
     * @return bool
     */
    protected function checkProductIsBooking(Product $product): bool
    {
        // For pool products, check if any single item is a booking product
        if ($product->type === ProductType::POOL) {
            return $product->hasBookingSingleItems();
        }

        // For regular products, check the type
        return $product->type === ProductType::BOOKING;
    }
}
