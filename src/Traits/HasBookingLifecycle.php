<?php

namespace Blax\Shop\Traits;

/**
 * Booking lifecycle for a {@see \Blax\Shop\Models\ProductPurchase} row.
 *
 * "Booking" here means a purchase whose dates (`from` / `until`) define a
 * time-bounded reservation. The trait is purchase-side — the corresponding
 * product-side concept is the BOOKING product type plus
 * {@see ChecksIfBooking}.
 */
trait HasBookingLifecycle
{
    /**
     * Has this purchase been booked across a date range?
     */
    public function isBooking(): bool
    {
        return ! is_null($this->from) && ! is_null($this->until);
    }

    /**
     * Has the booking window ended? False if not a booking at all.
     */
    public function isBookingEnded(): bool
    {
        if (! $this->isBooking()) {
            return false;
        }

        return now()->isAfter($this->until);
    }

    /**
     * Scope to date-bounded bookings only.
     */
    public function scopeBookings($query)
    {
        return $query->whereNotNull('from')->whereNotNull('until');
    }

    /**
     * Scope to bookings whose window is in the past.
     */
    public function scopeEndedBookings($query)
    {
        return $query->bookings()->where('until', '<', now());
    }
}
