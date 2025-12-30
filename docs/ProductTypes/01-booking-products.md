# Booking Products

## Overview

Booking products (`ProductType::BOOKING`) are time-based products that can be reserved for specific date ranges. They are designed for scenarios where customers need to reserve items for a period of time, such as hotel rooms, rental equipment, parking spaces, or event tickets.

## Key Characteristics

### 1. **Time-Based Availability**
- Products are reserved for specific date ranges (`from` to `until`)
- Stock is tracked on a per-date basis
- Multiple customers can have overlapping claims if enough stock exists
- Claims automatically release when they expire

### 2. **Stock Management**
- **MUST** have `manage_stock = true`
- Stock represents the number of units available simultaneously
- Example: A hotel room with stock=5 means 5 rooms can be booked at the same time

### 3. **Price Calculation**
- Prices are calculated based on the number of days
- Formula: `price = unit_amount × number_of_days × quantity`
- Example: $100/day room for 3 days = $300

### 4. **Stock Claiming**
- When added to cart, stock is "claimed" (reserved but not sold)
- Claims have a start date (`claimed_from`) and end date (`expires_at`)
- Claims reduce available stock during their active period
- Claims can be released (e.g., when removed from cart or cart expires)

## How It Works

### Stock Tracking System

Booking products use a sophisticated two-entry stock system:

1. **DECREASE Entry** (COMPLETED status)
   - Reduces available stock immediately
   - Quantity: `-X` (negative)
   - Has an `expires_at` date (when the booking ends)

2. **CLAIMED Entry** (PENDING status)
   - Tracks the claim/reservation
   - Quantity: `+X` (positive, represents claimed amount)
   - Has both `claimed_from` and `expires_at` dates
   - Links to the reference model (Cart, Order, etc.)

### Availability Checking

```php
// Check if product is available for a date range
$product->isAvailableForBooking($from, $until, $quantity);
```

The system checks:
1. How much stock is available (total stock)
2. How much is already claimed during the requested period
3. If `available - claimed >= requested_quantity`

### Example Scenario: Hotel Room

```php
// Create a hotel room product
$room = Product::create([
    'name' => 'Deluxe Suite',
    'type' => ProductType::BOOKING,
    'manage_stock' => true,
]);

// We have 5 rooms available
$room->increaseStock(5);

// Add price: $200 per day
ProductPrice::create([
    'purchasable_id' => $room->id,
    'purchasable_type' => Product::class,
    'unit_amount' => 20000, // $200.00 (in cents)
    'currency' => 'USD',
    'is_default' => true,
]);

// Customer books 2 rooms from Jan 1-3 (2 days)
$from = Carbon::parse('2025-01-01');
$until = Carbon::parse('2025-01-03');

// Check availability
if ($room->isAvailableForBooking($from, $until, 2)) {
    // Add to cart (claims stock automatically)
    $cart->addToCart($room, 2, [], $from, $until);
    
    // Price calculation:
    // 2 rooms × 2 days × $200/day = $800
}

// Available stock during booking:
// - Before: 5 rooms available
// - During Jan 1-3: 3 rooms available (5 - 2 claimed)
// - After Jan 3: 5 rooms available (claims expire)
```

## Configuration Requirements

### Database Requirements

1. **Product Table**
   ```php
   'type' => ProductType::BOOKING,
   'manage_stock' => true,  // REQUIRED
   ```

2. **Stock Table** (`product_stocks`)
   - `claimed_from` - When the booking starts
   - `expires_at` - When the booking ends
   - `reference_type` - Polymorphic relation (Cart, Order, etc.)
   - `reference_id` - ID of the reference model

### Validation

```php
// Validate booking configuration
try {
    $product->validateBookingConfiguration();
} catch (InvalidBookingConfigurationException $e) {
    // Handle invalid configuration
}
```

Common validation errors:
- Stock management not enabled
- No available stock
- No price set
- Invalid date range (from >= until)

## Cart Integration

### Adding to Cart

```php
$from = Carbon::parse('2025-01-15');
$until = Carbon::parse('2025-01-20'); // 5 days

$cartItem = $cart->addToCart($product, $quantity = 1, [], $from, $until);

// CartItem properties:
// - from: 2025-01-15
// - until: 2025-01-20
// - price: unit_amount × 5 days
// - quantity: number of units
```

### What Happens:
1. System checks availability for the date range
2. If available, stock is claimed:
   - DECREASE entry with `expires_at = 2025-01-20`
   - CLAIMED entry with `claimed_from = 2025-01-15`, `expires_at = 2025-01-20`
3. Cart item stores the date range
4. Price is calculated for the duration

### Removing from Cart

```php
$cartItem->delete();
```

What happens:
1. Claimed stocks are released
2. CLAIMED entry status changes to COMPLETED
3. Stock becomes available again

## Checkout Flow

### Purchase Process

```php
$cart->checkout();
```

1. **Before Checkout**
   - Stock is CLAIMED (reserved)
   - Status: PENDING
   - Can be released if cart expires

2. **After Successful Checkout**
   - CLAIMED entries remain (converted to sold)
   - Stock stays decreased for the booking period
   - After `expires_at`, stock automatically becomes available again

3. **Failed Checkout**
   - Claims are released
   - Stock returns to available pool

## Advanced Usage

### Checking Availability Calendar

```php
// Get available stock for each day
$from = Carbon::parse('2025-01-01');
$until = Carbon::parse('2025-01-31');

$availability = [];
$current = $from->copy();

while ($current <= $until) {
    $nextDay = $current->copy()->addDay();
    $available = $product->isAvailableForBooking($current, $nextDay, 1);
    $availability[$current->format('Y-m-d')] = $available;
    $current->addDay();
}
```

### Multiple Bookings

```php
// Booking 1: Jan 1-5
$cart->addToCart($room, 1, [], 
    Carbon::parse('2025-01-01'), 
    Carbon::parse('2025-01-05')
);

// Booking 2: Jan 3-7 (overlaps with Booking 1)
// This works if there's enough stock
$cart->addToCart($room, 1, [], 
    Carbon::parse('2025-01-03'), 
    Carbon::parse('2025-01-07')
);
```

### Getting Available Stock on a Specific Date

```php
$date = Carbon::parse('2025-01-15');
$available = $product->getAvailableStock($date);
```

## Common Use Cases

### 1. Hotel Rooms
- Stock = number of rooms
- Customers book rooms for date ranges
- Multiple rooms can be booked simultaneously

### 2. Equipment Rental
- Stock = number of items available
- Customers rent for specific periods
- Items return to inventory after rental ends

### 3. Parking Spaces
- Stock = number of spots
- Reserved for specific time periods
- Automatically available after reservation expires

### 4. Event Tickets (Time-Based)
- Stock = number of seats
- Reserved for specific time slots
- Released if not purchased within time limit

### 5. Service Appointments
- Stock = number of available slots
- Each booking claims one slot
- Slots are time-specific

## Best Practices

1. **Always Enable Stock Management**
   ```php
   'manage_stock' => true  // REQUIRED for booking products
   ```

2. **Set Appropriate Stock Levels**
   - Stock = maximum concurrent bookings
   - Too low = lost revenue
   - Too high = overbooking risk

3. **Handle Overlapping Bookings**
   - System automatically manages overlaps
   - Just ensure enough stock exists

4. **Cart Expiration**
   - Set appropriate cart expiration times
   - Expired claims auto-release stock

5. **Price Per Day**
   - Set `unit_amount` as the daily rate
   - System automatically multiplies by days

6. **Date Validation**
   - Always validate `from < until`
   - Validate minimum/maximum booking duration if needed

7. **Availability Checking**
   - Always check availability before claiming
   - Use `isAvailableForBooking()` method

## Troubleshooting

### "Not enough stock available"
- Check if stock is claimed by other bookings during the period
- Verify `manage_stock = true`
- Check if stock was actually added (`increaseStock()`)

### "Stock management not enabled"
- Set `manage_stock = true` on the product

### Bookings Not Releasing
- Check that cart items are properly deleted
- Verify that claims have `expires_at` set
- Expired claims should auto-release (verify cron/queue is running)

### Price Calculation Wrong
- Verify `unit_amount` is the daily rate
- Check that date calculation is correct (diff in days)
- Ensure price is multiplied by both days and quantity

## Related Documentation

- [Pool Products](./02-pool-products.md) - Managing groups of booking products
- [Product Relations](../05-product-relations.md) - How products relate to each other
