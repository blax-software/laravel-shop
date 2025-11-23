<?php

namespace Blax\Shop\Models;

use Blax\Workkit\Traits\HasMeta;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    use HasUuids, HasMeta;

    protected $fillable = [
        'cart_id',
        'purchasable_id',
        'purchasable_type',
        'quantity',
        'price',
        'regular_price',
        'subtotal',
        'parameters',
        'meta',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'regular_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'parameters' => 'array',
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

    public function purchasable()
    {
        return $this->morphTo('purchasable');
    }

    public function product(): BelongsTo|null
    {
        if ($this->purchasable_type === config('shop.models.product', Product::class)) {
            return $this->belongsTo(config('shop.models.product'), 'purchasable_id');
        }

        return null;
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
