<?php

declare(strict_types=1);

namespace Blax\Shop\Models;

use Blax\Shop\Events\SubscriptionCanceled;
use Blax\Shop\Events\SubscriptionRenewed;
use Blax\Shop\Events\SubscriptionStarted;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * Cashier-backed subscription, in the package's UUID convention, linked to the
 * {@see Product} it sells so the billing lifecycle can drive product actions
 * and fulfillment events.
 *
 * This is the package's missing "subscription lifecycle": Cashier owns the
 * billing mechanics (status, trials, grace, proration, Stripe sync); this
 * subclass adds the commerce link — `product()` — and the lifecycle hooks
 * ({@see recordStarted()}, {@see recordRenewed()}, {@see recordCanceled()})
 * that fire package events and run the product's {@see ProductAction}s with a
 * subscription + expiry context, so any host app gets duration-aware grants
 * for free.
 *
 * Host apps that already subclass Cashier's Subscription can point
 * `shop.models.subscription` at their model (and set
 * `shop.subscriptions.register_cashier_models = false`) — everything here is
 * resolved through config, never hard-coded class references.
 *
 * @property string|null $product_id
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SubscriptionItem> $items
 */
class Subscription extends CashierSubscription
{
    use HasUuids;

    public function getTable()
    {
        return config('shop.tables.subscriptions', 'subscriptions');
    }

    /**
     * The product this subscription sells (cached on `product_id`).
     *
     * @return BelongsTo<Model, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(config('shop.models.product', Product::class), 'product_id');
    }

    /**
     * Subscription line items — uses the configured item model.
     *
     * @return HasMany<SubscriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(
            config('shop.models.subscription_item', SubscriptionItem::class)
        );
    }

    /**
     * Resolve (and cache) the product this subscription sells: the linked
     * `product_id` first, else the first item's `stripe_product` mapped to a
     * Product via `stripe_product_id`.
     */
    public function resolveProduct(): ?Model
    {
        $productModel = config('shop.models.product', Product::class);

        if ($this->product_id) {
            $product = $productModel::find($this->product_id);
            if ($product) {
                return $product;
            }
        }

        $stripeProduct = $this->items()->first()?->stripe_product;
        if ($stripeProduct) {
            $product = $productModel::where('stripe_product_id', $stripeProduct)->first();
            if ($product && ! $this->product_id) {
                $this->forceFill(['product_id' => $product->getKey()])->saveQuietly();
            }

            return $product;
        }

        return null;
    }

    /**
     * Run the linked product's actions for a subscription lifecycle event,
     * passing the subscription and an optional access-expiry override so
     * grants can be scoped to the billing cycle.
     */
    public function callProductActions(
        ?\Carbon\Carbon $expiresAtOverride = null,
        ?string $event = null
    ): void {
        $product = $this->resolveProduct();
        if (! $product || ! method_exists($product, 'callActions')) {
            return;
        }

        $event ??= config('shop.subscriptions.started_event', 'subscription.started');

        $product->callActions($event, null, [
            'subscription' => $this,
            'expiresAtOverride' => $expiresAtOverride,
        ]);
    }

    /**
     * Mark a new subscription as started: fire {@see SubscriptionStarted} and
     * run the product's actions for the configured "started" event.
     */
    public function recordStarted(?\Carbon\Carbon $expiresAtOverride = null): void
    {
        $this->callProductActions($expiresAtOverride, config('shop.subscriptions.started_event', 'subscription.started'));
        SubscriptionStarted::dispatch($this);
    }

    /**
     * Mark a renewal (new billing cycle): fire {@see SubscriptionRenewed} and
     * re-run the product's actions so grants extend to the new period.
     */
    public function recordRenewed(?\Carbon\Carbon $expiresAtOverride = null): void
    {
        $this->callProductActions($expiresAtOverride, config('shop.subscriptions.renewed_event', 'subscription.renewed'));
        SubscriptionRenewed::dispatch($this);
    }

    /**
     * Mark a cancellation: fire {@see SubscriptionCanceled}. Access is left to
     * lapse at period end (Cashier grace handling) rather than revoked here.
     */
    public function recordCanceled(): void
    {
        SubscriptionCanceled::dispatch($this);
    }
}
