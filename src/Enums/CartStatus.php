<?php

namespace Blax\Shop\Enums;

enum CartStatus: string
{
    case ACTIVE = 'active';
    case ABANDONED = 'abandoned';
    case CONVERTED = 'converted';
    case EXPIRED = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::ABANDONED => 'Abandoned',
            self::CONVERTED => 'Converted',
            self::EXPIRED => 'Expired',
        };
    }
}
