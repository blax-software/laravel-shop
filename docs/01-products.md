# Product Management

## Overview

The Laravel Shop package provides a complete product management system with support for:
- Multi-language content through `HasMetaTranslation` trait
- Flexible pricing with `ProductPrice` model
- Stock management and reservations
- Product variants (parent/child relationships)
- Categories, attributes, and actions
- Product relations (related, upsell, cross-sell)

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
- Generate a random slug if not provided (e.g., 'new-product-abc12345')
- Initialize meta field as empty JSON object
- Set status to 'draft'
- Set type to 'simple'

### Basic Product Creation

```php
$product = Product::create([
    'slug' => 'blue-hoodie',
    'sku' => 'HOOD-BLU-001',
    'type' => 'simple',
    'status' => 'published',
    'is_visible' => true,
    'featured' => false,
]);

// Add translated content (stored in meta column)
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
    
    // Sale Period
    'sale_start' => now(),
    'sale_end' => now()->addDays(7),
    
    // Stock Management
    'manage_stock' => true,
    'low_stock_threshold' => 10,
    
    // Physical Properties
    'weight' => 0.5, // kg
    'length' => 20,  // cm
    'width' => 15,   // cm
    'height' => 10,  // cm
    'virtual' => false,
    'downloadable' => false,
    
    // Tax
    'tax_class' => 'standard',
]);

// Add translations
$product->setLocalized('name', 'Premium Wireless Headphones', 'en');
$product->setLocalized('name', 'Auriculares Premium Inalámbricos', 'es');

$product->setLocalized('description', 'High-quality wireless headphones with noise cancellation', 'en');
$product->setLocalized('short_description', 'Premium wireless headphones', 'en');

// Add custom meta data
$product->meta = (object)[
    'brand' => 'AudioPro',
    'color' => 'black',
    'warranty' => '2 years',
];
$product->save();
```

## Product Pricing

Products use the `ProductPrice` model for flexible pricing. Each product must have at least one price to be purchasable.

### Creating Product Prices

```php
use Blax\Shop\Models\ProductPrice;

// Create a default price
$price = ProductPrice::create([
    'purchasable_type' => Product::class,
    'purchasable_id' => $product->id,
    'currency' => 'USD',
    'unit_amount' => 4999, // $49.99 in cents
    'is_default' => true,
    'active' => true,
    'type' => 'one_time',
]);

// Add sale price
$price->update([
    'sale_unit_amount' => 3999, // $39.99
]);
```

### Multi-Currency Pricing

```php
// USD price (default)
ProductPrice::create([
    'purchasable_type' => Product::class,
    'purchasable_id' => $product->id,
    'currency' => 'USD',
    'unit_amount' => 4999,
    'is_default' => true,
    'active' => true,
]);

// EUR price
ProductPrice::create([
    'purchasable_type' => Product::class,
    'purchasable_id' => $product->id,
    'currency' => 'EUR',
    'unit_amount' => 4499,
    'is_default' => false,
    'active' => true,
]);
```

### Recurring Prices (Subscriptions)

```php
ProductPrice::create([
    'purchasable_type' => Product::class,
    'purchasable_id' => $product->id,
    'currency' => 'USD',
    'unit_amount' => 999, // $9.99/month
    'type' => 'recurring',
    'interval' => 'month',
    'interval_count' => 1,
    'trial_period_days' => 7,
    'is_default' => true,
    'active' => true,
]);
```

### Get Current Price

```php
// Get the current price (considers sale prices and dates)
$currentPrice = $product->getCurrentPrice(); // Returns float

// Check if product is on sale
if ($product->isOnSale()) {
    echo "On sale!";
}

// Get default price
$defaultPrice = $product->defaultPrice()->first();
```

## Stock Management

### Enable Stock Management

```php
$product->update([
    'manage_stock' => true,
    'low_stock_threshold' => 10,
]);
```

### Increase/Decrease Stock

```php
// Increase stock
$product->increaseStock(50);

// Decrease stock
$product->decreaseStock(1);

// Get available stock
$available = $product->getAvailableStock();

// Check if in stock
if ($product->isInStock()) {
    echo "In stock!";
}

// Check if low stock
if ($product->isLowStock()) {
    echo "Low stock warning!";
}
```

### Stock Reservations

```php
use Blax\Shop\Models\ProductStock;

// Reserve stock temporarily
$reservation = $product->reserveStock(
    quantity: 2,
    reference: $cart,
    until: now()->addMinutes(15),
    note: 'Cart reservation'
);

// Release reservation
$reservation->update(['status' => 'completed']);

// Get active reservations
$reservations = $product->reservations()->get();
```

### Stock History

```php
// Get all stock records
$stockRecords = $product->stocks()->get();

// Filter by type
$increases = $product->stocks()->where('type', 'increase')->get();
$decreases = $product->stocks()->where('type', 'decrease')->get();
$reservations = $product->stocks()->where('type', 'reservation')->get();
```

## Product Variants

### Create Parent Product

```php
$parent = Product::create([
    'type' => 'variable',
    'slug' => 'hoodie',
    'status' => 'published',
]);

$parent->setLocalized('name', 'Hoodie', 'en');
```

### Create Variants

```php
// Small variant
$small = Product::create([
    'type' => 'simple',
    'slug' => 'hoodie-small',
    'sku' => 'HOOD-S',
    'parent_id' => $parent->id,
    'status' => 'published',
]);

ProductPrice::create([
    'purchasable_type' => Product::class,
    'purchasable_id' => $small->id,
    'currency' => 'USD',
    'unit_amount' => 4999,
    'is_default' => true,
    'active' => true,
]);

// Medium variant
$medium = Product::create([
    'type' => 'simple',
    'slug' => 'hoodie-medium',
    'sku' => 'HOOD-M',
    'parent_id' => $parent->id,
    'status' => 'published',
]);

ProductPrice::create([
    'purchasable_type' => Product::class,
    'purchasable_id' => $medium->id,
    'currency' => 'USD',
    'unit_amount' => 4999,
    'is_default' => true,
    'active' => true,
]);

// Get all variants
$variants = $parent->children()->get();
```

## Categories

### Create Categories

```php
use Blax\Shop\Models\ProductCategory;

$category = ProductCategory::create([
    'name' => 'Electronics',
    'slug' => 'electronics',
    'description' => 'Electronic products',
    'is_visible' => true,
    'sort_order' => 1,
]);

// Create child category
$subCategory = ProductCategory::create([
    'name' => 'Headphones',
    'slug' => 'headphones',
    'parent_id' => $category->id,
    'is_visible' => true,
    'sort_order' => 1,
]);
```

### Attach Products to Categories

```php
// Attach single category
$product->categories()->attach($category->id);

// Attach multiple categories
$product->categories()->attach([$category1->id, $category2->id]);

// Sync categories (removes others)
$product->categories()->sync([$category1->id, $category2->id]);

// Get product categories
$categories = $product->categories()->get();
```

### Query Products by Category

```php
// Get products in category
$products = Product::byCategory($category->id)->get();

// Get category tree
$tree = ProductCategory::getTree();

// Get visible categories
$visible = ProductCategory::visible()->get();

// Get root categories
$roots = ProductCategory::roots()->get();
```

## Product Attributes

### Add Attributes

```php
use Blax\Shop\Models\ProductAttribute;

// Add color attribute
ProductAttribute::create([
    'product_id' => $product->id,
    'key' => 'Color',
    'value' => 'Blue',
    'sort_order' => 1,
]);

// Add size attribute
ProductAttribute::create([
    'product_id' => $product->id,
    'key' => 'Size',
    'value' => 'Large',
    'sort_order' => 2,
]);

// Get product attributes
$attributes = $product->attributes()->get();
```

## Product Actions

Product actions allow you to trigger events when certain things happen (e.g., on purchase).

### Create Product Actions

```php
use Blax\Shop\Models\ProductAction;

// Send email on purchase
ProductAction::create([
    'product_id' => $product->id,
    'action_type' => 'SendWelcomeEmail',
    'event' => 'purchased',
    'parameters' => [
        'template' => 'welcome',
        'delay' => 0,
    ],
    'active' => true,
    'sort_order' => 1,
]);

// Grant access on purchase
ProductAction::create([
    'product_id' => $product->id,
    'action_type' => 'GrantCourseAccess',
    'event' => 'purchased',
    'parameters' => [
        'course_id' => 123,
    ],
    'active' => true,
    'sort_order' => 2,
]);
```

### Trigger Actions

```php
// Actions are automatically triggered on events
// You can also manually trigger them:
$product->callActions('purchased', $productPurchase);

// On refund
$product->callActions('refunded', $productPurchase);
```

## Product Relations

### Related Products

```php
// Add related products
$product->relatedProducts()->attach($relatedProduct->id, [
    'type' => 'related',
]);

// Get related products
$related = $product->relatedProducts()->wherePivot('type', 'related')->get();
```

### Upsells

```php
// Add upsell product
$product->relatedProducts()->attach($upsellProduct->id, [
    'type' => 'upsell',
]);

// Get upsell products
$upsells = $product->upsells()->get();
```

### Cross-sells

```php
// Add cross-sell product
$product->relatedProducts()->attach($crossSellProduct->id, [
    'type' => 'cross-sell',
]);

// Get cross-sell products
$crossSells = $product->crossSells()->get();
```

## Querying Products

### Scopes

```php
// Published products
$published = Product::published()->get();

// Visible products (published + visible + published_at check)
$visible = Product::visible()->get();

// Featured products
$featured = Product::featured()->get();

// In stock products
$inStock = Product::inStock()->get();

// Low stock products
$lowStock = Product::lowStock()->get();

// By type
$simple = Product::where('type', 'simple')->get();
$virtual = Product::where('virtual', true)->get();
$downloadable = Product::where('downloadable', true)->get();
```

### Search

```php
// Search by slug, SKU, or name
$results = Product::search('headphones')->get();

// Price range
$results = Product::priceRange(min: 10.00, max: 100.00)->get();

// Order by price
$cheapest = Product::orderByPrice('asc')->get();
$expensive = Product::orderByPrice('desc')->get();
```

### Combining Scopes

```php
$products = Product::visible()
    ->inStock()
    ->byCategory($categoryId)
    ->priceRange(min: 20, max: 50)
    ->orderByPrice('asc')
    ->get();
```

## Product Visibility

```php
// Check if product is visible
if ($product->isVisible()) {
    // Product is published, visible, and published_at is in past
}

// Set visibility
$product->update([
    'status' => 'published',
    'is_visible' => true,
    'published_at' => now(),
]);
```

## Virtual & Downloadable Products

```php
// Virtual product (no shipping)
$product->update([
    'virtual' => true,
]);

// Downloadable product
$product->update([
    'downloadable' => true,
]);

// Both
$product->update([
    'virtual' => true,
    'downloadable' => true,
]);
```

## API Export

```php
// Get product as API array
$data = $product->toApiArray();

// Returns:
// [
//     'id' => '...',
//     'slug' => '...',
//     'sku' => '...',
//     'name' => '...', // localized
//     'description' => '...', // localized
//     'short_description' => '...', // localized
//     'type' => '...',
//     'price' => 49.99,
//     'sale_price' => null,
//     'is_on_sale' => false,
//     'low_stock' => false,
//     'featured' => false,
//     'virtual' => false,
//     'downloadable' => false,
//     'weight' => 0.5,
//     'dimensions' => [...],
//     'categories' => [...],
//     'attributes' => [...],
//     'variants' => [...],
//     'parent' => null,
//     'created_at' => '...',
//     'updated_at' => '...',
// ]
```
