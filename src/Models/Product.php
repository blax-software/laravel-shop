<?php

namespace Blax\Shop\Models;

use Blax\Shop\Contracts\Cartable;
use Blax\Workkit\Traits\HasMetaTranslation;
use Blax\Shop\Events\ProductCreated;
use Blax\Shop\Events\ProductUpdated;
use Blax\Shop\Contracts\Purchasable;
use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Exceptions\HasNoDefaultPriceException;
use Blax\Shop\Exceptions\HasNoPriceException;
use Blax\Shop\Exceptions\InvalidBookingConfigurationException;
use Blax\Shop\Exceptions\InvalidPoolConfigurationException;
use Blax\Shop\Services\CartService;
use Blax\Shop\Traits\HasCategories;
use Blax\Shop\Traits\HasPrices;
use Blax\Shop\Traits\HasPricingStrategy;
use Blax\Shop\Traits\HasProductRelations;
use Blax\Shop\Traits\HasStocks;
use Blax\Shop\Traits\MayBePoolProduct;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Cache;

class Product extends Model implements Purchasable, Cartable
{
    use HasFactory, HasUuids, HasMetaTranslation, HasStocks, HasPrices, HasPricingStrategy, HasCategories, HasProductRelations, MayBePoolProduct;

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
        return $this->belongsTo(static::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id');
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

    public function scopeVisible($query)
    {
        return $query->where('is_visible', true)
            ->where('status', ProductStatus::PUBLISHED->value)
            ->where(function ($q) {
                $q->whereNull('published_at')
                    ->orWhere('published_at', '<=', now());
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

        // Get stock claims that overlap with the requested period
        $overlappingClaims = $this->stocks()
            ->where('type', StockType::CLAIMED->value)
            ->where('status', StockStatus::PENDING->value)
            ->where(function ($query) use ($from, $until) {
                $query->where(function ($q) use ($from, $until) {
                    // Claim starts during the requested period
                    $q->whereBetween('claimed_from', [$from, $until]);
                })->orWhere(function ($q) use ($from, $until) {
                    // Claim ends during the requested period
                    $q->whereBetween('expires_at', [$from, $until]);
                })->orWhere(function ($q) use ($from, $until) {
                    // Claim encompasses the entire requested period
                    $q->where('claimed_from', '<=', $from)
                        ->where('expires_at', '>=', $until);
                })->orWhere(function ($q) use ($from, $until) {
                    // Claim without claimed_from (immediately claimed)
                    $q->whereNull('claimed_from')
                        ->where(function ($subQ) use ($from, $until) {
                            $subQ->whereNull('expires_at')
                                ->orWhere('expires_at', '>=', $from);
                        });
                });
            })
            ->sum('quantity');

        $availableStock = $this->getAvailableStock() - abs($overlappingClaims);

        return $availableStock >= $quantity;
    }

    /**
     * Scope for booking products
     */
    public function scopeBookings($query)
    {
        return $query->where('type', ProductType::BOOKING->value);
    }

    /**
     * Get the current price with pool product inheritance support
     */
    public function getCurrentPrice(bool|null $sales_price = null, mixed $cart = null): ?float
    {
        // If this is a pool product, use cart-aware pricing if cart is provided
        if ($this->isPool()) {
            // If no cart provided, try to get the cart from session first, then user's cart
            if (!$cart) {
                // Try session first
                $cartId = session(CartService::CART_SESSION_KEY);
                if ($cartId) {
                    $cart = \Blax\Shop\Models\Cart::find($cartId);
                    // Make sure the cart is valid (not expired/converted)
                    if ($cart && ($cart->isExpired() || $cart->isConverted())) {
                        $cart = null;
                    }
                }

                // Fall back to authenticated user's cart if no valid session cart
                if (!$cart && auth()->check()) {
                    $cart = auth()->user()->currentCart();
                }
            }

            if ($cart) {
                // Cart-aware: Get price for next available item after what's in cart
                $currentQuantityInCart = $cart->items()
                    ->where('purchasable_id', $this->getKey())
                    ->where('purchasable_type', get_class($this))
                    ->sum('quantity');

                return $this->getNextAvailablePoolPrice($currentQuantityInCart, $sales_price);
            }

            // No cart and no user: Get inherited price based on strategy (lowest/highest/average of ALL available items)
            return $this->getInheritedPoolPrice($sales_price);
        }

        // For non-pool products, use the trait's default behavior
        return $this->defaultPrice()->first()?->getCurrentPrice($sales_price ?? $this->isOnSale());
    }

    /**
     * Validate booking product configuration and provide helpful error messages
     * 
     * @throws InvalidBookingConfigurationException
     */
    public function validateBookingConfiguration(bool $throwOnWarnings = false): array
    {
        $errors = [];
        $warnings = [];

        if (!$this->isBooking()) {
            throw InvalidBookingConfigurationException::notABookingProduct($this->name);
        }

        // Critical: Stock management must be enabled
        if (!$this->manage_stock) {
            throw InvalidBookingConfigurationException::stockManagementNotEnabled($this->name);
        }

        // Check for available stock
        if ($this->getAvailableStock() <= 0) {
            $warnings[] = "No stock available for booking";
            if ($throwOnWarnings) {
                throw InvalidBookingConfigurationException::noStockAvailable($this->name);
            }
        }

        // Check for pricing
        if (!$this->hasPrice()) {
            $warnings[] = "No pricing configured";
            if ($throwOnWarnings) {
                throw InvalidBookingConfigurationException::noPricingConfigured($this->name);
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Validate product pricing configuration
     * 
     * @throws HasNoPriceException
     * @throws HasNoDefaultPriceException
     */
    public function validatePricing(bool $throwExceptions = true): array
    {
        $errors = [];
        $warnings = [];

        // Special handling for pool products
        if ($this->isPool()) {
            $hasDirectPrice = $this->prices()->exists();
            $singleItems = $this->singleProducts;

            if (!$hasDirectPrice) {
                // Check if single items have prices to inherit
                $singleItemsWithPrices = $singleItems->filter(function ($item) {
                    return $item->prices()->exists();
                });

                if ($singleItemsWithPrices->isEmpty()) {
                    $errors[] = "Pool product has no pricing (direct or inherited)";
                    if ($throwExceptions) {
                        throw HasNoPriceException::poolProductNoPriceAndNoSingleItemPrices($this->name);
                    }
                }
            }

            // If pool has direct prices, validate them
            if ($hasDirectPrice) {
                return $this->validateDirectPricing($throwExceptions);
            }

            // Pool with inherited pricing is valid
            return [
                'valid' => empty($errors),
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        // For all other product types, validate direct pricing
        return $this->validateDirectPricing($throwExceptions);
    }

    /**
     * Validate direct pricing on the product
     * 
     * @throws HasNoPriceException
     * @throws HasNoDefaultPriceException
     */
    protected function validateDirectPricing(bool $throwExceptions = true): array
    {
        $errors = [];
        $warnings = [];

        $allPrices = $this->prices;
        $priceCount = $allPrices->count();

        // No prices at all
        if ($priceCount === 0) {
            $errors[] = "Product has no prices configured";
            if ($throwExceptions) {
                throw HasNoPriceException::noPricesConfigured($this->name, $this->id);
            }

            return [
                'valid' => false,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        $defaultPrices = $allPrices->where('is_default', true);
        $defaultCount = $defaultPrices->count();

        // Multiple default prices
        if ($defaultCount > 1) {
            $errors[] = "Product has {$defaultCount} default prices (should have exactly 1)";
            if ($throwExceptions) {
                throw HasNoDefaultPriceException::multipleDefaultPrices($this->name, $defaultCount);
            }

            return [
                'valid' => false,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        // No default price
        if ($defaultCount === 0) {
            if ($priceCount === 1) {
                // Single price but not marked as default
                $errors[] = "Product has one price but it's not marked as default";
                if ($throwExceptions) {
                    throw HasNoDefaultPriceException::onlyNonDefaultPriceExists($this->name);
                }
            } else {
                // Multiple prices but none are default
                $errors[] = "Product has {$priceCount} prices but none are marked as default";
                if ($throwExceptions) {
                    throw HasNoDefaultPriceException::multiplePricesNoDefault($this->name, $priceCount);
                }
            }

            return [
                'valid' => false,
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        }

        // Valid: Exactly one default price
        return [
            'valid' => true,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Get helpful setup instructions for pool products
     */
    public static function getPoolSetupInstructions(): string
    {
        return <<<'INSTRUCTIONS'
# Pool Product Setup Guide

Pool products aggregate multiple individual items (e.g., parking spots, hotel rooms) 
into a single purchasable product where customers don't need to select specific items.

## Step 1: Create the Pool Product

```php
use Blax\Shop\Models\Product;
use Blax\Shop\Enums\ProductType;

$pool = Product::create([
    'type' => ProductType::POOL,
    'name' => 'Parking Lot',
    'slug' => 'parking-lot',
]);
```

## Step 2: Create Single Items (Booking Products)

```php
$spot1 = Product::create([
    'type' => ProductType::BOOKING,
    'name' => 'Parking Spot #1',
    'manage_stock' => true,
]);
$spot1->increaseStock(1);

$spot2 = Product::create([
    'type' => ProductType::BOOKING,
    'name' => 'Parking Spot #2',
    'manage_stock' => true,
]);
$spot2->increaseStock(1);
```

## Step 3: Link Single Items to Pool

```php
use Blax\Shop\Enums\ProductRelationType;

$pool->productRelations()->attach([
    $spot1->id => ['type' => ProductRelationType::SINGLE],
    $spot2->id => ['type' => ProductRelationType::SINGLE],
]);
```

## Step 4: Set Pricing (Optional)

```php
use Blax\Shop\Models\ProductPrice;

// Option A: Set price on pool (takes precedence)
ProductPrice::create([
    'purchasable_id' => $pool->id,
    'purchasable_type' => Product::class,
    'unit_amount' => 5000,  // 50.00 per day
    'currency' => 'USD',
    'is_default' => true,
]);

// Option B: Set prices on single items (pool inherits)
ProductPrice::create([
    'purchasable_id' => $spot1->id,
    'purchasable_type' => Product::class,
    'unit_amount' => 5000,
    'currency' => 'USD',
    'is_default' => true,
]);

// Set pricing strategy (if using inheritance)
$pool->setPoolPricingStrategy('average'); // or 'lowest' or 'highest'
```

## Step 5: Add to Cart with Timespan

```php
use Blax\Shop\Facades\Cart;
use Carbon\Carbon;

Cart::addBooking(
    $pool,
    2,  // quantity
    Carbon::parse('2025-01-15 09:00'),  // from
    Carbon::parse('2025-01-17 17:00'),  // until
);
```

## Validation

```php
// Validate configuration before use
$validation = $pool->validatePoolConfiguration();
if (!$validation['valid']) {
    foreach ($validation['errors'] as $error) {
        echo "Error: $error\n";
    }
}
```

INSTRUCTIONS;
    }

    /**
     * Get helpful setup instructions for booking products
     */
    public static function getBookingSetupInstructions(): string
    {
        return <<<'INSTRUCTIONS'
# Booking Product Setup Guide

Booking products represent time-based reservations (conference rooms, equipment, etc.)

## Step 1: Create the Booking Product

```php
use Blax\Shop\Models\Product;
use Blax\Shop\Enums\ProductType;

$product = Product::create([
    'type' => ProductType::BOOKING,
    'name' => 'Conference Room A',
    'manage_stock' => true,  // REQUIRED for bookings
]);
```

## Step 2: Set Initial Stock

```php
// For single-unit bookings (1 room, 1 equipment piece, etc.)
$product->increaseStock(1);

// For multiple units (e.g., 5 identical meeting rooms)
$product->increaseStock(5);
```

## Step 3: Configure Pricing

```php
use Blax\Shop\Models\ProductPrice;

ProductPrice::create([
    'purchasable_id' => $product->id,
    'purchasable_type' => Product::class,
    'unit_amount' => 10000,  // Price per day in cents (100.00 USD)
    'currency' => 'USD',
    'is_default' => true,
]);
```

## Step 4: Add to Cart with Timespan

```php
use Blax\Shop\Facades\Cart;
use Carbon\Carbon;

Cart::addBooking(
    $product,
    1,  // quantity
    Carbon::parse('2025-01-15 09:00'),  // from
    Carbon::parse('2025-01-17 17:00'),  // until
);

// Price will be: 100.00/day × 3 days = 300.00
```

## Check Availability

```php
$from = Carbon::parse('2025-01-15 09:00');
$until = Carbon::parse('2025-01-17 17:00');

if ($product->isAvailableForBooking($from, $until, 1)) {
    // Product is available for this period
    Cart::addBooking($product, 1, $from, $until);
}
```

## Validation

```php
// Validate configuration
$validation = $product->validateBookingConfiguration();
if (!$validation['valid']) {
    foreach ($validation['errors'] as $error) {
        echo "Error: $error\n";
    }
}
```

INSTRUCTIONS;
    }
}
