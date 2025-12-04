<?php

namespace Blax\Shop\Enums;

/**
 * StockStatus Enum
 * 
 * Defines the lifecycle status of stock entries.
 * 
 * Statuses:
 * - PENDING: Stock claim is active but not yet finalized
 *   Used for: Active reservations, bookings, cart claims
 *   Can be: Released (changed to COMPLETED) or Cancelled
 *   Effect: Stock is allocated but tracked as claimed
 * 
 * - COMPLETED: Stock movement is finalized
 *   Used for: Physical stock changes (INCREASE/DECREASE/RETURN)
 *   Also for: Released claims (no longer active)
 *   Effect: Counted as physical stock, cannot be modified
 * 
 * - CANCELLED: Stock entry was cancelled
 *   Used for: Cancelled reservations, voided transactions
 *   Effect: Not counted in any calculations
 * 
 * Typical Flow:
 * 1. Claim created -> PENDING
 * 2. Claim released -> COMPLETED
 * 3. Or claim cancelled -> CANCELLED
 * 
 * Physical stock changes (INCREASE/DECREASE) are always COMPLETED.
 */
enum StockStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
        };
    }
}
