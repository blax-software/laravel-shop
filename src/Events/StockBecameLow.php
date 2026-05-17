<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched once when available stock crosses below the product's
 * `low_stock_threshold` (and was above it immediately before). Fires from
 * stock-change paths in {@see \Blax\Shop\Traits\HasStocks}; the post-change
 * available count plus the threshold are carried in the payload so a
 * listener can build the alert message without re-querying.
 */
class StockBecameLow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Product $product,
        public int $availableAfter,
        public int $threshold,
    ) {}
}
