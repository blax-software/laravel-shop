<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\ProductPurchase;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a booking is cancelled before it would have started —
 * shopper cancellation, no-show policy, payment failure, etc. The package
 * does not auto-release stock claims on cancellation; that's a listener's
 * job (often paired with {@see StockReleased}).
 */
class BookingCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(public ProductPurchase $booking) {}
}
