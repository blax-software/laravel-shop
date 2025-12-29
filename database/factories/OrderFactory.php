<?php

namespace Blax\Shop\Database\Factories;

use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $subtotal = $this->faker->numberBetween(1000, 50000); // 10.00 to 500.00
        $discount = $this->faker->optional(0.3)->numberBetween(100, min(1000, $subtotal));
        $shipping = $this->faker->optional(0.5)->numberBetween(500, 1500);
        $tax = (int) (($subtotal - ($discount ?? 0)) * 0.1); // 10% tax
        $total = $subtotal - ($discount ?? 0) + ($shipping ?? 0) + $tax;

        return [
            'order_number' => 'ORD-' . now()->format('Ymd') . str_pad($this->faker->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'status' => OrderStatus::PENDING,
            'currency' => 'EUR',
            'amount_subtotal' => $subtotal,
            'amount_discount' => $discount ?? 0,
            'amount_shipping' => $shipping ?? 0,
            'amount_tax' => $tax,
            'amount_total' => $total,
            'amount_paid' => 0,
            'amount_refunded' => 0,
        ];
    }

    /**
     * Associate order with a customer.
     */
    public function forCustomer(Model $customer): static
    {
        return $this->state([
            'customer_type' => get_class($customer),
            'customer_id' => $customer->getKey(),
        ]);
    }

    /**
     * Associate order with a cart.
     */
    public function forCart(Cart $cart): static
    {
        return $this->state([
            'cart_id' => $cart->id,
            'customer_type' => $cart->customer_type,
            'customer_id' => $cart->customer_id,
            'currency' => $cart->currency ?? 'EUR',
        ]);
    }

    /**
     * Set order status to pending.
     */
    public function pending(): static
    {
        return $this->state([
            'status' => OrderStatus::PENDING,
        ]);
    }

    /**
     * Set order status to processing.
     */
    public function processing(): static
    {
        return $this->state([
            'status' => OrderStatus::PROCESSING,
        ]);
    }

    /**
     * Set order status to completed.
     */
    public function completed(): static
    {
        return $this->state([
            'status' => OrderStatus::COMPLETED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Set order status to shipped.
     */
    public function shipped(): static
    {
        return $this->state([
            'status' => OrderStatus::SHIPPED,
            'shipped_at' => now(),
        ]);
    }

    /**
     * Set order status to cancelled.
     */
    public function cancelled(): static
    {
        return $this->state([
            'status' => OrderStatus::CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Set order as fully paid.
     */
    public function paid(): static
    {
        return $this->state(function (array $attributes) {
            return [
                'amount_paid' => $attributes['amount_total'] ?? 10000,
                'paid_at' => now(),
                'status' => OrderStatus::PROCESSING,
            ];
        });
    }

    /**
     * Set order as partially paid.
     */
    public function partiallyPaid(int $amount = null): static
    {
        return $this->state(function (array $attributes) use ($amount) {
            $total = $attributes['amount_total'] ?? 10000;
            $paid = $amount ?? (int) ($total * 0.5);

            return [
                'amount_paid' => min($paid, $total - 1),
            ];
        });
    }

    /**
     * Set order as refunded.
     */
    public function refunded(): static
    {
        return $this->state(function (array $attributes) {
            $total = $attributes['amount_total'] ?? 10000;

            return [
                'status' => OrderStatus::REFUNDED,
                'amount_paid' => $total,
                'amount_refunded' => $total,
                'paid_at' => now()->subHour(),
                'refunded_at' => now(),
            ];
        });
    }

    /**
     * Add billing address.
     */
    public function withBillingAddress(): static
    {
        return $this->state([
            'billing_address' => (object) [
                'first_name' => $this->faker->firstName(),
                'last_name' => $this->faker->lastName(),
                'company' => $this->faker->optional()->company(),
                'address_1' => $this->faker->streetAddress(),
                'address_2' => $this->faker->optional()->secondaryAddress(),
                'city' => $this->faker->city(),
                'state' => $this->faker->stateAbbr(),
                'postcode' => $this->faker->postcode(),
                'country' => $this->faker->countryCode(),
                'email' => $this->faker->email(),
                'phone' => $this->faker->phoneNumber(),
            ],
        ]);
    }

    /**
     * Add shipping address.
     */
    public function withShippingAddress(): static
    {
        return $this->state([
            'shipping_address' => (object) [
                'first_name' => $this->faker->firstName(),
                'last_name' => $this->faker->lastName(),
                'company' => $this->faker->optional()->company(),
                'address_1' => $this->faker->streetAddress(),
                'address_2' => $this->faker->optional()->secondaryAddress(),
                'city' => $this->faker->city(),
                'state' => $this->faker->stateAbbr(),
                'postcode' => $this->faker->postcode(),
                'country' => $this->faker->countryCode(),
            ],
        ]);
    }

    /**
     * Set specific amounts.
     */
    public function withAmounts(
        int $subtotal,
        int $discount = 0,
        int $shipping = 0,
        int $tax = 0
    ): static {
        $total = $subtotal - $discount + $shipping + $tax;

        return $this->state([
            'amount_subtotal' => $subtotal,
            'amount_discount' => $discount,
            'amount_shipping' => $shipping,
            'amount_tax' => $tax,
            'amount_total' => $total,
        ]);
    }

    /**
     * Add payment information.
     */
    public function withPayment(
        string $method = 'card',
        string $provider = 'stripe',
        ?string $reference = null
    ): static {
        return $this->state([
            'payment_method' => $method,
            'payment_provider' => $provider,
            'payment_reference' => $reference ?? 'pi_' . $this->faker->regexify('[A-Za-z0-9]{24}'),
        ]);
    }
}
