<?php

declare(strict_types=1);

namespace Blax\Shop\Exceptions;

use Exception;

class TaxRateNotConfiguredException extends Exception
{
    public function __construct(
        string $message = 'No tax rate configured (config shop.tax.rates is empty) while shop.tax.require is enabled; refusing to bill a non-exempt charge at 0% tax.'
    ) {
        parent::__construct($message);
    }
}
