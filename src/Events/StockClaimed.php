<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductStock;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched from {@see \Blax\Shop\Traits\HasStocks::claimStock()} after a
 * reservation (PENDING/CLAIMED row) is created. The associated $reference
 * (cart, booking, anything polymorphic) is reachable via the $entry's
 * `reference_type` / `reference_id` columns.
 */
class StockClaimed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Product $product,
        public ProductStock $entry,
    ) {}
}
