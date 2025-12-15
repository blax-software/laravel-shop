<?php

namespace Blax\Shop\Enums;

enum PurchaseStatus: string
{
    case PENDING = 'pending';
    case UNPAID = 'unpaid';
    case COMPLETED = 'completed';
    case REFUNDED = 'refunded';
    case CART = 'cart';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::UNPAID => 'Unpaid',
            self::COMPLETED => 'Completed',
            self::REFUNDED => 'Refunded',
            self::CART => 'Cart',
            self::FAILED => 'Failed',
        };
    }
}
