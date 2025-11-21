<?php

namespace Blax\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductPurchase extends Model
{
    use HasUuids;

    protected $fillable = [
        'status',
        'purchasable_type',
        'purchasable_id',
        'product_id',
        'quantity',
        'meta',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'meta' => 'object',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('shop.tables.product_purchases', 'product_purchases'));
    }

    public function purchasable()
    {
        return $this->morphTo();
    }

    // Backward compatibility - user accessor
    public function user()
    {
        if ($this->purchasable_type === config('auth.providers.users.model', \Workbench\App\Models\User::class)) {
            return $this->purchasable();
        }
        return null;
    }

    // Backward compatibility accessor
    public function getUserIdAttribute()
    {
        if ($this->purchasable_type === config('auth.providers.users.model', \Workbench\App\Models\User::class)) {
            return $this->purchasable_id;
        }
        return null;
    }

    public function product()
    {
        return $this->belongsTo(config('shop.models.product', Product::class));
    }

    public static function scopeFromCart($query, $cartId)
    {
        return $query->where('cart_id', $cartId);
    }

    public static function scopeInCart($query)
    {
        return $query->where('status', 'cart');
    }

    public static function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    protected static function booted()
    {
        static::created(function ($productPurchase) {
            if ($productPurchase->status === 'completed' && $product = $productPurchase->product) {
                $product->callActions('purchased', $productPurchase);
            }
        });
    }
}
