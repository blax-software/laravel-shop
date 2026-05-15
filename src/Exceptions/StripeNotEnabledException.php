<?php

declare(strict_types=1);

namespace Blax\Shop\Exceptions;

use Exception;

class StripeNotEnabledException extends Exception
{
    public function __construct(string $message = "Stripe is not enabled.")
    {
        parent::__construct($message);
    }
}
