<?php

namespace Blax\Shop\Events;

use Blax\Shop\Models\ProductPurchase;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by {@see \Blax\Shop\Traits\HasLoanLifecycle::markReturned()} after
 * the loan's meta.returned_at is stamped and status flipped to completed.
 *
 * Listeners can use this to:
 *   - restock the item ($loan->purchasable->increaseStock(...))
 *   - finalise billing ($loan->accruedCost() is now stable)
 *   - send "thanks for returning" notifications
 *   - record audit-log entries
 */
class LoanReturned
{
    use Dispatchable, SerializesModels;

    public function __construct(public ProductPurchase $loan) {}
}
