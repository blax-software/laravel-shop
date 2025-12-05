<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Models\ProductCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait HasCategories
{
    /**
     * Categories assigned to the model.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            config('shop.models.product_category'),
            'product_category_product'
        );
    }

    /**
     * Scope: filter by a single category (id or slug).
     *
     * @param  Builder<static>  $query
     */
    public function scopeByCategory(Builder $query, ProductCategory|string $category_or_id): Builder
    {
        $categoryId = $category_or_id instanceof ProductCategory
            ? $category_or_id->id
            : $category_or_id;

        return $query->whereHas('categories', function (Builder $q) use ($categoryId) {
            $q->where('id', $categoryId)
                ->orWhere('slug', $categoryId);
        });
    }

    /**
     * Scope: filter by all provided categories.
     *
     * @param  Builder<static>  $query
     * @param  array<int, ProductCategory|string>  $category_ids
     */
    public function scopeByCategories(Builder $query, array $category_ids): Builder
    {
        foreach ($category_ids as $category_id) {
            $query->byCategory($category_id);
        }

        return $query;
    }

    /**
     * Scope: exclude a single category (id or slug).
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithoutCategory(Builder $query, ProductCategory|string $category_or_id): Builder
    {
        $categoryId = $category_or_id instanceof ProductCategory
            ? $category_or_id->id
            : $category_or_id;

        return $query->whereDoesntHave('categories', function (Builder $q) use ($categoryId) {
            $q->where('id', $categoryId)
                ->orWhere('slug', $categoryId);
        });
    }

    /**
     * Scope: exclude any of the provided categories.
     *
     * @param  Builder<static>  $query
     * @param  array<int, ProductCategory|string>  $category_ids
     */
    public function scopeWithoutCategories(Builder $query, array $category_ids): Builder
    {
        foreach ($category_ids as $category_id) {
            $query->withoutCategory($category_id);
        }

        return $query;
    }

    /**
     * Attach a single category model.
     */
    public function assignCategory(ProductCategory $category): void
    {
        $this->categories()->attach($category);
    }

    /**
     * Attach multiple category models.
     *
     * @param  array<int, ProductCategory>  $categories
     */
    public function assignCategories(array $categories): void
    {
        foreach ($categories as $category) {
            $this->assignCategory($category);
        }
    }

    /**
     * Detach a single category model.
     */
    public function removeCategory(ProductCategory $category): void
    {
        $this->categories()->detach($category);
    }

    /**
     * Detach multiple category models.
     *
     * @param  array<int, ProductCategory>  $categories
     */
    public function removeCategories(array $categories): void
    {
        foreach ($categories as $category) {
            $this->removeCategory($category);
        }
    }

    /**
     * Sync categories by ids or models.
     *
     * @param  array<int, ProductCategory|int|string>  $categories
     */
    public function syncCategories(array $categories): void
    {
        $this->categories()->sync($categories);
    }

    /**
     * Attach or create a category by name.
     */
    public function assignCategoryByName(string $name): void
    {
        $category = config('shop.models.product_category')::firstOrCreate(['name' => $name]);
        $this->assignCategory($category);
    }

    /**
     * Attach or create categories by names.
     *
     * @param  array<int, string>  $names
     */
    public function assignCategoriesByNames(array $names): void
    {
        foreach ($names as $name) {
            $this->assignCategoryByName($name);
        }
    }

    /**
     * Attach or create a category by slug.
     */
    public function assignCategoryBySlug(string $slug): void
    {
        $category = config('shop.models.product_category')::firstOrCreate(['slug' => $slug]);
        $this->assignCategory($category);
    }

    /**
     * Attach or create categories by slugs.
     *
     * @param  array<int, string>  $slugs
     */
    public function assignCategoriesBySlugs(array $slugs): void
    {
        foreach ($slugs as $slug) {
            $this->assignCategoryBySlug($slug);
        }
    }

    /**
     * Backward compatible alias with previous typo.
     *
     * @deprecated Use assignCategoryBySlug instead.
     */
    public function asssignCategoryBySlug(string $slug): void
    {
        $this->assignCategoryBySlug($slug);
    }

    /**
     * Check if the model is linked to a category (id or slug).
     */
    public function hasCategory(ProductCategory|string $category_or_id): bool
    {
        $categoryId = $category_or_id instanceof ProductCategory
            ? $category_or_id->id
            : $category_or_id;

        if ($this->relationLoaded('categories')) {
            /** @var Collection<int, ProductCategory> $categories */
            $categories = $this->categories;

            return $categories->contains(function (ProductCategory $category) use ($categoryId) {
                return $category->id === $categoryId || $category->slug === $categoryId;
            });
        }

        return $this->categories()
            ->where(function (Builder $q) use ($categoryId) {
                $q->where('id', $categoryId)
                    ->orWhere('slug', $categoryId);
            })
            ->exists();
    }

    /**
     * Check if the model has any of the provided categories.
     *
     * @param  array<int, ProductCategory|string>  $category_ids
     */
    public function hasAnyCategory(array $category_ids): bool
    {
        if ($this->relationLoaded('categories')) {
            /** @var Collection<int, ProductCategory> $categories */
            $categories = $this->categories;
            $ids = [];
            $slugs = [];

            foreach ($category_ids as $category) {
                $ids[] = $category instanceof ProductCategory ? $category->id : $category;
                $slugs[] = $category instanceof ProductCategory ? $category->slug : $category;
            }

            return $categories->contains(function (ProductCategory $category) use ($ids, $slugs) {
                return in_array($category->id, $ids, true) || in_array($category->slug, $slugs, true);
            });
        }

        $ids = [];
        $slugs = [];

        foreach ($category_ids as $category) {
            $ids[] = $category instanceof ProductCategory ? $category->id : $category;
            $slugs[] = $category instanceof ProductCategory ? $category->slug : $category;
        }

        return $this->categories()
            ->where(function (Builder $q) use ($ids, $slugs) {
                $q->whereIn('id', $ids)
                    ->orWhereIn('slug', $slugs);
            })
            ->exists();
    }

    /**
     * Check if the model has all of the provided categories.
     *
     * @param  array<int, ProductCategory|string>  $category_ids
     */
    public function hasAllCategories(array $category_ids): bool
    {
        if ($category_ids === []) {
            return false;
        }

        if ($this->relationLoaded('categories')) {
            /** @var Collection<int, ProductCategory> $categories */
            $categories = $this->categories;

            foreach ($category_ids as $category) {
                if (! $this->hasCategory($category)) {
                    return false;
                }
            }

            return true;
        }

        $ids = [];
        $slugs = [];

        foreach ($category_ids as $category) {
            $ids[] = $category instanceof ProductCategory ? $category->id : $category;
            $slugs[] = $category instanceof ProductCategory ? $category->slug : $category;
        }

        $count = $this->categories()
            ->where(function (Builder $q) use ($ids, $slugs) {
                $q->whereIn('id', $ids)
                    ->orWhereIn('slug', $slugs);
            })
            ->count();

        return $count >= count($category_ids);
    }
}
