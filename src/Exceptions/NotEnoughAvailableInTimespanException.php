<?php

namespace Blax\Shop\Exceptions;

use Exception;

class NotEnoughAvailableInTimespanException extends Exception
{
    public function __construct(
        public readonly string $productName,
        public readonly int $requested,
        public readonly int $available,
        public readonly \DateTimeInterface $from,
        public readonly \DateTimeInterface $until,
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        if (empty($message)) {
            $message = "Not enough '{$productName}' available in the requested timespan. Requested: {$requested}, Available: {$available}.";
        }

        parent::__construct($message, $code, $previous);
    }
}
