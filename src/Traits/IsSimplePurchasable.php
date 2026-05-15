<?php

declare(strict_types=1);

namespace Blax\Shop\Traits;

use Blax\Shop\Contracts\Cartable;
use Blax\Shop\Contracts\Purchasable;
use Blax\Shop\Models\ProductPurchase;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Drop-in trait for app models that want to be Cartable + Purchasable without
 * subclassing {@see \Blax\Shop\Models\Product}.
 *
 *     class Book extends Model implements Cartable, Purchasable
 *     {
 *         use HasUuids, IsSimplePurchasable;
 *     }
 *
 * Defaults shipped by this trait:
 *   - free price (override `getCurrentPrice()` / `getPriceAttribute()` for billing)
 *   - never on sale
 *   - `decreaseStock()` / `increaseStock()` are no-ops returning true
 *   - `purchases()` polymorphic relation against {@see ProductPurchase}
 *
 * If the host model needs to track availability, **override the
 * `decreaseStock()` and `increaseStock()` methods** — that's the contract the
 * package already exposes for inventory mutations. Typical override:
 *
 *     public function decreaseStock(int $quantity = 1): bool
 *     {
 *         return (bool) static::whereKey($this->getKey())
 *             ->where('available_copies', '>=', $quantity)
 *             ->update(['available_copies' => DB::raw('available_copies - '.$quantity)]);
 *     }
 *
 * Host model still needs to declare `implements Cartable, Purchasable` —
 * the trait satisfies the contract methods but cannot apply interfaces.
 */
trait IsSimplePurchasable
{
    /** @return MorphMany<ProductPurchase, $this> */
    public function purchases(): MorphMany
    {
        $purchaseModel = config('shop.models.product_purchase', ProductPurchase::class);

        return $this->morphMany($purchaseModel, 'purchasable');
    }

    public function getCurrentPrice(): ?float
    {
        return 0.0;
    }

    public function getPriceAttribute(): ?float
    {
        return 0.0;
    }

    public function isOnSale(): bool
    {
        return false;
    }

    public function decreaseStock(int $quantity = 1): bool
    {
        return true;
    }

    public function increaseStock(int $quantity = 1): bool
    {
        return true;
    }
}
