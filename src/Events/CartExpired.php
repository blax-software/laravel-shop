<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Cart;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a cart hits its `expires_at` (or the cleanup sweeper
 * decides to retire it). The cart is about to be deleted; listeners can
 * snapshot useful analytics before it goes away.
 */
class CartExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(public Cart $cart) {}
}
