<?php

declare(strict_types=1);

namespace Blax\Shop\Models;

use Blax\Shop\Database\Factories\ProductPriceTierFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step in a {@see ProductPrice}'s tier ladder. Used when the parent
 * price's `billing_scheme` is `tiered`.
 *
 *   up_to        — usage units this tier covers (null = unbounded). For a
 *                  loanable product, "units" are days of borrowing. The
 *                  previous tier's `up_to` is the implicit lower bound.
 *   unit_amount  — cents charged per unit consumed within this tier.
 *   flat_amount  — optional flat fee added once when the tier is entered.
 *   sort_order   — deterministic walk order.
 */
class ProductPriceTier extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'price_id',
        'up_to',
        'unit_amount',
        'flat_amount',
        'sort_order',
        'meta',
    ];

    protected $casts = [
        'up_to' => 'integer',
        'unit_amount' => 'integer',
        'flat_amount' => 'integer',
        'sort_order' => 'integer',
        'meta' => 'object',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('shop.tables.product_price_tiers', 'product_price_tiers'));
    }

    public function price(): BelongsTo
    {
        return $this->belongsTo(
            config('shop.models.product_price', ProductPrice::class),
            'price_id'
        );
    }

    protected static function newFactory(): ProductPriceTierFactory
    {
        return ProductPriceTierFactory::new();
    }
}
