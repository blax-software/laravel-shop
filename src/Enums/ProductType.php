<?php

namespace Blax\Shop\Enums;

enum ProductType: string
{
    case SIMPLE = 'simple';
    case VARIABLE = 'variable';
    case GROUPED = 'grouped';
    case EXTERNAL = 'external';
    case BOOKING = 'booking';
    case VARIATION = 'variation';

    public function label(): string
    {
        return match ($this) {
            self::SIMPLE => 'Simple',
            self::VARIABLE => 'Variable',
            self::GROUPED => 'Grouped',
            self::EXTERNAL => 'External',
            self::BOOKING => 'Booking',
            self::VARIATION => 'Variation',
        };
    }
}
