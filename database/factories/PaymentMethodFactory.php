<?php

namespace Blax\Shop\Database\Factories;

use Blax\Shop\Models\PaymentMethod;
use Blax\Shop\Models\PaymentProviderIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentMethodFactory extends Factory
{
    protected $model = PaymentMethod::class;

    public function definition(): array
    {
        $brand = $this->faker->randomElement(['visa', 'mastercard', 'amex', 'discover']);
        $expMonth = $this->faker->numberBetween(1, 12);
        $expYear = $this->faker->numberBetween(now()->year, now()->year + 5);

        return [
            'payment_provider_identity_id' => PaymentProviderIdentity::factory(),
            'provider_payment_method_id' => 'pm_' . $this->faker->bothify('??????????????'),
            'type' => 'card',
            'name' => null,
            'last_digits' => $this->faker->numberBetween(1000, 9999),
            'brand' => $brand,
            'exp_month' => $expMonth,
            'exp_year' => $expYear,
            'is_default' => false,
            'is_active' => true,
            'meta' => json_encode(new \stdClass()),
        ];
    }

    public function card(): static
    {
        return $this->state([
            'type' => 'card',
            'provider_payment_method_id' => 'pm_' . $this->faker->bothify('??????????????'),
        ]);
    }

    public function bankAccount(): static
    {
        return $this->state([
            'type' => 'bank_account',
            'provider_payment_method_id' => 'ba_' . $this->faker->bothify('??????????????'),
            'brand' => null,
            'exp_month' => null,
            'exp_year' => null,
        ]);
    }

    public function default(): static
    {
        return $this->state([
            'is_default' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state([
            'is_active' => false,
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'exp_month' => $this->faker->numberBetween(1, 12),
            'exp_year' => now()->year - 1,
        ]);
    }

    public function visa(): static
    {
        return $this->state(['brand' => 'visa']);
    }

    public function mastercard(): static
    {
        return $this->state(['brand' => 'mastercard']);
    }

    public function amex(): static
    {
        return $this->state(['brand' => 'amex']);
    }

    public function withName(string $name): static
    {
        return $this->state(['name' => $name]);
    }

    public function forProviderIdentity(PaymentProviderIdentity $identity): static
    {
        return $this->state([
            'payment_provider_identity_id' => $identity->id,
        ]);
    }
}
