<?php

declare(strict_types=1);

namespace Blax\Shop\Console\Commands;

use Illuminate\Console\Command;

class ShopListCommand extends Command
{
    protected $signature = 'shop:list';

    protected $description = 'Show every listable resource in the shop along with its total count';

    /**
     * Keyed by the subcommand suffix; value is the config key under `shop.models`
     * whose bound model is counted. Adding a new shop:list:<thing> command only
     * requires extending this map (and registering the new command).
     */
    private const LISTABLES = [
        'products' => 'product',
        'purchases' => 'product_purchase',
        'categories' => 'product_category',
        'orders' => 'order',
        'carts' => 'cart',
    ];

    public function handle(): int
    {
        $this->newLine();
        $this->line('  <fg=cyan;options=bold>Shop listings</>');
        $this->newLine();

        $rows = [];
        foreach (self::LISTABLES as $suffix => $modelKey) {
            $modelClass = config("shop.models.{$modelKey}");
            $count = $modelClass ? (int) $modelClass::query()->count() : 0;
            $rows[] = ["shop:list:{$suffix}", number_format($count).' entries'];
        }

        $this->table(['Command', 'Total'], $rows);
        $this->line('  <fg=gray>Run any of the above to see the full table. Most accept filter options — `<command> --help` shows what.</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
