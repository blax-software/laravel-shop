<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\ProductPurchase;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Booking-flavoured counterpart to {@see LoanCreated} / {@see PurchaseCreated}.
 * Hosts that build on BOOKING-typed products dispatch this when a
 * reservation is confirmed (after the booking calendar has been claimed
 * and the price is locked in).
 */
class BookingConfirmed
{
    use Dispatchable, SerializesModels;

    public function __construct(public ProductPurchase $booking) {}
}
