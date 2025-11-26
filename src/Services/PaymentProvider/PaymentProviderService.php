<?php

declare(strict_types=1);

namespace Blax\Shop\Services\PaymentProvider;

use Blax\Shop\Models\PaymentMethod;
use Blax\Shop\Models\PaymentProviderIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class PaymentProviderService
{
    protected StripeService $stripeService;

    public function __construct(StripeService $stripeService = null)
    {
        $this->stripeService = $stripeService ?? app(StripeService::class);
    }

    /**
     * Create or get a customer on the payment provider.
     * This will create a PaymentProviderIdentity record and a Stripe customer.
     *
     * @param Model $customer The customer model (e.g., User)
     * @param string $provider The payment provider name (default: 'stripe')
     * @param array $customerData Additional customer data for the provider
     * @return PaymentProviderIdentity
     */
    public function createOrGetCustomer(Model $customer, string $provider = 'stripe', array $customerData = []): PaymentProviderIdentity
    {
        // Check if customer already has a provider identity
        $identity = PaymentProviderIdentity::where('customer_type', get_class($customer))
            ->where('customer_id', $customer->id)
            ->where('provider_name', $provider)
            ->first();

        if ($identity) {
            return $identity;
        }

        // Create customer on the provider's side
        if ($provider === 'stripe') {
            $stripeCustomer = $this->stripeService->createCustomer(array_merge([
                'email' => $customer->email ?? null,
                'name' => $customer->name ?? null,
                'metadata' => [
                    'customer_id' => $customer->id,
                    'customer_type' => get_class($customer),
                ],
            ], $customerData));

            // Create local identity record
            $identity = PaymentProviderIdentity::create([
                'customer_type' => get_class($customer),
                'customer_id' => $customer->id,
                'provider_name' => $provider,
                'customer_identification_id' => $stripeCustomer->id,
                'meta' => [
                    'email' => $stripeCustomer->email,
                    'created_at' => $stripeCustomer->created,
                ],
            ]);

            return $identity;
        }

        throw new \InvalidArgumentException("Unsupported payment provider: {$provider}");
    }

    /**
     * Add a payment method to a customer.
     *
     * @param PaymentProviderIdentity $identity
     * @param string $paymentMethodId The payment method ID from the provider
     * @param array $additionalData Additional data to store
     * @return PaymentMethod
     */
    public function addPaymentMethod(PaymentProviderIdentity $identity, string $paymentMethodId, array $additionalData = []): PaymentMethod
    {
        if ($identity->provider_name === 'stripe') {
            // Attach payment method to customer on Stripe
            $stripePaymentMethod = $this->stripeService->attachPaymentMethod(
                $paymentMethodId,
                $identity->customer_identification_id
            );

            // Create local payment method record
            $paymentMethod = PaymentMethod::create([
                'payment_provider_identity_id' => $identity->id,
                'provider_payment_method_id' => $stripePaymentMethod->id,
                'type' => $stripePaymentMethod->type,
                'name' => $additionalData['name'] ?? null,
                'last_digits' => $stripePaymentMethod->card->last4 ?? null,
                'brand' => $stripePaymentMethod->card->brand ?? null,
                'exp_month' => $stripePaymentMethod->card->exp_month ?? null,
                'exp_year' => $stripePaymentMethod->card->exp_year ?? null,
                'is_default' => false,
                'is_active' => true,
                'meta' => [
                    'funding' => $stripePaymentMethod->card->funding ?? null,
                    'country' => $stripePaymentMethod->card->country ?? null,
                    'fingerprint' => $stripePaymentMethod->card->fingerprint ?? null,
                ],
            ]);

            // If this is the first payment method, set it as default
            if ($identity->paymentMethods()->count() === 1) {
                $this->setDefaultPaymentMethod($paymentMethod);
            }

            return $paymentMethod;
        }

        throw new \InvalidArgumentException("Unsupported payment provider: {$identity->provider_name}");
    }

    /**
     * List all payment methods for a customer.
     *
     * @param PaymentProviderIdentity $identity
     * @param bool $activeOnly Only return active payment methods
     * @return Collection
     */
    public function listPaymentMethods(PaymentProviderIdentity $identity, bool $activeOnly = true): Collection
    {
        $query = $identity->paymentMethods();

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    /**
     * Set a payment method as the default.
     *
     * @param PaymentMethod $paymentMethod
     * @return PaymentMethod
     */
    public function setDefaultPaymentMethod(PaymentMethod $paymentMethod): PaymentMethod
    {
        $identity = $paymentMethod->paymentProviderIdentity;

        if ($identity->provider_name === 'stripe') {
            // Update on Stripe
            $this->stripeService->setDefaultPaymentMethod(
                $identity->customer_identification_id,
                $paymentMethod->provider_payment_method_id
            );
        }

        // Update locally
        $paymentMethod->setAsDefault();

        return $paymentMethod->fresh();
    }

    /**
     * Remove a payment method.
     *
     * @param PaymentMethod $paymentMethod
     * @param bool $detachFromProvider Whether to detach from the payment provider
     * @return bool
     */
    public function removePaymentMethod(PaymentMethod $paymentMethod, bool $detachFromProvider = true): bool
    {
        $identity = $paymentMethod->paymentProviderIdentity;

        if ($detachFromProvider && $identity->provider_name === 'stripe') {
            try {
                $this->stripeService->detachPaymentMethod($paymentMethod->provider_payment_method_id);
            } catch (\Exception $e) {
                // Log the error but continue with local deletion
                logger()->error('Failed to detach payment method from Stripe', [
                    'payment_method_id' => $paymentMethod->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // If this was the default, set another as default
        if ($paymentMethod->is_default) {
            $nextMethod = $identity->paymentMethods()
                ->where('id', '!=', $paymentMethod->id)
                ->where('is_active', true)
                ->first();

            if ($nextMethod) {
                $this->setDefaultPaymentMethod($nextMethod);
            }
        }

        return $paymentMethod->delete();
    }

    /**
     * Sync payment methods from the provider.
     *
     * @param PaymentProviderIdentity $identity
     * @return Collection
     */
    public function syncPaymentMethods(PaymentProviderIdentity $identity): Collection
    {
        if ($identity->provider_name === 'stripe') {
            $stripePaymentMethods = $this->stripeService->listPaymentMethods(
                $identity->customer_identification_id
            );

            $localPaymentMethods = $identity->paymentMethods;
            $syncedMethods = collect();

            foreach ($stripePaymentMethods->data as $stripeMethod) {
                $localMethod = $localPaymentMethods->firstWhere(
                    'provider_payment_method_id',
                    $stripeMethod->id
                );

                if ($localMethod) {
                    // Update existing
                    $localMethod->update([
                        'last_digits' => $stripeMethod->card->last4 ?? null,
                        'brand' => $stripeMethod->card->brand ?? null,
                        'exp_month' => $stripeMethod->card->exp_month ?? null,
                        'exp_year' => $stripeMethod->card->exp_year ?? null,
                    ]);
                } else {
                    // Create new
                    $localMethod = PaymentMethod::create([
                        'payment_provider_identity_id' => $identity->id,
                        'provider_payment_method_id' => $stripeMethod->id,
                        'type' => $stripeMethod->type,
                        'last_digits' => $stripeMethod->card->last4 ?? null,
                        'brand' => $stripeMethod->card->brand ?? null,
                        'exp_month' => $stripeMethod->card->exp_month ?? null,
                        'exp_year' => $stripeMethod->card->exp_year ?? null,
                        'is_active' => true,
                    ]);
                }

                $syncedMethods->push($localMethod);
            }

            // Mark methods not found on provider as inactive
            $syncedIds = $syncedMethods->pluck('id');
            $identity->paymentMethods()
                ->whereNotIn('id', $syncedIds)
                ->update(['is_active' => false]);

            return $syncedMethods;
        }

        throw new \InvalidArgumentException("Unsupported payment provider: {$identity->provider_name}");
    }

    /**
     * Get the default payment method for a customer.
     *
     * @param PaymentProviderIdentity $identity
     * @return PaymentMethod|null
     */
    public function getDefaultPaymentMethod(PaymentProviderIdentity $identity): ?PaymentMethod
    {
        return $identity->defaultPaymentMethod;
    }
}
