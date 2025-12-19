<?php

namespace Blax\Shop\Exceptions;

use Exception;

class CartDatesRequiredException extends Exception
{
    public function __construct(string $message = "Both 'from' and 'until' dates must be provided together, or both omitted.")
    {
        parent::__construct($message);
    }
}
