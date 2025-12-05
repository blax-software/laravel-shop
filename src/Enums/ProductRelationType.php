<?php

namespace Blax\Shop\Enums;

enum ProductRelationType: string
{
    case RELATED = 'related';
    case UPSELL = 'upsell';
    case CROSS_SELL = 'cross-sell';
    case VARIATION = 'variation';
    case DOWNSELL = 'downsell';
    case ADD_ON = 'add-on';
    case BUNDLE = 'bundle';
    case SINGLE = 'single';


    public function label(): string
    {
        return match ($this) {
            self::RELATED => 'Related',
            self::UPSELL => 'Upsell',
            self::CROSS_SELL => 'Cross-sell',
            self::VARIATION => 'Variation',
            self::DOWNSELL => 'Downsell',
            self::ADD_ON => 'Add-on',
            self::BUNDLE => 'Bundle',
            self::SINGLE => 'Single',
        };
    }
}
