<?php

namespace Blax\Shop\Enums;

enum PriceType: string
{
    case ONE_TIME = 'one_time';
    case RECURRING = 'recurring';

    public function label(): string
    {
        return match ($this) {
            self::ONE_TIME => 'One Time',
            self::RECURRING => 'Recurring',
        };
    }
}
