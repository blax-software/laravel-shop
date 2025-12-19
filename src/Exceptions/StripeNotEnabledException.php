<?php

namespace Blax\Shop\Exceptions;

use Exception;

class StripeNotEnabledException extends Exception
{
    public function __construct(string $message = "Stripe is not enabled.")
    {
        parent::__construct($message);
    }
}
