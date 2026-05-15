<?php

declare(strict_types=1);

namespace Blax\Shop\Enums;

enum PricingStrategy: string
{
    case LOWEST = 'lowest';
    case HIGHEST = 'highest';
    case AVERAGE = 'average';

    public function label(): string
    {
        return match ($this) {
            self::LOWEST => 'Lowest',
            self::HIGHEST => 'Highest',
            self::AVERAGE => 'Average',
        };
    }

    public static function default(): self
    {
        return self::LOWEST;
    }
}
