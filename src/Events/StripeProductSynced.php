<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after a Product is pushed to Stripe and the resulting
 * stripe_product_id has been persisted on the model. Useful for confirming
 * the round-trip succeeded or for downstream replication to other catalog
 * systems that derive from Stripe IDs.
 */
class StripeProductSynced
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Product $product,
        public string $stripeProductId,
    ) {}
}
