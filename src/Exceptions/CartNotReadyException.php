<?php

namespace Blax\Shop\Exceptions;

use Exception;

class CartNotReadyException extends Exception
{
    public function __construct(string $message = "Cart is not ready for checkout. Some items may be unavailable.")
    {
        parent::__construct($message);
    }
}
