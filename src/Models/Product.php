<?php

namespace Blax\Shop\Models;

use Blax\Shop\Contracts\Cartable;
use Blax\Workkit\Traits\HasMetaTranslation;
use Blax\Shop\Events\ProductCreated;
use Blax\Shop\Events\ProductUpdated;
use Blax\Shop\Contracts\Purchasable;
use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Traits\HasPrices;
use Blax\Shop\Traits\HasStocks;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Cache;

class Product extends Model implements Purchasable, Cartable
{
    use HasFactory, HasUuids, HasMetaTranslation, HasStocks, HasPrices;

    protected $fillable = [
        'slug',
        'sku',
        'type',
        'stripe_product_id',
        'sale_start',
        'sale_end',
        'manage_stock',
        'low_stock_threshold',
        'weight',
        'length',
        'width',
        'height',
        'virtual',
        'downloadable',
        'parent_id',
        'featured',
        'is_visible',
        'status',
        'published_at',
        'meta',
        'tax_class',
        'sort_order',
        'name',
        'description',
        'short_description',
    ];

    protected $casts = [
        'manage_stock' => 'boolean',
        'virtual' => 'boolean',
        'downloadable' => 'boolean',
        'type' => ProductType::class,
        'status' => ProductStatus::class,
        'meta' => 'object',
        'sale_start' => 'datetime',
        'sale_end' => 'datetime',
        'published_at' => 'datetime',
        'featured' => 'boolean',
        'is_visible' => 'boolean',
        'low_stock_threshold' => 'integer',
        'sort_order' => 'integer',
    ];

    protected $dispatchesEvents = [
        'created' => ProductCreated::class,
        'updated' => ProductUpdated::class,
    ];

    protected $hidden = [
        'stripe_product_id',
    ];

    public function __construct(array $attributes = [])
    {
        // Initialize meta BEFORE parent constructor to avoid trait errors
        if (!isset($attributes['meta'])) {
            $attributes['meta'] = '{}';
        }

        parent::__construct($attributes);
        $this->setTable(config('shop.tables.products', 'products'));
    }

    /**
     * Initialize the HasMetaTranslation trait for the model.
     *
     * @return void
     */
    protected function initializeHasMetaTranslation()
    {
        // Ensure meta is never null
        if (!isset($this->attributes['meta'])) {
            $this->attributes['meta'] = '{}';
        }
    }

    protected static function booted()
    {
        parent::booted();

        static::creating(function ($model) {
            if (! $model->slug) {
                $model->slug = 'new-product-' . str()->random(8);
            }

            $model->slug = str()->slug($model->slug);

            // Ensure meta is initialized before creation
            if (is_null($model->getAttributes()['meta'] ?? null)) {
                $model->setAttribute('meta', json_encode(new \stdClass()));
            }
        });

        static::updated(function ($model) {
            if (config('shop.cache.enabled')) {
                Cache::forget(config('shop.cache.prefix') . 'product:' . $model->id);
            }
        });

        static::deleted(function ($model) {
            $model->actions()->delete();
            $model->attributes()->delete();
        });
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(
            config('shop.models.product_category'),
            'product_category_product'
        );
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(config('shop.models.product_attribute', 'Blax\Shop\Models\ProductAttribute'));
    }

    public function actions(): HasMany
    {
        return $this->hasMany(config('shop.models.product_action', ProductAction::class));
    }

    public function purchases(): MorphMany
    {
        return $this->morphMany(
            config('shop.models.product_purchase', ProductPurchase::class),
            'purchasable'
        );
    }

    public function scopePublished($query)
    {
        return $query->where('status', ProductStatus::PUBLISHED->value);
    }

    public function scopeFeatured($query)
    {
        return $query->where('featured', true);
    }

    public function isOnSale(): bool
    {
        if (!$this->sale_start) {
            return false;
        }

        $now = now();

        if ($now->lt($this->sale_start)) {
            return false;
        }

        if ($this->sale_end && $now->gt($this->sale_end)) {
            return false;
        }

        return true;
    }

    public static function getAvailableActions(): array
    {
        return ProductAction::getAvailableActions();
    }

    public function callActions(string $event = 'purchased', ?ProductPurchase $productPurchase = null, array $additionalData = [])
    {
        return ProductAction::callForProduct(
            $this,
            $event,
            $productPurchase,
            $additionalData
        );
    }

    public function relatedProducts(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'product_relations',
            'product_id',
            'related_product_id'
        )->withPivot('type')->withTimestamps();
    }

    public function upsells(): BelongsToMany
    {
        return $this->relatedProducts()->wherePivot('type', 'upsell');
    }

    public function crossSells(): BelongsToMany
    {
        return $this->relatedProducts()->wherePivot('type', 'cross-sell');
    }

    public function scopeVisible($query)
    {
        return $query->where('is_visible', true)
            ->where('status', ProductStatus::PUBLISHED->value)
            ->where(function ($q) {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
            });
    }

    public function scopeByCategory($query, $categoryId)
    {
        return $query->whereHas('categories', function ($q) use ($categoryId) {
            $q->where('id', $categoryId);
        });
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('slug', 'like', "%{$search}%")
                ->orWhere('sku', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%");
        });
    }

    public function isVisible(): bool
    {
        if (!$this->is_visible || $this->status !== ProductStatus::PUBLISHED) {
            return false;
        }

        if ($this->published_at && now()->lt($this->published_at)) {
            return false;
        }

        return true;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'sku' => $this->sku,
            'name' => $this->getLocalized('name'),
            'description' => $this->getLocalized('description'),
            'short_description' => $this->getLocalized('short_description'),
            'type' => $this->type,
            'price' => $this->getCurrentPrice(),
            'sale_price' => $this->sale_price,
            'is_on_sale' => $this->isOnSale(),
            'low_stock' => $this->isLowStock(),
            'featured' => $this->featured,
            'virtual' => $this->virtual,
            'downloadable' => $this->downloadable,
            'weight' => $this->weight,
            'dimensions' => [
                'length' => $this->length,
                'width' => $this->width,
                'height' => $this->height,
            ],
            'categories' => $this->categories,
            'attributes' => $this->attributes,
            'variants' => $this->children,
            'parent' => $this->parent,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Get an attribute from the model.
     *
     * @param  string  $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        $value = parent::getAttribute($key);

        // Ensure meta is never null for HasMetaTranslation trait
        if ($key === 'meta' && is_null($value)) {
            $this->attributes['meta'] = '{}';
            return json_decode('{}');
        }

        return $value;
    }

    /**
     * Create a new instance of the given model.
     *
     * @param  array  $attributes
     * @param  bool  $exists
     * @return static
     */
    public function newInstance($attributes = [], $exists = false)
    {
        // Ensure meta is initialized
        if (!isset($attributes['meta'])) {
            $attributes['meta'] = '{}';
        }

        return parent::newInstance($attributes, $exists);
    }

    /**
     * Check if this is a booking product
     */
    public function isBooking(): bool
    {
        return $this->type === ProductType::BOOKING;
    }

    /**
     * Check stock availability for a booking period
     */
    public function isAvailableForBooking(\DateTimeInterface $from, \DateTimeInterface $until, int $quantity = 1): bool
    {
        if (!$this->manage_stock) {
            return true;
        }

        // Get stock reservations that overlap with the requested period
        $overlappingReservations = $this->stocks()
            ->where('type', StockType::RESERVATION->value)
            ->where('status', StockStatus::PENDING->value)
            ->where(function ($query) use ($from, $until) {
                $query->where(function ($q) use ($from, $until) {
                    // Reservation starts during the requested period
                    $q->whereBetween('created_at', [$from, $until]);
                })->orWhere(function ($q) use ($from, $until) {
                    // Reservation ends during the requested period
                    $q->whereBetween('expires_at', [$from, $until]);
                })->orWhere(function ($q) use ($from, $until) {
                    // Reservation encompasses the entire requested period
                    $q->where('created_at', '<=', $from)
                      ->where('expires_at', '>=', $until);
                });
            })
            ->sum('quantity');

        $availableStock = $this->getAvailableStock() - abs($overlappingReservations);

        return $availableStock >= $quantity;
    }

    /**
     * Scope for booking products
     */
    public function scopeBookings($query)
    {
        return $query->where('type', ProductType::BOOKING->value);
    }
}
