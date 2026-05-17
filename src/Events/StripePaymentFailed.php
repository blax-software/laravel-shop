<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired on Stripe payment failure (card declined, authentication failed,
 * provider error). Listeners typically retry, notify the shopper, or roll
 * back any optimistic state the order had assumed.
 */
class StripePaymentFailed
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public ?Order $order,
        public array $payload,
        public ?string $reason = null,
    ) {}
}
