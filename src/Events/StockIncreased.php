<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductStock;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched from {@see \Blax\Shop\Traits\HasStocks::increaseStock()} after
 * a positive stock entry is written. Listeners commonly use this to log
 * inventory deliveries, push to an external WMS, or recompute aggregates.
 */
class StockIncreased
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Product $product,
        public ProductStock $entry,
        public int $availableAfter,
    ) {}
}
