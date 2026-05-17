<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when Stripe reports a refund has been processed — separate
 * from {@see OrderRefunded} (which is the package's domain event) so
 * listeners can distinguish refund decisions made internally from refunds
 * confirmed by the gateway.
 */
class StripeRefundProcessed
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public ?Order $order,
        public float $amount,
        public array $payload,
    ) {}
}
