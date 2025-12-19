<?php

namespace Blax\Shop\Exceptions;

use Exception;

class CartItemMissingInformationException extends Exception
{
    public function __construct(string $productName, string $missingFields)
    {
        parent::__construct("Cart item '{$productName}' is missing required information: {$missingFields}");
    }
}
