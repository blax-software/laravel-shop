<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched automatically when an {@see Order} row is created (via the
 * model's `$dispatchesEvents` map). Distinct from {@see CartConverted},
 * which also carries the originating cart — listen to whichever signal
 * matches your domain language.
 */
class OrderCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Order $order) {}
}
