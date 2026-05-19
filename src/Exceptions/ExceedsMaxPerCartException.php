<?php

declare(strict_types=1);

namespace Blax\Shop\Exceptions;

use Exception;

/**
 * Thrown when adding the requested quantity to a cart would push the total
 * quantity of a single product above its configured `max_per_cart` limit.
 *
 * Raised from {@see \Blax\Shop\Models\Cart::addToCart()}. Cart-scope only —
 * the cross-purchase cap is enforced by {@see ExceedsMaxPerUserException}.
 */
class ExceedsMaxPerCartException extends Exception
{
    public function __construct(
        string $message = 'Adding this quantity would exceed the per-cart limit for this product.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    public static function forProduct(string $productName, int $max, int $alreadyInCart, int $requested): self
    {
        $remaining = max(0, $max - $alreadyInCart);
        return new self(
            "Product '{$productName}' allows a maximum of {$max} per cart. " .
                "You already have {$alreadyInCart} in the cart and requested {$requested}. " .
                "You may add up to {$remaining} more."
        );
    }
}
