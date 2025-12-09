# Laravel Shop Package

[![Tests](https://github.com/blax-software/laravel-shop/actions/workflows/tests.yml/badge.svg)](https://github.com/blax-software/laravel-shop/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/blax-software/laravel-shop.svg?style=flat-square)](https://packagist.org/packages/blax-software/laravel-shop)
[![License](https://img.shields.io/packagist/l/blax-software/laravel-shop.svg?style=flat-square)](https://packagist.org/packages/blax-software/laravel-shop)
[![PHP Version](https://img.shields.io/packagist/php-v/blax-software/laravel-shop.svg?style=flat-square)](https://packagist.org/packages/blax-software/laravel-shop)

A comprehensive headless e-commerce package for Laravel with stock management, Stripe integration, and product actions.

## Features

- 🛍️ **Product Management** - Simple, variable, grouped, and external products
- 💰 **Multi-Currency Support** - Handle multiple currencies with ease
- 📦 **Advanced Stock Management** - Stock reservations, low stock alerts, and backorders
- 💳 **Stripe Integration** - Built-in Stripe product and price synchronization
- 🎯 **Product Actions** - Execute custom actions on product events (purchases, refunds)
- 🔗 **Product Relations** - Related products, upsells, and cross-sells
- 🌍 **Translation Ready** - Built-in meta translation support
- 📊 **Stock Logging** - Complete audit trail of stock changes
- 🎨 **Headless Architecture** - Perfect for API-first applications
- ⚡ **Caching Support** - Built-in cache management for better performance
- 🛒 **Shopping Capabilities** - Built-in trait for any purchaser model
- 🎭 **Facade Support** - Clean, expressive API through Shop and Cart facades
- 👤 **Guest Cart Support** - Session-based carts for unauthenticated users

## Installation

```bash
composer require blax-software/laravel-shop
```

Publish the configuration:

```bash
php artisan vendor:publish --provider="Blax\Shop\ShopServiceProvider"
```

Run migrations:

```bash
php artisan migrate
```

## Quick Start

### Setup Your User Model

Add the `HasShoppingCapabilities` trait to any model that should be able to purchase products (typically your User model):

```php
use Blax\Shop\Traits\HasShoppingCapabilities;

class User extends Authenticatable
{
    use HasShoppingCapabilities;
    
    // ...existing code...
}
```

### Creating Your First Product

```php
use Blax\Shop\Models\Product;

$product = Product::create([
    'slug' => 'amazing-t-shirt',
    'sku' => 'TSH-001',
    'type' => 'simple',
    'manage_stock' => true,
    'status' => 'published',
]);

$product->prices()->create([
    'currency' => 'USD',
    'unit_amount' => 1999, // $19.99
    'sale_unit_amount' => 1499, // $14.99
    'is_default' => true,
]);

$product->adjustStock(StockType::INCREASE, 100); // Add 100 items to stock
$product->adjustStock(StockType::DECREASE, 90); // Remove 100 items from stock
$product->adjustStock(
    StockType::CLAIMED, 
    10, 
    from: now(), 
    until: now()->addDay(), 
    note: 'Booked'
); // Claim/reserve 10 stocks


// Add translated name
$product->setLocalized('name', 'Amazing T-Shirt', 'en');
$product->setLocalized('description', 'A comfortable cotton t-shirt', 'en');
```

### Working with Cart (Authenticated Users)

```php
use Blax\Shop\Facades\Cart;
use Blax\Shop\Models\Product;

$product = Product::find($productId);
$user = auth()->user();

// Add to cart (via facade)
Cart::add($product, quantity: 2);

// Or via user trait
$cartItem = $user->addToCart($product, quantity: 1);

// Get cart totals
$total = Cart::total();
$itemCount = Cart::itemCount();

// Check if cart is empty
if (Cart::isEmpty()) {
    // Cart is empty
}

// Remove from cart
Cart::remove($product);

// Clear entire cart
Cart::clear();

// Checkout cart
$completedPurchases = Cart::checkout();
```

### Working with Guest Carts

```php
use Blax\Shop\Facades\Cart;

// Create or retrieve guest cart (uses session ID automatically)
$guestCart = Cart::guest();

// Or with specific session ID
$guestCart = Cart::guest('custom-session-id');

// Add items to guest cart
$guestCart->addToCart($product, quantity: 1);

// Get guest cart totals
$total = Cart::total($guestCart);
$itemCount = Cart::itemCount($guestCart);

// Check if guest cart is empty
if (Cart::isEmpty($guestCart)) {
    // Cart is empty
}

// Clear guest cart
Cart::clear($guestCart);

// Convert guest cart to user cart on login
$guestCart->convertToUserCart($user);
```

### Purchasing Products Directly

```php
use Blax\Shop\Models\Product;

$product = Product::find($productId);
$user = auth()->user();

// Simple purchase
$purchase = $user->purchase($product, quantity: 1);

// Purchase with options
$purchase = $user->purchase($product, quantity: 2, options: [
    'price_id' => $priceId,
    'charge_id' => $paymentIntent->id,
]);

// Check if user has purchased
if ($user->hasPurchased($product)) {
    // Grant access
}
```

### Using Shop Facade

```php
use Blax\Shop\Facades\Shop;

// Get all products
$products = Shop::products()->get();

// Get published products only
$products = Shop::published()->get();

// Get products in stock
$products = Shop::inStock()->get();

// Get featured products
$featured = Shop::featured()->get();

// Search products
$results = Shop::search('t-shirt')->get();

// Check stock availability
if (Shop::checkStock($product, quantity: 5)) {
    // Sufficient stock available
}

// Get available stock
$available = Shop::getAvailableStock($product);

// Check if product is on sale
if (Shop::isOnSale($product)) {
    // Show sale badge
}

// Get configuration
$currency = Shop::currency(); // USD
$config = Shop::config('cart.expire_after_days', 30);
```

## Documentation

- [Product Management](docs/01-products.md)
- [Stripe Integration](docs/02-stripe.md)
- [Purchasing Products](docs/03-purchasing.md)
- [Subscriptions](docs/04-subscriptions.md)
- [Stock Management](docs/05-stock.md)
- [API Usage](docs/06-api.md)

## Models

The package includes the following models:

- **Product** - Main product model with support for simple, variable, grouped, and external products
- **ProductPrice** - Multi-currency pricing with sale prices and subscription support
- **ProductCategory** - Hierarchical product categories
- **ProductStock** - Advanced stock management with reservations and logging
- **ProductAttribute** - Product attributes (size, color, material, etc.)
- **ProductPurchase** - Purchase records and history
- **ProductAction** - Custom actions triggered by product events
- **ProductActionRun** - Execution logs for product actions
- **Cart** - Shopping cart for authenticated users and guests
- **CartItem** - Individual items in a cart
- **PaymentMethod** - Saved payment methods
- **PaymentProviderIdentity** - Links users to payment providers (Stripe, etc.)

## Traits

Available traits for your models:

- **HasShoppingCapabilities** - Complete shopping functionality (cart + purchases)
- **HasCart** - Cart management functionality only
- **HasPaymentMethods** - Payment method management
- **HasStripeAccount** - Stripe integration for users
- **HasPrices** - Price management (for Product model)
- **HasStocks** - Stock management (for Product model)
- **HasCategories** - Category relationships (for Product model)
- **HasProductRelations** - Related products, upsells, cross-sells
- **HasChargingOptions** - Payment processing capabilities

## Facades

The package provides two facades for cleaner API access:

### Shop Facade

```php
use Blax\Shop\Facades\Shop;

Shop::products()              // Get product query builder
Shop::product($id)            // Find product by ID
Shop::categories()            // Get categories query builder
Shop::inStock()              // Get in-stock products
Shop::featured()             // Get featured products
Shop::published()            // Get published products
Shop::search($query)         // Search products
Shop::checkStock($product, $qty) // Check stock availability
Shop::getAvailableStock($product) // Get available stock quantity
Shop::isOnSale($product)     // Check if product is on sale
Shop::config($key, $default) // Get shop configuration
Shop::currency()             // Get default currency
```

### Cart Facade

```php
use Blax\Shop\Facades\Cart;

Cart::current()                    // Get current user's cart
Cart::guest($sessionId)            // Get/create guest cart
Cart::forUser($user)               // Get cart for specific user
Cart::find($cartId)                // Find cart by ID
Cart::add($product, $qty, $params) // Add item to cart
Cart::remove($product, $qty)       // Remove item from cart
Cart::update($cartItem, $qty)      // Update cart item quantity
Cart::clear($cart)                 // Clear cart items
Cart::checkout($cart)              // Checkout cart
Cart::total($cart)                 // Get cart total
Cart::itemCount($cart)             // Get item count
Cart::items($cart)                 // Get cart items
Cart::isEmpty($cart)               // Check if cart is empty
Cart::isExpired($cart)             // Check if cart is expired
Cart::isConverted($cart)           // Check if cart was converted
Cart::unpaidAmount($cart)          // Get unpaid amount
Cart::paidAmount($cart)            // Get paid amount
```

## Configuration

The `config/shop.php` file contains all configuration options:

```php
return [
    // Table names (customizable for multi-tenancy)
    'tables' => [
        'products' => 'products',
        'product_categories' => 'product_categories',
        'product_prices' => 'product_prices',
        'product_stocks' => 'product_stocks',
        'product_attributes' => 'product_attributes',
        'product_purchases' => 'product_purchases',
        'product_actions' => 'product_actions',
        'product_action_runs' => 'product_action_runs',
        'product_relations' => 'product_relations',
        'carts' => 'carts',
        'cart_items' => 'cart_items',
        'cart_discounts' => 'cart_discounts',
        'payment_methods' => 'payment_methods',
        'payment_provider_identities' => 'payment_provider_identities',
    ],
    
    // Model classes (allow overriding)
    'models' => [
        'product' => \Blax\Shop\Models\Product::class,
        'product_price' => \Blax\Shop\Models\ProductPrice::class,
        'product_category' => \Blax\Shop\Models\ProductCategory::class,
        'product_stock' => \Blax\Shop\Models\ProductStock::class,
        'product_attribute' => \Blax\Shop\Models\ProductAttribute::class,
        'product_purchase' => \Blax\Shop\Models\ProductPurchase::class,
        'cart' => \Blax\Shop\Models\Cart::class,
        'cart_item' => \Blax\Shop\Models\CartItem::class,
        'payment_provider_identity' => \Blax\Shop\Models\PaymentProviderIdentity::class,
        'payment_method' => \Blax\Shop\Models\PaymentMethod::class,
    ],
    
    // API Routes
    'routes' => [
        'enabled' => true,
        'prefix' => 'api/shop',
        'middleware' => ['api'],
        'name_prefix' => 'shop.',
    ],
    
    // Stock management
    'stock' => [
        'track_inventory' => true,
        'allow_backorders' => false,
        'low_stock_threshold' => 5,
        'log_changes' => true,
        'auto_release_expired' => true,
    ],
    
    // Product actions
    'actions' => [
        'path' => app_path('Jobs/ProductAction'),
        'namespace' => 'App\\Jobs\\ProductAction',
        'auto_discover' => true,
    ],
    
    // Stripe integration
    'stripe' => [
        'enabled' => env('SHOP_STRIPE_ENABLED', false),
        'sync_prices' => true,
    ],
    
    // Cache configuration
    'cache' => [
        'enabled' => env('SHOP_CACHE_ENABLED', true),
        'ttl' => 3600,
        'prefix' => 'shop:',
    ],
    
    // Cart configuration
    'cart' => [
        'expire_after_days' => 30,
        'auto_cleanup' => true,
        'merge_on_login' => true,
    ],
    
    // API response format
    'api' => [
        'include_meta' => true,
        'wrap_response' => true,
        'response_key' => 'data',
    ],
];
```

## Commands

### Add Example Products

Create example products for testing and demonstration purposes:

```bash
# Create 2 products of each type (default)
php artisan shop:add-example-products

# Create 5 products of each type
php artisan shop:add-example-products --count=5

# Clean existing example products first
php artisan shop:add-example-products --clean
```

This command creates:
- ✅ All 4 product types (simple, variable, grouped, external)
- ✅ Product categories
- ✅ Product attributes (material, size, color, etc.)
- ✅ Multiple pricing options (multi-currency, subscriptions)
- ✅ Example product actions (email notifications, stats updates)
- ✅ Variations for variable products
- ✅ Child products for grouped products
- ✅ Realistic data using Faker

### Reinstall Shop Tables

```bash
# With confirmation
php artisan shop:reinstall

# Force without confirmation
php artisan shop:reinstall --force
```

⚠️ **Warning:** This will delete all shop data!

## License

MIT License

## Support

For issues and questions, please use the [GitHub issue tracker](https://github.com/blax/laravel-shop/issues).
