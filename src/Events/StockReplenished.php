<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a product transitions from "out of stock" back to having
 * at least one available unit. Counterpart to {@see StockDepleted}. Useful
 * for waitlist fan-outs ("the book you wanted is back") or reactivating the
 * product on sales surfaces.
 */
class StockReplenished
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Product $product,
        public int $availableAfter,
    ) {}
}
