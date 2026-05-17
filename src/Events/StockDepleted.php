<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched the moment available stock drops to zero (and was positive
 * immediately before). The product can no longer be sold/loaned/booked at
 * its current quantity until restocked. Listeners typically hide the
 * product from sales surfaces or send "now sold out" notifications.
 */
class StockDepleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Product $product) {}
}
