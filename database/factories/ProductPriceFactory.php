<?php

namespace Blax\Shop\Database\Factories;

use Blax\Shop\Models\ProductPrice;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductPriceFactory extends Factory
{
    protected $model = ProductPrice::class;

    public function definition()
    {
        $type = $this->faker->randomElement(['one_time', 'recurring']);
        $unit_amount = $this->faker->randomFloat(2, 100, 40000);
        $sale_unit_amount = $this->faker->randomFloat(2, $unit_amount * 0.5, $unit_amount * 0.80);

        return [
            'type' => $type,
            'billing_scheme' => $this->faker->randomElement(['per_unit', 'tiered']),
            'unit_amount' => $this->faker->randomFloat(2, 1, 1000),
            'currency' => 'EUR',
            'is_default' => false,
            'unit_amount' => $unit_amount,
            'sale_unit_amount' => $sale_unit_amount,
            'interval' => $type === 'recurring' ? $this->faker->randomElement(['day', 'week', 'month', 'quarter', 'year']) : null,
            'interval_count' => $type === 'recurring' ? $this->faker->numberBetween(1, 12) : null,
            'trial_period_days' => $type === 'recurring' ? $this->faker->numberBetween(0, 30) : null,
        ];
    }
}
