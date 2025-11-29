<?php

namespace Blax\Shop\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ProductActionRun extends Model
{
    protected $table = 'product_action_runs';
    protected $fillable = [
        'action_id',
        'action_type',
        'product_purchase_id',
        'success',
    ];
    protected $casts = [
        'success' => 'boolean',
    ];

    public function action(): MorphTo
    {
        return $this->morphTo();
    }

    public function productPurchase()
    {
        return $this->belongsTo(ProductPurchase::class, 'product_purchase_id');
    }
}
