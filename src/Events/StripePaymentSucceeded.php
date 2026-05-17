<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when Stripe confirms a successful charge — both the matched Order
 * (if the package can resolve it from the payment_intent metadata) and the
 * raw Stripe payload are carried so listeners can act on either layer.
 *
 * If the Order resolution failed (orphan payment), $order is null.
 */
class StripePaymentSucceeded
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public ?Order $order,
        public array $payload,
    ) {}
}
