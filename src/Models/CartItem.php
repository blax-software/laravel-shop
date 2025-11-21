<?php

namespace Blax\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'cart_id',
        'product_id',
        'quantity',
        'price',
        'regular_price',
        'subtotal',
        'attributes',
        'meta',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'regular_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'attributes' => 'array',
        'meta' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->table = config('shop.tables.cart_items', 'cart_items');
    }

    protected static function boot()
    {
        parent::boot();

        // Auto-calculate subtotal before saving
        static::creating(function ($cartItem) {
            if (!isset($cartItem->subtotal)) {
                $cartItem->subtotal = $cartItem->quantity * $cartItem->price;
            }
        });

        static::updating(function ($cartItem) {
            if ($cartItem->isDirty(['quantity', 'price'])) {
                $cartItem->subtotal = $cartItem->quantity * $cartItem->price;
            }
        });
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(config('shop.models.cart'), 'cart_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(config('shop.models.product'), 'product_id');
    }

    public function getSubtotal(): float
    {
        return $this->quantity * $this->price;
    }

    public function scopeForCart($query, $cartId)
    {
        return $query->where('cart_id', $cartId);
    }

    public function scopeForProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }
}
