<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductStock;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a previously-pending claim is explicitly released back to
 * the available pool (typically via {@see ProductStock::release()} or a
 * cart-abandonment path). Distinct from {@see StockClaimExpired}, which
 * fires from the scheduled sweeper.
 */
class StockReleased
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Product $product,
        public ProductStock $entry,
    ) {}
}
