<?php

declare(strict_types=1);

namespace Blax\Shop\Console\Commands;

use Illuminate\Console\Command;

class ShopListCategoriesCommand extends Command
{
    protected $signature = 'shop:list:categories
                            {--with-products : Include the count of products in each category}';

    protected $description = 'List all product categories';

    public function handle(): int
    {
        $model = config('shop.models.product_category');
        $query = $model::query()->orderBy('name');

        if ($this->option('with-products')) {
            $query->withCount('products');
        }

        $categories = $query->get();

        if ($categories->isEmpty()) {
            $this->info('No categories found.');
            return self::SUCCESS;
        }

        $headers = ['ID', 'Name', 'Slug', 'Parent'];
        if ($this->option('with-products')) {
            $headers[] = 'Products';
        }

        $rows = $categories->map(function ($cat) {
            $row = [
                $cat->id,
                $cat->name,
                $cat->slug ?? '—',
                $cat->parent_id ? substr((string) $cat->parent_id, 0, 8).'…' : '—',
            ];
            if ($this->option('with-products')) {
                $row[] = (int) ($cat->products_count ?? 0);
            }
            return $row;
        });

        $this->table($headers, $rows);
        $this->info("Total categories: {$categories->count()}");

        return self::SUCCESS;
    }
}
