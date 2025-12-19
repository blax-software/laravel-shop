<?php

namespace Blax\Shop\Exceptions;

use Exception;

class PoolHasNoItemsException extends Exception
{
    public function __construct(string $message = "Pool product has no single items to claim.")
    {
        parent::__construct($message);
    }
}
