<?php

namespace Blax\Shop\Database\Factories;

use Blax\Shop\Models\ProductPriceTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductPriceTier> */
class ProductPriceTierFactory extends Factory
{
    protected $model = ProductPriceTier::class;

    public function definition(): array
    {
        return [
            'up_to' => null,
            'unit_amount' => 0,
            'flat_amount' => null,
            'sort_order' => 0,
        ];
    }
}
