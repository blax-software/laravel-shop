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
     * Assignable license seats this (seat-based) subscription provisions —
     * sized to the billed quantity and expiring at the period end. Empty for
     * ordinary subscriptions.
     *
     * @return HasMany<LicenseSeat, $this>
     */
    public function seats(): HasMany
    {
        return $this->hasMany(
            config('shop.models.license_seat', LicenseSeat::class),
            'subscription_id'
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
     * Resolve EVERY product this subscription sells — one entry per line item —
     * so multi-product (bundle) subscriptions fulfill ALL of them, not just the
     * first. Each entry is ['product' => Model, 'item' => SubscriptionItem].
     * Products are de-duplicated by key. Falls back to the single linked
     * `product_id` when no item maps to a product, and caches the first
     * resolved product on `product_id` for single-product callers.
     *
     * @return \Illuminate\Support\Collection<int, array{product: Model, item: ?Model}>
     */
    public function resolveProducts(): \Illuminate\Support\Collection
    {
        $productModel = config('shop.models.product', Product::class);
        $resolved = collect();
        $seen = [];

        foreach ($this->items as $item) {
            $stripeProduct = $item->stripe_product ?? null;
            if (! $stripeProduct) {
                continue;
            }
            $product = $productModel::where('stripe_product_id', $stripeProduct)->first();
            if (! $product) {
                continue;
            }
            $key = (string) $product->getKey();
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $resolved->push(['product' => $product, 'item' => $item]);
        }

        if ($resolved->isEmpty() && $this->product_id) {
            $product = $productModel::find($this->product_id);
            if ($product) {
                $resolved->push(['product' => $product, 'item' => $this->items->first()]);
            }
        }

        // Back-compat: cache the first resolved product on product_id.
        if (! $this->product_id && $resolved->isNotEmpty()) {
            $this->forceFill(['product_id' => $resolved->first()['product']->getKey()])->saveQuietly();
        }

        return $resolved;
    }

    /**
     * Run EVERY sold product's actions for a subscription lifecycle event,
     * passing the subscription, the originating line item, and an optional
     * access-expiry override so grants can be scoped to the billing cycle.
     * Multi-product (bundle) subscriptions now grant all line items.
     */
    public function callProductActions(
        ?\Carbon\Carbon $expiresAtOverride = null,
        ?string $event = null
    ): void {
        $event ??= config('shop.subscriptions.started_event', 'subscription.started');

        foreach ($this->resolveProducts() as $entry) {
            $product = $entry['product'] ?? null;
            if (! $product || ! method_exists($product, 'callActions')) {
                continue;
            }

            $product->callActions($event, null, [
                'subscription' => $this,
                'subscriptionItem' => $entry['item'] ?? null,
                'expiresAtOverride' => $expiresAtOverride,
            ]);
        }
    }

    /**
     * For each seat-based product on this subscription, ensure the seat pool
     * matches the billed quantity and every held seat's grant is valid until
     * `$expiresAt`. No-op for ordinary (non-seat) products, so this is safe to
     * call from every lifecycle hook.
     */
    protected function provisionSeats(?\Carbon\Carbon $expiresAt = null): void
    {
        if (! config('shop.seats.enabled', true)) {
            return;
        }

        $seatService = app(\Blax\Shop\Services\SeatService::class);

        foreach ($this->resolveProducts() as $entry) {
            $product = $entry['product'] ?? null;
            $item = $entry['item'] ?? null;

            if (! $product || ! method_exists($product, 'isSeatBased') || ! $product->isSeatBased()) {
                continue;
            }

            $quantity = (int) ($item?->quantity ?? $this->quantity ?? 1);
            $seatService->provisionForSubscription($this, $product, $quantity, $expiresAt);
        }
    }

    /**
     * Mark a new subscription as started: fire {@see SubscriptionStarted} and
     * run the product's actions for the configured "started" event.
     */
    public function recordStarted(?\Carbon\Carbon $expiresAtOverride = null): void
    {
        $this->callProductActions($expiresAtOverride, config('shop.subscriptions.started_event', 'subscription.started'));
        $this->provisionSeats($expiresAtOverride);
        SubscriptionStarted::dispatch($this);
    }

    /**
     * Mark a renewal (new billing cycle): fire {@see SubscriptionRenewed} and
     * re-run the product's actions so grants extend to the new period.
     */
    public function recordRenewed(?\Carbon\Carbon $expiresAtOverride = null): void
    {
        $this->callProductActions($expiresAtOverride, config('shop.subscriptions.renewed_event', 'subscription.renewed'));
        $this->provisionSeats($expiresAtOverride);
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
