<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Laravel\Cashier\Subscription;

/**
 * Dispatched when a subscription renews into a new billing cycle (recurring invoice paid). Extend access to the new period here.
 *
 * Carries the Cashier subscription (the package's {@see \Blax\Shop\Models\Subscription}
 * is a Cashier subscription, so this works for host subclasses too). Listen
 * here to drive fulfillment without coupling to billing internals.
 */
class SubscriptionRenewed
{
    use Dispatchable, SerializesModels;

    public function __construct(public Subscription $subscription) {}
}
