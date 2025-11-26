<?php

namespace Blax\Shop\Database\Factories;

use Blax\Shop\Models\PaymentProviderIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentProviderIdentityFactory extends Factory
{
    protected $model = PaymentProviderIdentity::class;

    public function definition(): array
    {
        return [
            'provider_name' => $this->faker->randomElement(['stripe', 'paypal', 'square']),
            'customer_identification_id' => 'cus_' . $this->faker->bothify('??????????????'),
            'meta' => json_encode(new \stdClass()),
        ];
    }

    public function stripe(): static
    {
        return $this->state([
            'provider_name' => 'stripe',
            'customer_identification_id' => 'cus_' . $this->faker->bothify('??????????????'),
        ]);
    }

    public function paypal(): static
    {
        return $this->state([
            'provider_name' => 'paypal',
            'customer_identification_id' => $this->faker->uuid(),
        ]);
    }

    public function forCustomer($customer): static
    {
        return $this->state([
            'customer_type' => get_class($customer),
            'customer_id' => $customer->id,
        ]);
    }
}
