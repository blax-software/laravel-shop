<?php

declare(strict_types=1);

namespace Blax\Shop\Exceptions;

use Exception;

/**
 * Thrown when a stock-consuming operation cannot proceed because the
 * available quantity is below the requested amount.
 *
 * Raised from {@see \Blax\Shop\Traits\HasStocks::decreaseStock()},
 * {@see \Blax\Shop\Traits\HasStocks::adjustStock()}, and
 * {@see \Blax\Shop\Models\ProductStock::claim()}.
 *
 * The message defaults to a generic phrase so the exception can be raised
 * with no arguments from helper paths; pass a richer message at the call
 * site when the calling context can describe the product, requested
 * quantity, or surrounding operation.
 */
class NotEnoughStockException extends Exception
{
    public function __construct(
        string $message = 'Not enough stock available for the requested operation.',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
