<?php

namespace Blax\Shop\Enums;

/**
 * StockType Enum
 * 
 * Defines the types of stock movements that can occur.
 * 
 * Types:
 * - CLAIMED: Stock claimed for reservation/booking (creates PENDING entry)
 *   Used for temporary allocations that can be released
 *   Examples: hotel bookings, equipment rentals, cart reservations
 * 
 * - RETURN: Stock returned to inventory (e.g., customer returns)
 *   Creates a positive adjustment to physical stock
 * 
 * - INCREASE: Stock added to inventory (e.g., new purchases, restocking)
 *   Creates a positive adjustment to physical stock
 * 
 * - DECREASE: Stock removed from inventory (e.g., sales, damage, loss)
 *   Creates a negative adjustment to physical stock
 * 
 * Usage Flow:
 * 1. INCREASE/DECREASE: Direct physical stock changes (COMPLETED status)
 * 2. CLAIMED: Temporary allocation (PENDING status, can be released)
 * 3. RETURN: Special case of INCREASE for returned items
 */
enum StockType: string
{
    case CLAIMED = 'claimed';
    case RETURN = 'return';
    case INCREASE = 'increase';
    case DECREASE = 'decrease';

    public function label(): string
    {
        return match ($this) {
            self::CLAIMED => 'Claimed',
            self::RETURN => 'Return',
            self::INCREASE => 'Increase',
            self::DECREASE => 'Decrease',
        };
    }
}
