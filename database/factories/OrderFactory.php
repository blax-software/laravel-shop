<?php

namespace Blax\Shop\Database\Factories;

use Blax\Shop\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'order_number' => 'ORD-' . strtoupper($this->faker->bothify('####-????')),
            'customer_id' => null,
            'customer_email' => $this->faker->safeEmail(),
            'customer_first_name' => $this->faker->firstName(),
            'customer_last_name' => $this->faker->lastName(),
            'status' => 'pending',
            'payment_status' => 'pending',
            'subtotal' => 0,
            'tax_total' => 0,
            'shipping_total' => 0,
            'discount_total' => 0,
            'total' => 0,
            'currency' => 'USD',
            'payment_method' => 'stripe',
        ];
    }

    public function completed(): static
    {
        return $this->state([
            'status' => 'completed',
            'payment_status' => 'paid',
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status' => 'cancelled',
            'payment_status' => 'failed',
        ]);
    }
}
