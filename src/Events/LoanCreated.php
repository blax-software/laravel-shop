<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\ProductPurchase;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a host app has just created a loan — i.e. a ProductPurchase
 * representing a checked-out item that the borrower will return later.
 *
 * The package doesn't dispatch this itself because the same model also
 * represents cart-stage rows, plain e-commerce purchases, and bookings;
 * only the host app knows when a particular create() means "loan started".
 *
 * Host apps should call:
 *
 *     event(new LoanCreated($purchase));
 *
 * right after persisting the purchase. Bind a listener if you need to send
 * welcome / receipt emails, write to an audit log, etc.
 */
class LoanCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public ProductPurchase $loan) {}
}
