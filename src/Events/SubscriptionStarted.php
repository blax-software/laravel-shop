<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Laravel\Cashier\Subscription;

/**
 * Dispatched when a new subscription becomes active (initial checkout / first invoice). Grant access for the first billing cycle here.
 *
 * Carries the Cashier subscription (the package's {@see \Blax\Shop\Models\Subscription}
 * is a Cashier subscription, so this works for host subclasses too). Listen
 * here to drive fulfillment without coupling to billing internals.
 */
class SubscriptionStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Subscription $subscription) {}
}
