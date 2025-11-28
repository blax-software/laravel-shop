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
    $cartItem = $user->addToCart($product, quantity: 1);
    
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

### Convert Cart to Purchases

```php
try {
    $purchases = $user->checkoutCart();
    
    // Checkout successful
    // Cart items are now converted to completed purchases
    // Cart is marked as converted
    
    return response()->json([
        'success' => true,
        'purchases' => $purchases,
        'total_items' => $purchases->count(),
    ]);
} catch (\Exception $e) {
    return response()->json([
        'error' => $e->getMessage()
    ], 400);
}
```

### Important Notes

- Checkout validates stock availability for all items
- Creates `ProductPurchase` records for each cart item
- Decreases stock for each item
- Triggers product actions
- Marks cart as converted (`converted_at` timestamp)
- Removes cart items after successful checkout

## Purchase History

### Check if User Purchased Product

```php
$product = Product::find($productId);

if ($user->hasPurchased($product)) {
    // User has purchased this product
    echo "You own this product!";
}
```

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

### Purchase Statistics

```php
$stats = $user->getPurchaseStats();

// Returns:
// [
//     'total_purchases' => 15,
//     'total_spent' => 450.00,
//     'total_items' => 23,
//     'cart_items' => 2,
//     'cart_total' => 89.99,
// ]
```

## Refunds

### Refund a Purchase

```php
$purchase = ProductPurchase::find($purchaseId);

try {
    $success = $user->refundPurchase($purchase);
    
    if ($success) {
        // Refund successful
        // Stock has been returned
        // Purchase status changed to 'refunded'
        // Product 'refunded' actions triggered
        
        return response()->json([
            'success' => true,
            'message' => 'Purchase refunded successfully',
        ]);
    }
} catch (\Exception $e) {
    return response()->json([
        'error' => $e->getMessage()
    ], 400);
}
```

### Important Notes

- Only completed purchases can be refunded
- Stock is automatically returned to inventory
- Product actions with event 'refunded' are triggered

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

// Get cart purchases (if converted)
$purchases = $cart->purchases()->get();

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

$purchase->status;           // cart, pending, unpaid, completed, refunded
$purchase->cart_id;          // Associated cart ID
$purchase->price_id;         // Associated price ID
$purchase->purchasable_id;   // Product ID
$purchase->purchasable_type; // Product class
$purchase->purchaser_id;     // User ID
$purchase->purchaser_type;   // User class
$purchase->quantity;         // Quantity purchased
$purchase->amount;           // Total amount
$purchase->amount_paid;      // Amount paid
$purchase->charge_id;        // Payment charge ID
$purchase->meta;             // Additional metadata
```

### Purchase Relationships

```php
// Get purchased product
$product = $purchase->purchasable;

// Get purchaser (user)
$user = $purchase->purchaser;
```

### Purchase Scopes

```php
// Get purchases in cart
$cartPurchases = ProductPurchase::inCart()->get();

// Get completed purchases
$completed = ProductPurchase::completed()->get();

// Get purchases from specific cart
$cartPurchases = ProductPurchase::fromCart($cartId)->get();
```

## Stock Reservations

When adding products to cart, stock is automatically reserved:

```php
// Stock is reserved when adding to cart
$cartItem = $user->addToCart($product, quantity: 2);

// Reservation is created automatically
// It expires after configured time (default: 15 minutes)
// Stock is released back when:
// - Reservation expires
// - Cart item is removed
// - Cart is abandoned
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
        $purchases = $user->checkoutCart();
        
        return redirect()->route('orders.success')
            ->with('success', 'Order placed successfully!');
    } catch (\Exception $e) {
        return redirect()->back()->with('error', $e->getMessage());
    }
});

// Order history
Route::get('/orders', function () {
    $user = auth()->user();
    
    $purchases = $user->completedPurchases()
        ->with('purchasable')
        ->orderBy('created_at', 'desc')
        ->get();
    
    return view('orders.index', compact('purchases'));
});
```
