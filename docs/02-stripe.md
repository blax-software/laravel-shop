# Stripe Integration

## Overview

The Laravel Shop package includes Stripe integration for:
- Syncing products from Stripe to your database
- Syncing prices from Stripe
- Associating products with Stripe product IDs
- Automatic price synchronization

## Configuration

### Environment Setup

Add to your `.env`:

```env
SHOP_STRIPE_ENABLED=true
SHOP_STRIPE_SYNC_PRICES=true
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
```

### Config File

Update `config/shop.php`:

```php
'stripe' => [
    'enabled' => env('SHOP_STRIPE_ENABLED', false),
    'sync_prices' => env('SHOP_STRIPE_SYNC_PRICES', true),
    'api_version' => '2023-10-16',
],
```

## Syncing Products from Stripe

### Sync Individual Product

```php
use Blax\Shop\Services\StripeService;
use Stripe\Product as StripeProduct;

// Get Stripe product
$stripeProduct = StripeProduct::retrieve('prod_xxxxx');

// Sync to local database
$product = StripeService::syncProductDown($stripeProduct);

// This creates/updates a Product with:
// - stripe_product_id
// - slug (generated from name)
// - type
// - virtual flag
// - status (based on Stripe active status)
// - name (localized)
// - features (if available)
```

### Sync Product Prices

```php
// Sync all prices for a product
StripeService::syncProductPricesDown($product);

// This creates/updates ProductPrice records with:
// - stripe_price_id
// - name (from Stripe nickname)
// - type (one_time or recurring)
// - unit_amount (price in cents)
// - currency
// - billing_scheme
// - interval (for recurring)
// - interval_count (for recurring)
// - trial_period_days (for recurring)
```

## Manual Product Creation with Stripe

### Create Product and Sync to Stripe

```php
use Blax\Shop\Models\Product;
use Stripe\Stripe;
use Stripe\Product as StripeProduct;
use Stripe\Price;

Stripe::setApiKey(config('services.stripe.secret'));

// Create local product first
$product = Product::create([
    'slug' => 'premium-plan',
    'status' => 'published',
]);

$product->setLocalized('name', 'Premium Plan', 'en');
$product->setLocalized('description', 'Access to all premium features', 'en');

// Create in Stripe
$stripeProduct = StripeProduct::create([
    'name' => $product->getLocalized('name'),
    'description' => $product->getLocalized('description'),
    'metadata' => [
        'product_id' => $product->id,
    ],
]);

// Save Stripe product ID
$product->update([
    'stripe_product_id' => $stripeProduct->id,
]);

// Create price in Stripe
$stripePrice = Price::create([
    'product' => $stripeProduct->id,
    'unit_amount' => 2999, // $29.99
    'currency' => 'usd',
    'recurring' => [
        'interval' => 'month',
    ],
]);

// Create local price
ProductPrice::create([
    'purchasable_type' => Product::class,
    'purchasable_id' => $product->id,
    'stripe_price_id' => $stripePrice->id,
    'currency' => 'usd',
    'unit_amount' => 2999,
    'type' => 'recurring',
    'interval' => 'month',
    'interval_count' => 1,
    'is_default' => true,
    'active' => true,
]);
```

## Automatic Syncing with Events

You can set up event listeners to automatically sync products to Stripe when they're created or updated.

### Create Event Listener

```php
// app/Listeners/SyncProductToStripe.php
namespace App\Listeners;

use Blax\Shop\Events\ProductCreated;
use Stripe\Stripe;
use Stripe\Product as StripeProduct;

class SyncProductToStripe
{
    public function handle(ProductCreated $event)
    {
        if (!config('shop.stripe.enabled')) {
            return;
        }

        $product = $event->product;

        // Skip if already has Stripe ID
        if ($product->stripe_product_id) {
            return;
        }

        Stripe::setApiKey(config('services.stripe.secret'));

        $stripeProduct = StripeProduct::create([
            'name' => $product->getLocalized('name') ?? $product->slug,
            'description' => $product->getLocalized('description'),
            'metadata' => [
                'product_id' => $product->id,
                'sku' => $product->sku,
            ],
        ]);

        $product->update([
            'stripe_product_id' => $stripeProduct->id,
        ]);
    }
}
```

### Register Event Listener

```php
// app/Providers/EventServiceProvider.php
use Blax\Shop\Events\ProductCreated;
use Blax\Shop\Events\ProductUpdated;
use App\Listeners\SyncProductToStripe;
use App\Listeners\UpdateStripeProduct;

protected $listen = [
    ProductCreated::class => [
        SyncProductToStripe::class,
    ],
    ProductUpdated::class => [
        UpdateStripeProduct::class,
    ],
];
```

## Working with Stripe Prices

### One-Time Prices

```php
use Stripe\Stripe;
use Stripe\Price;

Stripe::setApiKey(config('services.stripe.secret'));

// Create one-time price in Stripe
$stripePrice = Price::create([
    'product' => $product->stripe_product_id,
    'unit_amount' => 4999, // $49.99
    'currency' => 'usd',
]);

// Create local price
ProductPrice::create([
    'purchasable_type' => Product::class,
    'purchasable_id' => $product->id,
    'stripe_price_id' => $stripePrice->id,
    'currency' => 'usd',
    'unit_amount' => 4999,
    'type' => 'one_time',
    'is_default' => true,
    'active' => true,
]);
```

### Recurring Prices

```php
// Monthly subscription
$stripePrice = Price::create([
    'product' => $product->stripe_product_id,
    'unit_amount' => 999, // $9.99/month
    'currency' => 'usd',
    'recurring' => [
        'interval' => 'month',
        'interval_count' => 1,
        'trial_period_days' => 7,
    ],
]);

// Create local price
ProductPrice::create([
    'purchasable_type' => Product::class,
    'purchasable_id' => $product->id,
    'stripe_price_id' => $stripePrice->id,
    'currency' => 'usd',
    'unit_amount' => 999,
    'type' => 'recurring',
    'interval' => 'month',
    'interval_count' => 1,
    'trial_period_days' => 7,
    'is_default' => true,
    'active' => true,
]);
```

### Multiple Currency Prices

```php
// USD price
$usdPrice = Price::create([
    'product' => $product->stripe_product_id,
    'unit_amount' => 2999,
    'currency' => 'usd',
    'recurring' => ['interval' => 'month'],
]);

ProductPrice::create([
    'purchasable_type' => Product::class,
    'purchasable_id' => $product->id,
    'stripe_price_id' => $usdPrice->id,
    'currency' => 'usd',
    'unit_amount' => 2999,
    'type' => 'recurring',
    'interval' => 'month',
    'interval_count' => 1,
    'is_default' => true,
    'active' => true,
]);

// EUR price
$eurPrice = Price::create([
    'product' => $product->stripe_product_id,
    'unit_amount' => 2499,
    'currency' => 'eur',
    'recurring' => ['interval' => 'month'],
]);

ProductPrice::create([
    'purchasable_type' => Product::class,
    'purchasable_id' => $product->id,
    'stripe_price_id' => $eurPrice->id,
    'currency' => 'eur',
    'unit_amount' => 2499,
    'type' => 'recurring',
    'interval' => 'month',
    'interval_count' => 1,
    'is_default' => false,
    'active' => true,
]);
```

## Stripe Checkout Integration

### Create Checkout Session

```php
use Stripe\Stripe;
use Stripe\Checkout\Session;

Stripe::setApiKey(config('services.stripe.secret'));

Route::post('/checkout', function () {
    $user = auth()->user();
    $cartItems = $user->cartItems()->with('purchasable.prices')->get();

    // Build line items from cart
    $lineItems = $cartItems->map(function ($item) {
        $price = $item->purchasable->defaultPrice()->first();
        
        return [
            'price' => $price->stripe_price_id,
            'quantity' => $item->quantity,
        ];
    })->toArray();

    // Create checkout session
    $session = Session::create([
        'payment_method_types' => ['card'],
        'line_items' => $lineItems,
        'mode' => 'payment', // or 'subscription' for recurring
        'success_url' => route('checkout.success') . '?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => route('checkout.cancel'),
        'customer_email' => $user->email,
        'metadata' => [
            'user_id' => $user->id,
            'cart_id' => $user->currentCart()->id,
        ],
    ]);

    return redirect($session->url);
});
```

### Handle Successful Payment

```php
Route::get('/checkout/success', function (Request $request) {
    $sessionId = $request->get('session_id');
    
    Stripe::setApiKey(config('services.stripe.secret'));
    $session = Session::retrieve($sessionId);

    // Verify payment succeeded
    if ($session->payment_status === 'paid') {
        $user = auth()->user();
        
        // Convert cart to purchases
        $purchases = $user->checkoutCart();
        
        // Store charge ID
        foreach ($purchases as $purchase) {
            $purchase->update([
                'status' => 'completed',
                'charge_id' => $session->payment_intent,
                'amount_paid' => $session->amount_total / 100,
            ]);
        }

        return view('checkout.success', compact('purchases'));
    }

    return redirect()->route('cart.index')
        ->with('error', 'Payment was not successful');
});
```

## Webhook Handling

### Register Webhook Endpoint

```php
// routes/web.php
Route::post(
    '/stripe/webhook',
    [StripeWebhookController::class, 'handleWebhook']
)->withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class]);
```

### Handle Webhooks

```php
// app/Http/Controllers/StripeWebhookController.php
namespace App\Http\Controllers;

use Stripe\Stripe;
use Stripe\Webhook;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    public function handleWebhook(Request $request)
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent(
                $payload,
                $sigHeader,
                $webhookSecret
            );
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // Handle the event
        switch ($event->type) {
            case 'checkout.session.completed':
                $this->handleCheckoutComplete($event->data->object);
                break;
            
            case 'product.created':
            case 'product.updated':
                $this->handleProductUpdate($event->data->object);
                break;
            
            case 'price.created':
            case 'price.updated':
                $this->handlePriceUpdate($event->data->object);
                break;
        }

        return response()->json(['success' => true]);
    }

    protected function handleCheckoutComplete($session)
    {
        // Find purchase by session metadata
        $userId = $session->metadata->user_id ?? null;
        $cartId = $session->metadata->cart_id ?? null;

        if ($userId && $cartId) {
            // Mark purchases as completed
            ProductPurchase::where('cart_id', $cartId)
                ->update([
                    'status' => 'completed',
                    'charge_id' => $session->payment_intent,
                    'amount_paid' => $session->amount_total / 100,
                ]);
        }
    }

    protected function handleProductUpdate($stripeProduct)
    {
        StripeService::syncProductDown($stripeProduct);
    }

    protected function handlePriceUpdate($stripePrice)
    {
        // Update local price
        $price = ProductPrice::where('stripe_price_id', $stripePrice->id)->first();
        
        if ($price) {
            $price->update([
                'active' => $stripePrice->active,
                'unit_amount' => $stripePrice->unit_amount,
            ]);
        }
    }
}
```

## Best Practices

### 1. Always Use Stripe Price IDs

When integrating with Stripe Checkout or subscriptions, always use Stripe Price IDs:

```php
$price = $product->defaultPrice()->first();
$stripePriceId = $price->stripe_price_id;
```

### 2. Keep Prices in Sync

Use webhooks or scheduled commands to keep prices synchronized:

```php
// app/Console/Commands/SyncStripePrices.php
use Stripe\Stripe;
use Stripe\Product as StripeProduct;

Stripe::setApiKey(config('services.stripe.secret'));

Product::whereNotNull('stripe_product_id')->each(function ($product) {
    StripeService::syncProductPricesDown($product);
});
```

### 3. Store Stripe References

Always store Stripe IDs for traceability:

```php
$product->update([
    'stripe_product_id' => $stripeProduct->id,
]);

$price->update([
    'stripe_price_id' => $stripePrice->id,
]);

$purchase->update([
    'charge_id' => $paymentIntent->id,
]);
```

### 4. Handle Errors Gracefully

```php
try {
    $stripeProduct = StripeProduct::create([
        'name' => $product->getLocalized('name'),
    ]);
} catch (\Stripe\Exception\ApiErrorException $e) {
    \Log::error('Stripe API error: ' . $e->getMessage());
    // Handle error appropriately
}
```
