<?php

namespace Blax\Shop\Exceptions;

use Exception;

class InvalidDateRangeException extends Exception
{
    public function __construct(
        string $message = "The 'from' date must be before the 'until' date.",
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
