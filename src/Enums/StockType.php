<?php

namespace Blax\Shop\Enums;

enum StockType: string
{
    case RESERVATION = 'reservation';
    case ADJUSTMENT = 'adjustment';
    case SALE = 'sale';
    case RETURN = 'return';
    case INCREASE = 'increase';
    case DECREASE = 'decrease';
    case RELEASE = 'release';

    public function label(): string
    {
        return match ($this) {
            self::RESERVATION => 'Reservation',
            self::ADJUSTMENT => 'Adjustment',
            self::SALE => 'Sale',
            self::RETURN => 'Return',
            self::INCREASE => 'Increase',
            self::DECREASE => 'Decrease',
            self::RELEASE => 'Release',
        };
    }
}
