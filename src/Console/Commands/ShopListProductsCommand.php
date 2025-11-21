<?php

namespace Blax\Shop\Console\Commands;

use Illuminate\Console\Command;

class ShopListProductsCommand extends Command
{
    protected $signature = 'shop:list-products
                            {--with-actions : Include action counts}
                            {--with-purchases : Include purchase counts}
                            {--enabled : Only show enabled products}
                            {--disabled : Only show disabled products}';

    protected $description = 'List all products in the shop';

    public function handle()
    {
        $productModel = config('shop.models.product');
        $query = $productModel::query();

        if ($this->option('enabled')) {
            $query->where('enabled', true);
        } elseif ($this->option('disabled')) {
            $query->where('enabled', false);
        }

        if ($this->option('with-actions')) {
            $query->withCount('actions');
        }

        if ($this->option('with-purchases')) {
            $query->withCount('purchases');
        }

        $products = $query->orderBy('id')->get();

        if ($products->isEmpty()) {
            $this->info('No products found.');
            return 0;
        }

        $headers = ['ID', 'Name', 'Price', 'Type', 'Enabled'];

        if ($this->option('with-actions')) {
            $headers[] = 'Actions';
        }

        if ($this->option('with-purchases')) {
            $headers[] = 'Purchases';
        }

        $rows = $products->map(function ($product) {
            $row = [
                $product->id,
                $product->name,
                $product->price,
                $product->type ?? 'N/A',
                $product->enabled ? '✓' : '✗',
            ];

            if ($this->option('with-actions')) {
                $row[] = $product->actions_count ?? 0;
            }

            if ($this->option('with-purchases')) {
                $row[] = $product->purchases_count ?? 0;
            }

            return $row;
        });

        $this->table($headers, $rows);
        $this->info("Total products: {$products->count()}");

        return 0;
    }
}
