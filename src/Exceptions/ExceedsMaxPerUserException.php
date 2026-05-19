<?php

declare(strict_types=1);

namespace Blax\Shop\Exceptions;

use Exception;

/**
 * Thrown when adding the requested quantity to a cart would push the
 * customer's lifetime purchase total (already-purchased + current cart)
 * above the product's configured `max_per_user` limit.
 *
 * "Already purchased" is sourced from {@see \Blax\Shop\Models\ProductPurchase}
 * rows in any non-cancelled status (PENDING, UNPAID, COMPLETED). Guest carts
 * — i.e. carts without a `customer_id` — are not subject to this cap because
 * there's no identity to count against; the cap kicks in once the cart is
 * attached to a user.
 *
 * Raised from {@see \Blax\Shop\Models\Cart::addToCart()}.
 */
class ExceedsMaxPerUserException extends Exception
{
    public function __construct(
        string $message = 'Adding this quantity would exceed the per-user purchase limit for this product.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function forProduct(
        string $productName,
        int $max,
        int $alreadyPurchased,
        int $alreadyInCart,
        int $requested
    ): self {
        $consumed = $alreadyPurchased + $alreadyInCart;
        $remaining = max(0, $max - $consumed);
        return new self(
            "Product '{$productName}' allows a maximum of {$max} per customer. " .
                "You've already purchased {$alreadyPurchased} and have {$alreadyInCart} in the cart " .
                "(requested {$requested}). You may add up to {$remaining} more."
        );
    }
}
