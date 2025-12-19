<?php

namespace Blax\Shop\Exceptions;

use Exception;

class PriceCalculationException extends Exception
{
    public function __construct(string $productName, ?int $pricePerDay = null, ?int $days = null)
    {
        $message = "Cart item price calculation resulted in null for '{$productName}'";
        if ($pricePerDay !== null && $days !== null) {
            $message .= " (pricePerDay: {$pricePerDay}, days: {$days})";
        }
        parent::__construct($message);
    }
}
