<?php

declare(strict_types=1);

namespace Blax\Shop\Exceptions;

use Exception;

class InvalidPricingStrategyException extends Exception
{
    public function __construct(string $strategy)
    {
        parent::__construct("Invalid pricing strategy: {$strategy}");
    }
}
