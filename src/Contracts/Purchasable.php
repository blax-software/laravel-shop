<?php

namespace Blax\Shop\Contracts;

interface Purchasable
{
    public function getCurrentPrice(): ?float;

    public function isOnSale(): bool;

    public function decreaseStock(int $quantity = 1): bool;

    public function increaseStock(int $quantity = 1): void;
}
