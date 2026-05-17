<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Cart;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fires when a new cart row is persisted. Dispatched automatically by
 * {@see Cart}'s `$dispatchesEvents` map. Listeners commonly use this for
 * analytics ("session X started a cart") or to attach a default currency
 * inferred from the request.
 */
class CartCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Cart $cart) {}
}
