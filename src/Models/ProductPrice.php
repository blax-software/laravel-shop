<?php

namespace Blax\Shop\Models;

use Blax\Shop\Contracts\Cartable;
use Blax\Shop\Contracts\Purchasable;
use Blax\Workkit\Traits\HasMetaTranslation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductPrice extends Model implements Cartable
{
    use HasFactory, HasUuids, HasMetaTranslation;

    protected $fillable = [
        'purchasable_type',
        'purchasable_id',
        'stripe_price_id',
        'name',
        'type',
        'currency',
        'unit_amount',
        'sale_unit_amount',
        'is_default',
        'active',
        'billing_scheme',
        'interval',
        'interval_count',
        'trial_period_days',
        'meta',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'active' => 'boolean',
        'meta' => 'object',
        'unit_amount' => 'float',
        'sale_unit_amount' => 'float',
        'interval_count' => 'integer',
        'trial_period_days' => 'integer',
    ];

    public function purchasable()
    {
        return $this->morphTo();
    }

    public function scopeIsActive($query)
    {
        return $query->where('active', true);
    }

    public function getCurrentPrice($sale_price): float
    {
        if ($sale_price) {
            return $this->sale_unit_amount;
        }

        return $this->unit_amount;
    }
}
