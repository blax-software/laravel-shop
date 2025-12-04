<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Models\ProductCategory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasCategories
{
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            config('shop.models.product_category'),
            'product_category_product'
        );
    }

    public function scopeByCategory($query, ProductCategory|string $category_or_id)
    {
        $categoryId = $category_or_id instanceof ProductCategory
            ? $category_or_id->id
            : $category_or_id;

        return $query->whereHas('categories', function ($q) use ($categoryId) {
            $q->where('id', $categoryId);
        });
    }

    public function scopeByCategories($query, array $category_ids)
    {
        foreach ($category_ids as $category_id) {
            $query->byCategory($category_id);
        }

        return $query;
    }

    public function scopeWithoutCategory($query, ProductCategory|string $category_or_id)
    {
        $categoryId = $category_or_id instanceof ProductCategory
            ? $category_or_id->id
            : $category_or_id;

        return $query->whereDoesntHave('categories', function ($q) use ($categoryId) {
            $q->where('id', $categoryId);
        });
    }

    public function scopeWithoutCategories($query, array $category_ids)
    {
        foreach ($category_ids as $category_id) {
            $query->withoutCategory($category_id);
        }

        return $query;
    }

    public function assignCategory(ProductCategory $category): void
    {
        $this->categories()->attach($category);
    }

    public function assignCategories(array $categories): void
    {
        foreach ($categories as $category) {
            $this->assignCategory($category);
        }
    }

    public function removeCategory(ProductCategory $category): void
    {
        $this->categories()->detach($category);
    }

    public function removeCategories(array $categories): void
    {
        foreach ($categories as $category) {
            $this->removeCategory($category);
        }
    }

    public function syncCategories(array $categories): void
    {
        $this->categories()->sync($categories);
    }

    public function assignCategoryByName(string $name): void
    {
        $category = config('shop.models.product_category')::firstOrCreate(['name' => $name]);
        $this->assignCategory($category);
    }

    public function assignCategoriesByNames(array $names): void
    {
        foreach ($names as $name) {
            $this->assignCategoryByName($name);
        }
    }

    public function asssignCategoryBySlug(string $slug): void
    {
        $category = config('shop.models.product_category')::firstOrCreate(['slug' => $slug]);
        $this->assignCategory($category);
    }

    public function assignCategoriesBySlugs(array $slugs): void
    {
        foreach ($slugs as $slug) {
            $this->asssignCategoryBySlug($slug);
        }
    }
}
