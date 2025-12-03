<?php

namespace Blax\Shop\Models;

use Blax\Shop\Enums\PurchaseStatus;
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
        'from',
        'until',
        'meta',
    ];

    protected $casts = [
        'status' => PurchaseStatus::class,
        'quantity' => 'integer',
        'amount' => 'integer',
        'amount_paid' => 'integer',
        'from' => 'datetime',
        'until' => 'datetime',
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
        return $query->where('status', PurchaseStatus::CART->value);
    }

    public static function scopeCompleted($query)
    {
        return $query->where('status', PurchaseStatus::COMPLETED->value);
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

            if ($productPurchase->status === PurchaseStatus::COMPLETED && $product) {
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


            if ($productPurchase->status === PurchaseStatus::COMPLETED && $product) {
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

    /**
     * Check if this is a booking purchase
     */
    public function isBooking(): bool
    {
        return !is_null($this->from) && !is_null($this->until);
    }

    /**
     * Check if the booking has ended
     */
    public function isBookingEnded(): bool
    {
        if (!$this->isBooking()) {
            return false;
        }

        return now()->isAfter($this->until);
    }

    /**
     * Scope for booking purchases
     */
    public function scopeBookings($query)
    {
        return $query->whereNotNull('from')->whereNotNull('until');
    }

    /**
     * Scope for ended bookings
     */
    public function scopeEndedBookings($query)
    {
        return $query->bookings()->where('until', '<', now());
    }
}
