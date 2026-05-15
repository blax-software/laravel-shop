<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\ProductPurchase;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired by {@see \Blax\Shop\Traits\HasLoanLifecycle::extend()} after the
 * due date is pushed forward and the extensions_used counter ticks.
 *
 * Listeners receive the loan plus the number of weeks that were added on
 * this particular extension call — useful for "your loan has been extended
 * by N weeks" notifications without recomputing from the meta column.
 */
class LoanExtended
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public ProductPurchase $loan,
        public int $addedWeeks,
    ) {}
}
