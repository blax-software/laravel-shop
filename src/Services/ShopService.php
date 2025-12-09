<?php

namespace Blax\Shop\Services;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ShopService
{
    /**
     * Get all products query builder
     *
     * @return Builder
     */
    public function products(): Builder
    {
        return Product::query();
    }

    /**
     * Get a product by ID
     *
     * @param mixed $id
     * @return Product|null
     */
    public function product($id): ?Product
    {
        return Product::find($id);
    }

    /**
     * Get all categories query builder
     *
     * @return Builder
     */
    public function categories(): Builder
    {
        return ProductCategory::query();
    }

    /**
     * Get in-stock products
     *
     * @return Builder
     */
    public function inStock(): Builder
    {
        return Product::inStock();
    }

    /**
     * Get featured products
     *
     * @return Builder
     */
    public function featured(): Builder
    {
        return Product::featured();
    }

    /**
     * Get published and visible products
     *
     * @return Builder
     */
    public function published(): Builder
    {
        return Product::published()->visible();
    }

    /**
     * Search products by query
     *
     * @param string $query
     * @return Builder
     */
    public function search(string $query): Builder
    {
        /** @var Builder $query */
        $query = Product::where('name', 'like', "%{$query}%")
            ->orWhere('description', 'like', "%{$query}%");

        return $query;
    }

    /**
     * Check if product has available stock for quantity
     *
     * @param Product $product
     * @param int $quantity
     * @return bool
     */
    public function checkStock(Product $product, int $quantity): bool
    {
        if (!$product->manage_stock) {
            return true;
        }

        return $product->getAvailableStock() >= $quantity;
    }

    /**
     * Get available stock for a product
     *
     * @param Product $product
     * @return int
     */
    public function getAvailableStock(Product $product): int
    {
        if (!$product->manage_stock) {
            return PHP_INT_MAX;
        }

        return $product->getAvailableStock();
    }

    /**
     * Check if product is on sale
     *
     * @param Product $product
     * @return bool
     */
    public function isOnSale(Product $product): bool
    {
        return $product->isOnSale();
    }

    /**
     * Get shop configuration value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function config(string $key, $default = null)
    {
        return config("shop.{$key}", $default);
    }

    /**
     * Get default shop currency
     *
     * @return string
     */
    public function currency(): string
    {
        return config('shop.currency', 'USD');
    }
}
