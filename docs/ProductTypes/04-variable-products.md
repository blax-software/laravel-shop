# Variable Products

## Overview

A Variable product (`ProductType::VARIABLE`) is a **parent** that groups several `VARIATION` children. It exists as a presentation container — "T-shirt available in S, M, L" — but is never itself added to a cart. Buyers always select a specific variation child.

## Key Characteristics

### 1. **Container only**
- The Variable product carries the shared catalogue data (name, description, images, categories)
- It does **not** carry stock and is not added to carts directly
- Each child variation has its own SKU, stock, and (optionally) price

### 2. **Children are linked via `parent_id`**
- `Product.parent_id` on a `VARIATION` row points at the Variable parent
- Eloquent relations `parent()` / `children()` use this column
- Children may also be linked via the `ProductRelationType::VARIATION` relation table for sort order / labels

### 3. **No prices of its own**
- A Variable product typically has zero `ProductPrice` rows
- Listing pages can derive a price range from the children (`min(children.price) – max(children.price)`)

## How It Works

```
Product(type=VARIABLE, name='T-Shirt')
├── Product(type=VARIATION, parent_id=<TShirt>, sku='TSHIRT-S', manage_stock=true)
├── Product(type=VARIATION, parent_id=<TShirt>, sku='TSHIRT-M', manage_stock=true)
└── Product(type=VARIATION, parent_id=<TShirt>, sku='TSHIRT-L', manage_stock=true)
```

The customer picks "Medium", and the `M` variation goes into the cart — not the parent.

## Pricing

The Variable parent has **no prices**. All `ProductPrice` rows belong to the individual `VARIATION` children. See the [Variation products doc](./05-variation-products.md) for variant-side pricing.

If your UI shows a "from €X" label on the parent, derive it from the children:

```php
$min = $tshirt->children()
    ->with('prices')
    ->get()
    ->flatMap(fn ($v) => $v->prices)
    ->where('is_default', true)
    ->min('unit_amount');
```

## Configuration

```php
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;

$tshirt = Product::create([
    'name' => 'Logo T-Shirt',
    'type' => ProductType::VARIABLE,
    'slug' => 'logo-t-shirt',
    'manage_stock' => false,         // parent never carries stock
]);

foreach (['S', 'M', 'L'] as $size) {
    $variant = Product::create([
        'name' => "Logo T-Shirt ({$size})",
        'type' => ProductType::VARIATION,
        'parent_id' => $tshirt->id,
        'sku' => "TSHIRT-{$size}",
        'manage_stock' => true,
    ]);
    $variant->increaseStock(20);

    ProductPrice::create([
        'purchasable_id' => $variant->id,
        'purchasable_type' => Product::class,
        'unit_amount' => 2499,
        'is_default' => true,
    ]);
}
```

## Cart Integration

```php
// ✅ Right — add the child variation
$cart->addToCart($variant, 1);

// ❌ Wrong — adding the parent has no price/stock and will misbehave
// $cart->addToCart($tshirt, 1);
```

## Common Use Cases

- **Apparel** with sizes / colours
- **Coffee** with grind options
- **Software licences** with seat counts (per-seat tier ladders on each variation)

## Best Practices

1. **Hide variable parents from "Add to cart" UI.** Render the variation selector instead.
2. **Keep the parent's data presentational.** Shared marketing copy, images, categories — yes. Inventory and price — no, those live on children.
3. **Set `manage_stock = false` on the parent**, even though it has no stock subsystem activity, to make queries explicit.
4. **Use `parent_id` for the hierarchy** and a `VARIATION` relation row only if you need extra metadata (sort order, displayed label).

## Troubleshooting

### Parent is being added to cart
Filter UI: only show "Add to cart" on children. The package has no built-in guard preventing the parent from being added — it'll create a cart item with no price.

### Children don't show in lists
By default `Product::query()` returns everything including children. Scope the listing to exclude variations or to children of a specific parent:

```php
Product::whereNull('parent_id')->where('type', '!=', ProductType::VARIATION->value);
```

## Related Documentation

- [Variation products](./05-variation-products.md) — the child entities
- [Simple products](./03-simple-products.md) — when you don't need variants
- [Product Relations](../05-product-relations.md)
