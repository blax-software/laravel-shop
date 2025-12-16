# Laravel Shop Documentation

## Table of Contents

### Product Types
- [Booking Products](./ProductTypes/01-booking-products.md) - Time-based reservations and rentals
- [Pool Products](./ProductTypes/02-pool-products.md) - Managing groups of booking items

### Core Features
- [Products Overview](./01-products.md) - Basic product management
- [Stripe Integration](./02-stripe.md) - Payment processing
- [Purchasing](./03-purchasing.md) - Order and purchase flow
- [Stripe Checkout](./04-stripe-checkout.md) - Checkout integration
- [Product Relations](./05-product-relations.md) - How products relate to each other

## Quick Start

### Understanding Booking Products

Booking products are time-based items that can be reserved for specific date ranges. Perfect for:
- Hotel rooms
- Rental equipment
- Parking spaces
- Event tickets
- Service appointments

[Learn more →](./ProductTypes/01-booking-products.md)

### Understanding Pool Products

Pool products manage groups of individual items as a unified offering. Customers book from a "pool" and the system automatically assigns an available item. Ideal for:
- Hotel room categories ("Standard Rooms")
- Equipment fleets ("Rental Cars - Compact")
- Parking facilities ("Parking Spaces")
- General admission seating

[Learn more →](./ProductTypes/02-pool-products.md)

### Understanding Product Relations

The relation system enables complex product associations for marketing and structural purposes:
- **Marketing**: Upsells, cross-sells, related products
- **Structural**: Variations, bundles, pool/single items

[Learn more →](./05-product-relations.md)

## Key Concepts

### Stock Management
- **Booking Products**: Track time-based availability with claims
- **Pool Products**: Aggregate availability from single items
- **Claims**: Temporary stock reservations (cart, bookings)

### Pricing Strategies
Pool products support flexible pricing:
- **LOWEST** (default): Cheapest available item
- **HIGHEST**: Most expensive available item  
- **AVERAGE**: Average of available items

Prices are calculated from **available** items only, ensuring customers see accurate pricing.

### Product Relations
Nine relation types for different purposes:
- RELATED, UPSELL, CROSS_SELL, DOWNSELL, ADD_ON (marketing)
- VARIATION, BUNDLE (structural)
- SINGLE, POOL (special bidirectional for pool management)

## Common Workflows

### Creating a Booking Product

```php
$room = Product::create([
    'name' => 'Deluxe Suite',
    'type' => ProductType::BOOKING,
    'manage_stock' => true,
]);

$room->increaseStock(5); // 5 rooms available

ProductPrice::create([
    'purchasable_id' => $room->id,
    'purchasable_type' => Product::class,
    'unit_amount' => 20000, // $200/day
    'is_default' => true,
]);
```

### Creating a Pool Product

```php
// 1. Create pool
$pool = Product::create([
    'name' => 'Parking Spaces',
    'type' => ProductType::POOL,
    'manage_stock' => false,
]);

// 2. Create single items
$spot1 = Product::create([
    'type' => ProductType::BOOKING,
    'manage_stock' => true,
]);
$spot1->increaseStock(1);

// 3. Link them
$pool->attachSingleItems([$spot1->id, $spot2->id, $spot3->id]);

// 4. Set pricing strategy
$pool->setPricingStrategy(PricingStrategy::LOWEST);
```

### Booking a Product

```php
$from = Carbon::parse('2025-01-15');
$until = Carbon::parse('2025-01-20');

// Check availability
if ($product->isAvailableForBooking($from, $until, $quantity = 1)) {
    // Add to cart (claims stock automatically)
    $cart->addToCart($product, 1, [], $from, $until);
}
```

### Setting Up Product Relations

```php
// Cross-sells
$laptop->productRelations()->attach([
    $mouse->id => ['type' => ProductRelationType::CROSS_SELL->value],
    $bag->id => ['type' => ProductRelationType::CROSS_SELL->value],
]);

// Upsells
$basicPlan->productRelations()->attach($premiumPlan->id, [
    'type' => ProductRelationType::UPSELL->value
]);

// Retrieve
$crossSells = $laptop->crossSellProducts;
$upsell = $basicPlan->upsellProducts->first();
```

## Architecture Overview

### Product Types

```
ProductType Enum:
├── SIMPLE      → Standard products
├── VARIABLE    → Products with variations
├── GROUPED     → Product groups
├── EXTERNAL    → External/affiliate products
├── BOOKING     → Time-based reservations ⭐
├── VARIATION   → Variant of a variable product
└── POOL        → Container for booking items ⭐
```

### Relation Types

```
ProductRelationType Enum:
├── RELATED     → Similar products
├── UPSELL      → Premium alternatives
├── CROSS_SELL  → Complementary products
├── DOWNSELL    → Lower-priced alternatives
├── ADD_ON      → Optional extras
├── VARIATION   → Product variants
├── BUNDLE      → Package components
├── SINGLE      → Pool → Single items ⭐
└── POOL        → Single item → Pool ⭐
```

### Stock System

```
Stock Flow:
├── INCREASE    → Add inventory (COMPLETED)
├── DECREASE    → Remove inventory (COMPLETED)
├── CLAIMED     → Reserve inventory (PENDING) ⭐
│   ├── Creates DECREASE entry (reduces available)
│   └── Creates CLAIMED entry (tracks reservation)
└── RETURN      → Return to inventory (COMPLETED)

Available Stock = Sum(COMPLETED entries) - Sum(active CLAIMS)
```

## Best Practices

### Booking Products
✅ Always set `manage_stock = true`  
✅ Check availability before booking  
✅ Validate configuration with `validateBookingConfiguration()`  
✅ Handle date ranges properly (from < until)

### Pool Products
✅ Set pool `manage_stock = false`  
✅ Set single items `manage_stock = true`  
✅ Use `attachSingleItems()` for bidirectional relations  
✅ Validate with `validatePoolConfiguration()`  
✅ Set explicit pricing strategy

### Relations
✅ Use appropriate relation types semantically  
✅ Use helper methods (`upsellProducts` not manual queries)  
✅ Eager load to avoid N+1 queries  
✅ Add `sort_order` for display ordering

## Troubleshooting

### "Not enough stock available"
- Check if stock was added: `$product->increaseStock(5)`
- Check if stock is claimed by other bookings
- Verify `manage_stock = true`

### "Pool product has no single items"
- Use `attachSingleItems()` to link items
- Verify single items exist and have stock

### Pool/Single relations not bidirectional
- Must use `attachSingleItems()` not regular `attach()`
- This creates both SINGLE and POOL relations automatically

### Pricing shows wrong value
- Check pricing strategy: `$pool->getPricingStrategy()`
- Verify which items are available: `getSingleItemsAvailability()`
- Remember: only available items are priced

## Development Tips

### Testing Bookings

```php
// Create test booking product
$product = Product::factory()->create([
    'type' => ProductType::BOOKING,
    'manage_stock' => true,
]);
$product->increaseStock(10);

// Test availability
$this->assertTrue($product->isAvailableForBooking($from, $until, 5));

// Test booking
$cart->addToCart($product, 5, [], $from, $until);
$this->assertEquals(5, $product->getAvailableStock());
```

### Testing Pools

```php
// Create pool with items
$pool = Product::factory()->create(['type' => ProductType::POOL]);
$items = Product::factory()->count(3)->create([
    'type' => ProductType::BOOKING,
    'manage_stock' => true,
]);
$items->each->increaseStock(1);

$pool->attachSingleItems($items->pluck('id')->toArray());

// Test availability
$this->assertEquals(3, $pool->getPoolMaxQuantity($from, $until));
```

### Debugging Relations

```php
// Check relation exists
dd($product->productRelations()
    ->where('related_product_id', $otherId)
    ->where('type', ProductRelationType::RELATED->value)
    ->exists());

// Check bidirectional
dd($pool->singleProducts()->pluck('id'));
dd($singleItem->poolProducts()->pluck('id'));
```

## Performance

### Eager Loading

```php
// Load all relations
Product::with([
    'singleProducts',
    'poolProducts',
    'crossSellProducts',
    'upsellProducts',
    'prices'
])->get();

// Load nested
Product::with('singleProducts.prices')->get();
```

### Caching

```php
// Cache availability calendars
Cache::remember("pool:{$id}:availability", 3600, function() {
    return $pool->getPoolAvailabilityCalendar($from, $until);
});

// Cache pricing
Cache::remember("pool:{$id}:price", 3600, function() {
    return $pool->getCurrentPrice();
});
```

## API Examples

### Get Booking Availability

```php
GET /api/products/{id}/availability?from=2025-01-15&until=2025-01-20

Response:
{
    "available": true,
    "max_quantity": 5,
    "calendar": {
        "2025-01-15": 5,
        "2025-01-16": 3,
        ...
    }
}
```

### Get Pool Information

```php
GET /api/products/{id}/pool-details

Response:
{
    "pool": {
        "id": 1,
        "name": "Parking Spaces",
        "pricing_strategy": "lowest"
    },
    "single_items": [
        {"id": 10, "name": "Spot 1", "available": 1},
        {"id": 11, "name": "Spot 2", "available": 0},
        {"id": 12, "name": "Spot 3", "available": 1}
    ],
    "price_range": {
        "min": 2000,
        "max": 3000
    }
}
```

## Contributing

When adding new features:
1. Update relevant documentation
2. Add test cases for booking/pool scenarios
3. Consider stock management implications
4. Validate relation logic

## Support

For issues or questions:
- Check troubleshooting sections
- Review test cases for examples
- See related documentation for context
