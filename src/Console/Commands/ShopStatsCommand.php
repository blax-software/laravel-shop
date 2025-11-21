<?php

namespace Blax\Shop\Console\Commands;

use Blax\Shop\Models\ProductAction;
use Illuminate\Console\Command;

class ShopStatsCommand extends Command
{
    protected $signature = 'shop:stats';

    protected $description = 'Display shop statistics';

    public function handle()
    {
        $productModel = config('shop.models.product');
        $purchaseModel = config('shop.models.product_purchase');

        $totalProducts = $productModel::count();
        $enabledProducts = $productModel::where('enabled', true)->count();
        $disabledProducts = $productModel::where('enabled', false)->count();

        $totalActions = ProductAction::count();
        $enabledActions = ProductAction::where('enabled', true)->count();
        $disabledActions = ProductAction::where('enabled', false)->count();

        $totalPurchases = $purchaseModel::count();
        $totalRevenue = $purchaseModel::sum('price');

        $this->info('=== Shop Statistics ===');
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Products', $totalProducts],
                ['Enabled Products', $enabledProducts],
                ['Disabled Products', $disabledProducts],
                ['---', '---'],
                ['Total Actions', $totalActions],
                ['Enabled Actions', $enabledActions],
                ['Disabled Actions', $disabledActions],
                ['---', '---'],
                ['Total Purchases', $totalPurchases],
                ['Total Revenue', number_format($totalRevenue, 2)],
            ]
        );

        return 0;
    }
}
