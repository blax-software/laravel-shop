<?php

declare(strict_types=1);

namespace Blax\Shop\Contracts;

/**
 * Marker contract for anything that can be added to a {@see \Blax\Shop\Models\Cart}.
 *
 * The contract is intentionally empty — implementing `Cartable` declares
 * intent. {@see \Blax\Shop\Models\Cart::addToCart()} checks
 * `$item instanceof Cartable` and rejects models that haven't opted in,
 * to prevent accidentally cart-ing rows that have no domain meaning as a
 * purchase line (a `User` or `Address`, say).
 *
 * Implementors typically also implement {@see Purchasable} so the cart
 * can resolve a price, but the two are independent — a `ProductPrice`
 * row is `Cartable` only (the {@see Purchasable} half lives on the
 * parent `Product`).
 *
 * Pair this with {@see \Blax\Shop\Traits\IsSimplePurchasable} on a plain
 * Eloquent model for a no-subclass integration, or extend
 * {@see \Blax\Shop\Models\Product} for the full e-commerce surface.
 *
 * @see \Blax\Shop\Models\Cart::addToCart()
 * @see \Blax\Shop\Exceptions\CartableInterfaceException Thrown when a non-Cartable model is passed to the cart.
 */
interface Cartable
{
}
