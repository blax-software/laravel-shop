<?php

namespace Blax\Shop\Models;

use Blax\Workkit\Traits\HasExpiration;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Cart extends Model
{
    use HasUuids, HasExpiration;

    protected $fillable = [
        'session_id',
        'customer_type',
        'customer_id',
        'currency',
        'status',
        'last_activity_at',
        'expires_at',
        'converted_at',
        'meta',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'converted_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'meta' => 'object',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('shop.tables.carts', 'carts');
    }

    public function customer(): MorphTo
    {
        return $this->morphTo();
    }

    // Alias for backward compatibility
    public function user()
    {
        return $this->customer();
    }

    public function items(): HasMany
    {
        return $this->hasMany(config('shop.models.cart_item'), 'cart_id');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(config('shop.models.product_purchase', \Blax\Shop\Models\ProductPurchase::class), 'cart_id');
    }

    public function getTotal(): float
    {
        return $this->items->sum(function ($item) {
            return $item->quantity * $item->price;
        });
    }

    public function getTotalItems(): int
    {
        return $this->items->sum('quantity');
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isConverted(): bool
    {
        return !is_null($this->converted_at);
    }

    public function scopeActive($query)
    {
        return $query->whereNull('converted_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    public function scopeForUser($query, $userOrId)
    {
        if (is_object($userOrId)) {
            return $query->where('customer_id', $userOrId->id)
                ->where('customer_type', get_class($userOrId));
        }

        // If just an ID is passed, try to determine the user model class
        $userModel = config('auth.providers.users.model', \Workbench\App\Models\User::class);
        return $query->where('customer_id', $userOrId)
            ->where('customer_type', $userModel);
    }

    protected static function booted()
    {
        static::deleting(function ($cart) {
            $cart->items()->delete();
        });
    }
}
