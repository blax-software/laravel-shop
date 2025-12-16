<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Models\ProductPrice;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\DB;

trait HasPrices
{
    public function prices(): MorphMany
    {
        return $this->morphMany(
            config('shop.models.product_price', ProductPrice::class),
            'purchasable'
        );
    }

    public function getCurrentPrice(bool|null $sales_price = null, mixed $cart = null): ?float
    {
        // For pool products with a cart, get dynamic pricing based on cart state
        if ($cart && method_exists($this, 'isPool') && $this->isPool()) {
            $currentQuantityInCart = $cart->items()
                ->where('purchasable_id', $this->getKey())
                ->where('purchasable_type', get_class($this))
                ->sum('quantity');

            return $this->getNextAvailablePoolPrice($currentQuantityInCart, $sales_price);
        }

        return $this->defaultPrice()->first()?->getCurrentPrice($sales_price ?? $this->isOnSale());
    }

    public function scopePriceRange($query, ?float $min = null, ?float $max = null)
    {
        return $query->whereHas('prices', function ($q) use ($min, $max) {
            if ($min !== null) {
                $q->where('unit_amount', '>=', $min);
            }
            if ($max !== null) {
                $q->where('unit_amount', '<=', $max);
            }
        });
    }

    public function scopeOrderByPrice($query, string $direction = 'asc')
    {
        return $query->join('product_prices', function ($join) use ($query) {
            $join->on($query->getModel()->getTable() . '.id', '=', 'product_prices.purchasable_id')
                ->where('product_prices.purchasable_type', '=', get_class($query->getModel()))
                ->where('product_prices.is_default', '=', true);
        })->orderBy('product_prices.unit_amount', $direction)
            ->select($query->getModel()->getTable() . '.*');
    }


    public function defaultPrice()
    {
        return $this->prices()->where('is_default', true);
    }

    public function getPriceAttribute(): ?float
    {
        return $this->getCurrentPrice();
    }

    public function hasPrice(): bool
    {
        return $this->prices()->exists();
    }
}
