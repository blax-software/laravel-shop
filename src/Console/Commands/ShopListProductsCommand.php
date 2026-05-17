<?php

declare(strict_types=1);

namespace Blax\Shop\Console\Commands;

use Blax\Shop\Enums\ProductStatus;
use Illuminate\Console\Command;

class ShopListProductsCommand extends Command
{
    protected $signature = 'shop:list:products
                            {--with-actions : Include action counts}
                            {--with-purchases : Include purchase counts}
                            {--status= : Filter by status (e.g. published, draft, archived)}
                            {--visible : Only show is_visible=true products}
                            {--hidden : Only show is_visible=false products}
                            {--type= : Filter by product type (simple, variable, grouped, external, booking, pool, loanable)}';

    protected $description = 'List all products in the shop';

    public function handle(): int
    {
        $productModel = config('shop.models.product');
        $query = $productModel::query();

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }
        if ($this->option('visible')) {
            $query->where('is_visible', true);
        } elseif ($this->option('hidden')) {
            $query->where('is_visible', false);
        }
        if ($type = $this->option('type')) {
            $query->where('type', $type);
        }

        if ($this->option('with-actions')) {
            $query->withCount('actions');
        }
        if ($this->option('with-purchases')) {
            $query->withCount('purchases');
        }

        $products = $query->orderBy('name')->get();

        if ($products->isEmpty()) {
            $this->info('No products found.');
            return self::SUCCESS;
        }

        $headers = ['ID', 'Name', 'SKU', 'Type', 'Status', 'Visible', 'Default Price'];
        if ($this->option('with-actions')) {
            $headers[] = 'Actions';
        }
        if ($this->option('with-purchases')) {
            $headers[] = 'Purchases';
        }

        $rows = $products->map(function ($product) {
            $defaultPrice = optional($product->defaultPrice()->first())->getCurrentPrice();

            $row = [
                substr((string) $product->id, 0, 8).'…',
                $product->name,
                $product->sku ?: '—',
                $this->enumValue($product->type),
                $this->enumValue($product->status),
                $product->is_visible ? '✓' : '✗',
                $defaultPrice !== null ? number_format((float) $defaultPrice, 2) : '—',
            ];

            if ($this->option('with-actions')) {
                $row[] = (int) ($product->actions_count ?? 0);
            }
            if ($this->option('with-purchases')) {
                $row[] = (int) ($product->purchases_count ?? 0);
            }

            return $row;
        });

        $this->table($headers, $rows);
        $this->info("Total products: {$products->count()}");

        return self::SUCCESS;
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        return $value === null ? '—' : (string) $value;
    }
}
