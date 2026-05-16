<?php

declare(strict_types=1);

namespace Blax\Shop\Exceptions;

use Exception;

class NotLoanableProductException extends Exception
{
    public function __construct(string $message = "This method is only for loanable products.")
    {
        parent::__construct($message);
    }
}
