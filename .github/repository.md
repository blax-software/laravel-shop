# Laravel Shop - Repository Context

This document provides context for AI agents and developers working on this repository.

## Project Overview

- **Package Name**: `blax-software/laravel-shop`
- **Type**: Composer package (Laravel library)
- **Purpose**: A comprehensive headless e-commerce package for Laravel
- **License**: MIT
- **PHP Version**: 8.2+
- **Laravel Version**: 9.0 - 12.0

## Project Structure

```
├── config/                 # Configuration files
│   └── shop.php           # Main shop configuration
├── database/
│   ├── factories/         # Model factories for testing
│   └── migrations/        # Database migration stubs
├── docs/                  # Documentation
├── routes/
│   └── api.php           # API routes (webhooks, etc.)
├── src/
│   ├── Console/Commands/ # Artisan commands
│   ├── Contracts/        # Interfaces (Cartable, Chargable, Purchasable)
│   ├── Enums/            # PHP Enums (ProductType, OrderStatus, etc.)
│   ├── Events/           # Laravel events
│   ├── Exceptions/       # Custom exceptions
│   ├── Facades/          # Shop, Cart facades
│   ├── Http/             # Controllers (webhooks)
│   ├── Models/           # Eloquent models
│   ├── Services/         # Service classes
│   ├── Traits/           # Reusable traits
│   └── ShopServiceProvider.php
├── tests/
│   ├── Feature/          # Feature/integration tests
│   ├── Unit/             # Unit tests
│   ├── TestCase.php      # Base test case
│   └── bootstrap.php     # PHPUnit bootstrap
└── workbench/            # Orchestra Testbench workbench
```

## Key Concepts

### Product Types
- **SIMPLE**: Standalone product with no variations
  - E.g., "T-shirt", "Mug", "E-book"
- **VARIABLE**: Product with variations/options
  - E.g., "T-shirt" with sizes S, M, L
- **GROUPED**: Collection of related products sold together
  - E.g., "Gift Set" with multiple items
- **EXTERNAL**: Product linking to external purchase site
  - E.g., "Third-party course"
- **BOOKING**: Time-based bookable product
  - E.g., "Hotel Room", "Consultation Slot"
- **POOL**: Dynamic pricing based on availability and grouped stocks
  - E.g., "Parking Space", "Event Ticket"

### Pool Products
Pool products are complex - they consist of:
- A **pool parent** (e.g., "Parking Spaces")
- Multiple **single items** (e.g., individual parking spots)
- **Pricing strategy**: LOWEST, HIGHEST, or AVERAGE
- **Fallback pricing**: Pool can have a default price if singles don't have prices

Key pool concepts:
- `product_id` column on cart_items tracks which single is allocated
- `reallocatePoolItems()` reassigns singles when dates change
- Singles can use pool's price as fallback

### Cart System
- Authenticated users: Cart stored in database
- Guest users: Session-based cart with session ID
- Cart items track: `purchasable_id`, `purchasable_type`, `product_id`, `price_id`, `from`, `until`
- Booking items require date ranges

### Stripe Integration
- Syncs products/prices to Stripe
- Handles webhooks for checkout.session.completed
- Creates orders from completed checkout sessions
- Uses Laravel Cashier

## Commands

### Running Tests
```bash|fish
./vendor/bin/phpunit
```

## Testing

- **Framework**: PHPUnit 10+
- **Database**: SQLite in-memory for tests
- **Base Class**: `Blax\Shop\Tests\TestCase` (extends Orchestra Testbench)
- **Factories**: Located in `database/factories/`

### Writing Tests
```php
<?php

namespace Blax\Shop\Tests\Feature\Pool;

use Blax\Shop\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MyPoolTest extends TestCase
{
    #[Test]
    public function it_does_something()
    {
        // Test code
    }
}
```

## Key Models

| Model             | Description                                       |
|-------------------|---------------------------------------------------|
| `Product`         | Main product model with types, stock, pricing     |
| `ProductPrice`    | Prices for products (multi-currency, sale prices) |
| `ProductCategory` | Product categorization                            |
| `Cart`            | Shopping cart (user or guest)                     |
| `CartItem`        | Items in cart with quantities, dates, prices      |
| `Order`           | Completed orders                                  |
| `Purchase`        | Individual purchase records                       |

## Key Traits

| Trait                     | Purpose                                  |
|---------------------------|------------------------------------------|
| `HasShoppingCapabilities` | Add to User model for purchasing ability |
| `MayBePoolProduct`        | Pool product functionality               |
| `HasStock`                | Stock management methods                 |
| `Purchasable`             | Make models purchasable                  |

## Configuration

Main config file: `config/shop.php`

Key settings:
- `shop.tables.*` - Database table names
- `shop.cache.*` - Caching configuration
- `shop.stripe.*` - Stripe integration settings

## Dependencies

### Required
- `illuminate/support` & `illuminate/database` (Laravel)
- `blax-software/laravel-workkit` (Base utilities)
- `laravel/cashier` (Stripe integration)

### Dev
- `orchestra/testbench` (Laravel package testing)
- `phpunit/phpunit` (Testing)
- `mockery/mockery` (Mocking)

## Common Patterns

### Creating a Pool Product
```php
$pool = Product::create([
    'type' => ProductType::POOL,
    'name' => 'Parking Spaces',
    // ...
]);

$single1 = Product::create([
    'type' => ProductType::BOOKING,
    'name' => 'Spot A1',
    // ...
]);

$pool->attachSingleItems([$single1->id]);
```

### Adding to Cart with Dates
```php
$cart->addToCart($product, 1, [], $from, $until);
```

### Checking Out
```php
$cart->checkout(); // Creates purchases, claims stock
```

## Recent Architecture Decisions

1. **`product_id` column on cart_items**: Replaced `allocated_single_item_id` in meta with a proper foreign key column to track which pool single is allocated to a cart item.

2. **Order creation in webhooks**: Stripe checkout flow creates orders in the webhook handler when the cart doesn't have a pre-existing order.

3. **Price fallback for pool singles**: Singles without prices use the pool's price as fallback.
