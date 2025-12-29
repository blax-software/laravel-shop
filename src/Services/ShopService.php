<?php

namespace Blax\Shop\Services;

use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Models\Order;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductCategory;
use Blax\Shop\Models\ProductPurchase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class ShopService
{
    // =========================================================================
    // PRODUCT QUERIES
    // =========================================================================

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

    // =========================================================================
    // ORDER QUERIES
    // =========================================================================

    /**
     * Get all orders query builder.
     */
    public function orders(): Builder
    {
        return Order::query();
    }

    /**
     * Get an order by ID.
     */
    public function order(string $id): ?Order
    {
        return Order::find($id);
    }

    /**
     * Get an order by order number.
     */
    public function orderByNumber(string $orderNumber): ?Order
    {
        return Order::where('order_number', $orderNumber)->first();
    }

    /**
     * Get orders created today.
     */
    public function ordersToday(): Builder
    {
        return Order::whereDate('created_at', Carbon::today());
    }

    /**
     * Get orders created this week.
     */
    public function ordersThisWeek(): Builder
    {
        return Order::whereBetween('created_at', [
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek(),
        ]);
    }

    /**
     * Get orders created this month.
     */
    public function ordersThisMonth(): Builder
    {
        return Order::whereBetween('created_at', [
            Carbon::now()->startOfMonth(),
            Carbon::now()->endOfMonth(),
        ]);
    }

    /**
     * Get orders created this year.
     */
    public function ordersThisYear(): Builder
    {
        return Order::whereBetween('created_at', [
            Carbon::now()->startOfYear(),
            Carbon::now()->endOfYear(),
        ]);
    }

    /**
     * Get orders within a specific date range.
     */
    public function ordersBetween(\DateTimeInterface $from, \DateTimeInterface $until): Builder
    {
        return Order::whereBetween('created_at', [$from, $until]);
    }

    /**
     * Get orders with a specific status.
     */
    public function ordersWithStatus(OrderStatus $status): Builder
    {
        return Order::where('status', $status->value);
    }

    /**
     * Get pending orders.
     */
    public function pendingOrders(): Builder
    {
        return $this->ordersWithStatus(OrderStatus::PENDING);
    }

    /**
     * Get processing orders.
     */
    public function processingOrders(): Builder
    {
        return $this->ordersWithStatus(OrderStatus::PROCESSING);
    }

    /**
     * Get completed orders.
     */
    public function completedOrders(): Builder
    {
        return $this->ordersWithStatus(OrderStatus::COMPLETED);
    }

    /**
     * Get cancelled orders.
     */
    public function cancelledOrders(): Builder
    {
        return $this->ordersWithStatus(OrderStatus::CANCELLED);
    }

    /**
     * Get active orders (not in a final state).
     */
    public function activeOrders(): Builder
    {
        return Order::active();
    }

    /**
     * Get paid orders.
     */
    public function paidOrders(): Builder
    {
        return Order::paid();
    }

    /**
     * Get unpaid orders.
     */
    public function unpaidOrders(): Builder
    {
        return Order::unpaid();
    }

    // =========================================================================
    // REVENUE & STATISTICS
    // =========================================================================

    /**
     * Get total revenue (sum of amount_paid across all orders).
     * Returns value in cents.
     */
    public function totalRevenue(): int
    {
        return (int) Order::sum('amount_paid');
    }

    /**
     * Get revenue for today.
     * Returns value in cents.
     */
    public function revenueToday(): int
    {
        return (int) $this->ordersToday()->sum('amount_paid');
    }

    /**
     * Get revenue for this week.
     * Returns value in cents.
     */
    public function revenueThisWeek(): int
    {
        return (int) $this->ordersThisWeek()->sum('amount_paid');
    }

    /**
     * Get revenue for this month.
     * Returns value in cents.
     */
    public function revenueThisMonth(): int
    {
        return (int) $this->ordersThisMonth()->sum('amount_paid');
    }

    /**
     * Get revenue for this year.
     * Returns value in cents.
     */
    public function revenueThisYear(): int
    {
        return (int) $this->ordersThisYear()->sum('amount_paid');
    }

    /**
     * Get revenue between dates.
     * Returns value in cents.
     */
    public function revenueBetween(\DateTimeInterface $from, \DateTimeInterface $until): int
    {
        return (int) $this->ordersBetween($from, $until)->sum('amount_paid');
    }

    /**
     * Get total refunded amount.
     * Returns value in cents.
     */
    public function totalRefunded(): int
    {
        return (int) Order::sum('amount_refunded');
    }

    /**
     * Get net revenue (total revenue minus refunds).
     * Returns value in cents.
     */
    public function netRevenue(): int
    {
        return $this->totalRevenue() - $this->totalRefunded();
    }

    /**
     * Get average order value.
     * Returns value in cents.
     */
    public function averageOrderValue(): float
    {
        return (float) Order::avg('amount_total') ?? 0;
    }

    /**
     * Get shop statistics summary.
     * Optimized to use aggregated queries instead of individual counts.
     */
    public function stats(): array
    {
        // Aggregate product counts in single query
        $productStats = Product::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) as published,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
            SUM(CASE WHEN featured = 1 THEN 1 ELSE 0 END) as featured
        ")->first();

        // Aggregate order counts and revenue in single query
        $today = Carbon::today();
        $startOfWeek = Carbon::now()->startOfWeek();
        $endOfWeek = Carbon::now()->endOfWeek();
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();
        $startOfYear = Carbon::now()->startOfYear();
        $endOfYear = Carbon::now()->endOfYear();

        $orderStats = Order::selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN DATE(created_at) = ? THEN 1 ELSE 0 END) as today,
            SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as this_week,
            SUM(CASE WHEN created_at BETWEEN ? AND ? THEN 1 ELSE 0 END) as this_month,
            COALESCE(SUM(amount_paid), 0) as total_revenue,
            COALESCE(SUM(CASE WHEN DATE(created_at) = ? THEN amount_paid ELSE 0 END), 0) as revenue_today,
            COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN amount_paid ELSE 0 END), 0) as revenue_this_week,
            COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN amount_paid ELSE 0 END), 0) as revenue_this_month,
            COALESCE(SUM(CASE WHEN created_at BETWEEN ? AND ? THEN amount_paid ELSE 0 END), 0) as revenue_this_year,
            COALESCE(SUM(amount_refunded), 0) as total_refunded,
            COALESCE(AVG(amount_total), 0) as average_order
        ", [
            OrderStatus::PENDING->value,
            OrderStatus::PROCESSING->value,
            OrderStatus::COMPLETED->value,
            OrderStatus::CANCELLED->value,
            $today,
            $startOfWeek,
            $endOfWeek,
            $startOfMonth,
            $endOfMonth,
            $today,
            $startOfWeek,
            $endOfWeek,
            $startOfMonth,
            $endOfMonth,
            $startOfYear,
            $endOfYear,
        ])->first();

        // Aggregate cart counts in single query
        $cartStats = Cart::selectRaw("
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN status = 'abandoned' THEN 1 ELSE 0 END) as abandoned,
            SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
            SUM(CASE WHEN converted_at IS NOT NULL THEN 1 ELSE 0 END) as converted
        ")->first();

        $totalRevenue = (int) $orderStats->total_revenue;
        $totalRefunded = (int) $orderStats->total_refunded;

        return [
            'products' => [
                'total' => (int) $productStats->total,
                'published' => (int) $productStats->published,
                'draft' => (int) $productStats->draft,
                'featured' => (int) $productStats->featured,
            ],
            'orders' => [
                'total' => (int) $orderStats->total,
                'pending' => (int) $orderStats->pending,
                'processing' => (int) $orderStats->processing,
                'completed' => (int) $orderStats->completed,
                'cancelled' => (int) $orderStats->cancelled,
                'today' => (int) $orderStats->today,
                'this_week' => (int) $orderStats->this_week,
                'this_month' => (int) $orderStats->this_month,
            ],
            'revenue' => [
                'total' => $totalRevenue,
                'today' => (int) $orderStats->revenue_today,
                'this_week' => (int) $orderStats->revenue_this_week,
                'this_month' => (int) $orderStats->revenue_this_month,
                'this_year' => (int) $orderStats->revenue_this_year,
                'refunded' => $totalRefunded,
                'net' => $totalRevenue - $totalRefunded,
                'average_order' => (float) $orderStats->average_order,
            ],
            'carts' => [
                'active' => (int) $cartStats->active,
                'abandoned' => (int) $cartStats->abandoned,
                'expired' => (int) $cartStats->expired,
                'converted' => (int) $cartStats->converted,
            ],
            'categories' => [
                'total' => ProductCategory::count(),
            ],
        ];
    }

    /**
     * Get revenue grouped by day for a date range.
     */
    public function revenueByDay(\DateTimeInterface $from, \DateTimeInterface $until): \Illuminate\Support\Collection
    {
        return Order::whereBetween('created_at', [$from, $until])
            ->selectRaw('DATE(created_at) as date, SUM(amount_paid) as revenue, COUNT(*) as orders')
            ->groupBy('date')
            ->orderBy('date')
            ->get();
    }

    /**
     * Get revenue grouped by month for a date range.
     */
    public function revenueByMonth(\DateTimeInterface $from, \DateTimeInterface $until): \Illuminate\Support\Collection
    {
        return Order::whereBetween('created_at', [$from, $until])
            ->selectRaw('YEAR(created_at) as year, MONTH(created_at) as month, SUM(amount_paid) as revenue, COUNT(*) as orders')
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get();
    }

    /**
     * Get top selling products.
     */
    public function topProducts(int $limit = 10): Collection
    {
        return Product::withCount('purchases')
            ->orderByDesc('purchases_count')
            ->limit($limit)
            ->get();
    }

    // =========================================================================
    // CART QUERIES
    // =========================================================================

    /**
     * Get all carts query builder.
     */
    public function carts(): Builder
    {
        return Cart::query();
    }

    /**
     * Get active carts.
     */
    public function activeCarts(): Builder
    {
        return Cart::where('status', 'active');
    }

    /**
     * Get abandoned carts.
     */
    public function abandonedCarts(): Builder
    {
        return Cart::where('status', 'abandoned');
    }

    /**
     * Get expired carts.
     */
    public function expiredCarts(): Builder
    {
        return Cart::where('status', 'expired');
    }

    /**
     * Get carts that should be marked as expired (inactive for more than 1 hour).
     */
    public function cartsToExpire(): Builder
    {
        $expirationMinutes = config('shop.cart.expiration_minutes', 60);

        return Cart::where('status', 'active')
            ->where(function ($query) use ($expirationMinutes) {
                $query->where('last_activity_at', '<', Carbon::now()->subMinutes($expirationMinutes))
                    ->orWhere(function ($q) use ($expirationMinutes) {
                        $q->whereNull('last_activity_at')
                            ->where('updated_at', '<', Carbon::now()->subMinutes($expirationMinutes));
                    });
            });
    }

    /**
     * Get carts that should be deleted (unused for more than 24 hours).
     */
    public function cartsToDelete(): Builder
    {
        $deletionHours = config('shop.cart.deletion_hours', 24);

        return Cart::where('status', '!=', 'converted')
            ->whereNull('converted_at')
            ->where(function ($query) use ($deletionHours) {
                $query->where('last_activity_at', '<', Carbon::now()->subHours($deletionHours))
                    ->orWhere(function ($q) use ($deletionHours) {
                        $q->whereNull('last_activity_at')
                            ->where('updated_at', '<', Carbon::now()->subHours($deletionHours));
                    });
            });
    }

    /**
     * Expire stale carts (inactive for more than 1 hour).
     * Returns the number of carts expired.
     */
    public function expireStaleCarts(): int
    {
        return $this->cartsToExpire()->update([
            'status' => 'expired',
        ]);
    }

    /**
     * Delete old unused carts (unused for more than 24 hours).
     * Returns the number of carts deleted.
     */
    public function deleteOldCarts(): int
    {
        // Get cart IDs to delete
        $cartIds = $this->cartsToDelete()->pluck('id')->toArray();

        if (empty($cartIds)) {
            return 0;
        }

        // Delete cart items in batch first (foreign key constraint)
        CartItem::whereIn('cart_id', $cartIds)->delete();

        // Delete carts in batch
        return Cart::whereIn('id', $cartIds)->delete();
    }

    // =========================================================================
    // CONFIGURATION HELPERS
    // =========================================================================

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

    /**
     * Format money amount (from cents to display format).
     */
    public function formatMoney(int $cents, ?string $currency = null): string
    {
        $currency = $currency ?? $this->currency();
        $amount = $cents / 100;

        return number_format($amount, 2) . ' ' . strtoupper($currency);
    }
}
