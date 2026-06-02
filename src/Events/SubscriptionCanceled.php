<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Laravel\Cashier\Subscription;

/**
 * Dispatched when a subscription is canceled. Access typically lapses at period end via Cashier's grace handling rather than being revoked immediately.
 *
 * Carries the Cashier subscription (the package's {@see \Blax\Shop\Models\Subscription}
 * is a Cashier subscription, so this works for host subclasses too). Listen
 * here to drive fulfillment without coupling to billing internals.
 */
class SubscriptionCanceled
{
    use Dispatchable, SerializesModels;

    public function __construct(public Subscription $subscription) {}
}
