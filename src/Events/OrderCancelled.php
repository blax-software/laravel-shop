<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when an order is cancelled — by the shopper, by support, or
 * by a payment-failure path that decides the order can't proceed. Stock
 * claims tied to the order should be released by listeners (the package
 * does not do this automatically).
 */
class OrderCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order) {}
}
