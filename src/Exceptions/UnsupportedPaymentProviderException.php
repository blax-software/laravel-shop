<?php

namespace Blax\Shop\Exceptions;

use Exception;

class UnsupportedPaymentProviderException extends Exception
{
    public function __construct(string $provider)
    {
        parent::__construct("Unsupported payment provider: {$provider}");
    }
}
