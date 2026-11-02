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
use Blax\Shop\Models\StripeTransaction;
use Blax\Shop\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Stripe\Charge;
use Stripe\Stripe;

class ShopService
{
    /** Charge VAT per charge id / `pi:` payment intent, for one process. */
    protected array $ledgerVatCache = [];

    /** Retrieved invoices and Checkout Sessions, for one process. */
    protected array $ledgerStripeCache = [];

    protected bool $ledgerTaxColumn = false;

    // =========================================================================
    // PRODUCT QUERIES
    // =========================================================================

    /**
     * Get all products query builder
     */
    public function products(): Builder
    {
        return Product::query();
    }

    /**
     * Get a product by ID
     *
     * @param  mixed  $id
     */
    public function product($id): ?Product
    {
        return Product::find($id);
    }

    /**
     * Get all categories query builder
     */
    public function categories(): Builder
    {
        return ProductCategory::query();
    }

    /**
     * Get in-stock products
     */
    public function inStock(): Builder
    {
        return Product::inStock();
    }

    /**
     * Get featured products
     */
    public function featured(): Builder
    {
        return Product::featured();
    }

    /**
     * Get published and visible products
     */
    public function published(): Builder
    {
        return Product::published()->visible();
    }

    /**
     * Search products by query
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
     */
    public function checkStock(Product $product, int $quantity): bool
    {
        if (! $product->manage_stock) {
            return true;
        }

        return $product->getAvailableStock() >= $quantity;
    }

    /**
     * Get available stock for a product
     */
    public function getAvailableStock(Product $product): int
    {
        if (! $product->manage_stock) {
            return PHP_INT_MAX;
        }

        return $product->getAvailableStock();
    }

    /**
     * Check if product is on sale
     */
    public function isOnSale(Product $product): bool
    {
        return $product->isOnSale();
    }

    // =========================================================================
    // ORDER QUERIES
    // =========================================================================

    /**
     * The configured {@see Order} model class (`shop.models.order`).
     *
     * All order queries below resolve it instead of using `Order::` statically,
     * so a host subclass is what every finder returns.
     *
     * @return class-string<Order>
     */
    protected function orderModel(): string
    {
        return config('shop.models.order', Order::class);
    }

    /**
     * Get all orders query builder.
     */
    public function orders(): Builder
    {
        return $this->orderModel()::query();
    }

    /**
     * Get an order by ID.
     */
    public function order(string $id): ?Order
    {
        return $this->orderModel()::find($id);
    }

    /**
     * Get an order by order number.
     */
    public function orderByNumber(string $orderNumber): ?Order
    {
        return $this->orderModel()::where('order_number', $orderNumber)->first();
    }

    /**
     * Get orders created today.
     */
    public function ordersToday(): Builder
    {
        return $this->orderModel()::whereDate('created_at', Carbon::today());
    }

    /**
     * Get orders created this week.
     */
    public function ordersThisWeek(): Builder
    {
        return $this->orderModel()::whereBetween('created_at', [
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek(),
        ]);
    }

    /**
     * Get orders created this month.
     */
    public function ordersThisMonth(): Builder
    {
        return $this->orderModel()::whereBetween('created_at', [
            Carbon::now()->startOfMonth(),
            Carbon::now()->endOfMonth(),
        ]);
    }

    /**
     * Get orders created this year.
     */
    public function ordersThisYear(): Builder
    {
        return $this->orderModel()::whereBetween('created_at', [
            Carbon::now()->startOfYear(),
            Carbon::now()->endOfYear(),
        ]);
    }

    /**
     * Get orders within a specific date range.
     */
    public function ordersBetween(\DateTimeInterface $from, \DateTimeInterface $until): Builder
    {
        return $this->orderModel()::whereBetween('created_at', [$from, $until]);
    }

    /**
     * Get orders with a specific status.
     */
    public function ordersWithStatus(OrderStatus $status): Builder
    {
        return $this->orderModel()::where('status', $status->value);
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
        return $this->orderModel()::active();
    }

    /**
     * Get paid orders.
     */
    public function paidOrders(): Builder
    {
        return $this->orderModel()::paid();
    }

    /**
     * Get unpaid orders.
     */
    public function unpaidOrders(): Builder
    {
        return $this->orderModel()::unpaid();
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
        return (int) $this->orderModel()::sum('amount_paid');
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
        return (int) $this->orderModel()::sum('amount_refunded');
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
        return (float) $this->orderModel()::avg('amount_total') ?? 0;
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

        $orderStats = $this->orderModel()::selectRaw('
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
        ', [
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
        return $this->orderModel()::whereBetween('created_at', [$from, $until])
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
        return $this->orderModel()::whereBetween('created_at', [$from, $until])
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
     * @param  mixed  $default
     * @return mixed
     */
    public function config(string $key, $default = null)
    {
        return config("shop.{$key}", $default);
    }

    /**
     * Get default shop currency
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

        return number_format($amount, 2).' '.strtoupper($currency);
    }

    // =========================================================================
    // STRIPE LEDGER (net-of-fees, user-independent)
    // =========================================================================

    /**
     * The configured {@see StripeTransaction} model class.
     *
     * @return class-string<Model>
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
            'payment_failure_refund', 'refund_failure', 'stripe_fee', 'stripe_fx_fee', 'tax_fee', 'paypal_fee',
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
     * column into a cumulative sum for a break-even balance line. `tax` is the
     * signed VAT inside `gross` (rows with unknown VAT count as 0), so
     * `net - tax` is the day's money kept without VAT.
     *
     * @return \Illuminate\Support\Collection<int, object{date: string, gross: int, fee: int, net: int, count: int, tax: int}>
     */
    public function revenueLedgerByDay(\DateTimeInterface $from, \DateTimeInterface $until): \Illuminate\Support\Collection
    {
        return $this->ledgerModel()::query()
            ->whereBetween('created', [$from, $until])
            ->whereIn('source_type', $this->ledgerRevenueTypes())
            ->selectRaw('DATE(created) as date, SUM(amount) as gross, SUM(fee) as fee, SUM(net) as net, COUNT(*) as count')
            ->selectRaw($this->ledgerTaxSumSql())
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn ($r) => (object) [
                'date' => (string) $r->date,
                'gross' => (int) $r->gross,
                'fee' => (int) $r->fee,
                'net' => (int) $r->net,
                'count' => (int) $r->count,
                'tax' => (int) $r->tax,
            ]);
    }

    /**
     * Window totals over the revenue-bearing ledger, in cents: gross sales
     * (positive charge lines), the negative refund total, Stripe fees, and the
     * resulting net collected. `count` is the number of ledger rows in range.
     * `tax` is the signed VAT inside those rows (charges add, refunds take
     * their share back; unknown counts as 0), so `net - tax` is the money kept
     * without VAT.
     *
     * @return array{gross: int, refunds: int, fees: int, net: int, count: int, tax: int}
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
            ->selectRaw($this->ledgerTaxSumSql())
            ->first();

        return [
            'gross' => (int) ($row->gross ?? 0),
            'refunds' => (int) ($row->refunds ?? 0),
            'fees' => (int) ($row->fees ?? 0),
            'net' => (int) ($row->net ?? 0),
            'count' => (int) ($row->count ?? 0),
            'tax' => (int) ($row->tax ?? 0),
        ];
    }

    /**
     * {@see ledgerTotals} split per account the money moved through: one entry
     * per `provider` + `account` (Stripe, each PayPal account). The entries sum
     * to ledgerTotals. Before the provider columns are migrated everything is
     * reported as the one Stripe account.
     *
     * @return \Illuminate\Support\Collection<int, array{provider: string, account: string|null, gross: int, refunds: int, fees: int, net: int, count: int, tax: int}>
     */
    public function ledgerTotalsByAccount(\DateTimeInterface $from, \DateTimeInterface $until): \Illuminate\Support\Collection
    {
        $model = $this->ledgerModel();
        $table = (new $model)->getTable();

        if (! \Illuminate\Support\Facades\Schema::hasColumn($table, 'provider')) {
            return collect([['provider' => 'stripe', 'account' => null] + $this->ledgerTotals($from, $until)]);
        }

        return $model::query()
            ->whereBetween('created', [$from, $until])
            ->whereIn('source_type', $this->ledgerRevenueTypes())
            ->groupBy('provider', 'account')
            ->select('provider', 'account')
            ->selectRaw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as gross')
            ->selectRaw('SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END) as refunds')
            ->selectRaw('SUM(fee) as fees')
            ->selectRaw('SUM(net) as net')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw($this->ledgerTaxSumSql())
            ->orderBy('provider')
            ->get()
            ->map(fn ($r) => [
                'provider' => (string) $r->provider,
                'account' => $r->account,
                'gross' => (int) $r->gross,
                'refunds' => (int) $r->refunds,
                'fees' => (int) $r->fees,
                'net' => (int) $r->net,
                'count' => (int) $r->count,
                'tax' => (int) $r->tax,
            ])
            ->values();
    }

    /**
     * Money one buyer actually paid us, attributed from the user-independent
     * ledger by ANY of their Stripe customer id(s) OR buyer email(s) — so a
     * customer whose local user was deleted, or who paid as a guest before
     * signing up, is still attributed. Returns the signed split in cents.
     *
     * `amount` = SUM(amount) over revenue-type rows = gross charges minus
     * refunds: the euros we kept, before Stripe's per-charge fee. This is the
     * figure that catches subscription payments the per-user product_purchases
     * sum misses (they are stored there as amount=0). `tax` is the signed VAT
     * inside `amount` (unknown counts as 0).
     *
     * @param  string|array<int,string>  $customerIds  Stripe customer id(s) (cus_…)
     * @param  string|array<int,string>  $emails  buyer email(s)
     * @return array{amount: int, gross: int, refunds: int, fees: int, net: int, count: int, tax: int}
     */
    public function customerLedgerTotals(
        string|array $customerIds = [],
        string|array $emails = [],
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $until = null,
    ): array {
        $customerIds = array_values(array_filter((array) $customerIds, static fn ($v) => is_string($v) && $v !== ''));
        $emails = array_values(array_filter((array) $emails, static fn ($v) => is_string($v) && $v !== ''));

        $zero = ['amount' => 0, 'gross' => 0, 'refunds' => 0, 'fees' => 0, 'net' => 0, 'count' => 0, 'tax' => 0];

        // No identity to match on → nothing attributable. Guard so an empty
        // whereIn can never degrade into a match-everything.
        if (! $customerIds && ! $emails) {
            return $zero;
        }

        $row = $this->ledgerModel()::query()
            ->whereIn('source_type', $this->ledgerRevenueTypes())
            ->where(function ($q) use ($customerIds, $emails) {
                if ($customerIds) {
                    $q->orWhereIn('customer_id', $customerIds);
                }
                if ($emails) {
                    $q->orWhereIn('customer_email', $emails);
                }
            })
            ->when($from, fn ($q) => $q->where('created', '>=', $from))
            ->when($until, fn ($q) => $q->where('created', '<=', $until))
            ->selectRaw('SUM(amount) as amount')
            ->selectRaw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as gross')
            ->selectRaw('SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END) as refunds')
            ->selectRaw('SUM(fee) as fees')
            ->selectRaw('SUM(net) as net')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw($this->ledgerTaxSumSql())
            ->first();

        return [
            'amount' => (int) ($row->amount ?? 0),
            'gross' => (int) ($row->gross ?? 0),
            'refunds' => (int) ($row->refunds ?? 0),
            'fees' => (int) ($row->fees ?? 0),
            'net' => (int) ($row->net ?? 0),
            'count' => (int) ($row->count ?? 0),
            'tax' => (int) ($row->tax ?? 0),
        ];
    }

    /**
     * Money received (net of refunds) grouped by Stripe customer id, in cents:
     * `[customer_id => amount]` over the revenue-bearing ledger. The caller maps
     * customer ids to local users (e.g. users.stripe_id) to attribute revenue
     * per user in one pass. Rows with no customer id (a guest charge carrying
     * only an email) are omitted here — attribute those by email via
     * {@see customerLedgerTotals}.
     *
     * @return \Illuminate\Support\Collection<string, int>
     */
    public function ledgerAmountByCustomer(
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $until = null,
    ): \Illuminate\Support\Collection {
        return $this->ledgerModel()::query()
            ->whereIn('source_type', $this->ledgerRevenueTypes())
            ->whereNotNull('customer_id')
            ->when($from, fn ($q) => $q->where('created', '>=', $from))
            ->when($until, fn ($q) => $q->where('created', '<=', $until))
            ->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(amount) as amount')
            ->pluck('amount', 'customer_id')
            ->map(fn ($v) => (int) $v);
    }

    /**
     * Flatten a Stripe BalanceTransaction into a ledger row. Pass an explicit
     * `$source` (the Charge/Refund already retrieved for a webhook) to attribute
     * the buyer; otherwise the transaction's own expanded `source` is used (the
     * importer path). A refund's buyer is read from its underlying charge so
     * refunds net against the right customer.
     *
     * Once the ledger has its `tax` column the row also carries `tax`, the VAT
     * inside `amount` ({@see ledgerRowTax}); it is looked up only when
     * `$withTax` is true, else null.
     *
     * @return array<string, mixed>
     */
    public function ledgerRowFromBalanceTransaction($txn, $source = null, bool $withTax = true): array
    {
        [$source, $sourceId] = $this->ledgerSourceOf($txn, $source);

        $chargeSource = is_object($source->charge ?? null) ? $source->charge : null;

        $customerId = null;
        $customerEmail = null;
        foreach ([$source, $chargeSource] as $s) {
            if (! is_object($s)) {
                continue;
            }
            $customerId = $customerId ?? ($s->customer ?? null);
            $customerEmail = $customerEmail
                ?? ($s->billing_details->email ?? null)
                ?? ($s->receipt_email ?? null)
                ?? ($s->customer_email ?? null);
        }

        $amount = (int) ($txn->amount ?? 0);
        $fee = (int) ($txn->fee ?? 0);
        $net = (int) ($txn->net ?? 0);
        $currency = $txn->currency ?? null;
        $meta = [
            'status' => $txn->status ?? null,
            'fee_details' => isset($txn->fee_details) ? json_decode(json_encode($txn->fee_details), true) : null,
        ];

        // The PaymentIntent links this row to the same payment in another
        // account's ledger (PayPal writes it into its custom field).
        $paymentIntent = $source->payment_intent ?? $chargeSource->payment_intent ?? null;
        if (is_object($paymentIntent)) {
            $paymentIntent = $paymentIntent->id ?? null;
        }
        if (is_string($paymentIntent) && $paymentIntent !== '') {
            $meta['payment_intent'] = $paymentIntent;
        }

        if ($external = $this->externallySettledAmount($txn, $source)) {
            $meta['settled_outside_stripe'] = true;
            $meta['stripe_amount'] = $amount;
            $meta['stripe_net'] = $net;
            [$amount, $currency] = [$external['amount'], $external['currency'] ?? $currency];
            $net = $amount - $fee;
        }

        // Stripe books its account-level fees (Billing, Tax, FX) as a negative
        // amount. Store them as a fee with amount 0, so they lower net but are
        // not mistaken for refunds in the gross/refunds split.
        if (in_array($txn->type ?? null, ['stripe_fee', 'stripe_fx_fee', 'tax_fee'], true) && $amount !== 0) {
            $meta['stripe_amount'] = $amount;
            $fee -= $amount;
            $amount = 0;
            $net = -$fee;
        }

        $row = [
            'stripe_id' => $txn->id,
            'source_type' => $txn->type ?? null,
            'reporting_category' => $txn->reporting_category ?? null,
            'source_id' => $sourceId,
            'amount' => $amount,
            'fee' => $fee,
            'net' => $net,
            'currency' => $currency,
            'customer_id' => is_string($customerId) ? $customerId : null,
            'customer_email' => is_string($customerEmail) ? $customerEmail : null,
            'description' => $txn->description ?? null,
            'created' => isset($txn->created) ? Carbon::createFromTimestamp($txn->created) : null,
            'available_on' => isset($txn->available_on) ? Carbon::createFromTimestamp($txn->available_on) : null,
            'meta' => $meta,
        ];

        // Only once the column exists, so the row still saves before the migration.
        if ($this->ledgerHasTaxColumn()) {
            $row['tax'] = null;
            if ($withTax) {
                [$row['tax'], $taxSource] = $this->ledgerRowTax($row['source_type'], $source, $sourceId, $amount, $meta['payment_intent'] ?? null);
                if ($taxSource !== null) {
                    $row['meta']['tax_source'] = $taxSource;
                }
            }
        }

        return $row;
    }

    /**
     * The object behind a balance transaction and its id: the explicit
     * `$source`, else the transaction's own (expanded or id-only) `source`.
     *
     * @return array{0: object|null, 1: string|null}
     */
    protected function ledgerSourceOf($txn, $source): array
    {
        $source = is_object($source) ? $source : (is_object($txn->source ?? null) ? $txn->source : null);
        $sourceId = $source?->id ?? (is_string($txn->source ?? null) ? $txn->source : null);

        return [$source, is_string($sourceId) ? $sourceId : null];
    }

    /**
     * Signed gross for a payment or refund that settled outside the Stripe
     * balance, or null when Stripe's own `amount` already holds it.
     *
     * PayPal pays into the merchant's PayPal balance, so its balance transaction
     * carries amount 0 and only Stripe's fee. The buyer still paid the charge's
     * full amount, so read it off the charge (or refund) instead. `$source` is
     * whatever the caller resolved: the expanded charge/refund from the importer,
     * or the charge the webhook retrieved (for a refund, the matching refund is
     * looked up on it by balance transaction id).
     *
     * @return array{amount: int, currency: string|null}|null
     */
    protected function externallySettledAmount($txn, $source): ?array
    {
        if ((int) ($txn->amount ?? 0) !== 0 || ! is_object($source)) {
            return null;
        }

        $kind = $source->object ?? null;
        $type = $txn->type ?? null;

        if (in_array($type, ['charge', 'payment'], true) && $kind === 'charge') {
            $gross = (int) ($source->amount_captured ?? $source->amount ?? 0);

            return $gross > 0 ? ['amount' => $gross, 'currency' => $source->currency ?? null] : null;
        }

        if (! in_array($type, ['refund', 'payment_refund'], true)) {
            return null;
        }

        $refund = $kind === 'refund' ? $source : null;
        if ($kind === 'charge') {
            foreach (($source->refunds->data ?? []) as $r) {
                $btId = is_object($r->balance_transaction ?? null) ? $r->balance_transaction->id : ($r->balance_transaction ?? null);
                if ($btId === $txn->id) {
                    $refund = $r;
                    break;
                }
            }
        }

        $refunded = (int) ($refund->amount ?? 0);

        return $refunded > 0 ? ['amount' => -$refunded, 'currency' => $refund->currency ?? null] : null;
    }

    /**
     * Idempotently upsert one BalanceTransaction into the ledger (key = `txn_`
     * id). Never downgrades a known customer_id/email back to null — so the
     * real-time webhook, which resolves the buyer, is not clobbered by a later
     * importer pass that couldn't. Returns 'created' or 'updated'.
     *
     * The VAT (`tax`) is looked up once: a row whose VAT is already known for
     * the same amount keeps it without a Stripe call, and a failed lookup never
     * replaces a known value.
     */
    public function recordBalanceTransaction($txn, $source = null): string
    {
        $row = $this->ledgerRowFromBalanceTransaction($txn, $source, false);
        $existing = $this->ledgerModel()::query()->where('stripe_id', $row['stripe_id'])->first();

        if ($this->ledgerHasTaxColumn()) {
            $existingSource = $existing ? ($existing->meta->tax_source ?? null) : null;
            $known = $existing && ($existing->tax !== null || $existingSource === 'none');

            if ($known && (int) $existing->amount === $row['amount']) {
                [$tax, $taxSource] = [$existing->tax, $existingSource];
            } else {
                [$txnSource, $sourceId] = $this->ledgerSourceOf($txn, $source);
                [$tax, $taxSource] = $this->ledgerRowTax($row['source_type'], $txnSource, $sourceId, $row['amount'], $row['meta']['payment_intent'] ?? null);

                if ($tax === null && $taxSource === null && $existing?->tax !== null) {
                    [$tax, $taxSource] = [$existing->tax, $existingSource];
                }
            }

            $row['tax'] = $tax;
            if ($taxSource !== null) {
                $row['meta']['tax_source'] = $taxSource;
            }
        }

        if ($existing) {
            if ($row['customer_id'] === null && $existing->customer_id !== null) {
                $row['customer_id'] = $existing->customer_id;
            }
            if ($row['customer_email'] === null && $existing->customer_email !== null) {
                $row['customer_email'] = $existing->customer_email;
            }
            $existing->fill($row)->save();

            return 'updated';
        }

        $this->ledgerModel()::query()->create($row);

        return 'created';
    }

    /**
     * Real-time ledger sync for a single charge — the webhook's main path.
     * Retrieves the charge with its balance transaction and any refunds' balance
     * transactions and upserts each, user-independent (a deleted customer's money
     * is still recorded). Returns the number of rows touched. Never throws: on
     * any Stripe/DB error it logs and returns 0, so it can't break the webhook.
     */
    public function syncLedgerForCharge(?string $chargeId): int
    {
        // ch_ = card and most methods; py_ = PayPal and other "payment" charges.
        if (! is_string($chargeId) || ! (str_starts_with($chargeId, 'ch_') || str_starts_with($chargeId, 'py_'))) {
            return 0;
        }

        $secret = config('services.stripe.secret') ?: config('cashier.secret') ?: env('STRIPE_SECRET');
        if (! $secret) {
            return 0;
        }

        try {
            Stripe::setApiKey($secret);
            $charge = $this->retrieveLedgerSyncCharge($chargeId);

            $count = 0;
            if (is_object($charge->balance_transaction ?? null)) {
                $this->recordBalanceTransaction($charge->balance_transaction, $charge);
                $count++;
            }
            foreach (($charge->refunds->data ?? []) as $refund) {
                if (is_object($refund->balance_transaction ?? null)) {
                    // Attribute the refund to the charge's buyer.
                    $this->recordBalanceTransaction($refund->balance_transaction, $charge);
                    $count++;
                }
            }

            return $count;
        } catch (\Throwable $e) {
            Log::warning('[shop:ledger] syncLedgerForCharge failed', [
                'charge' => $chargeId,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * The charge for {@see syncLedgerForCharge}, with its balance transaction,
     * its refunds' balance transactions and its invoice expanded (the invoice
     * holds the VAT, so expanding it saves a call). API versions without
     * `Charge.invoice` (2025-03-31 and later) refuse that expansion; the charge
     * is then fetched without it.
     */
    protected function retrieveLedgerSyncCharge(string $chargeId): object
    {
        $expand = ['balance_transaction', 'refunds.data.balance_transaction'];

        try {
            return Charge::retrieve(['id' => $chargeId, 'expand' => [...$expand, 'invoice']]);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            return Charge::retrieve(['id' => $chargeId, 'expand' => $expand]);
        }
    }

    // =========================================================================
    // LEDGER VAT
    // =========================================================================

    /**
     * Ledger row types whose amount is a share of a charge, and so carries the
     * charge's VAT: the charge itself (`charge`, `payment`), and what gives
     * some of it back or returns it (`refund`, `payment_refund`, a bounced
     * debit's `payment_failure_refund`, a failed refund's `refund_failure`).
     *
     * @return list<string>
     */
    protected function ledgerTaxTypes(): array
    {
        return ['charge', 'payment', 'refund', 'payment_refund', 'payment_failure_refund', 'refund_failure'];
    }

    /** Whether the ledger table has the `tax` column (a found column is remembered). */
    protected function ledgerHasTaxColumn(): bool
    {
        if ($this->ledgerTaxColumn) {
            return true;
        }

        $model = $this->ledgerModel();

        return $this->ledgerTaxColumn = \Illuminate\Support\Facades\Schema::hasColumn((new $model)->getTable(), 'tax');
    }

    /** `SUM(tax) as tax` for the aggregates, or `0 as tax` before the column exists. */
    protected function ledgerTaxSumSql(): string
    {
        return $this->ledgerHasTaxColumn() ? 'SUM(COALESCE(tax, 0)) as tax' : '0 as tax';
    }

    /**
     * The VAT inside one ledger row's `amount`, signed like the amount, and
     * where it came from: `[tax, source]`.
     *
     * The charge's VAT comes from Stripe: its invoice (`tax`, pro-rated by the
     * captured amount over the invoice total, so a partly credit-paid invoice
     * counts only the cash part), else the Checkout Session of its payment
     * intent (`total_details.amount_tax`). A row then carries the share of it
     * its amount is of the charge, so a refund carries the refunded share,
     * negative. Prices may or may not include VAT; only Stripe's figures count.
     *
     * Returns `[null, 'none']` when Stripe holds no VAT data for the charge,
     * `[null, null]` when the row is not a charge share or the lookup failed
     * (it is tried again on the next import). Never throws.
     *
     * @return array{0: int|null, 1: string|null}
     */
    protected function ledgerRowTax(?string $type, ?object $source, ?string $sourceId, int $amount, ?string $paymentIntent): array
    {
        if (! in_array($type, $this->ledgerTaxTypes(), true)) {
            return [null, null];
        }

        if ($amount === 0) {
            return [0, null];
        }

        try {
            [$charge, $chargeId] = $this->ledgerChargeOf($source, $sourceId);
            $isCharge = in_array($type, ['charge', 'payment'], true);

            $vat = $this->ledgerChargeVat($charge ?? $chargeId, $paymentIntent, ! $isCharge);

            // A refund known only by its id: ask Stripe which charge it refunds.
            if ($vat === null && $charge === null && $chargeId === null && $this->isLedgerRefundId($sourceId)) {
                $refund = $this->stripeClient()->refunds->retrieve($sourceId);
                $refundCharge = $refund->charge ?? null;
                $refundIntent = $refund->payment_intent ?? null;

                $vat = $this->ledgerChargeVat(
                    is_object($refundCharge) ? $refundCharge : (is_string($refundCharge) ? $refundCharge : null),
                    $paymentIntent ?? (is_string($refundIntent) ? $refundIntent : null),
                    true,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('[shop:ledger] VAT lookup failed', ['source' => $sourceId, 'error' => $e->getMessage()]);

            return [null, null];
        }

        if ($vat === null) {
            return [null, null];
        }

        if ($vat['tax'] === null) {
            return [null, 'none'];
        }

        return [$vat['base'] > 0 ? (int) round($amount * $vat['tax'] / $vat['base']) : 0, $vat['source']];
    }

    /**
     * The charge a row belongs to, as `[object|null, id|null]`: the source
     * itself when it is a charge (`ch_`, `py_`), the refunded charge when it is
     * a refund.
     *
     * @return array{0: object|null, 1: string|null}
     */
    protected function ledgerChargeOf(?object $source, ?string $sourceId): array
    {
        $isCharge = static fn ($id) => is_string($id) && (str_starts_with($id, 'ch_') || str_starts_with($id, 'py_'));

        if ($source !== null) {
            $kind = $source->object ?? null;
            $id = $source->id ?? null;

            if ($kind === 'charge' || ($kind === null && $isCharge($id))) {
                return [$source, is_string($id) ? $id : null];
            }

            if ($kind === 'refund' || ($kind === null && $this->isLedgerRefundId($id))) {
                $charge = $source->charge ?? null;
                if (is_object($charge)) {
                    return [$charge, is_string($charge->id ?? null) ? $charge->id : null];
                }

                return [null, $isCharge($charge) ? $charge : null];
            }

            return [null, null];
        }

        return [null, $isCharge($sourceId) ? $sourceId : null];
    }

    protected function isLedgerRefundId(mixed $id): bool
    {
        return is_string($id) && (str_starts_with($id, 're_') || str_starts_with($id, 'pyr_'));
    }

    /**
     * One charge's VAT as `{tax, base, source}`: `tax` is the VAT inside the
     * charge's captured amount `base` (null when Stripe holds none, `source`
     * then `none`). Remembered per charge and payment intent for the process,
     * so a charge and its refunds cost one lookup. With `$fromLedger`, the
     * charge's own ledger row is asked first (refunds of a known charge then
     * need no Stripe call). Returns null when nothing identifies the charge or
     * the lookup failed.
     *
     * @return array{tax: int|null, base: int, source: string}|null
     */
    protected function ledgerChargeVat(object|string|null $charge, ?string $paymentIntent = null, bool $fromLedger = false): ?array
    {
        $chargeId = is_object($charge) ? ($charge->id ?? null) : $charge;
        $chargeId = is_string($chargeId) && $chargeId !== '' ? $chargeId : null;

        if (is_object($charge) && $paymentIntent === null) {
            $paymentIntent = $this->ledgerIdOf($charge->payment_intent ?? null);
        }
        $paymentIntent = is_string($paymentIntent) && $paymentIntent !== '' ? $paymentIntent : null;

        $keys = array_values(array_filter([$chargeId, $paymentIntent ? 'pi:'.$paymentIntent : null]));
        foreach ($keys as $key) {
            if (array_key_exists($key, $this->ledgerVatCache)) {
                return $this->ledgerVatCache[$key] ?: null;
            }
        }

        if ($keys === []) {
            return null;
        }

        try {
            $vat = $fromLedger ? $this->ledgerVatFromChargeRow($chargeId, $paymentIntent) : null;

            if ($vat === null && ! is_object($charge)) {
                if ($chargeId === null) {
                    // Only a payment intent, and no ledger row has it: nothing
                    // to ask Stripe here, and nothing to remember.
                    return null;
                }
                $charge = $this->retrieveLedgerCharge($chargeId);
            }

            if ($vat === null && is_object($charge)) {
                $vat = $this->ledgerVatOfCharge($charge);
            }
        } catch (\Throwable $e) {
            Log::warning('[shop:ledger] VAT lookup failed', [
                'charge' => $chargeId,
                'payment_intent' => $paymentIntent,
                'error' => $e->getMessage(),
            ]);
            $vat = null;
        }

        if (is_object($charge) && ($pi = $this->ledgerIdOf($charge->payment_intent ?? null))) {
            $keys[] = 'pi:'.$pi;
        }
        foreach (array_unique($keys) as $key) {
            // Failures are remembered too, so one broken charge is not asked
            // again for each of its refunds in the same run.
            $this->rememberLedgerLookup($this->ledgerVatCache, $key, $vat ?? false);
        }

        return $vat;
    }

    /**
     * The VAT of a charge whose ledger row already has it, matched on the
     * charge id or its payment intent.
     *
     * @return array{tax: int|null, base: int, source: string}|null
     */
    protected function ledgerVatFromChargeRow(?string $chargeId, ?string $paymentIntent): ?array
    {
        if ($chargeId === null && $paymentIntent === null) {
            return null;
        }

        $row = $this->ledgerModel()::query()
            ->whereIn('source_type', ['charge', 'payment'])
            ->where('amount', '!=', 0)
            ->where(function ($q) use ($chargeId, $paymentIntent) {
                if ($chargeId !== null) {
                    $q->orWhere('source_id', $chargeId);
                }
                if ($paymentIntent !== null) {
                    $q->orWhere('meta->payment_intent', $paymentIntent);
                }
            })
            ->where(fn ($q) => $q->whereNotNull('tax')->orWhere('meta->tax_source', 'none'))
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'tax' => $row->tax === null ? null : (int) $row->tax,
            'base' => (int) $row->amount,
            'source' => (string) ($row->meta->tax_source ?? 'ledger'),
        ];
    }

    /**
     * The VAT inside a Stripe charge, from its invoice, else from the Checkout
     * Session of its payment intent. `source` is `none` (and `tax` null) when
     * neither exists.
     *
     * @return array{tax: int|null, base: int, source: string}
     */
    protected function ledgerVatOfCharge(object $charge): array
    {
        $captured = (int) ($charge->amount_captured ?? $charge->amount ?? 0);

        $invoice = $charge->invoice ?? null;
        if (is_string($invoice) && $invoice !== '') {
            $invoice = $this->retrieveLedgerInvoice($invoice);
        }

        if (is_object($invoice)) {
            return [
                'tax' => $this->ledgerProRate($this->ledgerInvoiceTax($invoice), $captured, (int) ($invoice->total ?? 0)),
                'base' => $captured,
                'source' => 'invoice',
            ];
        }

        $paymentIntent = $this->ledgerIdOf($charge->payment_intent ?? null);
        $session = $paymentIntent ? $this->retrieveLedgerCheckoutSession($paymentIntent) : null;

        if (is_object($session)) {
            return [
                'tax' => $this->ledgerProRate((int) ($session->total_details->amount_tax ?? 0), $captured, (int) ($session->amount_total ?? 0)),
                'base' => $captured,
                'source' => 'checkout_session',
            ];
        }

        return ['tax' => null, 'base' => $captured, 'source' => 'none'];
    }

    /**
     * An invoice's VAT: `tax` (API versions up to 2025-02-24), else the sum of
     * `total_tax_amounts`, else of `total_taxes` (2025-03-31 and later).
     * Inclusive and exclusive taxes both count.
     */
    protected function ledgerInvoiceTax(object $invoice): int
    {
        if (isset($invoice->tax) && is_numeric($invoice->tax)) {
            return (int) $invoice->tax;
        }

        foreach (['total_tax_amounts', 'total_taxes'] as $field) {
            $lines = $invoice->{$field} ?? null;
            if ($lines !== null) {
                $sum = 0;
                foreach ($lines as $line) {
                    $sum += (int) ($line->amount ?? 0);
                }

                return $sum;
            }
        }

        return 0;
    }

    /** The share of `$tax` (on `$total`) the captured cash pays, capped at all of it. */
    protected function ledgerProRate(int $tax, int $captured, int $total): int
    {
        if ($total <= 0 || $captured <= 0) {
            return 0;
        }

        return (int) round($tax * min($captured, $total) / $total);
    }

    protected function ledgerIdOf(mixed $value): ?string
    {
        $id = is_object($value) ? ($value->id ?? null) : $value;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** A charge with its invoice expanded (without, where the API version has no `Charge.invoice`). */
    protected function retrieveLedgerCharge(string $chargeId): object
    {
        try {
            return $this->stripeClient()->charges->retrieve($chargeId, ['expand' => ['invoice']]);
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            return $this->stripeClient()->charges->retrieve($chargeId);
        }
    }

    protected function retrieveLedgerInvoice(string $invoiceId): object
    {
        $key = 'invoice:'.$invoiceId;
        if (! array_key_exists($key, $this->ledgerStripeCache)) {
            $this->rememberLedgerLookup($this->ledgerStripeCache, $key, $this->stripeClient()->invoices->retrieve($invoiceId));
        }

        return $this->ledgerStripeCache[$key];
    }

    /** The Checkout Session that took the payment intent, or null when none did. */
    protected function retrieveLedgerCheckoutSession(string $paymentIntent): ?object
    {
        $key = 'session:'.$paymentIntent;
        if (! array_key_exists($key, $this->ledgerStripeCache)) {
            $list = $this->stripeClient()->checkout->sessions->all(['payment_intent' => $paymentIntent, 'limit' => 1]);
            $this->rememberLedgerLookup($this->ledgerStripeCache, $key, $list->data[0] ?? null);
        }

        return $this->ledgerStripeCache[$key];
    }

    /** Remember a lookup, dropping the oldest once a long-running process holds many. */
    protected function rememberLedgerLookup(array &$cache, string $key, mixed $value): void
    {
        unset($cache[$key]);
        $cache[$key] = $value;

        if (count($cache) > 2000) {
            array_shift($cache);
        }
    }

    /**
     * Fill `tax` on the Stripe charge, payment and refund rows that have none
     * yet, from Stripe's own data (read-only; see {@see ledgerRowTax}). Charges
     * go first, so their refunds reuse the charge's VAT without another call.
     * Idempotent: rows that got a value are not touched again, and rows Stripe
     * holds no VAT data for are marked `meta.tax_source = none` and skipped on
     * later runs unless `$recheck`. A failed lookup leaves the row NULL and
     * goes on. `$dryRun` writes nothing. `$progress` is called with the running
     * totals after every row.
     *
     * @return array{scanned: int, filled: int, tax: int, none: int, failed: int}
     */
    public function backfillLedgerTax(
        bool $dryRun = false,
        ?\DateTimeInterface $since = null,
        bool $recheck = false,
        ?callable $progress = null,
    ): array {
        $totals = ['scanned' => 0, 'filled' => 0, 'tax' => 0, 'none' => 0, 'failed' => 0];

        if (! $this->ledgerHasTaxColumn()) {
            return $totals;
        }

        $model = $this->ledgerModel();
        $hasProvider = \Illuminate\Support\Facades\Schema::hasColumn((new $model)->getTable(), 'provider');
        $chargeTypes = ['charge', 'payment'];

        foreach ([$chargeTypes, array_values(array_diff($this->ledgerTaxTypes(), $chargeTypes))] as $types) {
            $rows = $model::query()
                ->whereNull('tax')
                ->whereIn('source_type', $types)
                ->when($hasProvider, fn ($q) => $q->where('provider', 'stripe'))
                ->when($since, fn ($q) => $q->where('created', '>=', $since))
                ->lazyById(200);

            foreach ($rows as $row) {
                $meta = json_decode(json_encode($row->meta ?? new \stdClass), true) ?: [];
                if (! $recheck && ($meta['tax_source'] ?? null) === 'none') {
                    continue;
                }

                $totals['scanned']++;
                [$tax, $taxSource] = $this->ledgerRowTax(
                    $row->source_type,
                    null,
                    $row->source_id,
                    (int) $row->amount,
                    $meta['payment_intent'] ?? null,
                );

                if ($tax === null) {
                    $taxSource === 'none' ? $totals['none']++ : $totals['failed']++;
                } else {
                    $totals['filled']++;
                    $totals['tax'] += $tax;
                }

                if (! $dryRun && ($tax !== null || $taxSource !== null)) {
                    if ($taxSource !== null) {
                        $meta['tax_source'] = $taxSource;
                    }
                    $row->forceFill(['tax' => $tax, 'meta' => $meta])->save();
                }

                if ($progress) {
                    $progress($totals);
                }
            }
        }

        return $totals;
    }

    // =========================================================================
    // REFUNDS
    // =========================================================================

    /**
     * Refund (part of) a Stripe-paid order through the Stripe API and record it on the order.
     *
     * `$amount` is in cents and may not exceed what is still refundable
     * (`amount_paid - amount_refunded`). The refund is recorded with
     * {@see Order::applyStripeRefund()}, so the `refund.created` / `charge.refunded`
     * webhooks that follow do not count it again. `$reason` is stored on the order
     * note and in the refund's metadata; Stripe's own `reason` enum can be passed in
     * `$params` (e.g. `['reason' => 'requested_by_customer']`).
     *
     * @param  array<string, mixed>  $params  extra Stripe refund params
     * @return object the Stripe Refund
     *
     * @throws \InvalidArgumentException when the amount is not refundable
     * @throws \RuntimeException when the order has no Stripe payment or Stripe is not configured
     */
    public function refundOrder(Order $order, int $amount, ?string $reason = null, array $params = []): object
    {
        $reference = (string) ($order->payment_reference ?? '');
        $isIntent = str_starts_with($reference, 'pi_');

        if (! $isIntent && ! str_starts_with($reference, 'ch_')) {
            throw new \RuntimeException('This order has no Stripe payment to refund.');
        }

        $refundable = $this->refundableAmount($order);
        if ($amount < 1 || $amount > $refundable) {
            throw new \InvalidArgumentException("Refund amount must be between 1 and {$refundable} cents.");
        }

        $metadata = array_filter([
            'order_id' => (string) $order->getKey(),
            'order_number' => (string) $order->order_number,
            'reason' => $reason ? mb_substr($reason, 0, 500) : null,
        ]);

        $refund = $this->stripeClient()->refunds->create(
            array_merge([
                ($isIntent ? 'payment_intent' : 'charge') => $reference,
                'amount' => $amount,
                'metadata' => $metadata,
            ], $params),
            // Same order, same amount, same refund state → the same refund, so a
            // double-click cannot refund twice.
            ['idempotency_key' => 'shop-refund-'.$order->getKey().'-'.(int) $order->amount_refunded.'-'.$amount]
        );

        $order->applyStripeRefund(
            (string) $refund->id,
            (int) ($refund->amount ?? $amount),
            $reason ?: "Refunded via Stripe (Refund: {$refund->id})"
        );

        return $refund;
    }

    /** Cents that can still be refunded on the order. */
    public function refundableAmount(Order $order): int
    {
        return max(0, (int) $order->amount_paid - (int) $order->amount_refunded);
    }

    /**
     * The Stripe API client. Bind `\Stripe\StripeClient` (or any object exposing
     * `->refunds->create()`) in the container to override it, e.g. in tests.
     */
    protected function stripeClient(): object
    {
        if (app()->bound(\Stripe\StripeClient::class)) {
            return app(\Stripe\StripeClient::class);
        }

        $secret = config('services.stripe.secret') ?: config('cashier.secret') ?: env('STRIPE_SECRET');
        if (! $secret) {
            throw new \RuntimeException('Stripe is not configured.');
        }

        return new \Stripe\StripeClient($secret);
    }
}
