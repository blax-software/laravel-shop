<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Host-dispatched event marking "stock is back at full capacity" — useful
 * for inventory health dashboards or "no copies checked out" signals.
 *
 * Not fired automatically by the package: the canonical notion of "max"
 * varies by domain (library physical copies, venue capacity, shelf SKU
 * count) and the package's stock ledger is grow-only, so any auto-rule
 * would overlap with {@see StockIncreased}. Hosts dispatch this themselves
 * when their domain-specific ceiling is met.
 */
class StockFullyAvailable
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Product $product,
        public int $availableAfter,
    ) {}
}
