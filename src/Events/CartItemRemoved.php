<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after a cart item is removed (either by the shopper or via the
 * cart service when a product becomes unavailable). The model carried here
 * is the already-deleted instance — listeners can read its attributes but
 * not save back.
 */
class CartItemRemoved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Cart $cart,
        public CartItem $item,
    ) {}
}
