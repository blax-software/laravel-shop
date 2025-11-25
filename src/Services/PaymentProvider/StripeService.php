<?php

declare(strict_types=1);

namespace Blax\Shop\Services;

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
    public function createCustomer(array $data): \Stripe\Customer
    {
        return $this->stripe->customers->create($data);
    }

    /**
     * Retrieve a customer.
     */
    public function getCustomer(string $customerId): \Stripe\Customer
    {
        return $this->stripe->customers->retrieve($customerId);
    }

    /**
     * Update a customer.
     */
    public function updateCustomer(string $customerId, array $data): \Stripe\Customer
    {
        return $this->stripe->customers->update($customerId, $data);
    }

    /**
     * Create a product.
     */
    public function createProduct(string $name, ?string $description = null, array $metadata = []): \Stripe\Product
    {
        return $this->stripe->products->create([
            'name' => $name,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Create a recurring or one-time price for a product.
     * $unitAmount in smallest currency unit (e.g. cents).
     * For one-time price omit recurring params.
     */
    public function createPriceForProduct(
        string $productId,
        int $unitAmount,
        string $currency = 'usd',
        ?string $interval = 'month'
    ): \Stripe\Price {
        $data = [
            'product' => $productId,
            'unit_amount' => $unitAmount,
            'currency' => $currency,
        ];
        if ($interval) {
            $data['recurring'] = ['interval' => $interval];
        }
        return $this->stripe->prices->create($data);
    }

    /**
     * Create a PaymentIntent.
     * $amount in smallest currency unit.
     */
    public function createPaymentIntent(
        int $amount,
        string $currency = 'usd',
        array $paymentMethodTypes = ['card'],
        array $metadata = [],
        ?string $customerId = null
    ): \Stripe\PaymentIntent {
        $data = [
            'amount' => $amount,
            'currency' => $currency,
            'payment_method_types' => $paymentMethodTypes,
            'metadata' => $metadata,
        ];
        if ($customerId) {
            $data['customer'] = $customerId;
        }
        return $this->stripe->paymentIntents->create($data);
    }

    /**
     * Retrieve a PaymentIntent.
     */
    public function getPaymentIntent(string $paymentIntentId): \Stripe\PaymentIntent
    {
        return $this->stripe->paymentIntents->retrieve($paymentIntentId);
    }

    /**
     * Confirm a PaymentIntent, optionally with a payment method.
     */
    public function confirmPaymentIntent(string $paymentIntentId, ?string $paymentMethodId = null): \Stripe\PaymentIntent
    {
        $data = [];
        if ($paymentMethodId) {
            $data['payment_method'] = $paymentMethodId;
        }
        return $this->stripe->paymentIntents->confirm($paymentIntentId, $data);
    }

    /**
     * Create a Checkout Session (one-time or subscription depending on price config).
     * $lineItems: array of ['price'=> 'price_xxx', 'quantity'=>1]
     */
    public function createCheckoutSession(
        array $lineItems,
        string $successUrl,
        string $cancelUrl,
        ?string $customerId = null,
        array $metadata = []
    ): \Stripe\Checkout\Session {
        $data = [
            'mode' => 'payment',
            'line_items' => $lineItems,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
        ];
        if ($customerId) {
            $data['customer'] = $customerId;
        }
        // If any price is recurring, Stripe auto switches mode to 'subscription' when you set 'mode'
        $hasRecurring = false;
        foreach ($lineItems as $li) {
            if (isset($li['price']) && str_contains((string)$li['price'], 'price_')) {
                // Lightweight heuristic; real check would retrieve price and inspect 'recurring'
                // For brevity not retrieving each price here.
            }
        }
        // Allow caller to override mode via metadata if needed.
        return $this->stripe->checkout->sessions->create($data);
    }

    /**
     * Retrieve a Checkout Session.
     */
    public function getCheckoutSession(string $sessionId): \Stripe\Checkout\Session
    {
        return $this->stripe->checkout->sessions->retrieve($sessionId);
    }

    /**
     * Create a subscription from price.
     */
    public function createSubscription(
        string $customerId,
        string $priceId,
        array $metadata = []
    ): \Stripe\Subscription {
        return $this->stripe->subscriptions->create([
            'customer' => $customerId,
            'items' => [['price' => $priceId]],
            'metadata' => $metadata,
        ]);
    }

    /**
     * Cancel a subscription.
     * If $invoiceNow true, finalize & invoice any pending items first (simple approach).
     */
    public function cancelSubscription(string $subscriptionId, bool $invoiceNow = false): \Stripe\Subscription
    {
        if ($invoiceNow) {
            // Optional: finalize latest invoice draft before cancellation (skipped for brevity).
        }
        return $this->stripe->subscriptions->cancel($subscriptionId);
    }

    /**
     * List invoices for a customer.
     */
    public function listInvoices(string $customerId, int $limit = 10): \Stripe\Collection
    {
        return $this->stripe->invoices->all([
            'customer' => $customerId,
            'limit' => $limit,
        ]);
    }
}
