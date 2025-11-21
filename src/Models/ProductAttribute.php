<?php

namespace Blax\Shop\Models;

use Blax\Shop\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAttribute extends Model
{
    use HasUuids;

    protected $fillable = [
        'product_id',
        'key',
        'value',
        'sort_order',
        'meta',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'meta' => 'object',
    ];

    protected $hidden = [
        'id',
        'product_id',
        'created_at',
        'updated_at',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('shop.tables.product_attributes', 'product_attributes'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(config('shop.models.product', Product::class));
    }
}
