<?php

declare(strict_types=1);

namespace Blax\Shop\Exceptions;

use Exception;

/**
 * Thrown when a single cart mixes recurring (subscription) and one-time
 * prices. Stripe Checkout sessions are single-mode — `payment` OR
 * `subscription` — so such a cart cannot be checked out in one session and
 * must be split (or the offending lines removed) by the host application.
 */
class MixedCheckoutModeException extends Exception
{
    public function __construct(
        string $message = "Cannot mix recurring and one-time prices in a single checkout session."
    ) {
        parent::__construct($message);
    }
}
