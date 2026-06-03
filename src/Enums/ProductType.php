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
    /**
     * Service: an intangible/served product (subscriptions, access licences,
     * consulting) with no physical stock. Behaves like SIMPLE for cart/stock
     * purposes; the distinct type just lets hosts and reporting tell goods
     * from services apart.
     */
    case SERVICE = 'service';
    /**
     * Subscription: a service sold on a recurring basis (the actual cadence
     * lives on the {@see \Blax\Shop\Models\ProductPrice} as a recurring price).
     * Like SERVICE it carries no physical stock; the distinct type lets hosts
     * model "this product is fundamentally a subscription" for catalogue and
     * reporting.
     */
    case SUBSCRIPTION = 'subscription';

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
            self::SERVICE => 'Service',
            self::SUBSCRIPTION => 'Subscription',
        };
    }
}
