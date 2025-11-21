# Purchasing Products

## Setup

First, add the `HasShoppingCapabilities` trait to your User model (or any model that should purchase products):

```php
use Blax\Shop\Traits\HasShoppingCapabilities;

class User extends Authenticatable
{
    use HasShoppingCapabilities;
}
```

## Direct Purchase

### Simple Purchase

```php
$user = auth()->user();
$product = Product::find($productId);

try {
    $purchase = $user->purchase($product, quantity: 1);
    
    // Purchase successful
    return response()->json([
        'success' => true,
        'purchase_id' => $purchase->id,
    ]);
} catch (\Exception $e) {
    return response()->json([
        'error' => $e->getMessage()
    ], 400);
}
```

### Purchase with Options

```php
$purchase = $user->purchase($product, quantity: 2, options: [
    'price_id' => $priceId,        // Use specific price
    'charge_id' => $paymentId,     // Associate with payment
    'cart_id' => $cartId,          // Associate with cart
    'status' => 'pending',         // Custom status
]);
```

### Check Purchase History

```php
// Check if user has purchased a product
if ($user->hasPurchased($product)) {
    // User has purchased this product
}

// Get purchase history for a product
$history = $user->getPurchaseHistory($product);

// Get all completed purchases
$purchases = $user->completedPurchases()->get();
```

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

### Update Cart Quantity

```php
$cartItem = ProductPurchase::find($cartItemId);

try {
    $user->updateCartQuantity($cartItem, quantity: 3);
    
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
$cartItem = ProductPurchase::find($cartItemId);

$user->removeFromCart($cartItem);
```

### Get Cart Information

```php
// Get all cart items
$cartItems = $user->cartItems()->with('product')->get();

// Get cart total
$total = $user->getCartTotal();

// Get items count
$count = $user->getCartItemsCount();

// Clear cart
$user->clearCart();
```

### Checkout

```php
try {
    $completedPurchases = $user->checkout(options: [
        'charge_id' => $paymentIntent->id,
    ]);
    
    return response()->json([
        'success' => true,
        'purchases' => $completedPurchases,
        'total' => $completedPurchases->sum('amount'),
    ]);
} catch (\Exception $e) {
    return response()->json(['error' => $e->getMessage()], 400);
}
```

## Refunds

```php
$purchase = ProductPurchase::find($purchaseId);
$user = $purchase->purchasable;

try {
    $user->refundPurchase($purchase, options: [
        'refund_id' => $refundId,
        'reason' => 'Customer request',
    ]);
    
    return response()->json(['success' => true]);
} catch (\Exception $e) {
    return response()->json(['error' => $e->getMessage()], 400);
}
```

## Purchase Statistics

```php
$stats = $user->getPurchaseStats();

// Returns:
// [
//     'total_purchases' => 10,
//     'total_spent' => 299.90,
//     'total_items' => 15,
//     'cart_items' => 2,
//     'cart_total' => 49.98,
// ]
```

## Basic Purchase Flow

### 1. Check Product Availability

```php
use Blax\Shop\Models\Product;

$product = Product::find($productId);
$quantity = 1;

// Check if product is available
if (!$product->isVisible()) {
    return response()->json(['error' => 'Product not available'], 404);
}

// Check stock
if ($product->manage_stock) {
    $available = $product->getAvailableStock();
    
    if ($available < $quantity) {
        return response()->json([
            'error' => 'Insufficient stock',
            'available' => $available
        ], 400);
    }
}
```

### 2. Reserve Stock (Optional)

Reserve stock during checkout process:

```php
// Reserve for 15 minutes
$reservation = $product->reserveStock(
    quantity: $quantity,
    reference: auth()->user(),
    until: now()->addMinutes(15),
    note: 'Checkout reservation'
);

if (!$reservation) {
    return response()->json(['error' => 'Unable to reserve stock'], 400);
}

// Store reservation ID in session
session(['stock_reservation_id' => $reservation->id]);
```

### 3. Process Payment

```php
// Your payment processing logic
$payment = PaymentService::process([
    'amount' => $product->getCurrentPrice() * $quantity,
    'currency' => 'USD',
    'product_id' => $product->id,
]);

if ($payment->failed()) {
    // Release reservation
    $reservation->update(['status' => 'cancelled']);
    return response()->json(['error' => 'Payment failed'], 400);
}
```

### 4. Complete Purchase

```php
use Blax\Shop\Models\ProductPurchase;

// Decrease stock
$product->decreaseStock($quantity);

// Create purchase record
$purchase = ProductPurchase::create([
    'product_id' => $product->id,
    'purchasable_type' => get_class(auth()->user()),
    'purchasable_id' => auth()->id(),
    'quantity' => $quantity,
    'status' => 'completed',
    'meta' => [
        'payment_id' => $payment->id,
        'price_paid' => $product->getCurrentPrice(),
        'currency' => 'USD',
    ],
]);

// Complete reservation
if ($reservation) {
    $reservation->update(['status' => 'completed']);
}

// Trigger product actions
$product->callActions('purchased', $purchase, [
    'user' => auth()->user(),
    'payment' => $payment,
]);

return response()->json([
    'success' => true,
    'purchase_id' => $purchase->id,
]);
```

## Shopping Cart Implementation

### Cart Item Model

```php
// app/Models/CartItem.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Blax\Shop\Models\Product;

class CartItem extends Model
{
    protected $fillable = [
        'cart_id',
        'product_id',
        'quantity',
        'price',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getSubtotal()
    {
        return $this->price * $this->quantity;
    }
}
```

### Cart Service

```php
// app/Services/CartService.php
namespace App\Services;

use App\Models\CartItem;
use Blax\Shop\Models\Product;

class CartService
{
    public function add(Product $product, int $quantity = 1)
    {
        $cart = $this->getCart();

        // Check stock
        if ($product->manage_stock && $product->getAvailableStock() < $quantity) {
            throw new \Exception('Insufficient stock');
        }

        // Check if item already in cart
        $cartItem = $cart->items()->where('product_id', $product->id)->first();

        if ($cartItem) {
            $newQuantity = $cartItem->quantity + $quantity;
            
            // Check stock for new quantity
            if ($product->manage_stock && $product->getAvailableStock() < $newQuantity) {
                throw new \Exception('Insufficient stock for requested quantity');
            }
            
            $cartItem->update(['quantity' => $newQuantity]);
        } else {
            $cartItem = $cart->items()->create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'price' => $product->getCurrentPrice(),
            ]);
        }

        return $cartItem;
    }

    public function update(CartItem $cartItem, int $quantity)
    {
        $product = $cartItem->product;

        // Check stock
        if ($product->manage_stock && $product->getAvailableStock() < $quantity) {
            throw new \Exception('Insufficient stock');
        }

        $cartItem->update(['quantity' => $quantity]);

        return $cartItem;
    }

    public function remove(CartItem $cartItem)
    {
        $cartItem->delete();
    }

    public function clear()
    {
        $cart = $this->getCart();
        $cart->items()->delete();
    }

    public function getTotal()
    {
        $cart = $this->getCart();
        return $cart->items->sum(fn($item) => $item->getSubtotal());
    }

    public function checkout()
    {
        $cart = $this->getCart();
        $items = $cart->items()->with('product')->get();

        // Reserve stock for all items
        $reservations = [];
        foreach ($items as $item) {
            $reservation = $item->product->reserveStock(
                $item->quantity,
                $cart,
                now()->addMinutes(15)
            );

            if (!$reservation) {
                // Rollback previous reservations
                foreach ($reservations as $res) {
                    $res->update(['status' => 'cancelled']);
                }
                throw new \Exception('Unable to reserve stock for: ' . $item->product->getLocalized('name'));
            }

            $reservations[] = $reservation;
        }

        return [
            'items' => $items,
            'reservations' => $reservations,
            'total' => $this->getTotal(),
        ];
    }

    protected function getCart()
    {
        // Implementation depends on your cart system
        // Could be session-based or user-based
        return auth()->user()->cart ?? session()->get('cart');
    }
}
```

### Cart Controller

```php
// app/Http/Controllers/CartController.php
namespace App\Http\Controllers;

use App\Services\CartService;
use Blax\Shop\Models\Product;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        protected CartService $cartService
    ) {}

    public function add(Request $request, Product $product)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        try {
            $cartItem = $this->cartService->add($product, $validated['quantity']);

            return response()->json([
                'success' => true,
                'cart_item' => $cartItem,
                'cart_total' => $this->cartService->getTotal(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function update(Request $request, $cartItemId)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $cartItem = CartItem::findOrFail($cartItemId);

        try {
            $this->cartService->update($cartItem, $validated['quantity']);

            return response()->json([
                'success' => true,
                'cart_total' => $this->cartService->getTotal(),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }

    public function remove($cartItemId)
    {
        $cartItem = CartItem::findOrFail($cartItemId);
        $this->cartService->remove($cartItem);

        return response()->json([
            'success' => true,
            'cart_total' => $this->cartService->getTotal(),
        ]);
    }

    public function checkout()
    {
        try {
            $checkoutData = $this->cartService->checkout();

            return response()->json([
                'success' => true,
                'checkout' => $checkoutData,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => $e->getMessage()
            ], 400);
        }
    }
}
```

## Handling Refunds

```php
public function refund($purchaseId)
{
    $purchase = ProductPurchase::findOrFail($purchaseId);
    $product = $purchase->product;

    // Process refund with payment processor
    $refund = PaymentService::refund($purchase->meta['payment_id']);

    if ($refund->success) {
        // Return stock
        $product->increaseStock($purchase->quantity);

        // Update purchase status
        $purchase->update([
            'status' => 'refunded',
            'meta' => array_merge($purchase->meta, [
                'refund_id' => $refund->id,
                'refunded_at' => now(),
            ]),
        ]);

        // Trigger refund actions
        $product->callActions('refunded', $purchase, [
            'refund' => $refund,
        ]);

        return response()->json(['success' => true]);
    }

    return response()->json(['error' => 'Refund failed'], 400);
}
```

## Product Actions on Purchase

Product actions allow you to execute custom logic when products are purchased:

```php
use Blax\Shop\Models\ProductAction;

// Create action to grant access to a course
ProductAction::create([
    'product_id' => $product->id,
    'action_type' => 'grant_access',
    'event' => 'purchased',
    'config' => [
        'resource_type' => 'course',
        'resource_id' => 123,
    ],
    'active' => true,
]);

// Action is automatically triggered when product is purchased
// Implement the action handler in your application
```

See [Product Actions documentation](docs/07-product-actions.md) for more details.
