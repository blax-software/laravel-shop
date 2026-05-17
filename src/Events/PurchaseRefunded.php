<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\ProductPurchase;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a single purchase (line-item, loan, or booking) is
 * refunded — separate from {@see OrderRefunded}, which represents the
 * order-level event. Both can fire from the same operator action; listen
 * to whichever level matches your reporting needs.
 */
class PurchaseRefunded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ProductPurchase $purchase,
        public float $amount,
    ) {}
}
