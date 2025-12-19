<?php

namespace Blax\Shop\Exceptions;

use Exception;

class CartAlreadyConvertedException extends Exception
{
    public function __construct(string $message = "Cart has already been converted/checked out.")
    {
        parent::__construct($message);
    }
}
