<?php

namespace Blax\Shop\Exceptions;

use Exception;

class CartableInterfaceException extends Exception
{
    public function __construct(string $message = "Item must implement the Cartable interface.")
    {
        parent::__construct($message);
    }
}
