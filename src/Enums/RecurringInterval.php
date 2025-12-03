<?php

namespace Blax\Shop\Enums;

enum RecurringInterval: string
{
    case DAY = 'day';
    case WEEK = 'week';
    case MONTH = 'month';
    case YEAR = 'year';
    case QUARTER = 'quarter';

    public function label(): string
    {
        return match ($this) {
            self::DAY => 'Day',
            self::WEEK => 'Week',
            self::MONTH => 'Month',
            self::QUARTER => 'Quarter',
            self::YEAR => 'Year',
        };
    }
}
