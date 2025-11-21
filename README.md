# Laravel Shop Package

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
    'price' => 29.99,
    'regular_price' => 29.99,
    'manage_stock' => true,
    'stock_quantity' => 100,
    'status' => 'published',
]);

// Add translated name
$product->setLocalized('name', 'Amazing T-Shirt', 'en');
$product->setLocalized('description', 'A comfortable cotton t-shirt', 'en');
```

### Purchasing a Product

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

// Add to cart
$cartItem = $user->addToCart($product, quantity: 1);

// Checkout cart
$completedPurchases = $user->checkout();

// Check if user has purchased
if ($user->hasPurchased($product)) {
    // Grant access
}
```

## Documentation

- [Product Management](docs/01-products.md)
- [Stripe Integration](docs/02-stripe.md)
- [Purchasing Products](docs/03-purchasing.md)
- [Subscriptions](docs/04-subscriptions.md)
- [Stock Management](docs/05-stock.md)
- [API Usage](docs/06-api.md)

## Configuration

The `config/shop.php` file contains all configuration options:

```php
return [
    'tables' => [
        'products' => 'products',
        'product_categories' => 'product_categories',
        // ...
    ],
    
    'stripe' => [
        'enabled' => env('SHOP_STRIPE_ENABLED', false),
        'sync_prices' => env('SHOP_STRIPE_SYNC_PRICES', true),
    ],
    
    'stock' => [
        'allow_backorders' => env('SHOP_ALLOW_BACKORDERS', false),
        'log_changes' => env('SHOP_LOG_STOCK_CHANGES', true),
    ],
    
    'cache' => [
        'enabled' => env('SHOP_CACHE_ENABLED', true),
        'prefix' => 'shop:',
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
