<?php

declare(strict_types=1);

namespace Blax\Shop\Services\PaymentProvider;

use Blax\Shop\Models\Product;
use Illuminate\Support\Collection;

class StripeService
{
    public $stripe;

    public function __construct()
    {
        $this->stripe = new \Stripe\StripeClient(config('shop.payment.stripe.secret_key'));
    }

    /**
     * Create a customer.
     * $data example: ['email'=>'user@example.com','name'=>'John Doe','metadata'=>['order_id'=>123]]
     */
    public function createCustomer(array $data)
    {
        return $this->stripe->customers->create($data);
    }

    /**
     * Retrieve a customer.
     */
    public function getCustomer(string $customerId)
    {
        return $this->stripe->customers->retrieve($customerId);
    }

    /**
     * Update a customer.
     */
    public function updateCustomer(string $customerId, array $data)
    {
        return $this->stripe->customers->update($customerId, $data);
    }

    /**
     * Delete a customer.
     */
    public function deleteCustomer(string $customerId)
    {
        return $this->stripe->customers->delete($customerId);
    }

    /**
     * Create a payment method (setup intent approach).
     * Returns a setup intent that can be confirmed on the client side.
     */
    public function createSetupIntent(string $customerId, array $options = [])
    {
        return $this->stripe->setupIntents->create(array_merge([
            'customer' => $customerId,
        ], $options));
    }

    /**
     * Attach a payment method to a customer.
     */
    public function attachPaymentMethod(string $paymentMethodId, string $customerId)
    {
        return $this->stripe->paymentMethods->attach($paymentMethodId, [
            'customer' => $customerId,
        ]);
    }

    /**
     * Detach a payment method from a customer.
     */
    public function detachPaymentMethod(string $paymentMethodId)
    {
        return $this->stripe->paymentMethods->detach($paymentMethodId);
    }

    /**
     * Retrieve a payment method.
     */
    public function getPaymentMethod(string $paymentMethodId)
    {
        return $this->stripe->paymentMethods->retrieve($paymentMethodId);
    }

    /**
     * List all payment methods for a customer.
     */
    public function listPaymentMethods(string $customerId, array $params = [])
    {
        return $this->stripe->paymentMethods->all(array_merge([
            'customer' => $customerId,
            'type' => 'card',
        ], $params));
    }

    /**
     * Update a payment method.
     */
    public function updatePaymentMethod(string $paymentMethodId, array $data)
    {
        return $this->stripe->paymentMethods->update($paymentMethodId, $data);
    }

    /**
     * Set a payment method as the default for a customer.
     */
    public function setDefaultPaymentMethod(string $customerId, string $paymentMethodId)
    {
        return $this->stripe->customers->update($customerId, [
            'invoice_settings' => [
                'default_payment_method' => $paymentMethodId,
            ],
        ]);
    }
}
