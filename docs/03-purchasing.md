# Purchasing & Shopping Cart

## Setup

Add the `HasShoppingCapabilities` trait to your User model (or any model that should be able to purchase products):

```php
use Blax\Shop\Traits\HasShoppingCapabilities;

class User extends Authenticatable
{
    use HasShoppingCapabilities;
}
```

This trait provides methods for:
- Direct product purchases
- Shopping cart management
- Purchase history
- Cart checkout

## Direct Purchase

### Purchase a Product

```php
$user = auth()->user();
$product = Product::find($productId);

// Product must have a default price
try {
    $purchase = $user->purchase($product);
    
    // Purchase successful
    return response()->json([
        'success' => true,
        'purchase_id' => $purchase->id,
        'amount' => $purchase->amount,
    ]);
} catch (\Exception $e) {
    return response()->json([
        'error' => $e->getMessage()
    ], 400);
}
```

### Purchase with Specific Price

```php
$price = ProductPrice::find($priceId);

$purchase = $user->purchase(
    $price,
    quantity: 2
);
```

### Purchase with Metadata

```php
$purchase = $user->purchase(
    $product,
    quantity: 1,
    meta: [
        'gift' => true,
        'message' => 'Happy Birthday!',
        'gift_recipient' => 'john@example.com',
    ]
);
```

### Important Notes

- Product must have at least one default price
- Product must not have multiple default prices (will throw `MultiplePurchaseOptions` exception)
- If stock management is enabled, sufficient stock must be available
- Product must be visible (published, visible flag, and published_at date)
- Purchase automatically decreases stock if `manage_stock` is enabled
- Product actions are automatically triggered on purchase

## Shopping Cart

### Add to Cart

```php
$user = auth()->user();
$product = Product::find($productId);

try {
    // For regular products
    $cartItem = $user->addToCart($product, quantity: 1);
    
    // For booking products (requires dates)
    $from = Carbon::parse('2025-01-15');
    $until = Carbon::parse('2025-01-20');
    $cartItem = $user->addToCart($product, quantity: 1, parameters: [
        'from' => $from,
        'until' => $until,
    ]);
    
    return response()->json([
        'success' => true,
        'cart_item' => $cartItem,
        'cart_total' => $user->getCartTotal(),
        'cart_count' => $user->getCartItemsCount(),
    ]);
} catch (\Exception $e) {
    return response()->json(['error' => $e->getMessage()], 400);
}
```

### Add with Parameters

```php
$cartItem = $user->addToCart(
    $product,
    quantity: 2,
    parameters: [
        'color' => 'blue',
        'size' => 'large',
    ]
);
```

### Get Cart Items

```php
$cartItems = $user->cartItems()->get();

foreach ($cartItems as $item) {
    echo $item->purchasable->getLocalized('name');
    echo $item->quantity;
    echo $item->price;
    echo $item->subtotal;
}
```

### Update Cart Item Quantity

```php
$cartItem = CartItem::find($cartItemId);

try {
    $updatedItem = $user->updateCartQuantity($cartItem, quantity: 3);
    
    return response()->json([
        'success' => true,
        'cart_total' => $user->getCartTotal(),
    ]);
} catch (\Exception $e) {
    return response()->json(['error' => $e->getMessage()], 400);
}
```

### Remove from Cart

```php
$cartItem = CartItem::find($cartItemId);

$user->removeFromCart($cartItem);

return response()->json([
    'success' => true,
    'cart_total' => $user->getCartTotal(),
    'cart_count' => $user->getCartItemsCount(),
]);
```

### Clear Cart

```php
$count = $user->clearCart();

return response()->json([
    'success' => true,
    'removed_items' => $count,
]);
```

### Get Cart Totals

```php
// Get cart total
$total = $user->getCartTotal();

// Get cart items count
$count = $user->getCartItemsCount();

// Get cart stats
$stats = [
    'total' => $user->getCartTotal(),
    'count' => $user->getCartItemsCount(),
    'items' => $user->cartItems()->with('purchasable')->get(),
];
```

## Cart Checkout

### Checkout Cart

```php
try {
    // Get current cart
    $cart = $user->currentCart();
    
    // Checkout (creates purchases and order)
    $cart->checkout();
    
    // Access the order
    $order = $cart->order;
    
    return response()->json([
        'success' => true,
        'order' => $order,
        'order_number' => $order->order_number,
    ]);
} catch (\Exception $e) {
    return response()->json([
        'error' => $e->getMessage()
    ], 400);
}
```

### What Happens During Checkout

1. **Validates Cart**
   - Checks that cart is not empty
   - Validates all items have required information
   - For booking products: validates dates are set
   
2. **Claims Stock**
   - Claims stock for booking/pool products
   - Validates stock availability
   
3. **Creates Order**
   - Generates order number
   - Creates Order record linked to cart
   - Copies cart total to order amounts
   
4. **Creates Purchases**
   - Creates ProductPurchase records for each cart item
   - Links purchases to order
   
5. **Converts Cart**
   - Marks cart as CONVERTED
   - Sets `converted_at` timestamp

### Important Notes

- Stock is claimed at checkout time (not add-to-cart time for bookings)
- Cart items remain in database but are marked as converted
- Order is created with PENDING status by default

## Purchase History

### Get All Purchases

```php
// Get all purchases (any status)
$allPurchases = $user->purchases()->get();

// Get only completed purchases
$completedPurchases = $user->completedPurchases()->get();

// Get purchases for specific product
$productPurchases = $user->purchases()
    ->where('purchasable_id', $product->id)
    ->where('purchasable_type', Product::class)
    ->get();
```

## Order Management

### Get All Orders

```php
// Get all orders
$orders = $user->orders()->get();

// Get orders with specific status
use Blax\Shop\Enums\OrderStatus;

$pendingOrders = $user->pendingOrders()->get();
$processingOrders = $user->processingOrders()->get();
$completedOrders = $user->completedOrders()->get();

// Get active orders (not completed/cancelled/refunded)
$activeOrders = $user->activeOrders()->get();
```

### Order Status Flow

Orders progress through these statuses:

1. **PENDING** - Order received but awaiting payment confirmation
2. **PROCESSING** - Payment received and order is being processed
3. **ON_HOLD** - Order on hold, awaiting further action
4. **IN_PREPARATION** - Order being prepared (packing, manufacturing)
5. **READY_FOR_PICKUP** - Order ready for pickup (for local pickup orders)
6. **SHIPPED** - Order has been shipped and is in transit
7. **DELIVERED** - Order delivered to customer
8. **COMPLETED** - Order complete, all actions fulfilled
9. **CANCELLED** - Order was cancelled
10. **REFUNDED** - Order was refunded
11. **FAILED** - Payment or processing failed

### Get Order by Number

```php
$order = $user->findOrderByNumber('ORD-2025-0001');

if ($order) {
    echo "Order found: {$order->order_number}";
}
```

### Order Details

```php
$order = Order::find($orderId);

// Order properties
$order->order_number;       // Unique order number
$order->status;             // OrderStatus enum
$order->amount_total;       // Total amount (in cents)
$order->amount_paid;        // Amount paid (in cents)
$order->amount_subtotal;    // Subtotal before tax/shipping
$order->amount_tax;         // Tax amount
$order->amount_shipping;    // Shipping cost
$order->amount_discount;    // Discount applied
$order->amount_refunded;    // Amount refunded

// Dates
$order->created_at;         // When order was created
$order->paid_at;            // When payment was received
$order->shipped_at;         // When order was shipped
$order->delivered_at;       // When order was delivered
$order->completed_at;       // When order was completed
$order->cancelled_at;       // When order was cancelled
$order->refunded_at;        // When order was refunded

// Additional info
$order->payment_method;     // Payment method used
$order->payment_provider;   // Payment provider (e.g., 'stripe')
$order->payment_reference;  // Provider reference ID
$order->billing_address;    // Billing address object
$order->shipping_address;   // Shipping address object
$order->customer_note;      // Customer's note
$order->internal_note;      // Internal staff note
```

### Order Relationships

```php
// Get order customer
$customer = $order->customer;

// Get order purchases (line items)
$purchases = $order->purchases()->get();

// Get original cart
$cart = $order->cart;

// Get order notes
$notes = $order->notes()->get();
```

### Order Statistics

```php
// Total spent across all orders
$totalSpent = $user->total_spent; // Accessor in cents

// Number of orders
$orderCount = $user->order_count;

// Number of completed orders
$completedCount = $user->completed_order_count;

// Check if user has any orders
if ($user->hasOrders()) {
    echo "Customer has placed orders";
}

// Check if user has active orders
if ($user->hasActiveOrders()) {
    echo "Customer has orders in progress";
}

// Get latest order
$latestOrder = $user->latestOrder();
```

### Filter Orders by Date

```php
$from = Carbon::parse('2025-01-01');
$to = Carbon::parse('2025-12-31');

$ordersThisYear = $user->ordersBetween($from, $to)->get();
```

### Order Payment Status

```php
// Check if order is paid
if ($order->is_paid) {
    echo "Order has been paid";
}

// Check if fully paid
if ($order->is_fully_paid) {
    echo "Order is fully paid";
}

// Get outstanding amount
$outstanding = $order->amount_outstanding; // In cents
```

### Update Order Status

```php
use Blax\Shop\Enums\OrderStatus;

// Update order status
$order->update(['status' => OrderStatus::PROCESSING]);

// Mark as shipped
$order->update([
    'status' => OrderStatus::SHIPPED,
    'shipped_at' => now(),
]);

// Mark as delivered
$order->update([
    'status' => OrderStatus::DELIVERED,
    'delivered_at' => now(),
]);

// Mark as completed
$order->update([
    'status' => OrderStatus::COMPLETED,
    'completed_at' => now(),
]);
```

### Add Order Notes

```php
use Blax\Shop\Models\OrderNote;

// Add customer-visible note
OrderNote::create([
    'order_id' => $order->id,
    'content' => 'Your order has been shipped!',
    'is_customer_note' => true,
]);

// Add internal note
OrderNote::create([
    'order_id' => $order->id,
    'content' => 'Customer requested gift wrapping',
    'is_customer_note' => false,
]);

// Get all notes
$allNotes = $order->notes()->get();

// Get customer-visible notes only
$customerNotes = $order->notes()->where('is_customer_note', true)->get();
```

## Refunds

### Refund an Order

```php
use Blax\Shop\Enums\OrderStatus;

$order = Order::find($orderId);

// Mark order as refunded
$order->update([
    'status' => OrderStatus::REFUNDED,
    'refunded_at' => now(),
    'amount_refunded' => $order->amount_total,
]);

// Stock will be released back from associated purchases
```

## Cart Model

### Get Current Cart

```php
// Get or create current active cart
$cart = $user->currentCart();

// Cart properties
$cart->session_id;      // Session ID for guest carts
$cart->customer_id;     // User ID
$cart->customer_type;   // User model class
$cart->currency;        // Cart currency (default: USD)
$cart->status;          // active, abandoned, converted, expired
$cart->converted_at;    // When cart was checked out
$cart->expires_at;      // Cart expiration date
$cart->last_activity_at; // Last activity timestamp
```

### Cart Relationships

```php
// Get cart items
$items = $cart->items()->get();

// Get cart order (if converted)
$order = $cart->order;

// Get cart customer (user)
$customer = $cart->customer;
```

### Cart Methods

```php
// Get cart total
$total = $cart->getTotal();

// Get total items
$itemCount = $cart->getTotalItems();

// Check if cart is expired
if ($cart->isExpired()) {
    // Cart has expired
}

// Check if cart is converted
if ($cart->isConverted()) {
    // Cart has been checked out
}
```

### Add Items to Cart Directly

```php
use Blax\Shop\Models\Cart;

$cart = Cart::find($cartId);

$cartItem = $cart->addToCart(
    $product, // or $productPrice
    quantity: 2,
    parameters: ['size' => 'L']
);
```

## Product Purchase Model

### Purchase Properties

```php
$purchase = ProductPurchase::find($purchaseId);

$purchase->status;           // pending, unpaid, completed, refunded, failed
$purchase->cart_id;          // Associated cart ID
$purchase->price_id;         // Associated price ID
$purchase->purchasable_id;   // Product ID
$purchase->purchasable_type; // Product class
$purchase->purchaser_id;     // User ID
$purchase->purchaser_type;   // User class
$purchase->quantity;         // Quantity purchased
$purchase->amount;           // Total amount (in cents)
$purchase->amount_paid;      // Amount paid (in cents)
$purchase->charge_id;        // Payment charge ID
$purchase->from;             // Booking start date (for bookings)
$purchase->until;            // Booking end date (for bookings)
$purchase->meta;             // Additional metadata
```

### Purchase Relationships

```php
// Get purchased product
$product = $purchase->purchasable;

// Get purchaser (user)
$user = $purchase->purchaser;

// Get associated cart item
$cartItem = $purchase->cartItem;

// Get associated order
$order = $purchase->order;
```

### Purchase Scopes

```php
use Blax\Shop\Enums\PurchaseStatus;

// Get completed purchases
$completed = ProductPurchase::where('status', PurchaseStatus::COMPLETED)->get();

// Get pending purchases
$pending = ProductPurchase::where('status', PurchaseStatus::PENDING)->get();
```

## Stock Claims

When adding booking products to cart, stock is claimed at checkout time:

```php
// For booking products, stock is NOT claimed when adding to cart
$cartItem = $user->addToCart($bookingProduct, quantity: 1, parameters: [
    'from' => Carbon::parse('2025-01-15'),
    'until' => Carbon::parse('2025-01-20'),
]);

// Stock is validated and claimed during checkout
$cart = $user->currentCart();
$cart->checkout(); // Claims stock at this point

// For regular products, stock is claimed immediately when adding to cart
$cartItem = $user->addToCart($regularProduct, quantity: 2);
// Stock is claimed immediately for non-booking products
```

## Error Handling

### Common Exceptions

```php
use Blax\Shop\Exceptions\NotPurchasable;
use Blax\Shop\Exceptions\MultiplePurchaseOptions;
use Blax\Shop\Exceptions\NotEnoughStockException;

try {
    $purchase = $user->purchase($product);
} catch (NotPurchasable $e) {
    // Product has no default price
} catch (MultiplePurchaseOptions $e) {
    // Product has multiple default prices - need to specify which one
    $price = $product->prices()->where('currency', 'USD')->first();
    $purchase = $user->purchase($price);
} catch (NotEnoughStockException $e) {
    // Insufficient stock available
    $available = $product->getAvailableStock();
    echo "Only {$available} items available";
} catch (\Exception $e) {
    // General error
    echo $e->getMessage();
}
```

## Complete Example

```php
// Product listing
Route::get('/products', function () {
    $products = Product::visible()
        ->inStock()
        ->with(['prices' => fn($q) => $q->where('is_default', true)])
        ->get();
    
    return view('products.index', compact('products'));
});

// Add to cart
Route::post('/cart/add/{product}', function (Product $product) {
    $user = auth()->user();
    
    try {
        $cartItem = $user->addToCart($product, quantity: 1);
        
        return redirect()->back()->with('success', 'Product added to cart!');
    } catch (\Exception $e) {
        return redirect()->back()->with('error', $e->getMessage());
    }
});

// View cart
Route::get('/cart', function () {
    $user = auth()->user();
    
    $cartItems = $user->cartItems()->with('purchasable')->get();
    $cartTotal = $user->getCartTotal();
    $cartCount = $user->getCartItemsCount();
    
    return view('cart.index', compact('cartItems', 'cartTotal', 'cartCount'));
});

// Checkout
Route::post('/checkout', function () {
    $user = auth()->user();
    
    try {
        $cart = $user->currentCart();
        $cart->checkout();
        
        // Access the created order
        $order = $cart->order;
        
        return redirect()->route('orders.success', ['order' => $order->id])
            ->with('success', "Order {$order->order_number} placed successfully!");
    } catch (\Exception $e) {
        return redirect()->back()->with('error', $e->getMessage());
    }
});

// Order history
Route::get('/orders', function () {
    $user = auth()->user();
    
    $orders = $user->orders()
        ->with(['purchases.purchasable'])
        ->orderBy('created_at', 'desc')
        ->get();
    
    return view('orders.index', compact('orders'));
});

// View specific order
Route::get('/orders/{order}', function (Order $order) {
    $user = auth()->user();
    
    // Ensure user owns this order
    if ($order->customer_id !== $user->id) {
        abort(403);
    }
    
    $order->load(['purchases.purchasable', 'notes']);
    
    return view('orders.show', compact('order'));
});
```
