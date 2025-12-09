<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Enums\ProductRelationType;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasProductRelations
{
    public function productRelations(): BelongsToMany
    {
        return $this->belongsToMany(
            static::class,
            config('shop.tables.product_relations', 'product_relations'),
            'product_id',
            'related_product_id'
        )->withPivot('type', 'sort_order')->withTimestamps();
    }

    public function relatedProducts(): BelongsToMany
    {
        return $this->relationsByType(ProductRelationType::RELATED);
    }

    public function variantProducts(): BelongsToMany
    {
        return $this->relationsByType(ProductRelationType::VARIATION);
    }

    public function upsellProducts(): BelongsToMany
    {
        return $this->relationsByType(ProductRelationType::UPSELL);
    }

    public function crossSellProducts(): BelongsToMany
    {
        return $this->relationsByType(ProductRelationType::CROSS_SELL);
    }

    public function downsellProducts(): BelongsToMany
    {
        return $this->relationsByType(ProductRelationType::DOWNSELL);
    }

    public function addOnProducts(): BelongsToMany
    {
        return $this->relationsByType(ProductRelationType::ADD_ON);
    }

    public function bundleProducts(): BelongsToMany
    {
        return $this->relationsByType(ProductRelationType::BUNDLE);
    }

    public function singleProducts(): BelongsToMany
    {
        return $this->relationsByType(ProductRelationType::SINGLE);
    }

    public function relationsByType(ProductRelationType|string $type): BelongsToMany
    {
        $typeValue = $type instanceof ProductRelationType ? $type->value : $type;

        return $this->productRelations()->wherePivot('type', $typeValue);
    }
}
