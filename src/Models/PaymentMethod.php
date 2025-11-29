<?php

namespace Blax\Shop\Models;

use Blax\Shop\Database\Factories\PaymentMethodFactory;
use Blax\Workkit\Traits\HasMeta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentMethod extends Model
{
    use HasFactory, HasUuids, HasMeta;

    protected $fillable = [
        'payment_provider_identity_id',
        'provider_payment_method_id',
        'type',
        'name',
        'last_digits',
        'last_alphanumeric',
        'brand',
        'exp_month',
        'exp_year',
        'expires_at',
        'is_default',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'exp_month' => 'integer',
        'exp_year' => 'integer',
        'expires_at' => 'datetime',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'meta' => 'object',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('shop.tables.payment_methods', 'payment_methods');
    }

    /**
     * Get the payment provider identity that owns this payment method.
     */
    public function paymentProviderIdentity(): BelongsTo
    {
        return $this->belongsTo(config('shop.models.payment_provider_identity', PaymentProviderIdentity::class));
    }

    /**
     * Get the customer through the payment provider identity.
     */
    public function customer()
    {
        return $this->paymentProviderIdentity->customer();
    }

    /**
     * Check if this payment method is expired.
     */
    public function isExpired(): bool
    {
        $now = now();

        // Prefer explicit timestamp if provided (for non-card methods like crypto wallets)
        if ($this->expires_at) {
            return $now->isAfter($this->expires_at);
        }

        // Fallback to month/year for card-like methods
        if ($this->exp_month && $this->exp_year) {
            $expirationDate = now()->setYear($this->exp_year)->setMonth($this->exp_month)->endOfMonth();
            return $now->isAfter($expirationDate);
        }

        return false;
    }

    /**
     * Get a formatted display name for the payment method.
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->name) {
            return $this->name;
        }

        $parts = [];

        if ($this->brand) {
            $parts[] = ucfirst($this->brand);
        }

        if ($this->last_digits) {
            $parts[] = "ending in {$this->last_digits}";
        }

        return implode(' ', $parts) ?: 'Payment Method';
    }

    /**
     * Get a formatted expiration date.
     */
    public function getFormattedExpirationAttribute(): ?string
    {
        if (!$this->exp_month || !$this->exp_year) {
            return null;
        }

        return sprintf('%02d/%d', $this->exp_month, $this->exp_year);
    }

    /**
     * Set this payment method as the default for its provider identity.
     */
    public function setAsDefault(): self
    {
        // Remove default flag from all other payment methods for this provider identity
        static::where('payment_provider_identity_id', $this->payment_provider_identity_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->is_default = true;
        $this->save();

        return $this;
    }

    /**
     * Deactivate this payment method.
     */
    public function deactivate(): self
    {
        $this->is_active = false;
        $this->save();

        return $this;
    }

    /**
     * Activate this payment method.
     */
    public function activate(): self
    {
        $this->is_active = true;
        $this->save();

        return $this;
    }

    /**
     * Scope a query to only include active payment methods.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include default payment methods.
     */
    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }

    /**
     * Scope a query to only include non-expired payment methods.
     */
    public function scopeNotExpired($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('exp_year')
                ->orWhere('exp_year', '>', now()->year)
                ->orWhere(function ($q2) {
                    $q2->where('exp_year', now()->year)
                        ->where('exp_month', '>=', now()->month);
                });
        });
    }

    /**
     * Create a new factory instance for the model.
     */
    protected static function newFactory()
    {
        return PaymentMethodFactory::new();
    }
}
