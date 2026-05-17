<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after a {@see CartItem} row is created (via the cart service
 * or directly). Carries both the cart and the new item so listeners can
 * recompute totals, surface "added to cart" toasts, or claim stock.
 */
class CartItemAdded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Cart $cart,
        public CartItem $item,
    ) {}
}
