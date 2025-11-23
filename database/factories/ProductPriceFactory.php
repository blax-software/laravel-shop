<?php

namespace Blax\Shop\Database\Factories;

use Blax\Shop\Models\ProductPrice;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ProductPriceFactory extends Factory
{
    protected $model = ProductPrice::class;

    public function definition()
    {
        return [
            'unit_amount' => $this->faker->randomFloat(2, 1, 1000),
            'currency' => 'EUR',
            'is_default' => false,
        ];
    }
}
