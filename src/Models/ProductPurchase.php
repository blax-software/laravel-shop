<?php

namespace Blax\Shop\Models;

use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Traits\HasBookingLifecycle;
use Blax\Shop\Traits\HasLoanLifecycle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductPurchase extends Model
{
    use HasBookingLifecycle, HasLoanLifecycle, HasUuids;

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

    /**
     * The price this purchase bills against (see HasLoanLifecycle::calculateCost).
     */
    public function price()
    {
        return $this->belongsTo(
            config('shop.models.product_price', ProductPrice::class),
            'price_id'
        );
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

    /*
     * Lifecycle methods live in product-type traits:
     *   - HasBookingLifecycle  → isBooking, isBookingEnded, scopeBookings,
     *                            scopeEndedBookings   (booking products)
     *   - HasLoanLifecycle     → isReturned, isOverdue, getDomainStatus,
     *                            returnedAt, extensionsUsed, canExtend,
     *                            extend, markReturned, scopeActiveLoans,
     *                            scopeReturned, scopeOverdue  (loanable products)
     */
}
