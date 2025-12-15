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
        'purchase_id',
        'meta',
        'from',
        'until',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'price' => 'decimal:2',
        'regular_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'parameters' => 'array',
        'meta' => 'array',
        'from' => 'datetime',
        'until' => 'datetime',
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

    public function purchase()
    {
        return $this->hasOne(
            config('shop.models.product_purchase', ProductPurchase::class),
            'id',
            'purchase_id'
        );
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

    /**
     * Get required adjustments for this cart item before checkout.
     * 
     * Returns an array of fields that need to be set, with suggested field names.
     * For booking products and pools with booking items, dates are required.
     * 
     * This method is useful for:
     * - Validating cart items before checkout
     * - Displaying missing information to users
     * - Checking if a cart item needs additional user input
     * 
     * Example usage:
     * ```php
     * // Check if cart item needs adjustments
     * $adjustments = $cartItem->requiredAdjustments();
     * 
     * if (!empty($adjustments)) {
     *     // Item needs dates before checkout
     *     // $adjustments = ['from' => 'datetime', 'until' => 'datetime']
     *     echo "Please select booking dates";
     * }
     * 
     * // Check all cart items before checkout
     * foreach ($cart->items as $item) {
     *     $required = $item->requiredAdjustments();
     *     if (!empty($required)) {
     *         // Handle missing information
     *     }
     * }
     * ```
     * 
     * @return array Array of required field adjustments, e.g., ['from' => 'datetime', 'until' => 'datetime']
     */
    public function requiredAdjustments(): array
    {
        $adjustments = [];

        // Only check if purchasable is a Product
        if ($this->purchasable_type !== config('shop.models.product', Product::class)) {
            return $adjustments;
        }

        $product = $this->purchasable;

        if (!$product) {
            return $adjustments;
        }

        // Check if dates are required (for booking products or pools with booking items)
        $requiresDates = $product->isBooking() ||
            ($product->isPool() && $product->hasBookingSingleItems());

        if ($requiresDates) {
            if (is_null($this->from)) {
                $adjustments['from'] = 'datetime';
            }

            if (is_null($this->until)) {
                $adjustments['until'] = 'datetime';
            }
        }

        return $adjustments;
    }
}
