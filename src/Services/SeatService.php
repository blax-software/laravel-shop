<?php

declare(strict_types=1);

namespace Blax\Shop\Services;

use Blax\Shop\Enums\SeatStatus;
use Blax\Shop\Models\LicenseSeat;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Turns a seat-based purchase/subscription of `quantity = N` into N assignable
 * {@see LicenseSeat} rows, and keeps that pool in sync across the billing
 * lifecycle. All operations are idempotent — safe to re-run on duplicate
 * webhooks or repeated lifecycle hooks.
 *
 * Assignment / reassignment / reclaim live on the {@see LicenseSeat} model
 * itself; this service only owns pool provisioning and renewal sync.
 */
class SeatService
{
    /**
     * Ensure a one-time purchase has exactly `quantity` seats. Seats from a
     * plain purchase have no expiry.
     *
     * @return Collection<int, LicenseSeat>
     */
    public function provisionForPurchase(ProductPurchase $purchase): Collection
    {
        $product = $purchase->product ?? $purchase->purchasable;

        if (! $product) {
            return collect();
        }

        return $this->ensureSeats(
            (string) $product->getKey(),
            ['product_purchase_id' => (string) $purchase->getKey()],
            max(0, (int) $purchase->quantity),
            null,
        );
    }

    /**
     * Ensure a subscription has `quantity` seats and that every seat's grant is
     * valid until `$expiresAt`. On renewal this extends the expiry of held
     * seats and re-fires their grants so access rolls into the new cycle.
     *
     * @return Collection<int, LicenseSeat>
     */
    public function provisionForSubscription(
        Subscription $subscription,
        Model $product,
        int $quantity,
        ?Carbon $expiresAt = null,
    ): Collection {
        $seats = $this->ensureSeats(
            (string) $product->getKey(),
            ['subscription_id' => (string) $subscription->getKey()],
            max(0, $quantity),
            $expiresAt,
        );

        foreach ($seats as $seat) {
            if ($expiresAt && (! $seat->expires_at || ! $seat->expires_at->equalTo($expiresAt))) {
                $seat->forceFill(['expires_at' => $expiresAt])->save();
            }

            if ($seat->status === SeatStatus::ASSIGNED) {
                $seat->refreshGrant();
            }
        }

        return $seats;
    }

    /**
     * Get (or top up to) `$target` active seats for a source, creating any that
     * are missing as UNASSIGNED. Never destroys existing seats — shrinking a
     * pool is a deliberate `retire()` decision left to the caller.
     *
     * @param  array<string, string>  $source
     * @return Collection<int, LicenseSeat>
     */
    protected function ensureSeats(string $productId, array $source, int $target, ?Carbon $expiresAt): Collection
    {
        /** @var class-string<LicenseSeat> $model */
        $model = config('shop.models.license_seat', LicenseSeat::class);

        $seats = $model::query()
            ->where($source)
            ->whereIn('status', [SeatStatus::UNASSIGNED->value, SeatStatus::ASSIGNED->value])
            ->orderBy('created_at')
            ->get();

        for ($i = $seats->count(); $i < $target; $i++) {
            $seats->push($model::create(array_merge($source, [
                'product_id' => $productId,
                'status' => SeatStatus::UNASSIGNED,
                'expires_at' => $expiresAt,
            ])));
        }

        return $seats;
    }
}
