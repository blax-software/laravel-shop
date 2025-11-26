<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Models\PaymentMethod;
use Blax\Shop\Models\PaymentProviderIdentity;
use Blax\Shop\Services\PaymentProvider\PaymentProviderService;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

trait HasPaymentMethods
{
    /**
     * Get all payment provider identities for this model.
     */
    public function paymentProviderIdentities(): MorphMany
    {
        return $this->morphMany(
            config('shop.models.payment_provider_identity', PaymentProviderIdentity::class),
            'customer'
        );
    }

    /**
     * Get payment provider identity for a specific provider.
     *
     * @param string $provider
     * @return PaymentProviderIdentity|null
     */
    public function getPaymentProviderIdentity(string $provider = 'stripe'): ?PaymentProviderIdentity
    {
        return $this->paymentProviderIdentities()
            ->where('provider_name', $provider)
            ->first();
    }

    /**
     * Create or get a payment provider identity.
     *
     * @param string $provider
     * @param array $customerData
     * @return PaymentProviderIdentity
     */
    public function createOrGetPaymentProviderIdentity(string $provider = 'stripe', array $customerData = []): PaymentProviderIdentity
    {
        $service = app(PaymentProviderService::class);
        return $service->createOrGetCustomer($this, $provider, $customerData);
    }

    /**
     * Add a payment method.
     *
     * @param string $paymentMethodId The payment method ID from the provider
     * @param string $provider The payment provider name
     * @param array $additionalData Additional data to store
     * @return PaymentMethod
     */
    public function addPaymentMethod(string $paymentMethodId, string $provider = 'stripe', array $additionalData = []): PaymentMethod
    {
        $identity = $this->createOrGetPaymentProviderIdentity($provider);
        $service = app(PaymentProviderService::class);
        return $service->addPaymentMethod($identity, $paymentMethodId, $additionalData);
    }

    /**
     * Get all payment methods for this model.
     *
     * @param string|null $provider Filter by provider
     * @param bool $activeOnly Only return active payment methods
     * @return Collection
     */
    public function paymentMethods(string $provider = null, bool $activeOnly = true): Collection
    {
        $identities = $this->paymentProviderIdentities();

        if ($provider) {
            $identities->where('provider_name', $provider);
        }

        $methods = collect();
        foreach ($identities->get() as $identity) {
            $query = $identity->paymentMethods();
            if ($activeOnly) {
                $query->where('is_active', true);
            }
            $methods = $methods->merge($query->get());
        }

        return $methods;
    }

    /**
     * Get the default payment method.
     *
     * @param string $provider
     * @return PaymentMethod|null
     */
    public function defaultPaymentMethod(string $provider = 'stripe'): ?PaymentMethod
    {
        $identity = $this->getPaymentProviderIdentity($provider);
        if (!$identity) {
            return null;
        }

        return $identity->paymentMethods()
            ->where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Set a payment method as default.
     *
     * @param PaymentMethod $paymentMethod
     * @return PaymentMethod
     */
    public function setDefaultPaymentMethod(PaymentMethod $paymentMethod): PaymentMethod
    {
        $service = app(PaymentProviderService::class);
        return $service->setDefaultPaymentMethod($paymentMethod);
    }

    /**
     * Remove a payment method.
     *
     * @param PaymentMethod $paymentMethod
     * @param bool $detachFromProvider
     * @return bool
     */
    public function removePaymentMethod(PaymentMethod $paymentMethod, bool $detachFromProvider = true): bool
    {
        $service = app(PaymentProviderService::class);
        return $service->removePaymentMethod($paymentMethod, $detachFromProvider);
    }

    /**
     * Sync payment methods from the provider.
     *
     * @param string $provider
     * @return Collection
     */
    public function syncPaymentMethods(string $provider = 'stripe'): Collection
    {
        $identity = $this->getPaymentProviderIdentity($provider);
        if (!$identity) {
            return collect();
        }

        $service = app(PaymentProviderService::class);
        return $service->syncPaymentMethods($identity);
    }

    /**
     * Check if the model has any payment methods.
     *
     * @param string|null $provider
     * @return bool
     */
    public function hasPaymentMethods(string $provider = null): bool
    {
        return $this->paymentMethods($provider)->isNotEmpty();
    }

    /**
     * Check if the model has a default payment method.
     *
     * @param string $provider
     * @return bool
     */
    public function hasDefaultPaymentMethod(string $provider = 'stripe'): bool
    {
        return $this->defaultPaymentMethod($provider) !== null;
    }
}
