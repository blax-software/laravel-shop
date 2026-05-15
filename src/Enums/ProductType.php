<?php

declare(strict_types=1);

namespace Blax\Shop\Enums;

enum ProductType: string
{
    case SIMPLE = 'simple';
    case VARIABLE = 'variable';
    case GROUPED = 'grouped';
    case EXTERNAL = 'external';
    case BOOKING = 'booking';
    case VARIATION = 'variation';
    case POOL = 'pool';
    /**
     * Loanable: a checked-out-and-returned product. Pair with
     * {@see \Blax\Shop\Traits\HasLoanLifecycle} on ProductPurchase to operate
     * the borrow → extend → return flow.
     */
    case LOANABLE = 'loanable';

    public function label(): string
    {
        return match ($this) {
            self::SIMPLE => 'Simple',
            self::VARIABLE => 'Variable',
            self::GROUPED => 'Grouped',
            self::EXTERNAL => 'External',
            self::BOOKING => 'Booking',
            self::VARIATION => 'Variation',
            self::POOL => 'Pool',
            self::LOANABLE => 'Loanable',
        };
    }
}
