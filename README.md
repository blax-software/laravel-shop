[![Blax Software OSS](https://raw.githubusercontent.com/blax-software/laravel-workkit/master/art/oss-initiative-banner.svg)](https://github.com/blax-software)

# Laravel Shop

[![Tests](https://github.com/blax-software/laravel-shop/actions/workflows/tests.yml/badge.svg)](https://github.com/blax-software/laravel-shop/actions/workflows/tests.yml)
[![Tests Count](https://img.shields.io/badge/tests-1349%20passing-success?style=flat-square)](#testing)
[![Assertions](https://img.shields.io/badge/assertions-3641-blue?style=flat-square)](#testing)
[![Latest Version](https://img.shields.io/packagist/v/blax-software/laravel-shop.svg?style=flat-square)](https://packagist.org/packages/blax-software/laravel-shop)
[![License](https://img.shields.io/packagist/l/blax-software/laravel-shop.svg?style=flat-square)](https://packagist.org/packages/blax-software/laravel-shop)
[![PHP Version](https://img.shields.io/packagist/php-v/blax-software/laravel-shop.svg?style=flat-square)](https://packagist.org/packages/blax-software/laravel-shop)

A comprehensive headless e-commerce package for Laravel with stock management, Stripe integration, and product actions.

## Features

- 🛍️ **Product Management** - Simple, variable, grouped, external, booking, and pool products
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
php artisan migrate
```

That's it — the package's migrations are auto-loaded from `vendor/` so a fresh `migrate` is all you need.

Optionally publish the config:

```bash
php artisan vendor:publish --tag="shop-config"
```

If you'd rather own the migrations in your own `database/migrations/` directory (e.g. to customise schemas, switch ID types, etc.):

```bash
php artisan vendor:publish --tag="shop-migrations"
```

To stop the package from also auto-loading them, set `'run_migrations' => false` in `config/shop.php`.

## Configuration

The main configuration file is located at `config/shop.php`. Here you can configure:
- Database table names
- Caching settings
- Stripe integration keys and settings
- Currency settings

## Quick Start

### Setup Your User Model

Add the `HasShoppingCapabilities` trait to any model that should be able to purchase products (typically your User model):

```php
use Blax\Shop\Traits\HasShoppingCapabilities;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasShoppingCapabilities;
    
    // ...existing code...
}
```

### Creating Your First Product

Use the provided Enums to ensure type safety and consistency.

```php
use Blax\Shop\Models\Product;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\StockType;

$product = Product::create([
    'slug' => 'amazing-t-shirt',
    'sku' => 'TSH-001',
    'type' => ProductType::SIMPLE,
    'manage_stock' => true,
    'status' => ProductStatus::PUBLISHED,
    'name' => 'Amazing T-Shirt', // Uses meta translation
    'description' => 'A comfortable cotton t-shirt',
]);

// Add Price
$product->prices()->create([
    'currency' => 'USD',
    'unit_amount' => 1999, // $19.99
    'sale_unit_amount' => 1499, // $14.99
    'is_default' => true,
]);

// Manage Stock
$product->adjustStock(StockType::INCREASE, 100); // Add 100 items to stock
$product->adjustStock(StockType::DECREASE, 10); // Remove 10 items from stock

// Reserve Stock (e.g., for a booking)
$product->adjustStock(
    StockType::CLAIMED, 
    1, 
    from: now(), 
    until: now()->addDay(), 
    note: 'Reserved for Order #123'
);
```

### Working with Cart

```php
use Blax\Shop\Facades\Cart;

// Add item to cart
Cart::addToCart($product, 1);

// Add item with date range (for bookings)
Cart::addToCart($product, 1, [], now(), now()->addDay());

// Checkout
$cart = Cart::getCart();
$cart->checkout(); // Creates purchases, claims stock, etc.
```

## Advanced Usage

### Pool Products

Pool products are collections of single items (e.g., "Parking Spaces" containing "Spot A1", "Spot A2").

```php
use Blax\Shop\Models\Product;
use Blax\Shop\Enums\ProductType;

// Create the Pool Parent
$pool = Product::create([
    'type' => ProductType::POOL,
    'name' => 'Parking Spaces',
    'manage_stock' => true, // Pool manages availability
]);

// Create Single Items
$spot1 = Product::create([
    'type' => ProductType::BOOKING,
    'name' => 'Spot A1',
]);

$spot2 = Product::create([
    'type' => ProductType::BOOKING,
    'name' => 'Spot A2',
]);

// Attach Singles to Pool
$pool->attachSingleItems([$spot1->id, $spot2->id]);
```

### Booking Products

Booking products are time-based and require `from` and `until` dates when adding to cart.

```php
use Blax\Shop\Models\Product;
use Blax\Shop\Enums\ProductType;

$room = Product::create([
    'type' => ProductType::BOOKING,
    'name' => 'Conference Room',
    'manage_stock' => true,
]);

// Check availability
$isAvailable = $room->availableOnDate(now(), now()->addHour());
```

## Testing

We test this package for many edge cases across every surface — products,
stock, pricing strategies, cart/checkout, loan lifecycle, pool aggregation,
booking, Stripe sync and the event surface — so host applications can lean
on the behaviour with confidence.

```
Tests: 1349, Assertions: 3641
```

CI runs the full suite on every push (see the badge above). To run it
locally:

```bash
./vendor/bin/phpunit
```

The tests use an in-memory SQLite database and Orchestra Testbench, so they
run in roughly a minute with no external services required.

## Documentation

For more detailed documentation, please refer to the `docs/` directory in the repository.

## License

MIT. See [LICENSE](LICENSE).

## Star History

<a href="https://www.star-history.com/?repos=blax-software%2Flaravel-shop&type=date&legend=top-left">
 <picture>
   <source media="(prefers-color-scheme: dark)" srcset="https://api.star-history.com/chart?repos=blax-software/laravel-shop&type=date&theme=dark&legend=top-left" />
   <source media="(prefers-color-scheme: light)" srcset="https://api.star-history.com/chart?repos=blax-software/laravel-shop&type=date&legend=top-left" />
   <img alt="Star History Chart" src="https://api.star-history.com/chart?repos=blax-software/laravel-shop&type=date&legend=top-left" />
 </picture>
</a>
