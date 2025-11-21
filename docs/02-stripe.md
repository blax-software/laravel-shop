# Stripe Integration

## Configuration

### Enable Stripe

Add to your `.env`:

```env
SHOP_STRIPE_ENABLED=true
SHOP_STRIPE_SYNC_PRICES=true
STRIPE_KEY=your_stripe_key
STRIPE_SECRET=your_stripe_secret
```

Update `config/shop.php`:

```php
'stripe' => [
    'enabled' => env('SHOP_STRIPE_ENABLED', false),
    'sync_prices' => env('SHOP_STRIPE_SYNC_PRICES', true),
    'api_version' => '2023-10-16',
],
```

## Creating Products in Stripe

### Manual Stripe Product Creation

```php
use App\Services\StripeService;

$product = Product::create([
    'slug' => 'premium-plan',
    'price' => 29.99,
    'status' => 'published',
]);

// Create in Stripe
$stripeProduct = StripeService::createProduct($product);

// Store Stripe product ID
$product->update([
    'stripe_product_id' => $stripeProduct->id,
]);
```

### Automatic Sync

If you have event listeners set up, products can be automatically synced to Stripe:

```php
use Blax\Shop\Events\ProductCreated;
use App\Listeners\SyncProductToStripe;

// In EventServiceProvider
protected $listen = [
    ProductCreated::class => [
        SyncProductToStripe::class,
    ],
];

// Listener implementation
class SyncProductToStripe
{
    public function handle(ProductCreated $event)
    {
        if (config('shop.stripe.enabled')) {
            $stripeProduct = StripeService::createProduct($event->product);
            
            $event->product->update([
                'stripe_product_id' => $stripeProduct->id,
            ]);
        }
    }
}
```

## Syncing Prices to Stripe

### Create Stripe Prices

```php
use App\Services\StripeService;
use Blax\Shop\Models\ProductPrice;

// Sync default price
StripeService::syncProductPricesDown($product);

// Create additional currency prices
$eurPrice = ProductPrice::create([
    'product_id' => $product->id,
    'currency' => 'EUR',
    'price' => 24.99,
]);

// Create corresponding Stripe price
$stripePrice = StripeService::createPrice($product, $eurPrice);

$eurPrice->update([
    'stripe_price_id' => $stripePrice->id,
]);
```

## Creating Checkout Sessions

### One-time Payment

```php
use Stripe\Stripe;
use Stripe\Checkout\Session;

Stripe::setApiKey(config('services.stripe.secret'));

$product = Product::find($productId);

$session = Session::create([
    'payment_method_types' => ['card'],
    'line_items' => [[
        'price_data' => [
            'currency' => 'usd',
            'product_data' => [
                'name' => $product->getLocalized('name'),
                'description' => $product->getLocalized('short_description'),
            ],
            'unit_amount' => $product->getCurrentPrice() * 100, // Convert to cents
        ],
        'quantity' => 1,
    ]],
    'mode' => 'payment',
    'success_url' => route('checkout.success') . '?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => route('checkout.cancel'),
    'metadata' => [
        'product_id' => $product->id,
    ],
]);

return redirect($session->url);
```

### Using Stripe Price IDs

```php
// If you have synced prices
$priceId = $product->prices()
    ->where('currency', 'USD')
    ->where('is_default', true)
    ->first()
    ->stripe_price_id;

$session = Session::create([
    'payment_method_types' => ['card'],
    'line_items' => [[
        'price' => $priceId,
        'quantity' => 1,
    ]],
    'mode' => 'payment',
    'success_url' => route('checkout.success') . '?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => route('checkout.cancel'),
]);
```

## Handling Webhooks

### Register Webhook Endpoint

```php
// routes/api.php
use App\Http\Controllers\StripeWebhookController;

Route::post('/stripe/webhook', [StripeWebhookController::class, 'handle']);
```

### Webhook Controller

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Stripe\Webhook;
use Blax\Shop\Models\Product;

class StripeWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('services.stripe.webhook_secret');

        try {
            $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Invalid signature'], 400);
        }

        switch ($event->type) {
            case 'checkout.session.completed':
                $this->handleCheckoutCompleted($event->data->object);
                break;
                
            case 'payment_intent.succeeded':
                $this->handlePaymentSucceeded($event->data->object);
                break;
                
            case 'charge.refunded':
                $this->handleRefund($event->data->object);
                break;
        }

        return response()->json(['status' => 'success']);
    }

    protected function handleCheckoutCompleted($session)
    {
        $productId = $session->metadata->product_id ?? null;
        
        if (!$productId) {
            return;
        }

        $product = Product::find($productId);
        
        if (!$product) {
            return;
        }

        // Decrease stock
        $quantity = $session->metadata->quantity ?? 1;
        $product->decreaseStock($quantity);

        // Create purchase record
        $purchase = $product->purchases()->create([
            'purchasable_type' => get_class(auth()->user()),
            'purchasable_id' => $session->customer ?? $session->client_reference_id,
            'quantity' => $quantity,
            'status' => 'completed',
            'meta' => [
                'stripe_session_id' => $session->id,
                'stripe_payment_intent' => $session->payment_intent,
            ],
        ]);

        // Trigger product actions
        $product->callActions('purchased', $purchase, [
            'stripe_session' => $session,
        ]);
    }

    protected function handlePaymentSucceeded($paymentIntent)
    {
        // Handle successful payment
    }

    protected function handleRefund($charge)
    {
        // Handle refund
        $metadata = $charge->metadata;
        $productId = $metadata->product_id ?? null;

        if ($productId) {
            $product = Product::find($productId);
            $quantity = $metadata->quantity ?? 1;
            
            $product->increaseStock($quantity);
            
            // Trigger refund actions
            $product->callActions('refunded', null, [
                'stripe_charge' => $charge,
            ]);
        }
    }
}
```

### Configure Webhook Secret

Add to `.env`:

```env
STRIPE_WEBHOOK_SECRET=whsec_...
```

Get your webhook secret from Stripe Dashboard → Developers → Webhooks.

## Multi-Currency Support

### Create Prices for Multiple Currencies

```php
$product = Product::create([
    'slug' => 'premium-plan',
    'price' => 29.99, // USD base price
]);

// USD (default)
ProductPrice::create([
    'product_id' => $product->id,
    'currency' => 'USD',
    'price' => 29.99,
    'is_default' => true,
]);

// EUR
ProductPrice::create([
    'product_id' => $product->id,
    'currency' => 'EUR',
    'price' => 24.99,
]);

// GBP
ProductPrice::create([
    'product_id' => $product->id,
    'currency' => 'GBP',
    'price' => 21.99,
]);

// Sync all to Stripe
StripeService::syncProductPricesDown($product);
```

### Checkout with Currency Selection

```php
$currency = $request->input('currency', 'USD');

$price = $product->prices()
    ->where('currency', $currency)
    ->first();

$session = Session::create([
    'payment_method_types' => ['card'],
    'line_items' => [[
        'price' => $price->stripe_price_id,
        'quantity' => 1,
    ]],
    'mode' => 'payment',
    'success_url' => route('checkout.success'),
    'cancel_url' => route('checkout.cancel'),
]);
```

## Testing

### Use Stripe Test Mode

```env
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
```

### Test Card Numbers

