<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched at the moment a cart becomes an order — usually from the
 * checkout flow after the order row is persisted and the cart is marked
 * converted. Use to trigger receipt emails, fulfilment workflows, analytics
 * conversion pings, etc.
 */
class CartConverted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Cart $cart,
        public Order $order,
    ) {}
}
