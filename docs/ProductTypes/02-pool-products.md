# Pool Products

## Overview

Pool products (`ProductType::POOL`) are special container products that manage a group of individual items (typically booking products) as a unified product offering. Instead of customers selecting a specific item, they book from a "pool" and the system automatically assigns an available item.

## Concept

Think of a pool product as a "hotel" rather than a specific "room":
- **Pool Product** = "Parking Spaces" (what customers see)
- **Single Items** = Individual parking spots 1, 2, 3 (managed behind the scenes)

Customers book "a parking space" without specifying which one. The system automatically assigns the first available spot.

## Key Characteristics

### 1. **Does Not Manage Its Own Stock**
- Always set `manage_stock = false` on pool products
- Stock comes from the sum of available single items
- Pool availability = total available single items

### 2. **Contains Single Items**
- Single items are linked via product relations (`ProductRelationType::SINGLE`)
- Single items are typically `ProductType::BOOKING` with `manage_stock = true`
- Each single item has its own stock (usually 1)

### 3. **Bidirectional Relations**
- Pool → Single Items (via `SINGLE` relation type)
- Single Items → Pool (via `POOL` relation type, reverse reference)

### 4. **Flexible Pricing**
- Can have its own direct price
- OR inherit price from single items using a pricing strategy
- Three strategies: LOWEST, HIGHEST, AVERAGE (default: LOWEST)

### 5. **Automatic Assignment**
- When booked, automatically claims the first available single item
- Customers don't choose which specific item
- System handles all assignment logic

## Architecture

### Relation Structure

```
Pool Product (type: POOL, manage_stock: false)
  ├── SINGLE relation → Single Item 1 (type: BOOKING, stock: 1)
  ├── SINGLE relation → Single Item 2 (type: BOOKING, stock: 1)
  └── SINGLE relation → Single Item 3 (type: BOOKING, stock: 1)

Single Item 1
  └── POOL relation → Pool Product (reverse reference)
```

### Stock Flow

```
Customer books → Pool Product
                 ↓
         Checks availability of single items
                 ↓
         Claims first available single item
                 ↓
         Single item stock is reduced
```

## Creating Pool Products

### Basic Setup

```php
// 1. Create the pool product
$parkingPool = Product::create([
    'name' => 'Parking Spaces',
    'type' => ProductType::POOL,
    'manage_stock' => false,  // IMPORTANT: Pool doesn't manage stock
]);

// 2. Create individual single items (booking products)
$spot1 = Product::create([
    'name' => 'Parking Spot 1',
    'type' => ProductType::BOOKING,
    'manage_stock' => true,  // Single items DO manage stock
]);
$spot1->increaseStock(1);  // Each spot has 1 unit

$spot2 = Product::create([
    'name' => 'Parking Spot 2', 
    'type' => ProductType::BOOKING,
    'manage_stock' => true,
]);
$spot2->increaseStock(1);

$spot3 = Product::create([
    'name' => 'Parking Spot 3',
    'type' => ProductType::BOOKING,
    'manage_stock' => true,
]);
$spot3->increaseStock(1);

// 3. Set prices on single items
ProductPrice::create([
    'purchasable_id' => $spot1->id,
    'purchasable_type' => Product::class,
    'unit_amount' => 2000,  // $20/day
    'currency' => 'USD',
    'is_default' => true,
]);

// Similar for spot2 and spot3...

// 4. Link single items to the pool
$parkingPool->attachSingleItems([$spot1->id, $spot2->id, $spot3->id]);

// This creates bidirectional relations automatically:
// - Pool → Single Items (SINGLE type)
// - Single Items → Pool (POOL type)
```

## Pricing Strategies

Pool products support flexible pricing through the `HasPricingStrategy` trait.

### 1. Direct Pool Pricing

Set a price directly on the pool product:

```php
ProductPrice::create([
    'purchasable_id' => $parkingPool->id,
    'purchasable_type' => Product::class,
    'unit_amount' => 2500,  // Fixed $25/day for any parking spot
    'currency' => 'USD',
    'is_default' => true,
]);

// Pool uses its own price, ignoring single item prices
$price = $parkingPool->getCurrentPrice();  // 2500
```

### 2. Inherited Pricing (Default)

If no direct price is set, pool inherits from **available** single items:

```php
use Blax\Shop\Enums\PricingStrategy;

// Single items have different prices:
// - Spot 1: $20/day
// - Spot 2: $30/day  
// - Spot 3: $25/day

// LOWEST strategy (default)
$parkingPool->setPricingStrategy(PricingStrategy::LOWEST);
$price = $parkingPool->getCurrentPrice();  // 2000 ($20 - lowest)

// HIGHEST strategy
$parkingPool->setPricingStrategy(PricingStrategy::HIGHEST);
$price = $parkingPool->getCurrentPrice();  // 3000 ($30 - highest)

// AVERAGE strategy
$parkingPool->setPricingStrategy(PricingStrategy::AVERAGE);
$price = $parkingPool->getCurrentPrice();  // 2500 ($25 - average)
```

### 3. Available-Based Pricing (Dynamic)

**Critical Feature:** Pricing only considers **available** single items, not all items.

```php
// Scenario: 3 parking spots
// - Spot 1: $20/day (AVAILABLE)
// - Spot 2: $30/day (CLAIMED for Jan 1-5)
// - Spot 3: $25/day (AVAILABLE)

$from = Carbon::parse('2025-01-03');
$until = Carbon::parse('2025-01-04');

// With LOWEST strategy:
// Available spots: Spot 1 ($20), Spot 3 ($25)
// Price: $20 (lowest of available spots, not $30 from claimed spot)
$price = $parkingPool->getCurrentPrice();  // 2000
```

This ensures customers always see the price of what they'll actually get!

## Availability Checking

### Get Pool Availability

```php
$from = Carbon::parse('2025-01-15');
$until = Carbon::parse('2025-01-20');

// Check if pool has N units available
$available = $parkingPool->isPoolAvailable($from, $until, $quantity = 2);

// Get maximum available quantity
$maxQuantity = $parkingPool->getPoolMaxQuantity($from, $until);  // 3

// Get detailed availability per single item
$items = $parkingPool->getSingleItemsAvailability($from, $until);
// Returns:
// [
//     ['id' => 1, 'name' => 'Spot 1', 'available' => 1],
//     ['id' => 2, 'name' => 'Spot 2', 'available' => 0],  // claimed
//     ['id' => 3, 'name' => 'Spot 3', 'available' => 1],
// ]
```

### Availability Calendar

```php
// Get availability for each day in a range
$calendar = $parkingPool->getPoolAvailabilityCalendar(
    '2025-01-01', 
    '2025-01-31'
);

// Returns:
// [
//     '2025-01-01' => 3,
//     '2025-01-02' => 2,  // 1 claimed
//     '2025-01-03' => 3,
//     ...
// ]
```

### Find Available Periods

```php
// Find periods with at least 2 spots available for 3+ consecutive days
$periods = $parkingPool->getPoolAvailablePeriods(
    startDate: '2025-01-01',
    endDate: '2025-01-31',
    quantity: 2,
    minConsecutiveDays: 3
);

// Returns:
// [
//     [
//         'from' => '2025-01-01',
//         'until' => '2025-01-10',
//         'min_available' => 2,
//     ],
//     [
//         'from' => '2025-01-15',
//         'until' => '2025-01-20',
//         'min_available' => 3,
//     ],
// ]
```

## Stock Claiming Process

### Automatic Assignment

When a pool product is added to cart:

```php
$from = Carbon::parse('2025-01-15');
$until = Carbon::parse('2025-01-20');

// Customer adds pool to cart
$cartItem = $cart->addToCart($parkingPool, $quantity = 2, [], $from, $until);
```

**Behind the scenes:**

1. **Check Availability**
   - System checks if 2 spots are available during Jan 15-20
   - Uses `getPoolMaxQuantity($from, $until)`

2. **Claim Stock from Single Items**
   - Calls `claimPoolStock(2, $cartItem, $from, $until)`
   - Finds first 2 available single items
   - Claims 1 unit from each: `$spot->claimStock(1, $cartItem, $from, $until)`

3. **Store Claimed Items**
   - Cart item metadata stores which single items were claimed
   - Metadata: `claimed_single_items: [spot1_id, spot2_id]`

4. **Calculate Price**
   - Gets price from available single items (using pricing strategy)
   - Multiplies by number of days
   - Stores in cart item

### Manual Stock Operations

```php
// Manually claim pool stock
$claimedItems = $parkingPool->claimPoolStock(
    quantity: 2,
    reference: $order,
    from: Carbon::parse('2025-01-15'),
    until: Carbon::parse('2025-01-20'),
    note: 'VIP booking'
);
// Returns: [Spot1, Spot3] (array of claimed Product instances)

// Release pool stock
$released = $parkingPool->releasePoolStock($order);
// Returns: 2 (number of claims released)
```

## Validation

### Configuration Validation

```php
use Blax\Shop\Exceptions\InvalidPoolConfigurationException;

try {
    $result = $parkingPool->validatePoolConfiguration();
    // Returns:
    // [
    //     'valid' => true,
    //     'errors' => [],
    //     'warnings' => ['Some items have zero stock'],
    // ]
} catch (InvalidPoolConfigurationException $e) {
    // Critical error
}
```

**Common Validation Errors:**

1. **No Single Items**
   ```php
   throw InvalidPoolConfigurationException::noSingleItems($poolName);
   ```

2. **Mixed Product Types**
   ```php
   throw InvalidPoolConfigurationException::mixedSingleItemTypes($poolName);
   ```

3. **Single Items Without Stock Management**
   ```php
   throw InvalidPoolConfigurationException::singleItemsWithoutStock($poolName, $itemNames);
   ```

4. **Single Items With Zero Stock** (warning)
   ```php
   throw InvalidPoolConfigurationException::singleItemsWithZeroStock($poolName, $itemNames);
   ```

### Validation on Creation

```php
// Always validate after setup
$parkingPool->attachSingleItems([$spot1->id, $spot2->id]);

if (!$parkingPool->validatePoolConfiguration()['valid']) {
    // Handle errors
}
```

## Cart Integration

### Adding Pool to Cart

```php
$from = Carbon::parse('2025-01-15');
$until = Carbon::parse('2025-01-17');  // 2 days

$cartItem = $cart->addToCart($parkingPool, $quantity = 1, [], $from, $until);

// Cart item properties:
// - purchasable: Pool Product
// - quantity: 1
// - from: 2025-01-15
// - until: 2025-01-17
// - price: (unit_amount × 2 days)
// - meta->claimed_single_items: [spot_id]
```

### Viewing Claimed Items

```php
$meta = $cartItem->getMeta();
$claimedItemIds = $meta->claimed_single_items ?? [];

// Load the actual products
$claimedItems = Product::whereIn('id', $claimedItemIds)->get();
```

### Removing from Cart

```php
$cartItem->delete();
```

**What happens:**
1. System finds claimed single items from metadata
2. Releases claims on each single item
3. Stock becomes available again

## Advanced Usage

### Mixed Pricing

```php
// Different single items can have different prices
$spot1->defaultPrice()->update(['unit_amount' => 2000]);  // $20/day
$spot2->defaultPrice()->update(['unit_amount' => 3000]);  // $30/day (premium)
$spot3->defaultPrice()->update(['unit_amount' => 2500]);  // $25/day

// With LOWEST strategy (default)
$parkingPool->setPricingStrategy(PricingStrategy::LOWEST);
$price = $parkingPool->getCurrentPrice();  // 2000

// Customer gets cheapest available spot
// But if $20 spot is claimed, next customer gets $25 spot at $25 price
```

### Dynamic Pricing Based on Availability

```php
$from = Carbon::parse('2025-01-15');
$until = Carbon::parse('2025-01-20');

// Get price for specific dates (considers availability)
$price = $parkingPool->getLowestAvailablePoolPrice($from, $until);
```

### Price Range Display

```php
// Get min/max price range from available items
$range = $parkingPool->getPoolPriceRange();
// Returns: ['min' => 2000, 'max' => 3000]

// Display to customer: "From $20 to $30 per day"
```

### Checking for Booking Single Items

```php
// Check if pool contains any booking-type single items
$hasBooking = $parkingPool->hasBookingSingleItems();  // true

// This affects how cart handles date requirements
```

## Common Use Cases

### 1. Parking Spaces

```php
// Pool: "Parking at Hotel"
// Singles: Spot 1, Spot 2, Spot 3, ..., Spot 50
// Each spot: 1 unit stock
// Customer books "a parking space", system assigns specific spot
```

### 2. Hotel Room Categories

```php
// Pool: "Standard Rooms"
// Singles: Room 101, Room 102, Room 103
// All same price, customer doesn't care which room
// System auto-assigns available room
```

### 3. Equipment Fleet

```php
// Pool: "Rental Cars - Compact"
// Singles: Car VIN-001, Car VIN-002, Car VIN-003
// Customer rents "a compact car", not a specific car
// System tracks which car is assigned
```

### 4. Event Seating

```php
// Pool: "General Admission"
// Singles: Seat A1, Seat A2, Seat A3, ...
// Customer books "general admission ticket"
// System assigns specific seat
```

### 5. Service Providers

```php
// Pool: "Consultation Services"
// Singles: Consultant A, Consultant B, Consultant C
// Customer books "a consultation"
// System assigns available consultant
```

## Best Practices

### 1. Pool Configuration

```php
// ✅ CORRECT
$pool = Product::create([
    'type' => ProductType::POOL,
    'manage_stock' => false,  // Pool doesn't manage stock
]);

// ❌ INCORRECT
$pool = Product::create([
    'type' => ProductType::POOL,
    'manage_stock' => true,  // Wrong! Pool shouldn't manage stock
]);
```

### 2. Single Item Configuration

```php
// ✅ CORRECT
$singleItem = Product::create([
    'type' => ProductType::BOOKING,
    'manage_stock' => true,  // Single items manage stock
]);
$singleItem->increaseStock(1);

// ❌ INCORRECT
$singleItem = Product::create([
    'type' => ProductType::BOOKING,
    'manage_stock' => false,  // Single items need stock management
]);
```

### 3. Use attachSingleItems()

```php
// ✅ CORRECT - Creates bidirectional relations
$pool->attachSingleItems([$item1->id, $item2->id]);

// ❌ INCORRECT - Only creates one-way relation
$pool->productRelations()->attach($item1->id, [
    'type' => ProductRelationType::SINGLE->value
]);
// Missing reverse POOL relation!
```

### 4. Always Validate

```php
// ✅ CORRECT
$pool->attachSingleItems($itemIds);
$validation = $pool->validatePoolConfiguration();

if (!$validation['valid']) {
    throw new Exception('Invalid pool configuration');
}

// ❌ INCORRECT - No validation
$pool->attachSingleItems($itemIds);
// What if items don't have stock? What if mixed types?
```

### 5. Set Pricing Strategy

```php
// ✅ CORRECT - Explicit strategy
$pool->setPricingStrategy(PricingStrategy::LOWEST);

// ⚠️ IMPLICIT - Uses default (LOWEST)
// $pool price will use LOWEST by default
```

### 6. Check Availability Before Booking

```php
// ✅ CORRECT
if ($pool->isPoolAvailable($from, $until, $quantity)) {
    $cart->addToCart($pool, $quantity, [], $from, $until);
}

// ❌ INCORRECT - No availability check
$cart->addToCart($pool, $quantity, [], $from, $until);
// May fail if not enough stock!
```

## Troubleshooting

### "Pool product has no single items to claim"

**Cause:** Pool has no single items attached

**Solution:**
```php
$pool->attachSingleItems([$item1->id, $item2->id]);
```

### "Not enough stock available"

**Cause:** All single items are claimed/booked

**Solutions:**
1. Add more single items to the pool
2. Check if claims have expired and need cleanup
3. Verify single items have stock: `$item->increaseStock(1)`

### Pricing Shows Wrong Value

**Cause:** Pricing strategy or unavailable items

**Solution:**
```php
// Check which items are available
$items = $pool->getSingleItemsAvailability($from, $until);

// Verify pricing strategy
$strategy = $pool->getPricingStrategy();

// Check prices from available items only
$price = $pool->getLowestAvailablePoolPrice($from, $until);
```

### Single Items Not Released After Cart Deletion

**Cause:** Metadata not properly storing claimed items

**Solution:**
```php
// Ensure cart item has metadata
$meta = $cartItem->getMeta();
$claimedItems = $meta->claimed_single_items ?? [];

// Manually release if needed
$pool->releasePoolStock($cartItem);
```

### Bidirectional Relations Missing

**Cause:** Used `productRelations()->attach()` instead of `attachSingleItems()`

**Solution:**
```php
// Always use this method for pools
$pool->attachSingleItems($itemIds);

// This creates BOTH:
// - Pool → Items (SINGLE)
// - Items → Pool (POOL)
```

## Performance Considerations

### 1. Lazy Loading

```php
// ❌ N+1 query problem
foreach ($pools as $pool) {
    $pool->singleProducts;  // Query per pool
}

// ✅ Eager loading
$pools = Product::with('singleProducts')->where('type', ProductType::POOL)->get();
```

### 2. Availability Caching

For high-traffic scenarios:

```php
// Cache availability calendar
$cacheKey = "pool:{$pool->id}:availability:{$from}:{$until}";
$calendar = Cache::remember($cacheKey, 3600, function() use ($pool, $from, $until) {
    return $pool->getPoolAvailabilityCalendar($from, $until);
});
```

### 3. Batch Validation

```php
// Validate multiple pools at once
$pools->each(function($pool) {
    try {
        $pool->validatePoolConfiguration();
    } catch (InvalidPoolConfigurationException $e) {
        Log::error("Invalid pool: {$pool->name}", ['error' => $e->getMessage()]);
    }
});
```

## Related Documentation

- [Booking Products](./01-booking-products.md) - Understanding single items in pools
- [Product Relations](../05-product-relations.md) - Relation system details
- [Pricing Strategies](../07-pricing-strategies.md) - In-depth pricing documentation
- [Stock Management](../06-stock-management.md) - How stock system works
