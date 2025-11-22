<?php

namespace Blax\Shop\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class ProductCategory extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'parent_id',
        'sort_order',
        'is_visible',
        'meta',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'meta' => 'object',
    ];

    protected $hidden = [
        'created_at',
        'updated_at',
    ];

    protected $appends = [
        'product_count',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('shop.tables.product_categories', 'product_categories'));
    }

    protected static function booted()
    {
        static::creating(function ($model) {
            if (!$model->slug) {
                $model->slug = str()->slug($model->name);
            }
        });

        static::saved(function ($model) {
            if (config('shop.cache.enabled')) {
                Cache::forget(config('shop.cache.prefix') . 'categories:tree');
                Cache::forget(config('shop.cache.prefix') . 'category:' . $model->id);
            }
        });

        static::deleted(function ($model) {
            if (config('shop.cache.enabled')) {
                Cache::forget(config('shop.cache.prefix') . 'categories:tree');
            }
        });
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            config('shop.models.product'),
            'product_category_product'
        );
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->where('is_visible', true)
            ->orderBy('sort_order');
    }

    public function allChildren(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')
            ->orderBy('sort_order');
    }

    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    // Backward compatibility accessor
    public function getIsVisibleAttribute(): bool
    {
        return $this->attributes['is_visible'] ?? true;
    }

    public function getProductCountAttribute(): int
    {
        return $this->products()->count();
    }

    public function toArray(): array
    {
        $array = parent::toArray();

        // Only include nested children if explicitly loaded
        if ($this->relationLoaded('children')) {
            $array['children'] = $this->children->toArray();
        }

        return $array;
    }

    public static function getTree(): array
    {
        if (config('shop.cache.enabled')) {
            return Cache::remember(
                config('shop.cache.prefix') . 'categories:tree',
                config('shop.cache.ttl'),
                fn() => self::buildTree()
            );
        }

        return self::buildTree();
    }

    protected static function buildTree(): array
    {
        $categories = self::visible()
            ->with('children')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->get();

        return $categories->toArray();
    }

    public function getPath(): array
    {
        $path = [];
        $category = $this;

        while ($category) {
            array_unshift($path, [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
            ]);
            $category = $category->parent;
        }

        return $path;
    }
}
