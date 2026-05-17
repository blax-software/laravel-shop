<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Cart;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched by the cart-cleanup sweeper (see
 * {@see \Blax\Shop\Console\Commands\ShopCleanupCartsCommand}) when a cart
 * has been inactive past the abandon threshold but is not yet hard-expired.
 * Listeners typically use this to send recovery emails or release temporary
 * stock claims attached to the cart.
 */
class CartAbandoned
{
    use Dispatchable, SerializesModels;

    public function __construct(public Cart $cart) {}
}
