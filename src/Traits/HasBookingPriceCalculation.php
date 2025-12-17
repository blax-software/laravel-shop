<?php

namespace Blax\Shop\Traits;

use Carbon\Carbon;

trait HasBookingPriceCalculation
{
    /**
     * Calculate the fractional days between two dates.
     * This calculates the exact duration in minutes and converts to days (24-hour periods).
     * 
     * Examples:
     * - 1 day exactly (24 hours) = 1.0
     * - 1.5 days (36 hours) = 1.5
     * - 12 hours = 0.5
     * - 1 day + 6 hours = 1.25
     * 
     * @param \DateTimeInterface $from Start date/time
     * @param \DateTimeInterface $until End date/time
     * @return float Number of days (can be fractional)
     */
    protected function calculateBookingDays(\DateTimeInterface $from, \DateTimeInterface $until): float
    {
        if (!$from instanceof Carbon) {
            $from = Carbon::parse($from);
        }
        if (!$until instanceof Carbon) {
            $until = Carbon::parse($until);
        }

        // Calculate the exact duration in minutes
        $totalMinutes = $from->diffInMinutes($until);

        // Convert to days (1 day = 1440 minutes)
        $days = $totalMinutes / 1440;

        // Round to 10 decimal places to avoid floating point errors
        // while maintaining precision for fractional days
        $days = round($days, 10);

        // Return at least a minimum value if dates are the same or very close
        return max(0.000694, $days); // 0.000694 ≈ 1 minute in days
    }

    /**
     * Calculate the price for a booking based on exact duration.
     * 
     * @param float $pricePerDay Price per day (24 hours)
     * @param \DateTimeInterface $from Start date/time
     * @param \DateTimeInterface $until End date/time
     * @return float Calculated price for the duration
     */
    protected function calculateBookingPrice(
        float $pricePerDay,
        \DateTimeInterface $from,
        \DateTimeInterface $until
    ): float {
        $days = $this->calculateBookingDays($from, $until);
        return $pricePerDay * $days;
    }
}
