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

        // Realistic price points (in cents)
        $realisticPrices = [
            999,   // $9.99
            1499,  // $14.99
            1999,  // $19.99
            2499,  // $24.99
            2999,  // $29.99
            3999,  // $39.99
            4999,  // $49.99
            5999,  // $59.99
            7999,  // $79.99
            9999,  // $99.99
            12999, // $129.99
            14999, // $149.99
            19999, // $199.99
            24999, // $249.99
            29999, // $299.99
        ];

        $unit_amount = $this->faker->randomElement($realisticPrices);
        $sale_unit_amount = $this->faker->optional(0.3)->passthrough(
            intval($unit_amount * $this->faker->randomFloat(2, 0.7, 0.9))
        );

        return [
            'type' => $type,
            'billing_scheme' => $this->faker->randomElement(['per_unit', 'tiered']),
            'unit_amount' => $unit_amount,
            'currency' => 'EUR',
            'is_default' => false,
            'sale_unit_amount' => $sale_unit_amount,
            'interval' => $type === 'recurring' ? $this->faker->randomElement(['day', 'week', 'month', 'quarter', 'year']) : null,
            'interval_count' => $type === 'recurring' ? $this->faker->numberBetween(1, 12) : null,
            'trial_period_days' => $type === 'recurring' ? $this->faker->numberBetween(0, 30) : null,
        ];
    }
}
