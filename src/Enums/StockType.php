<?php

namespace Blax\Shop\Enums;

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
