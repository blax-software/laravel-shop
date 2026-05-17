<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when an order is refunded — partially or fully. $amount
 * carries the refunded amount (in the order's currency) so listeners can
 * keep running totals without re-querying refund history.
 */
class OrderRefunded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Order $order,
        public float $amount,
        public bool $partial,
    ) {}
}
