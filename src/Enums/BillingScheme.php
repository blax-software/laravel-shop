<?php

namespace Blax\Shop\Enums;

enum BillingScheme: string
{
    case PER_UNIT = 'per_unit';
    case TIERED = 'tiered';

    public function label(): string
    {
        return match ($this) {
            self::PER_UNIT => 'Per Unit',
            self::TIERED => 'Tiered',
        };
    }
}
