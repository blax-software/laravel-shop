<?php

namespace Blax\Shop\Models;

use App\Services\StripeService;
use Blax\Shop\Contracts\Cartable;
use Blax\Workkit\Traits\HasMetaTranslation;
use Blax\Shop\Events\ProductCreated;
use Blax\Shop\Events\ProductUpdated;
use Blax\Shop\Contracts\Purchasable;
use Blax\Shop\Exceptions\NotEnoughStockException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Product extends Model implements Purchasable, Cartable
{
    use HasFactory, HasUuids, HasMetaTranslation;

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
    ];

    protected $casts = [
        'manage_stock' => 'boolean',
        'virtual' => 'boolean',
        'downloadable' => 'boolean',
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

    public function prices(): MorphMany
    {
        return $this->morphMany(
            config('shop.models.product_price', ProductPrice::class),
            'purchasable'
        );
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

    public function stocks(): HasMany
    {
        return $this->hasMany(config('shop.models.product_stock', 'Blax\Shop\Models\ProductStock'));
    }

    public function actions(): HasMany
    {
        return $this->hasMany(config('shop.models.product_action', ProductAction::class));
    }

    public function getAvailableStocksAttribute(): int
    {
        return $this->stocks()
            ->available()
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->sum('quantity') ?? 0;
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
        return $query->where('status', 'published');
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

    public function getCurrentPrice(bool|null $sales_price = null): ?float
    {
        return $this->defaultPrice()->first()?->getCurrentPrice($sales_price ?? $this->isOnSale());
    }

    public function isInStock(): bool
    {
        if (!$this->manage_stock) {
            return true;
        }

        return $this->getAvailableStock() > 0;
    }

    public function decreaseStock(int $quantity = 1): bool
    {
        if (!$this->manage_stock) {
            return true;
        }

        if ($this->AvailableStocks < $quantity) {
            return throw new NotEnoughStockException("Not enough stock available for product ID {$this->id}");
        }

        $this->stocks()->create([
            'quantity' => -$quantity,
            'type' => 'decrease',
            'status' => 'completed',
        ]);

        $this->logStockChange(-$quantity, 'decrease');

        $this->save();

        return true;
    }

    public function increaseStock(int $quantity = 1): bool
    {
        if (!$this->manage_stock) {
            return false;
        }

        $this->stocks()->create([
            'quantity' => $quantity,
            'type' => 'increase',
            'status' => 'completed',
        ]);

        $this->logStockChange($quantity, 'increase');

        $this->save();

        return true;
    }

    public function reserveStock(
        int $quantity,
        $reference = null,
        ?\DateTimeInterface $until = null,
        ?string $note = null
    ): ?\Blax\Shop\Models\ProductStock {

        if (!$this->manage_stock) {
            return null;
        }

        $stockModel = config('shop.models.product_stock', 'Blax\Shop\Models\ProductStock');

        return $stockModel::reserve(
            $this,
            $quantity,
            $reference,
            $until,
            $note
        );
    }

    public function getAvailableStock(): int
    {
        if (!$this->manage_stock) {
            return PHP_INT_MAX;
        }

        return max(0, $this->AvailableStocks);
    }

    public function getReservedStock(): int
    {
        return $this->activeStocks()->sum('quantity');
    }

    protected function logStockChange(int $quantityChange, string $type): void
    {
        DB::table('product_stock_logs')->insert([
            'product_id' => $this->id,
            'quantity_change' => $quantityChange,
            'quantity_after' => $this->stock_quantity,
            'type' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function syncPricesDown()
    {
        if (config('shop.stripe.enabled') && config('shop.stripe.sync_prices')) {
            StripeService::syncProductPricesDown($this);
        }
        return $this;
    }

    public static function getAvailableActions(): array
    {
        return ProductAction::getAvailableActions();
    }

    public function callActions(string $event = 'purchased', ?ProductPurchase $productPurchase = null, array $additionalData = []): void
    {
        ProductAction::callForProduct($this, $event, $productPurchase, $additionalData);
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

    public function scopeInStock($query)
    {
        return $query->where(function ($q) {
            $q->where('manage_stock', false)
                ->orWhere(function ($q2) {
                    $q2->where('manage_stock', true)
                        ->whereRaw("(SELECT SUM(quantity) FROM " . config('shop.tables.product_stocks', 'product_stocks') . " WHERE product_id = " . config('shop.tables.products', 'products') . ".id) > 0");
                });
        });
    }

    public function scopeVisible($query)
    {
        return $query->where('is_visible', true)
            ->where('status', 'published')
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

    public function scopePriceRange($query, ?float $min = null, ?float $max = null)
    {
        if ($min !== null) {
            $query->where('price', '>=', $min);
        }
        if ($max !== null) {
            $query->where('price', '<=', $max);
        }
        return $query;
    }

    public function scopeOrderByPrice($query, string $direction = 'asc')
    {
        return $query->orderBy('price', $direction);
    }

    public function scopeLowStock($query)
    {
        $stockTable = config('shop.tables.product_stocks', 'product_stocks');
        $productTable = config('shop.tables.products', 'products');
        
        return $query->where('manage_stock', true)
            ->whereNotNull('low_stock_threshold')
            ->whereRaw("(\n                SELECT COALESCE(SUM(quantity), 0)\n                FROM {$stockTable}\n                WHERE {$stockTable}.product_id = {$productTable}.id\n                AND {$stockTable}.status IN ('completed', 'pending')\n                AND ({$stockTable}.expires_at IS NULL OR {$stockTable}.expires_at > ?)\n            ) <= {$productTable}.low_stock_threshold", [now()]);
    }

    public function isLowStock(): bool
    {
        if (!$this->manage_stock || !$this->low_stock_threshold) {
            return false;
        }

        return $this->getAvailableStock() <= $this->low_stock_threshold;
    }

    public function isVisible(): bool
    {
        if (!$this->is_visible || $this->status !== 'published') {
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

    public function defaultPrice()
    {
        return $this->prices()->where('is_default', true);
    }

    public function getPriceAttribute(): ?float
    {
        return $this->getCurrentPrice();
    }

    public function reservations()
    {
        $stockModel = config('shop.models.product_stock', 'Blax\Shop\Models\ProductStock');

        return $stockModel::reservations()
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where('product_id', $this->id);
    }
}
