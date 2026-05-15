<?php

declare(strict_types=1);

namespace Blax\Shop\Exceptions;

use Exception;

/**
 * Base exception for any condition that prevents an item from being
 * legally added to a cart or charged.
 *
 * Subclasses describe the specific failure mode while sharing a single
 * catchable parent — callers that just want to translate any
 * "not-purchasable" outcome to a 422 response can `catch (NotPurchasable
 * $e)` without enumerating every concrete case.
 *
 * Concrete subclasses shipped by the package:
 *
 *  - {@see HasNoPriceException} — no price record exists.
 *  - {@see HasNoDefaultPriceException} — prices exist but none is marked default.
 *  - {@see InvalidBookingConfigurationException} — booking product is misconfigured.
 *  - {@see InvalidPoolConfigurationException} — pool product is misconfigured.
 */
class NotPurchasable extends Exception
{
    public function __construct(
        string $message = 'This item cannot be purchased in its current state.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
