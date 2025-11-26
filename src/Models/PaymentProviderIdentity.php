<?php

namespace Blax\Shop\Models;

use Blax\Shop\Database\Factories\PaymentProviderIdentityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentProviderIdentity extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'customer_type',
        'customer_id',
        'provider_name',
        'customer_identification_id',
        'meta',
    ];

    protected $casts = [
        'meta' => 'object',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('shop.tables.payment_provider_identities', 'payment_provider_identities');
    }

    /**
     * Get the customer that owns this payment provider identity.
     */
    public function customer(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get all payment methods for this provider identity.
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(config('shop.models.payment_method', PaymentMethod::class));
    }

    /**
     * Get the default payment method for this provider identity.
     */
    public function defaultPaymentMethod()
    {
        return $this->hasOne(config('shop.models.payment_method', PaymentMethod::class))
            ->where('is_default', true)
            ->where('is_active', true);
    }

    /**
     * Get active payment methods.
     */
    public function activePaymentMethods(): HasMany
    {
        return $this->paymentMethods()->where('is_active', true);
    }

    /**
     * Find or create a payment provider identity for a customer.
     */
    public static function findOrCreateForCustomer($customer, string $providerName, string $customerIdentificationId): self
    {
        return static::firstOrCreate([
            'customer_type' => get_class($customer),
            'customer_id' => $customer->id,
            'provider_name' => $providerName,
        ], [
            'customer_identification_id' => $customerIdentificationId,
        ]);
    }

    /**
     * Update the customer identification ID.
     */
    public function updateCustomerIdentificationId(string $customerIdentificationId): self
    {
        $this->customer_identification_id = $customerIdentificationId;
        $this->save();

        return $this;
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return PaymentProviderIdentityFactory::new();
    }
}
