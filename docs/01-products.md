# Product Management

## Creating Products

### Minimal Product Creation

The absolute minimum to create a product:

```php
use Blax\Shop\Models\Product;

$product = Product::create([
    'slug' => 'my-product',
]);
```

This will automatically:
- Generate a random slug if not provided
- Create a default name "New Product [slug]"
- Set status to 'draft'
- Set type to 'simple'

### Basic Product Creation

```php
$product = Product::create([
    'slug' => 'blue-hoodie',
    'sku' => 'HOOD-BLU-001',
    'type' => 'simple',
    'price' => 49.99,
    'regular_price' => 49.99,
    'status' => 'published',
    'is_visible' => true,
    'featured' => false,
]);

// Add translated content
$product->setLocalized('name', 'Blue Hoodie', 'en');
$product->setLocalized('description', 'Comfortable cotton hoodie', 'en');
$product->setLocalized('short_description', 'Cotton hoodie', 'en');
```

### Advanced Product Creation

```php
$product = Product::create([
    // Basic Info
    'slug' => 'premium-headphones',
    'sku' => 'HEAD-PREM-001',
    'type' => 'simple',
    'status' => 'published',
    'is_visible' => true,
    'featured' => true,
    'published_at' => now(),
    'sort_order' => 10,
    
    // Pricing
    'price' => 199.99,
    'regular_price' => 249.99,
    'sale_price' => 199.99,
    'sale_start' => now(),
    'sale_end' => now()->addDays(7),
    
    // Stock Management
    'manage_stock' => true,
    'stock_quantity' => 50,
    'low_stock_threshold' => 10,
    'in_stock' => true,
    'stock_status' => 'instock',
    
    // Physical Properties
    'weight' => 0.5, // kg
    'length' => 20,  // cm
    'width' => 15,   // cm
    'height' => 10,  // cm
    'virtual' => false,
    'downloadable' => false,
    
    // Tax
    'tax_class' => 'standard',
    
    // Custom Meta
    'meta' => [
        'brand' => 'AudioPro',
        'color' => 'black',
        'warranty' => '2 years',
    ],
]);

// Add translations
$product->setLocalized('name', 'Premium Wireless Headphones', 'en');
$product->setLocalized('name', 'Auriculares Premium Inalámbricos', 'es');

$product->setLocalized('description', 'High-quality wireless headphones with noise cancellation', 'en');
$product->setLocalized('short_description', 'Premium wireless headphones', 'en');
```

## Product Types

### Simple Product

```php
$product = Product::create([
    'type' => 'simple',
    'slug' => 't-shirt',
    'price' => 19.99,
]);
```

### Variable Product (Parent)

```php
$parent = Product::create([
    'type' => 'variable',
    'slug' => 'hoodie',
    'price' => 49.99, // Base price
]);

// Create variants
$small = Product::create([
    'type' => 'simple',
    'slug' => 'hoodie-small',
    'sku' => 'HOOD-S',
    'parent_id' => $parent->id,
    'price' => 49.99,
]);

$medium = Product::create([
    'type' => 'simple',
    'slug' => 'hoodie-medium',
    'sku' => 'HOOD-M',
    'parent_id' => $parent->id,
    'price' => 49.99,
]);

$large = Product::create([
    'type' => 'simple',
    'slug' => 'hoodie-large',
    'sku' => 'HOOD-L',
    'parent_id' => $parent->id,
    'price' => 54.99, // Different price
]);
```

### Grouped Product

```php
$bundle = Product::create([
    'type' => 'grouped',
    'slug' => 'starter-bundle',
    'price' => 99.99,
]);

// Link products to the bundle (handle this in your app logic)
```

### Virtual/Downloadable Product

```php
$ebook = Product::create([
    'slug' => 'laravel-guide',
    'price' => 29.99,
    'virtual' => true,
    'downloadable' => true,
    'manage_stock' => false, // Virtual products don't need stock
]);
```

## Product Attributes

Add custom attributes to products:

```php
use Blax\Shop\Models\ProductAttribute;

// Add size attribute
ProductAttribute::create([
    'product_id' => $product->id,
    'key' => 'size',
    'value' => 'Large',
    'type' => 'select',
    'sort_order' => 1,
]);

// Add color attribute
ProductAttribute::create([
    'product_id' => $product->id,
    'key' => 'color',
    'value' => '#FF0000',
    'type' => 'color',
    'sort_order' => 2,
]);

// Retrieve attributes
$attributes = $product->attributes;
```

## Product Categories

```php
use Blax\Shop\Models\ProductCategory;

// Create category
$category = ProductCategory::create([
    'slug' => 'clothing',
]);
$category->setLocalized('name', 'Clothing', 'en');

// Attach product to category
$product->categories()->attach($category->id);

// Detach
$product->categories()->detach($category->id);

// Sync categories
$product->categories()->sync([
    $category1->id,
    $category2->id,
]);
```

## Multi-Currency Pricing

```php
use Blax\Shop\Models\ProductPrice;

// Add EUR pricing
ProductPrice::create([
    'product_id' => $product->id,
    'currency' => 'EUR',
    'price' => 39.99,
    'is_default' => false,
]);

// Add GBP pricing
ProductPrice::create([
    'product_id' => $product->id,
    'currency' => 'GBP',
    'price' => 34.99,
    'is_default' => false,
]);

// Get all prices
$prices = $product->prices;

// Get price for specific currency
$eurPrice = $product->prices()->where('currency', 'EUR')->first();
```

## Product Relations

### Related Products

```php
// Attach related products
$product->relatedProducts()->attach($relatedProduct->id, [
    'type' => 'related',
    'sort_order' => 1,
]);

// Get all related products
$related = $product->relatedProducts()->get();
```

### Upsells

```php
// Attach upsell product
$product->relatedProducts()->attach($premiumProduct->id, [
    'type' => 'upsell',
    'sort_order' => 1,
]);

// Get upsells
$upsells = $product->upsells;
```

### Cross-sells

```php
// Attach cross-sell product
$product->relatedProducts()->attach($accessory->id, [
    'type' => 'cross-sell',
    'sort_order' => 1,
]);

// Get cross-sells
$crossSells = $product->crossSells;
```

## Querying Products

### Basic Queries

```php
// Published products
$products = Product::published()->get();

// In stock products
$products = Product::inStock()->get();

// Featured products
$products = Product::featured()->get();

// Visible products (published and within publish date)
$products = Product::visible()->get();
```

### Advanced Queries

```php
// Search products
$products = Product::search('hoodie')->get();

// Filter by category
$products = Product::byCategory($categoryId)->get();

// Price range
$products = Product::priceRange(10, 50)->get();

// Order by price
$products = Product::orderByPrice('asc')->get();

// Low stock products
$products = Product::lowStock()->get();

// Combined query
$products = Product::visible()
    ->inStock()
    ->byCategory($categoryId)
    ->priceRange(20, 100)
    ->orderByPrice('asc')
    ->paginate(20);
```

## Product Methods

### Sale Detection

```php
if ($product->isOnSale()) {
    echo "On sale!";
}
```

### Current Price

```php
$price = $product->getCurrentPrice(); // Returns sale_price if on sale, otherwise regular_price
```

### Visibility Check

```php
if ($product->isVisible()) {
    // Show product
}
```

### Low Stock Check

```php
if ($product->isLowStock()) {
    // Show low stock warning
}
```

## API Serialization

```php
// Get API-friendly array
$data = $product->toApiArray();

// Returns:
// [
//     'id' => '...',
//     'slug' => '...',
//     'name' => '...',
//     'price' => 49.99,
//     'is_on_sale' => true,
//     'in_stock' => true,
//     'categories' => [...],
//     'attributes' => [...],
//     'variants' => [...],
//     // ...
// ]
```

## Events

The package dispatches events on product lifecycle:

```php
use Blax\Shop\Events\ProductCreated;
use Blax\Shop\Events\ProductUpdated;

// Listen to events in your EventServiceProvider
protected $listen = [
    ProductCreated::class => [
        SendProductCreatedNotification::class,
    ],
    ProductUpdated::class => [
        ClearProductCache::class,
    ],
];
```
