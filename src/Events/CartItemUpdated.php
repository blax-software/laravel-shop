<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a cart item's quantity, dates, or pricing fields change.
 * Distinct from {@see CartItemAdded} so listeners can treat "added once"
 * and "quantity ticked up" as separate signals (e.g. for funnel metrics).
 */
class CartItemUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Cart $cart,
        public CartItem $item,
    ) {}
}
