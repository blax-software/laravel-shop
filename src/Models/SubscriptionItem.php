<?php

declare(strict_types=1);

namespace Blax\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Cashier\SubscriptionItem as CashierSubscriptionItem;

/**
 * Cashier-backed subscription line item in the package's UUID convention.
 * Bound to the configured {@see Subscription} model.
 */
class SubscriptionItem extends CashierSubscriptionItem
{
    use HasUuids;

    public function getTable()
    {
        return config('shop.tables.subscription_items', 'subscription_items');
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(
            config('shop.models.subscription', Subscription::class)
        );
    }
}
