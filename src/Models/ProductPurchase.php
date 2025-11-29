<?php

namespace Blax\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductPurchase extends Model
{
    use HasUuids;

    protected $fillable = [
        'status',
        'cart_id',
        'price_id',
        'purchasable_id',
        'purchasable_type',
        'purchaser_id',
        'purchaser_type',
        'quantity',
        'amount',
        'amount_paid',
        'charge_id',
        'meta',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'amount' => 'integer',
        'amount_paid' => 'integer',
        'meta' => 'object',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('shop.tables.product_purchases', 'product_purchases'));
    }

    public function purchasable()
    {
        return $this->morphTo('purchasable');
    }

    public function purchaser()
    {
        return $this->morphTo('purchaser');
    }

    public function product()
    {
        return $this->belongsTo(config('shop.models.product', Product::class));
    }

    public function user()
    {
        if ($this->purchasable_type === config('auth.providers.users.model', \Workbench\App\Models\User::class)) {
            return $this->purchasable();
        }
        return null;
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
            $product = ($productPurchase->purchasable instanceof Product)
                ? $productPurchase->purchasable
                : null;

            $product ??= ($productPurchase->purchasable instanceof ProductPrice)
                ? $productPurchase->purchasable?->product
                : $product;

            if ($productPurchase->status === 'completed' && $product) {
                $product->callActions('purchased', $productPurchase);
            }
        });

        // updated purchase from unpaid to paid
        static::updated(function ($productPurchase) {
            $product = ($productPurchase->purchasable instanceof Product)
                ? $productPurchase->purchasable
                : null;

            $product ??= ($productPurchase->purchasable instanceof ProductPrice)
                ? $productPurchase->purchasable?->product
                : $product;


            if ($productPurchase->status === 'completed' && $product) {
                $product->callActions('purchased', $productPurchase);
            }
        });
    }

    public function actionRuns()
    {
        return $this->hasManyThrough(
            ProductActionRun::class,
            ProductAction::class,
            'product_id', // Foreign key on ProductAction table...
            'action_id', // Foreign key on ProductActionRun table...
            'purchasable_id', // Local key on ProductPurchase table...
            'id' // Local key on ProductAction table...
        );
    }
}
