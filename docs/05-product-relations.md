# Product Relations

## Overview

The Product Relations system enables complex relationships between products through a flexible, type-based association model. Products can be related to each other in various ways for different business purposes like upselling, bundling, cross-selling, and structural groupings.

## Architecture

### Database Structure

Product relations are stored in a many-to-many pivot table with type differentiation:

```
products
├── id
├── name
├── type
└── ...

product_relations (pivot table)
├── product_id        → The source product
├── related_product_id → The target product
├── type              → ProductRelationType enum
├── sort_order        → Optional ordering
└── timestamps
```

### Key Concepts

1. **Directional Relations**: Relations go from `product_id` to `related_product_id`
2. **Typed Relations**: Each relation has a specific type (e.g., UPSELL, RELATED)
3. **Flexible**: Same product can have multiple relation types to different products
4. **Bidirectional Support**: Some relations (POOL/SINGLE) create reverse relations automatically

## Relation Types

### Marketing Relations

These relations help with product discovery and sales optimization:

#### 1. RELATED (`ProductRelationType::RELATED`)

**Purpose**: Products that are commonly viewed or purchased together

**Use Case**: "Customers also viewed" or "Similar products"

**Example**:
```php
// Canon Camera → Canon Lenses (related products)
$camera->productRelations()->attach($lens->id, [
    'type' => ProductRelationType::RELATED->value
]);

// Access
$relatedProducts = $camera->relatedProducts;
```

**Direction**: Can be one-way or bidirectional
**Visibility**: Typically shown on product detail pages

---

#### 2. UPSELL (`ProductRelationType::UPSELL`)

**Purpose**: Higher-tier or premium alternatives to encourage upgrades

**Use Case**: Suggesting a better/more expensive product

**Example**:
```php
// Basic Plan → Premium Plan (upsell)
$basicPlan->productRelations()->attach($premiumPlan->id, [
    'type' => ProductRelationType::UPSELL->value
]);

// Access
$upsells = $basicPlan->upsellProducts;
```

**Direction**: One-way (Basic → Premium, not reverse)
**Visibility**: Product pages, cart pages, checkout

---

#### 3. CROSS_SELL (`ProductRelationType::CROSS_SELL`)

**Purpose**: Complementary products often purchased together

**Use Case**: "Frequently bought together" or "Complete your purchase"

**Example**:
```php
// Laptop → Mouse, Laptop Bag, USB Hub (cross-sells)
$laptop->productRelations()->attach([$mouse->id, $bag->id, $hub->id], [
    'type' => ProductRelationType::CROSS_SELL->value
]);

// Access
$crossSells = $laptop->crossSellProducts;
```

**Direction**: One-way (main product → accessories)
**Visibility**: Cart page, checkout, product page

---

#### 4. DOWNSELL (`ProductRelationType::DOWNSELL`)

**Purpose**: Lower-priced alternatives if customer balks at price

**Use Case**: "Too expensive? Try this instead"

**Example**:
```php
// Premium Plan → Basic Plan (downsell)
$premiumPlan->productRelations()->attach($basicPlan->id, [
    'type' => ProductRelationType::DOWNSELL->value
]);

// Access
$downsells = $premiumPlan->downsellProducts;
```

**Direction**: One-way (Premium → Basic, not reverse)
**Visibility**: Shown when customer hesitates or abandons cart

---

#### 5. ADD_ON (`ProductRelationType::ADD_ON`)

**Purpose**: Optional extras that enhance the main product

**Use Case**: Extended warranties, gift wrapping, rush delivery

**Example**:
```php
// Product → Extended Warranty (add-on)
$product->productRelations()->attach($warranty->id, [
    'type' => ProductRelationType::ADD_ON->value
]);

// Access
$addOns = $product->addOnProducts;
```

**Direction**: One-way (main product → add-on)
**Visibility**: Product page, during add-to-cart flow

---

### Structural Relations

These relations define product hierarchy and composition:

#### 6. VARIATION (`ProductRelationType::VARIATION`)

**Purpose**: Different versions/variants of the same base product

**Use Case**: Size, color, or configuration variations

**Example**:
```php
// T-Shirt (Variable) → T-Shirt Small, T-Shirt Medium (variations)
$tshirt->productRelations()->attach([$small->id, $medium->id, $large->id], [
    'type' => ProductRelationType::VARIATION->value
]);

// Access
$variations = $tshirt->variantProducts;
```

**Direction**: One-way (parent → variations)
**Visibility**: Product page variant selector

---

#### 7. BUNDLE (`ProductRelationType::BUNDLE`)

**Purpose**: Group of products sold together as a package

**Use Case**: "Starter Kit" or "Complete Package" offerings

**Example**:
```php
// Starter Bundle → Individual Products
$starterKit->productRelations()->attach([
    $product1->id,
    $product2->id,
    $product3->id
], [
    'type' => ProductRelationType::BUNDLE->value
]);

// Access
$bundleProducts = $starterKit->bundleProducts;
```

**Direction**: One-way (bundle → components)
**Visibility**: Bundle product page showing contents

---

### Pool Relations (Special)

These are bidirectional relations for pool/single item management:

#### 8. SINGLE (`ProductRelationType::SINGLE`)

**Purpose**: Link pool product to its individual component items

**Use Case**: Pool product pointing to actual bookable items

**Example**:
```php
// Parking Pool → Individual Spots (single items)
$pool->productRelations()->attach($spot1->id, [
    'type' => ProductRelationType::SINGLE->value
]);

// Access
$singleItems = $pool->singleProducts;
```

**Direction**: Pool → Single Items
**Auto-creates**: Reverse POOL relation

---

#### 9. POOL (`ProductRelationType::POOL`)

**Purpose**: Link individual items back to their pool container

**Use Case**: Single item referencing its parent pool

**Example**:
```php
// This is automatically created when using attachSingleItems()
// Individual Spot → Parking Pool (pool reference)

// Access
$pools = $spot1->poolProducts;
```

**Direction**: Single Item → Pool
**Auto-created**: By `attachSingleItems()` method

---

## Usage Examples

### Basic Relations

```php
// Create a product with related products
$camera = Product::find(1);

// Add related products (one at a time)
$camera->productRelations()->attach($lens->id, [
    'type' => ProductRelationType::RELATED->value,
    'sort_order' => 1,
]);

// Add multiple related products
$camera->productRelations()->attach([
    $lens->id => ['type' => ProductRelationType::RELATED->value],
    $tripod->id => ['type' => ProductRelationType::RELATED->value],
    $bag->id => ['type' => ProductRelationType::RELATED->value],
]);

// Retrieve related products
$relatedProducts = $camera->relatedProducts;
```

### Cross-Selling

```php
// Set up cross-sells for laptop
$laptop->productRelations()->attach([
    $mouse->id => ['type' => ProductRelationType::CROSS_SELL->value, 'sort_order' => 1],
    $bag->id => ['type' => ProductRelationType::CROSS_SELL->value, 'sort_order' => 2],
    $warranty->id => ['type' => ProductRelationType::CROSS_SELL->value, 'sort_order' => 3],
]);

// In cart or checkout
$crossSells = $laptop->crossSellProducts()->orderBy('sort_order')->get();
```

### Upselling Flow

```php
// Basic → Premium upsell path
$basicPlan->productRelations()->attach($premiumPlan->id, [
    'type' => ProductRelationType::UPSELL->value
]);

// Premium → Enterprise upsell path
$premiumPlan->productRelations()->attach($enterprisePlan->id, [
    'type' => ProductRelationType::UPSELL->value
]);

// Show upsells
if ($cart->contains($basicPlan)) {
    $suggestedUpgrade = $basicPlan->upsellProducts->first();
}
```

### Product Variations

```php
// Variable product with variations
$tshirt = Product::create([
    'name' => 'T-Shirt',
    'type' => ProductType::VARIABLE,
]);

// Create variations
$small = Product::create(['name' => 'T-Shirt Small', 'type' => ProductType::VARIATION]);
$medium = Product::create(['name' => 'T-Shirt Medium', 'type' => ProductType::VARIATION]);
$large = Product::create(['name' => 'T-Shirt Large', 'type' => ProductType::VARIATION]);

// Link variations
$tshirt->productRelations()->attach([
    $small->id => ['type' => ProductRelationType::VARIATION->value],
    $medium->id => ['type' => ProductRelationType::VARIATION->value],
    $large->id => ['type' => ProductRelationType::VARIATION->value],
]);

// Display on product page
$variations = $tshirt->variantProducts;
```

### Pool/Single Relations (Special Case)

Pool relations are unique because they're bidirectional:

```php
// ✅ CORRECT WAY - Use attachSingleItems()
$pool = Product::create(['type' => ProductType::POOL]);
$spot1 = Product::create(['type' => ProductType::BOOKING]);
$spot2 = Product::create(['type' => ProductType::BOOKING]);

// This creates BOTH directions automatically:
$pool->attachSingleItems([$spot1->id, $spot2->id]);

// Now both directions work:
$pool->singleProducts;  // Returns: [spot1, spot2]
$spot1->poolProducts;   // Returns: [pool]

// ❌ WRONG WAY - Don't use attach() directly
$pool->productRelations()->attach($spot1->id, [
    'type' => ProductRelationType::SINGLE->value
]);
// This only creates one direction! Missing reverse POOL relation.
```

**What `attachSingleItems()` does:**

```php
public function attachSingleItems(array $singleItemIds): void
{
    // 1. Attach SINGLE relations from pool to items
    $this->productRelations()->attach(
        array_fill_keys($singleItemIds, ['type' => ProductRelationType::SINGLE->value])
    );

    // 2. Attach reverse POOL relations from items to pool
    foreach ($singleItemIds as $singleItemId) {
        $singleItem = static::find($singleItemId);
        $singleItem->productRelations()->attach($this->id, [
            'type' => ProductRelationType::POOL->value
        ]);
    }
}
```

## Advanced Usage

### Filtering by Relation Type

```php
// Get all relations of a specific type
$upsells = $product->relationsByType(ProductRelationType::UPSELL);

// Or use dedicated method
$upsells = $product->upsellProducts;

// Get multiple types
$suggestions = $product->productRelations()
    ->whereIn('type', [
        ProductRelationType::UPSELL->value,
        ProductRelationType::CROSS_SELL->value
    ])
    ->get();
```

### Custom Queries

```php
// Relations with additional constraints
$premiumUpsells = $product->upsellProducts()
    ->where('products.price', '>', 10000)
    ->orderBy('products.price', 'asc')
    ->get();

// Limited cross-sells
$topCrossSells = $product->crossSellProducts()
    ->orderByPivot('sort_order')
    ->limit(3)
    ->get();
```

### Checking Relations

```php
// Check if product has any upsells
if ($product->upsellProducts()->exists()) {
    // Show upsell section
}

// Count related products
$relatedCount = $product->relatedProducts()->count();

// Check specific relation
$hasRelation = $product->productRelations()
    ->where('related_product_id', $otherProduct->id)
    ->where('type', ProductRelationType::RELATED->value)
    ->exists();
```

### Managing Relations

```php
// Add relation
$product->productRelations()->attach($relatedId, [
    'type' => ProductRelationType::RELATED->value,
    'sort_order' => 1,
]);

// Update relation
$product->productRelations()->updateExistingPivot($relatedId, [
    'sort_order' => 2
]);

// Remove relation
$product->productRelations()->detach($relatedId);

// Remove all relations of a type
$product->relatedProducts()->detach();

// Sync relations (replace all)
$product->productRelations()->sync([
    $id1 => ['type' => ProductRelationType::RELATED->value],
    $id2 => ['type' => ProductRelationType::RELATED->value],
]);
```

## Relation Type Reference

| Type       | Enum                              | Direction   | Auto-Reverse | Typical Use                     |
|------------|-----------------------------------|-------------|--------------|---------------------------------|
| RELATED    | `ProductRelationType::RELATED`    | One-way     | No           | Similar products, "also viewed" |
| UPSELL     | `ProductRelationType::UPSELL`     | One-way     | No           | Premium alternatives            |
| CROSS_SELL | `ProductRelationType::CROSS_SELL` | One-way     | No           | Complementary products          |
| DOWNSELL   | `ProductRelationType::DOWNSELL`   | One-way     | No           | Lower-priced alternatives       |
| ADD_ON     | `ProductRelationType::ADD_ON`     | One-way     | No           | Optional extras                 |
| VARIATION  | `ProductRelationType::VARIATION`  | One-way     | No           | Product variants                |
| BUNDLE     | `ProductRelationType::BUNDLE`     | One-way     | No           | Package components              |
| SINGLE     | `ProductRelationType::SINGLE`     | Pool → Item | Yes (POOL)   | Pool single items               |
| POOL       | `ProductRelationType::POOL`       | Item → Pool | Yes (SINGLE) | Item's pool reference           |

## Best Practices

### 1. Use Appropriate Relation Types

```php
// ✅ CORRECT - Semantic meaning
$product->productRelations()->attach($accessory->id, [
    'type' => ProductRelationType::CROSS_SELL->value  // Complementary
]);

// ❌ INCORRECT - Wrong type
$product->productRelations()->attach($accessory->id, [
    'type' => ProductRelationType::UPSELL->value  // Accessory isn't an upgrade
]);
```

### 2. Use Helper Methods

```php
// ✅ CORRECT - Dedicated method
$upsells = $product->upsellProducts;

// ❌ VERBOSE - Manual filtering
$upsells = $product->productRelations()
    ->wherePivot('type', ProductRelationType::UPSELL->value)
    ->get();
```

### 3. Sort Order for Display

```php
// ✅ CORRECT - Use sort_order
$product->productRelations()->attach($items, [
    $item1->id => ['type' => ProductRelationType::CROSS_SELL->value, 'sort_order' => 1],
    $item2->id => ['type' => ProductRelationType::CROSS_SELL->value, 'sort_order' => 2],
]);

$crossSells = $product->crossSellProducts()->orderByPivot('sort_order')->get();
```

### 4. Always Use attachSingleItems() for Pools

```php
// ✅ CORRECT - Bidirectional
$pool->attachSingleItems([$item1->id, $item2->id]);

// ❌ INCORRECT - One-way only
$pool->productRelations()->attach($item1->id, [
    'type' => ProductRelationType::SINGLE->value
]);
```

### 5. Eager Load Relations

```php
// ✅ CORRECT - Avoid N+1
$products = Product::with('crossSellProducts')->get();

foreach ($products as $product) {
    $product->crossSellProducts;  // Already loaded
}

// ❌ INCORRECT - N+1 queries
$products = Product::all();

foreach ($products as $product) {
    $product->crossSellProducts;  // Query per product
}
```

### 6. Validate Relation Logic

```php
// ✅ CORRECT - Check business logic
if ($premiumPlan->price > $basicPlan->price) {
    $basicPlan->productRelations()->attach($premiumPlan->id, [
        'type' => ProductRelationType::UPSELL->value
    ]);
}

// ❌ INCORRECT - No validation
$basicPlan->productRelations()->attach($cheaperPlan->id, [
    'type' => ProductRelationType::UPSELL->value  // Upsell to cheaper product??
]);
```

## Common Patterns

### Product Page Relations Display

```php
// Show all related products
$relatedProducts = $product->relatedProducts()->limit(4)->get();

// Show upsell if available
$upsell = $product->upsellProducts()->first();

// Show add-ons
$addOns = $product->addOnProducts;
```

### Cart Cross-Sells

```php
$cart = $user->currentCart();
$allCrossSells = collect();

foreach ($cart->items as $item) {
    $crossSells = $item->purchasable->crossSellProducts;
    $allCrossSells = $allCrossSells->merge($crossSells);
}

// Remove duplicates and products already in cart
$uniqueCrossSells = $allCrossSells
    ->unique('id')
    ->reject(fn($p) => $cart->items->contains('purchasable_id', $p->id));
```

### Upsell at Checkout

```php
$cartTotal = $cart->getTotal();

// Find upsells for products in cart
$upsellOpportunities = $cart->items
    ->map(fn($item) => $item->purchasable->upsellProducts)
    ->flatten()
    ->unique('id');

// Filter to affordable upsells
$affordableUpsells = $upsellOpportunities->filter(
    fn($upsell) => $upsell->price <= $cartTotal * 1.2  // Max 20% more
);
```

### Bundle Product Display

```php
// Show bundle contents
$bundle = Product::find($id);
$components = $bundle->bundleProducts;

$totalValue = $components->sum('price');
$bundlePrice = $bundle->price;
$savings = $totalValue - $bundlePrice;

// "Save $50 when you buy the bundle!"
```

## Troubleshooting

### Relations Not Showing

**Check:**
1. Relation type is correct: `ProductRelationType::RELATED->value`
2. Using correct method: `relatedProducts` not `productRelations`
3. Pivot data exists: Check `product_relations` table

### Pool/Single Relations Not Bidirectional

**Solution:**
```php
// Use dedicated method
$pool->attachSingleItems($itemIds);

// NOT regular attach()
```

### Duplicate Relations

**Prevent:**
```php
// Check before adding
if (!$product->relatedProducts()->where('id', $relatedId)->exists()) {
    $product->productRelations()->attach($relatedId, [
        'type' => ProductRelationType::RELATED->value
    ]);
}
```

### N+1 Query Issues

**Solution:**
```php
// Eager load
$products = Product::with([
    'crossSellProducts',
    'upsellProducts',
    'relatedProducts'
])->get();
```

## Related Documentation

- [Pool Products](./ProductTypes/02-pool-products.md) - POOL/SINGLE relations in detail
- [Product Types](./ProductTypes/) - Understanding different product types
