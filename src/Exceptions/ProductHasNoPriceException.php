<?php

namespace Blax\Shop\Exceptions;

use Exception;

class ProductHasNoPriceException extends Exception
{
    public function __construct(string $productName)
    {
        parent::__construct("Product '{$productName}' has no valid price.");
    }
}
