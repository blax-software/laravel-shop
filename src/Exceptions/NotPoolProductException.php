<?php

namespace Blax\Shop\Exceptions;

use Exception;

class NotPoolProductException extends Exception
{
    public function __construct(string $message = "This method is only for pool products.")
    {
        parent::__construct($message);
    }
}
