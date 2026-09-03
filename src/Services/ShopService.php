<?php

declare(strict_types=1);

namespace Blax\Shop\Services;

use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Enums\RecurringInterval;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Models\Order;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductCategory;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Models\StripeTransaction;
use Blax\Shop\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Carbon\Carbon;

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
    // SUBSCRIPTION REVENUE (MRR)
    // =========================================================================

    /**
     * Subscription statuses that count as a live, revenue-bearing subscriber.
     *
     * Mirrors Stripe's "active subscribers" metric: a subscription still
     * billing counts even when it is set to cancel at period end (status stays
     * `active` with a future `ends_at`) or is retrying payment (`past_due`).
     * Filtering on `ends_at IS NULL` — a common mistake — silently drops
     * paying, cancel-at-period-end subscribers and under-reports MRR.
     * Override via `config('shop.metrics.subscription_statuses')`.
     *
     * @return array<int, string>
     */
    public function subscriberStatuses(): array
    {
        return config('shop.metrics.subscription_statuses', ['active', 'past_due', 'trialing']);
    }

    /**
     * Base query for the subscriptions counted in subscriber/MRR metrics.
     *
     * @return Builder<Subscription>
     */
    protected function metricSubscriptions(): Builder
    {
        $model = config('shop.models.subscription', Subscription::class);

        return $model::query()->whereIn('stripe_status', $this->subscriberStatuses());
    }

    /**
     * Number of live subscribers (see {@see subscriberStatuses()}).
     */
    public function activeSubscribers(): int
    {
        return $this->metricSubscriptions()->count();
    }

    /**
     * Monthly Recurring Revenue in cents.
     *
     * Every live subscription's line items, each normalized to a monthly
     * run-rate from its price interval (year ÷ 12, quarter ÷ 3, week/day by the
     * average-days-per-month constant so weekly/daily match Stripe's own MRR
     * normalization). Computed from the mirrored {@see ProductPrice} rows — no
     * Stripe API call — so the total is only as accurate as the mirror:
     * a `product_prices.unit_amount` that has drifted from the live Stripe
     * price will skew it, and a line item whose price is not mirrored at all is
     * excluded. Use {@see subscriptionMetrics()} to see that excluded count
     * (`unpriced_items`) rather than trusting a silent number, and keep the
     * mirror synced from Stripe for an exact figure.
     */
    public function mrr(): int
    {
        return $this->subscriptionMetrics()['mrr'];
    }

    /**
     * MRR split by product slug, in cents, highest first.
     *
     * @return \Illuminate\Support\Collection<string, int>
     */
    public function mrrByProduct(): \Illuminate\Support\Collection
    {
        return collect($this->subscriptionMetrics()['by_product']);
    }

    /**
     * Every subscription metric in a single pass over the live subscriptions:
     * subscriber count, MRR (cents), MRR by product slug, and the number of
     * line items whose price could not be resolved from the mirror — so an
     * under-count is surfaced, never silent.
     *
     * @return array{subscribers: int, mrr: int, by_product: array<string, int>, unpriced_items: int}
     */
    public function subscriptionMetrics(): array
    {
        $subscriptions = $this->metricSubscriptions()->with(['items', 'product'])->get();

        // Resolve every referenced price in one query, keyed by Stripe price id.
        $priceModel = config('shop.models.product_price', ProductPrice::class);
        $priceIds = $subscriptions
            ->flatMap(fn ($sub) => $sub->items->pluck('stripe_price'))
            ->filter()
            ->unique()
            ->values();
        $prices = $priceModel::query()
            ->whereIn('stripe_price_id', $priceIds->all())
            ->with('purchasable')
            ->get()
            ->keyBy('stripe_price_id');

        $mrr = 0.0;
        $cogs = 0.0;
        $license = 0.0;
        $byProduct = [];
        $unpricedItems = 0;

        foreach ($subscriptions as $subscription) {
            foreach ($subscription->items as $item) {
                $price = $prices->get($item->stripe_price);

                if ($price === null) {
                    $unpricedItems++;

                    continue;
                }

                $quantity = (int) ($item->quantity ?? 1);
                $monthly = $this->priceMonthlyAmount($price, $quantity);

                if ($monthly === null) {
                    // Price resolved but is not recurring (a one-time price on a
                    // subscription line) — carries no MRR, and is not a data gap.
                    continue;
                }

                $mrr += $monthly;
                $cogs += $this->priceMonthlyCost($price, $quantity) ?? 0;
                $license += $this->priceMonthlyLicense($price, $quantity) ?? 0;
                $slug = $this->priceProductSlug($price)
                    ?? $this->subscriptionProductSlug($subscription)
                    ?? 'unknown';
                $byProduct[$slug] = ($byProduct[$slug] ?? 0) + $monthly;
            }
        }

        arsort($byProduct);

        return [
            'subscribers' => $subscriptions->count(),
            'mrr' => (int) round($mrr),
            'cogs' => (int) round($cogs),
            'license_cost' => (int) round($license),
            'net_mrr' => (int) round($mrr - $cogs - $license),
            'by_product' => array_map(static fn ($cents) => (int) round($cents), $byProduct),
            'unpriced_items' => $unpricedItems,
        ];
    }

    /**
     * Net Monthly Recurring Revenue in cents: {@see mrr()} minus recurring cost
     * of goods (`cost_amount`) minus the amortized royalty / minimum licence fee
     * ({@see priceMonthlyLicense()}). The recurring contribution margin — MRR
     * after everything it costs to deliver the subscription.
     */
    public function netMrr(): int
    {
        return $this->subscriptionMetrics()['net_mrr'];
    }

    /**
     * Normalize a recurring price's `unit_amount` to a monthly run-rate in cents
     * for `$quantity` units. Null for a non-recurring (one-time) price.
     */
    protected function priceMonthlyAmount(ProductPrice $price, int $quantity = 1): ?int
    {
        return $this->monthlyForPrice($price, (float) $price->unit_amount, $quantity);
    }

    /**
     * Normalize a recurring price's `cost_amount` (COGS — license/shipping/etc.)
     * to a monthly figure in cents for `$quantity` units. Null when the price is
     * one-time; 0 when it carries no cost basis.
     */
    protected function priceMonthlyCost(ProductPrice $price, int $quantity = 1): ?int
    {
        return $this->monthlyForPrice($price, (float) ($price->cost_amount ?? 0), $quantity);
    }

    /**
     * Shared interval normalization: turn `$amountCents` charged/incurred once
     * per the price's billing period into a monthly run-rate for `$quantity`
     * units. Weekly and daily use the Gregorian average-days-per-month constant
     * so they line up with Cashier/Stripe's MRR normalization. Returns null for
     * a non-recurring (one-time) price.
     */
    protected function monthlyForPrice(ProductPrice $price, float $amountCents, int $quantity = 1): ?int
    {
        $interval = $price->interval instanceof RecurringInterval
            ? $price->interval->value
            : (is_string($price->interval) ? $price->interval : null);

        if ($interval === null) {
            return null; // one-time price — not recurring
        }

        $amount = $amountCents * max(1, $quantity);
        $count = max(1, (int) ($price->interval_count ?: 1));
        $daysPerMonth = 30.436875; // Gregorian average month length

        $monthly = match ($interval) {
            'year' => $amount / (12 * $count),
            'quarter' => $amount / (3 * $count),
            'month' => $amount / $count,
            'week' => $amount / ($count * 7 / $daysPerMonth),
            'day' => $amount / ($count / $daysPerMonth),
            default => null,
        };

        return $monthly === null ? null : (int) round($monthly);
    }

    /**
     * Monthly run-rate (cents) of the royalty / minimum licence fee for a
     * recurring price. The fee ({@see ProductPrice::licenseFeePerPeriod()}) is
     * owed once per licence-term bracket — 1–3 months, 6 months, or 12 months —
     * so it is amortized over that bracket, NOT per billing cycle. That is why a
     * monthly subscriber renewing three times in a quarter still costs one
     * quarterly fee: a €25.50/mo Full Seat with a €15 minimum contributes €15 ÷
     * 3 = €5/month. Null for a non-recurring price; 0 when there is no rule.
     */
    protected function priceMonthlyLicense(ProductPrice $price, int $quantity = 1): ?int
    {
        $interval = $price->interval instanceof RecurringInterval
            ? $price->interval->value
            : (is_string($price->interval) ? $price->interval : null);

        if ($interval === null) {
            return null; // one-time price — subscription licence run-rate N/A
        }

        $perPeriod = $price->licenseFeePerPeriod();

        if ($perPeriod <= 0) {
            return 0;
        }

        return (int) round($perPeriod * max(1, $quantity) / $this->licenseBracketMonths($price));
    }

    /**
     * The licence-term bracket a price falls in, in months: its term (interval ×
     * interval_count) snapped to 3 (the ≤ quarter accounting bracket), 6, or 12.
     * The quarterly accounting period is the smallest bracket, so any
     * sub-quarterly billing (monthly/weekly) amortizes over 3 months.
     */
    protected function licenseBracketMonths(ProductPrice $price): int
    {
        $interval = $price->interval instanceof RecurringInterval
            ? $price->interval->value
            : (is_string($price->interval) ? $price->interval : null);

        $count = max(1, (int) ($price->interval_count ?: 1));
        $daysPerMonth = 30.436875;

        $termMonths = match ($interval) {
            'year' => 12 * $count,
            'quarter' => 3 * $count,
            'month' => $count,
            'week' => $count * 7 / $daysPerMonth,
            'day' => $count / $daysPerMonth,
            default => 1,
        };

        return $termMonths <= 3 ? 3 : ($termMonths <= 6 ? 6 : 12);
    }

    /**
     * Product slug carried by a price's purchasable (usually a Product), or
     * null when the purchasable is absent or has no slug/name.
     */
    protected function priceProductSlug(ProductPrice $price): ?string
    {
        $purchasable = $price->purchasable;

        if (! is_object($purchasable)) {
            return null;
        }

        return $purchasable->slug ?? $purchasable->name ?? null;
    }

    /**
     * Product slug for the subscription's linked product, or null.
     */
    protected function subscriptionProductSlug(Subscription $subscription): ?string
    {
        $product = $subscription->product;

        if (! is_object($product)) {
            return null;
        }

        return $product->slug ?? $product->name ?? null;
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

    // =========================================================================
    // STRIPE LEDGER (net-of-fees, user-independent)
    // =========================================================================

    /**
     * The configured {@see StripeTransaction} model class.
     *
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     */
    protected function ledgerModel(): string
    {
        return config('shop.models.stripe_transaction', StripeTransaction::class);
    }

    /**
     * BalanceTransaction `type`s that count as revenue (net of fees/refunds).
     *
     * @return array<int, string>
     */
    public function ledgerRevenueTypes(): array
    {
        return config('shop.ledger.revenue_types', [
            'charge', 'payment', 'refund', 'payment_refund', 'adjustment', 'dispute', 'dispute_reversal',
        ]);
    }

    /**
     * The earliest ledger row's Stripe `created` timestamp — the "start" of the
     * business's money history, for an all-time cumulative view. Null when empty.
     */
    public function ledgerEarliest(): ?Carbon
    {
        $min = $this->ledgerModel()::query()->min('created');

        return $min ? Carbon::parse($min) : null;
    }

    /**
     * Revenue-bearing ledger movements grouped by Stripe day, in cents.
     *
     * One row per day with signed `gross` (SUM amount), `fee` (SUM fee) and
     * `net` (SUM net = the money kept after fees and refunds) plus the row
     * `count`. Refund/dispute days push `gross`/`net` down. Feed the `net`
     * column into a cumulative sum for a break-even balance line.
     *
     * @return \Illuminate\Support\Collection<int, object{date: string, gross: int, fee: int, net: int, count: int}>
     */
    public function revenueLedgerByDay(\DateTimeInterface $from, \DateTimeInterface $until): \Illuminate\Support\Collection
    {
        return $this->ledgerModel()::query()
            ->whereBetween('created', [$from, $until])
            ->whereIn('source_type', $this->ledgerRevenueTypes())
            ->selectRaw('DATE(created) as date, SUM(amount) as gross, SUM(fee) as fee, SUM(net) as net, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => (object) [
                'date' => (string) $r->date,
                'gross' => (int) $r->gross,
                'fee' => (int) $r->fee,
                'net' => (int) $r->net,
                'count' => (int) $r->count,
            ]);
    }

    /**
     * Window totals over the revenue-bearing ledger, in cents: gross sales
     * (positive charge lines), the negative refund total, Stripe fees, and the
     * resulting net collected. `count` is the number of ledger rows in range.
     *
     * @return array{gross: int, refunds: int, fees: int, net: int, count: int}
     */
    public function ledgerTotals(\DateTimeInterface $from, \DateTimeInterface $until): array
    {
        // CASE WHEN (not GREATEST/LEAST) so the split is portable to SQLite tests.
        $row = $this->ledgerModel()::query()
            ->whereBetween('created', [$from, $until])
            ->whereIn('source_type', $this->ledgerRevenueTypes())
            ->selectRaw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as gross')
            ->selectRaw('SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END) as refunds')
            ->selectRaw('SUM(fee) as fees')
            ->selectRaw('SUM(net) as net')
            ->selectRaw('COUNT(*) as count')
            ->first();

        return [
            'gross' => (int) ($row->gross ?? 0),
            'refunds' => (int) ($row->refunds ?? 0),
            'fees' => (int) ($row->fees ?? 0),
            'net' => (int) ($row->net ?? 0),
            'count' => (int) ($row->count ?? 0),
        ];
    }
}
