<?php

namespace Blax\Shop\Models;

use Blax\Workkit\Traits\HasMetaTranslation;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProductPrice extends Model
{
    use HasUuids, HasMetaTranslation;

    protected $fillable = [
        'active',
        'product_id',
        'stripe_price_id',
        'name',
        'type',
        'price',
        'sale_price',
        'is_default',
        'billing_scheme',
        'interval',
        'interval_count',
        'trial_period_days',
        'currency',
        'meta',
    ];

    protected $casts = [
        'price' => 'integer',
        'sale_price' => 'integer',
        'is_default' => 'boolean',
        'trial_period_days' => 'integer',
        'meta' => 'object',
        'active' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeIsActive($query)
    {
        return $query->where('active', true);
    }
}
