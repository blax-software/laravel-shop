<?php

namespace Blax\Shop\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Shop Facade - Admin and Developer helper methods.
 * 
 * Product Queries:
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
 * @method static \Illuminate\Database\Eloquent\Collection topProducts(int $limit = 10)
 * 
 * Order Queries:
 * @method static \Illuminate\Database\Eloquent\Builder orders()
 * @method static \Blax\Shop\Models\Order|null order(string $id)
 * @method static \Blax\Shop\Models\Order|null orderByNumber(string $orderNumber)
 * @method static \Illuminate\Database\Eloquent\Builder ordersToday()
 * @method static \Illuminate\Database\Eloquent\Builder ordersThisWeek()
 * @method static \Illuminate\Database\Eloquent\Builder ordersThisMonth()
 * @method static \Illuminate\Database\Eloquent\Builder ordersThisYear()
 * @method static \Illuminate\Database\Eloquent\Builder ordersBetween(\DateTimeInterface $from, \DateTimeInterface $until)
 * @method static \Illuminate\Database\Eloquent\Builder ordersWithStatus(\Blax\Shop\Enums\OrderStatus $status)
 * @method static \Illuminate\Database\Eloquent\Builder pendingOrders()
 * @method static \Illuminate\Database\Eloquent\Builder processingOrders()
 * @method static \Illuminate\Database\Eloquent\Builder completedOrders()
 * @method static \Illuminate\Database\Eloquent\Builder cancelledOrders()
 * @method static \Illuminate\Database\Eloquent\Builder activeOrders()
 * @method static \Illuminate\Database\Eloquent\Builder paidOrders()
 * @method static \Illuminate\Database\Eloquent\Builder unpaidOrders()
 * 
 * Revenue & Statistics:
 * @method static int totalRevenue()
 * @method static int revenueToday()
 * @method static int revenueThisWeek()
 * @method static int revenueThisMonth()
 * @method static int revenueThisYear()
 * @method static int revenueBetween(\DateTimeInterface $from, \DateTimeInterface $until)
 * @method static int totalRefunded()
 * @method static int netRevenue()
 * @method static float averageOrderValue()
 * @method static array stats()
 * @method static \Illuminate\Support\Collection revenueByDay(\DateTimeInterface $from, \DateTimeInterface $until)
 * @method static \Illuminate\Support\Collection revenueByMonth(\DateTimeInterface $from, \DateTimeInterface $until)
 * 
 * Cart Queries:
 * @method static \Illuminate\Database\Eloquent\Builder carts()
 * @method static \Illuminate\Database\Eloquent\Builder activeCarts()
 * @method static \Illuminate\Database\Eloquent\Builder abandonedCarts()
 * @method static \Illuminate\Database\Eloquent\Builder expiredCarts()
 * @method static \Illuminate\Database\Eloquent\Builder cartsToExpire()
 * @method static \Illuminate\Database\Eloquent\Builder cartsToDelete()
 * @method static int expireStaleCarts()
 * @method static int deleteOldCarts()
 * 
 * Configuration:
 * @method static mixed config(string $key, mixed $default = null)
 * @method static string currency()
 * @method static string formatMoney(int $cents, ?string $currency = null)
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
