<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when an order transitions to a paid state (in-app payment
 * captured, Stripe webhook reconciled, or manual mark-as-paid action).
 * Use this to fire off fulfilment workflows or payout reconciliation.
 */
class OrderPaid
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order) {}
}
