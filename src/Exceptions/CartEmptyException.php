<?php

declare(strict_types=1);

namespace Blax\Shop\Exceptions;

use Exception;

class CartEmptyException extends Exception
{
    public function __construct(string $message = "Cart is empty.")
    {
        parent::__construct($message);
    }
}
