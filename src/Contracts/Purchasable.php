<?php

declare(strict_types=1);

namespace Blax\Shop\Contracts;

/**
 * Contract for models that can be priced, stocked, and recorded as a purchase.
 *
 * Where {@see Cartable} is a pure marker, `Purchasable` is the *behavioural*
 * surface the cart, checkout, and order machinery rely on:
 *
 *  - **Pricing** — {@see self::getCurrentPrice()} and
 *    {@see self::getPriceAttribute()} resolve the unit price the cart
 *    will charge. Sale-aware implementations toggle on
 *    {@see self::isOnSale()}.
 *  - **Inventory** — {@see self::decreaseStock()} runs when a unit is
 *    consumed (cart checkout, loan checkout); {@see self::increaseStock()}
 *    when one is returned. Both return `bool` so the caller can detect
 *    a sold-out condition without an exception.
 *  - **Audit trail** — {@see self::purchases()} exposes the historic
 *    record of consumption events as a polymorphic relation against
 *    {@see \Blax\Shop\Models\ProductPurchase}.
 *
 * Two reference implementations ship in the package:
 *
 *  - {@see \Blax\Shop\Models\Product} — the full e-commerce model.
 *  - {@see \Blax\Shop\Traits\IsSimplePurchasable} — drop-in trait for
 *    host models that want the contract without subclassing `Product`.
 *
 * @see \Blax\Shop\Models\ProductPurchase
 * @see \Blax\Shop\Traits\HasStocks Reference inventory implementation.
 */
interface Purchasable
{
    /**
     * Resolve the unit price this item should currently be charged at,
     * in the package's monetary unit (integer cents floated for math).
     *
     * Return `null` when no price is configured; callers (cart, order
     * total) treat `null` as "free" or as an error depending on context.
     */
    public function getCurrentPrice(): ?float;

    /**
     * Eloquent attribute accessor for `$model->price` — the same value
     * {@see self::getCurrentPrice()} resolves, exposed for convenience
     * in Blade / JSON serialization.
     */
    public function getPriceAttribute(): ?float;

    /**
     * Whether the item is currently selling at a discounted price.
     *
     * Pricing logic uses this to pick between {@see self::getCurrentPrice()}
     * and a sale price source. Implementations that don't support sales
     * may always return `false`.
     */
    public function isOnSale(): bool;

    /**
     * Consume `$quantity` units of inventory.
     *
     * Returns `true` when stock was successfully reduced (or when the
     * implementation doesn't track stock and so always reports success).
     * Returns `false` to signal "not enough"; implementations may
     * alternatively throw {@see \Blax\Shop\Exceptions\NotEnoughStockException}.
     *
     * Race-safety: implementations should prefer an atomic conditional
     * UPDATE over a `lockForUpdate` dance — see the laravel-shop
     * principles doc for the canonical pattern.
     */
    public function decreaseStock(int $quantity = 1): bool;

    /**
     * Restore `$quantity` units to inventory (e.g. on a return / refund).
     *
     * Symmetric to {@see self::decreaseStock()}. Returning `false` means
     * the implementation declined to record the change (typically because
     * stock management is disabled on this record).
     */
    public function increaseStock(int $quantity = 1): bool;

    /**
     * Polymorphic relation to the purchase history.
     *
     * Implementations return a {@see \Illuminate\Database\Eloquent\Relations\MorphMany}
     * pointing at {@see \Blax\Shop\Models\ProductPurchase} via the
     * `purchasable_*` columns. The return type is intentionally
     * unconstrained on the interface to preserve backward compatibility
     * — see the canonical implementations on `Product` and `IsSimplePurchasable`.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany<\Blax\Shop\Models\ProductPurchase, $this>
     */
    public function purchases();
}
