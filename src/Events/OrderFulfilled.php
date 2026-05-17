<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when an order is marked fulfilled (shipped, delivered, picked
 * up, or otherwise handed off — the package is fulfilment-channel
 * agnostic). Hosts that distinguish "shipped" from "delivered" can listen
 * here for the final hand-off and define their own intermediate events.
 */
class OrderFulfilled
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order) {}
}
