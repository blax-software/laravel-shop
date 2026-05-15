# Variation Products

## Overview

A Variation (`ProductType::VARIATION`) is a **child** of a [Variable](./04-variable-products.md) parent — one specific variant the customer can actually buy. Functionally a Variation behaves like a [Simple product](./03-simple-products.md) bolted to a parent: it owns its SKU, its stock, and its prices.

## Key Characteristics

### 1. **Always has a parent**
- `parent_id` points at a `VARIABLE` product (`Product::parent()`)
- Catalogue presentation flows from the parent (name, images, description)

### 2. **Owns its own commerce data**
- `sku` is the per-variant code
- Each Variation can `manage_stock` independently
- Each Variation has its own `ProductPrice` rows

### 3. **Cartable on its own**
- The cart accepts variations directly: `$cart->addToCart($variation, 1)`
- The resulting `CartItem.purchasable` is the Variation, not the parent

## How It Works

Visually identical to a Simple product, with one extra column:

```
Product(type=VARIATION, parent_id=<Variable>)
   sku  → unique code per variation
   prices()  → its own ProductPrice rows
   stocks() → its own product_stocks rows when manage_stock=true
```

The `parent()` relation reads back the Variable parent so listing pages can hydrate shared data.

## Pricing

Variations accept the full pricing surface available to [Simple products](./03-simple-products.md):

| Scheme | When to use |
|---|---|
| `ONE_TIME` / `PER_UNIT` | Standard pricing per variant (e.g. €24.99 for the Medium shirt) |
| `RECURRING` | Subscription variants (e.g. monthly vs annual plans of the same SaaS) |
| `TIERED` | Per-variant usage tiers (e.g. one variant of an API plan that charges by call volume) |
| `sale_unit_amount` | Promotion on a specific variant without disturbing the others |

Example: monthly vs annual plans as variations of one SaaS product.

```php
$saas = Product::create(['name' => 'Pro Plan', 'type' => ProductType::VARIABLE]);

$monthly = Product::create([
    'name' => 'Pro Plan — Monthly',
    'type' => ProductType::VARIATION,
    'parent_id' => $saas->id,
    'sku' => 'PRO-MO',
]);
ProductPrice::create([
    'purchasable_id' => $monthly->id,
    'purchasable_type' => Product::class,
    'type' => PriceType::RECURRING,
    'interval' => RecurringInterval::MONTH,
    'interval_count' => 1,
    'unit_amount' => 1900,
    'is_default' => true,
]);

$annual = Product::create([
    'name' => 'Pro Plan — Annual',
    'type' => ProductType::VARIATION,
    'parent_id' => $saas->id,
    'sku' => 'PRO-YR',
]);
ProductPrice::create([
    'purchasable_id' => $annual->id,
    'purchasable_type' => Product::class,
    'type' => PriceType::RECURRING,
    'interval' => RecurringInterval::YEAR,
    'unit_amount' => 19000,
    'is_default' => true,
]);
```

## Configuration

```php
$variant = Product::create([
    'name' => 'Logo T-Shirt (Medium)',
    'type' => ProductType::VARIATION,
    'parent_id' => $tshirt->id,
    'sku' => 'TSHIRT-M',
    'manage_stock' => true,
]);
$variant->increaseStock(20);

ProductPrice::create([
    'purchasable_id' => $variant->id,
    'purchasable_type' => Product::class,
    'unit_amount' => 2499,
    'is_default' => true,
]);
```

## Cart Integration

```php
$cart->addToCart($variant, 1);

// Resulting purchase:
$purchase->purchasable_type;  // App\Models\Product (or your subclass)
$purchase->purchasable_id;    // $variant->id
$purchase->product->parent;   // the Variable parent, if you need it
```

## Common Use Cases

- **Apparel sizes / colours**
- **Subscription billing intervals** (monthly / annual)
- **Software seat-count plans** (5-seat / 25-seat / unlimited)
- **Hardware configurations** (CPU / RAM tiers of one base machine)

## Best Practices

1. **Always populate `parent_id`.** Orphaned variations are confusing — they'll show in catalogue queries but have no shared marketing copy.
2. **One default price per currency per variation.**
3. **Keep variant-specific data on the variant** (price, stock, SKU, attributes). Keep shared data on the parent (description, images, categories).
4. **Filter listings**. When you query "all products for the catalogue", exclude variations explicitly — they're not standalone catalogue entries.

   ```php
   Product::where('type', '!=', ProductType::VARIATION->value)->get();
   ```

## Troubleshooting

### Variation shows up in main catalogue listings
Add a `where('type', '!=', ProductType::VARIATION->value)` clause to your listing query, or use a global scope.

### Stock lives on the wrong row
Variations carry their own stock, Variable parents don't. If `getAvailableStock()` returns 0 on a variation, check `manage_stock = true` and that `increaseStock()` was called on the **variation**, not the parent.

## Related Documentation

- [Variable products](./04-variable-products.md) — the parent type
- [Simple products](./03-simple-products.md) — for the un-varianted single-SKU case
- [Product Relations](../05-product-relations.md)
