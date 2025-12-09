<?php

namespace Blax\Shop\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Illuminate\Database\Eloquent\Builder products()
 * @method static \Blax\Shop\Models\Product|null product(mixed $id)
 * @method static \Illuminate\Database\Eloquent\Builder categories()
 * @method static \Illuminate\Database\Eloquent\Builder inStock()
 * @method static \Illuminate\Database\Eloquent\Builder featured()
 * @method static \Illuminate\Database\Eloquent\Builder published()
 * @method static \Illuminate\Database\Eloquent\Builder search(string $query)
 * @method static bool checkStock(\Blax\Shop\Models\Product $product, int $quantity)
 * @method static int getAvailableStock(\Blax\Shop\Models\Product $product)
 * @method static bool isOnSale(\Blax\Shop\Models\Product $product)
 * @method static mixed config(string $key, mixed $default = null)
 * @method static string currency()
 */
class Shop extends Facade
{
    /**
     * Get the registered name of the component.
     *
     * @return string
     */
    protected static function getFacadeAccessor()
    {
        return 'shop.service';
    }
}
