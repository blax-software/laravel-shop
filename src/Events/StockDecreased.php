<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductStock;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched from {@see \Blax\Shop\Traits\HasStocks::decreaseStock()} after
 * a negative stock entry is written. Use this to track depletion sources,
 * trigger reorder workflows, or fan out availability-change notifications.
 */
class StockDecreased
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Product $product,
        public ProductStock $entry,
        public int $availableAfter,
    ) {}
}
