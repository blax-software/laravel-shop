<?php

declare(strict_types=1);

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

    /**
     * Resolve the cheapest price this buyer may actually be charged, honouring
     * conditional (`meta.requires`) pricing.
     *
     * The eligible set = every active standalone price PLUS every active
     * conditional price the buyer satisfies (per the bound
     * {@see \Blax\Shop\Contracts\EntitlementChecker}); the cheapest of those
     * wins. So a buyer with the prerequisite gets the cheaper conditional price
     * (e.g. 8€ with Full Seat), and a buyer without it never sees it.
     *
     * Returns null when nothing is eligible — e.g. a product sold ONLY at a
     * conditional price to a buyer who doesn't qualify (there is genuinely no
     * price for them). Callers must treat null as "not purchasable by you".
     *
     * @param  mixed  $buyer  Usually a User; null for a guest (satisfies nothing).
     */
    public function resolvePriceFor(mixed $buyer = null): ?ProductPrice
    {
        $active = $this->prices()->where('active', true)->get();

        if ($active->isEmpty()) {
            return null;
        }

        $checker = app(\Blax\Shop\Contracts\EntitlementChecker::class);

        $eligible = $active->filter(function (ProductPrice $price) use ($buyer, $checker) {
            if (! $price->isConditional()) {
                return true;
            }

            return $checker->satisfies($buyer, $price->requires());
        });

        return $eligible->sortBy(fn (ProductPrice $price) => $price->unit_amount)->first();
    }

    public function getPriceAttribute(): ?float
    {
        return $this->getCurrentPrice();
    }

    public function hasPrice(): bool
    {
        return $this->prices()->exists();
    }

    public static function fromPrice($price_id)
    {
        return static::whereHas('prices', function ($q) use ($price_id) {
            $q->where('id', $price_id);
        })->first();
    }
}
