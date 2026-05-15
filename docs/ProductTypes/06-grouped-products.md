# Grouped Products

## Overview

A Grouped product (`ProductType::GROUPED`) is a **bundle / multi-pack of independent products** that are sold together. Unlike a Variable product (where the children are alternatives), a Grouped product's children are companions — buying the group adds each child to the order.

A "starter kit", a "his + hers set", or "buy this whole season at a discount" are all grouped products.

## Key Characteristics

### 1. **Container of independent SKUs**
- The Grouped product itself has no stock and (usually) no price
- Each child is a fully fledged product (most commonly a `SIMPLE`)
- Children remain individually purchasable

### 2. **Linked via `BUNDLE` relations**
- Children attach through the `product_relations` pivot with `type = 'bundle'`
- Bundles can carry per-relation metadata (quantity, sort order)

### 3. **Per-child pricing**
- The group's total is the sum of its children's effective prices (after sales)
- You can optionally set a `ProductPrice` on the Grouped product itself as a fixed discount price ("normally €X, the bundle is €Y")

## How It Works

```
Product(type=GROUPED, name='Espresso Starter Kit')
   │
   └── BUNDLE relations
           ├── Product(SIMPLE, name='Espresso Machine')
           ├── Product(SIMPLE, name='Grinder')
           └── Product(SIMPLE, name='Beans, 1kg')
```

When the buyer adds the kit to the cart, your checkout flow expands it into per-child cart items (each preserving its own stock and price), or — depending on UI — adds the group as one line item priced as a sum.

## Pricing

| Strategy | How |
|---|---|
| **Sum of children** | No price on the group. Total = `sum(child.unit_amount)`. UI shows the breakdown. |
| **Bundle discount** | Add a single `ProductPrice` to the group with the discounted total. The system shows both the sum-of-children and the bundle price. |
| **Sale on the bundle** | `sale_unit_amount` on the group's price for limited-time bundle promotions. |

Recurring or tiered prices on the bundle itself are unusual — children handle those.

```php
$kit = Product::create([
    'name' => 'Espresso Starter Kit',
    'type' => ProductType::GROUPED,
    'slug' => 'espresso-starter-kit',
]);

// Attach children with a sort order
$kit->productRelations()->attach([
    $machine->id => ['type' => ProductRelationType::BUNDLE->value, 'sort_order' => 0],
    $grinder->id => ['type' => ProductRelationType::BUNDLE->value, 'sort_order' => 1],
    $beans->id   => ['type' => ProductRelationType::BUNDLE->value, 'sort_order' => 2],
]);

// Optional: discounted bundle price
ProductPrice::create([
    'purchasable_id' => $kit->id,
    'purchasable_type' => Product::class,
    'unit_amount' => 39900, // €399 instead of €459 individually
    'is_default' => true,
]);
```

## Configuration

```php
$kit = Product::create([
    'name' => 'Espresso Starter Kit',
    'type' => ProductType::GROUPED,
    'manage_stock' => false,  // each child handles its own stock
]);

// Fetch the bundled items
$items = $kit->productRelations()
    ->wherePivot('type', ProductRelationType::BUNDLE->value)
    ->get();
```

## Cart Integration

Two reasonable UI patterns:

### Pattern A — expand on add (recommended for stock accuracy)

```php
foreach ($kit->bundleProducts as $child) {
    $cart->addToCart($child, 1);
}
```

Stock decrements per child, and an order shows each line item.

### Pattern B — single bundle line item

```php
$cart->addToCart($kit, 1);
```

Order shows one line. You're responsible for fulfilment logic that ships all children. Stock won't auto-decrement on the children — handle that in your own listener if you need it.

## Common Use Cases

- **Starter kits** (machine + accessories)
- **Seasonal bundles** ("Black Friday set")
- **His + hers / multi-pack** (gift sets)
- **Course + ebook combos** (digital)

## Best Practices

1. **Decide expansion strategy up front.** Pattern A (expand on add) is the safest for inventory; Pattern B is cleaner UX but needs custom stock logic.
2. **Don't manage stock on the group itself.** Children own inventory.
3. **Use `sort_order` on `BUNDLE` relations** to control display order — important for kits where one item is the "headline".
4. **Use a bundle ProductPrice only as a discount.** Otherwise pricing is implicit from the children.

## Troubleshooting

### Bundle total looks wrong
Sum the children explicitly when rendering. The group's own price (if set) is the discount price — it doesn't sum.

### Children not shipping with the order
If you used Pattern B (single line item), wire a `ProductPurchase::created` listener to dispatch a "fulfil bundle children" job, or expand at checkout time.

## Related Documentation

- [Variable products](./04-variable-products.md) — alternatives, not companions
- [Product Relations](../05-product-relations.md) — `BUNDLE` is just one relation type
- [Simple products](./03-simple-products.md) — the typical child of a grouped product
