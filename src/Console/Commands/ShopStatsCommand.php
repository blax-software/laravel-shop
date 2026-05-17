<?php

declare(strict_types=1);

namespace Blax\Shop\Console\Commands;

use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Models\ProductAction;
use Illuminate\Console\Command;

class ShopStatsCommand extends Command
{
    protected $signature = 'shop:stats';

    protected $description = 'Display shop statistics';

    public function handle(): int
    {
        $productModel = config('shop.models.product');
        $purchaseModel = config('shop.models.product_purchase');
        $cartModel = config('shop.models.cart');
        $orderModel = config('shop.models.order');

        $rows = [];

        // Products
        $totalProducts = $productModel::count();
        $publishedProducts = $productModel::where('status', ProductStatus::PUBLISHED->value)->count();
        $visibleProducts = $productModel::where('is_visible', true)->count();
        $rows[] = ['Products: total', $totalProducts];
        $rows[] = ['Products: published', $publishedProducts];
        $rows[] = ['Products: visible', $visibleProducts];

        // Physical inventory rollup — how many units the business still owns
        // across every managed product (loaned/claimed copies count). Skips
        // unmanaged products so a single "no scarcity" item doesn't render
        // ∞ at the rollup level.
        $physicalUnits = $productModel::where('manage_stock', true)
            ->get()
            ->sum(fn ($product) => $product->getPhysicalStock());
        $rows[] = ['Products: physical units', $physicalUnits];

        $rows[] = ['---', '---'];

        // Actions
        $totalActions = ProductAction::count();
        $activeActions = ProductAction::where('active', true)->count();
        $rows[] = ['Actions: total', $totalActions];
        $rows[] = ['Actions: active', $activeActions];
        $rows[] = ['Actions: inactive', $totalActions - $activeActions];

        $rows[] = ['---', '---'];

        // Purchases (loans, bookings, sales)
        $totalPurchases = $purchaseModel::count();
        $completedPurchases = $purchaseModel::where('status', PurchaseStatus::COMPLETED->value)->count();
        $pendingPurchases = $purchaseModel::where('status', PurchaseStatus::PENDING->value)->count();
        $revenueCents = (int) $purchaseModel::sum('amount_paid');
        $rows[] = ['Purchases: total', $totalPurchases];
        $rows[] = ['Purchases: completed', $completedPurchases];
        $rows[] = ['Purchases: pending', $pendingPurchases];
        $rows[] = ['Revenue (paid)', number_format($revenueCents / 100, 2)];

        // Carts (model may be absent in minimal installs — guard accordingly)
        if ($cartModel) {
            $rows[] = ['---', '---'];
            $rows[] = ['Carts: total', $cartModel::count()];
        }

        // Orders
        if ($orderModel) {
            $rows[] = ['Orders: total', $orderModel::count()];
        }

        $this->info('=== Shop Statistics ===');
        $this->newLine();
        $this->table(['Metric', 'Value'], $rows);

        return self::SUCCESS;
    }
}
