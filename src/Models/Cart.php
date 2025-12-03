<?php

namespace Blax\Shop\Models;

use Blax\Shop\Contracts\Cartable;
use Blax\Shop\Enums\CartStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Workkit\Traits\HasExpiration;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Cart extends Model
{
    use HasUuids, HasExpiration, HasFactory;

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
        'status' => CartStatus::class,
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
            return $item->subtotal;
        });
    }

    public function getTotalItems(): int
    {
        return $this->items->sum('quantity');
    }

    public function getUnpaidAmount(): float
    {
        $paidAmount = $this->purchases()
            ->whereColumn('total_amount', '!=', 'amount_paid')
            ->sum('total_amount');

        return max(0, $this->getTotal() - $paidAmount);
    }

    public function getPaidAmount(): float
    {
        return $this->purchases()
            ->whereColumn('total_amount', '!=', 'amount_paid')
            ->sum('total_amount');
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

    public static function scopeUnpaid($query)
    {
        return $query->whereDoesntHave('purchases', function ($q) {
            $q->whereColumn('total_amount', '!=', 'amount_paid');
        });
    }

    protected static function booted()
    {
        static::deleting(function ($cart) {
            $cart->items()->delete();
        });
    }

    public function addToCart(
        Model $cartable,
        $quantity = 1,
        $parameters = []
    ): CartItem {

        // $cartable must implement Cartable
        if (! $cartable instanceof Cartable) {
            throw new \Exception("Item must implement the Cartable interface.");
        }

        $cartItem = $this->items()->create([
            'purchasable_id' => $cartable->getKey(),
            'purchasable_type' => get_class($cartable),
            'quantity' => $quantity,
            'price' => $cartable?->getCurrentPrice(),
            'regular_price' => $cartable?->getCurrentPrice(false) ?? $cartable?->unit_amount,
            'subtotal' => ($cartable?->getCurrentPrice()) * $quantity,
            'parameters' => $parameters,
        ]);

        $cartItem = $cartItem->fresh();

        return $cartItem;
    }

    public function checkout(): static
    {
        $items = $this->items()
            ->with('purchasable')
            ->get();

        if ($items->isEmpty()) {
            throw new \Exception("Cart is empty");
        }

        // Create ProductPurchase for each cart item
        foreach ($items as $item) {
            $product = $item->purchasable;
            $quantity = $item->quantity;
            
            // Extract booking dates from parameters if this is a booking product
            $from = null;
            $until = null;
            if ($product->type === ProductType::BOOKING && $item->parameters) {
                $params = is_array($item->parameters) ? $item->parameters : (array) $item->parameters;
                $from = $params['from'] ?? null;
                $until = $params['until'] ?? null;
                
                // Convert to Carbon instances if they're strings
                if ($from && is_string($from)) {
                    $from = \Carbon\Carbon::parse($from);
                }
                if ($until && is_string($until)) {
                    $until = \Carbon\Carbon::parse($until);
                }
            }

            $purchase = $this->customer->purchase(
                $product->prices()->first(),
                $quantity,
                null,
                $from,
                $until
            );

            $purchase->update([
                'cart_id' => $item->cart_id,
            ]);

            // Remove item from cart
            $item->update([
                'purchase_id' => $purchase->id,
            ]);
        }

        $this->update([
            'converted_at' => now(),
        ]);

        return $this;
    }
}
