# Simple Products

## Overview

Simple products (`ProductType::SIMPLE`) are stand-alone, single-SKU items — the default product shape. They sell as one unit each, optionally track stock, and accept the full range of price configurations. If you're not sure which type to use, start here.

## Key Characteristics

### 1. **One SKU, one cart item**
- Each purchase decrements quantity by N
- No date windows, no variant resolution, no pool aggregation
- The `quantity` on `CartItem` / `ProductPurchase` is literal "N copies sold"

### 2. **Stock is optional**
- `manage_stock = false` (default) — sells with unlimited availability
- `manage_stock = true` — uses the package's stock subsystem (`product_stocks`, `increaseStock` / `decreaseStock`)

### 3. **Most flexible pricing**
- Per-unit one-time prices (the common case)
- Recurring subscription prices
- Tiered prices for usage-based billing
- Sale prices (`sale_unit_amount`)

## How It Works

### Lifecycle

```
add to cart → CartItem(quantity=N)
   │
   ├─ if manage_stock → decreaseStock(N)
   ▼
checkout → ProductPurchase(purchasable=Product, quantity=N, amount=N × unit_amount)
```

No date columns are set on the `CartItem` / `ProductPurchase` — `from` and `until` remain `null`, which is what distinguishes a Simple sale from a Booking / Loan.

## Pricing

Simple products work with every billing shape the package supports. Pick the one that matches your billing semantics.

### One-time, per-unit (most common)

```php
ProductPrice::create([
    'purchasable_id' => $product->id,
    'purchasable_type' => Product::class,
    'type' => PriceType::ONE_TIME,
    'billing_scheme' => BillingScheme::PER_UNIT,
    'currency' => 'EUR',
    'unit_amount' => 2999, // €29.99
    'is_default' => true,
]);
```

### With a sale price

```php
ProductPrice::create([
    'purchasable_id' => $product->id,
    'purchasable_type' => Product::class,
    'unit_amount' => 2999,        // regular
    'sale_unit_amount' => 1999,   // €19.99 on sale
    'sale_start' => now(),
    'sale_end' => now()->addWeek(),
    'is_default' => true,
]);

$product->getCurrentPrice();      // 2999 (regular)
$product->getCurrentPrice(true);  // 1999 (sale)
```

### Recurring subscription

```php
ProductPrice::create([
    'purchasable_id' => $product->id,
    'purchasable_type' => Product::class,
    'type' => PriceType::RECURRING,
    'interval' => RecurringInterval::MONTH,
    'interval_count' => 1,
    'trial_period_days' => 14,
    'unit_amount' => 999, // €9.99/month
    'is_default' => true,
]);
```

See [Stripe integration](../02-stripe.md) for how recurring prices sync to Stripe.

### Tiered (usage-based)

For things like API calls, GB transferred, seats. Set `billing_scheme = TIERED` and add `ProductPriceTier` rows — see [Prices — tiered billing](../Prices/01-price-types-and-billing-schemes.md#tiered-billing-billingschemetiered).

## Configuration

### Minimal

```php
$mug = Product::create([
    'name' => 'Coffee Mug',
    'type' => ProductType::SIMPLE,  // also the default if you omit `type`
    'slug' => 'coffee-mug',
]);

ProductPrice::create([
    'purchasable_id' => $mug->id,
    'purchasable_type' => Product::class,
    'unit_amount' => 1500,
    'is_default' => true,
]);
```

### With stock tracking

```php
$mug = Product::create([
    'name' => 'Coffee Mug',
    'type' => ProductType::SIMPLE,
    'manage_stock' => true,
]);

$mug->increaseStock(50);          // we received 50 from the supplier

$mug->getAvailableStock();        // 50
$mug->isInStock();                // true
$mug->isLowStock();               // false until you set low_stock_threshold
```

## Cart Integration

```php
$cart->addToCart($mug, 2);  // adds 2 mugs to the cart
```

What happens:
1. A `CartItem` is created with `purchasable_type = Product::class`, `quantity = 2`
2. If `manage_stock = true`, `decreaseStock(2)` runs immediately
3. `subtotal` = `unit_amount × 2`

At checkout the cart item becomes a `ProductPurchase` with `from` and `until` left null.

## Common Use Cases

- **Physical merchandise**: mugs, books (when you don't need loaning), apparel one-offs
- **Digital downloads**: set `virtual = true` and `downloadable = true`
- **Service tickets** without a date attached
- **SaaS plans** (use `RECURRING`)
- **Usage credits** (use `TIERED`)

## Best Practices

1. **Skip stock tracking for unlimited products.** Don't set `manage_stock = true` on infinite digital goods — the audit log churns for no reason.
2. **Set `is_default = true` on exactly one price** per product per currency. The `getCurrentPrice()` accessor picks the default.
3. **Use sale prices instead of writing to `unit_amount`.** `sale_unit_amount` preserves the regular price for after the sale.
4. **Slug uniqueness is enforced.** If you don't set one, the `creating` hook generates `new-product-XXXXXXXX`.

## Troubleshooting

### `getCurrentPrice()` returns 0
You probably forgot to mark the price `is_default = true`. The product has prices but none are flagged as default.

### Stock counter never moves
Make sure `manage_stock = true` is set on the product. `increaseStock` / `decreaseStock` are no-ops otherwise.

## Related Documentation

- [Variable products](./04-variable-products.md) — when one SKU isn't enough
- [Loanable products](./08-loanable-products.md) — when the product is borrowed and returned
- [Prices — types and billing schemes](../Prices/01-price-types-and-billing-schemes.md)
- [Purchasing](../03-purchasing.md)
