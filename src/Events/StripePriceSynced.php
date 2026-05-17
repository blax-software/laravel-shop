<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\ProductPrice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after a ProductPrice is pushed to Stripe and the resulting
 * Stripe price ID is persisted. Distinct from {@see StripeProductSynced}
 * so listeners can react specifically when pricing changes propagate.
 */
class StripePriceSynced
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ProductPrice $price,
        public string $stripePriceId,
    ) {}
}
