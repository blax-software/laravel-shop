<?php

namespace Blax\Shop\Exceptions;

use Exception;

class ProductMissingAssociationException extends Exception
{
    public function __construct(string $message = "Cannot sync price without associated product.")
    {
        parent::__construct($message);
    }
}
