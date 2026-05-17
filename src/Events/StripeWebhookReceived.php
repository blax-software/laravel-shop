<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Catch-all event fired for every incoming Stripe webhook the package
 * processes. Listen here when you need a single hook for audit/logging or
 * to route to custom handlers based on `$type`. The more specific events
 * ({@see StripePaymentSucceeded}, {@see StripePaymentFailed}, etc.) carry
 * the same payload but only fire for their respective Stripe types.
 *
 * `$payload` is the decoded JSON body as Stripe sent it; do not mutate it.
 */
class StripeWebhookReceived
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public array $payload,
    ) {}
}
