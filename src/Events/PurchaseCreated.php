<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\ProductPurchase;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Generic counterpart to {@see LoanCreated} / {@see BookingConfirmed} —
 * fires for any newly created ProductPurchase row regardless of domain
 * shape. Listen here when you don't care which kind of purchase it is.
 *
 * Hosts can rely on the model's $dispatchesEvents map, or call
 * `event(new PurchaseCreated($purchase))` directly when assembling rows
 * outside the normal save() path.
 */
class PurchaseCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public ProductPurchase $purchase) {}
}
