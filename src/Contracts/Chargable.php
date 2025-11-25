<?php

namespace Blax\Shop\Contracts;

interface Chargable
{
    public function getDefaultPaymentMethod(): ?string;

    public function paymentMethods(): array;
}
