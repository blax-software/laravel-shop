<?php

namespace Blax\Shop\Enums;

enum StockType: string
{
    case RESERVATION = 'reservation';
    case RETURN = 'return';
    case INCREASE = 'increase';
    case DECREASE = 'decrease';

    public function label(): string
    {
        return match ($this) {
            self::RESERVATION => 'Reservation',
            self::RETURN => 'Return',
            self::INCREASE => 'Increase',
            self::DECREASE => 'Decrease',
        };
    }
}
