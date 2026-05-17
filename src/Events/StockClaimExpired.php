<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductStock;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched from the {@see \Blax\Shop\Console\Commands\ReleaseExpiredStocks}
 * sweeper when a pending claim's `expires_at` has passed and the package
 * automatically returns its quantity to available stock. Pair with
 * {@see StockReleased} if a listener needs to handle either path uniformly.
 */
class StockClaimExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Product $product,
        public ProductStock $entry,
    ) {}
}
