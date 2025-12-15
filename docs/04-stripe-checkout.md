# Stripe Checkout Integration

This document describes the Stripe Checkout integration for the Laravel Shop package.

## Configuration

### Enable Stripe

Add the following to your `.env` file:

```env
SHOP_STRIPE_ENABLED=true
STRIPE_KEY=pk_test_...
STRIPE_SECRET=sk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

### Configure Services

In your `config/services.php`:

```php
'stripe' => [
    'key' => env('STRIPE_KEY'),
    'secret' => env('STRIPE_SECRET'),
    'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
],
```

## Price Configuration

All products and product prices must have a `stripe_price_id` before they can be used in Stripe Checkout.

### Setting Stripe Price ID

```php
$product = Product::find($id);
$price = $product->defaultPrice()->first();
$price->update(['stripe_price_id' => 'price_...']);
```

## Creating a Checkout Session

### API Endpoint

```
POST /api/shop/stripe/checkout/{cartId}
```

### Example Request

```bash
curl -X POST https://your-domain.com/api/shop/stripe/checkout/cart-uuid-here \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Example Response

```json
{
  "session_id": "cs_test_...",
  "url": "https://checkout.stripe.com/c/pay/cs_test_..."
}
```

Redirect the user to the `url` to complete payment.

## Handling Success/Cancel

### Success URL

```
GET /api/shop/stripe/success?session_id={SESSION_ID}&cart_id={CART_ID}
```

When payment is successful:
- Cart status is updated to `CONVERTED`
- Cart's `converted_at` is set
- ProductPurchases are updated with:
  - `status` → `COMPLETED`
  - `charge_id` → Stripe Payment Intent ID
  - `amount_paid` → Amount from Stripe (in dollars, converted from cents)

### Cancel URL

```
GET /api/shop/stripe/cancel?cart_id={CART_ID}
```

When payment is cancelled, the cart remains in `ACTIVE` status and the user can try again.

## Webhook Handler

### Webhook URL

```
POST /api/shop/stripe/webhook
```

### Supported Events

The webhook handler processes the following Stripe events:

- `checkout.session.completed` - Updates cart to converted, updates purchases
- `checkout.session.async_payment_succeeded` - Same as completed
- `checkout.session.async_payment_failed` - Logs failure
- `charge.succeeded` - Updates purchases with charge info
- `charge.failed` - Marks purchases as `FAILED`
- `payment_intent.succeeded` - Updates purchases
- `payment_intent.payment_failed` - Marks purchases as `FAILED`

### Configuring Webhook in Stripe

1. Go to Stripe Dashboard → Developers → Webhooks
2. Click "Add endpoint"
3. Enter your webhook URL: `https://your-domain.com/api/shop/stripe/webhook`
4. Select events to listen to (or select "receive all events")
5. Copy the signing secret and add it to your `.env` as `STRIPE_WEBHOOK_SECRET`

## Route Customization

### Disabling Stripe Routes

The Stripe routes are automatically registered if:
- `config('shop.stripe.enabled')` is `true`
- Routes haven't already been defined in your Laravel app

You can manually define routes in your application's route files to override the default behavior.

### Custom Routes Example

```php
// routes/web.php or routes/api.php

use Blax\Shop\Http\Controllers\StripeCheckoutController;
use Blax\Shop\Http\Controllers\StripeWebhookController;

Route::post('custom/stripe/checkout/{cartId}', [StripeCheckoutController::class, 'createCheckoutSession'])
    ->name('shop.stripe.checkout');

Route::get('custom/stripe/success', [StripeCheckoutController::class, 'success'])
    ->name('shop.stripe.success');

Route::get('custom/stripe/cancel', [StripeCheckoutController::class, 'cancel'])
    ->name('shop.stripe.cancel');

Route::post('custom/stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
    ->name('shop.stripe.webhook');
```

## ProductPurchase Updates

The webhook handler automatically updates ProductPurchase records with charge information if the columns exist:

- `charge_id` - Stripe Payment Intent ID
- `amount_paid` - Amount paid in dollars

These fields are automatically populated from the fillable array on the ProductPurchase model.

## Error Handling

### Missing Stripe Price ID

If a cart item doesn't have a `stripe_price_id`, the checkout session creation will fail with:

```json
{
  "error": "Item 'Product Name' is missing a Stripe price ID"
}
```

### Stripe API Errors

All Stripe API errors are caught and logged. The response will include:

```json
{
  "error": "Failed to create checkout session: [error message]"
}
```

## Pool Products with MayBePoolProduct Trait

Pool-related methods have been moved to the `MayBePoolProduct` trait to keep the Product model cleaner.

### Using Pool Methods

All pool methods work the same way, they're just now in a trait:

```php
$pool = Product::find($poolId);

// Check if pool
$pool->isPool(); // returns bool

// Get available quantity
$pool->getAvailableQuantity($from, $until);

// Get pool max quantity
$pool->getPoolMaxQuantity($from, $until);

// Claim pool stock
$pool->claimPoolStock($quantity, $reference, $from, $until);

// Release pool stock
$pool->releasePoolStock($reference);

// Pricing methods
$pool->getLowestPoolPrice();
$pool->getHighestPoolPrice();
$pool->getPoolPriceRange();
$pool->setPoolPricingStrategy('lowest'); // 'lowest', 'highest', 'average'

// Validation
$pool->validatePoolConfiguration();

// Availability methods
$pool->getPoolAvailabilityCalendar($start, $end, $quantity);
$pool->getSingleItemsAvailability($from, $until);
$pool->isPoolAvailable($from, $until, $quantity);
$pool->getPoolAvailablePeriods($start, $end, $quantity, $minDays);
```

### Benefits of Trait

- Cleaner Product model
- Pool functionality can be used by other models in the future
- Better separation of concerns
- Easier testing and maintenance

## Example Usage Flow

```php
// 1. Create a product with Stripe price
$product = Product::create([...]);
$price = ProductPrice::create([
    'purchasable_id' => $product->id,
    'purchasable_type' => Product::class,
    'stripe_price_id' => 'price_1234567890',
    'unit_amount' => 2000, // $20.00 in cents
    'is_default' => true,
]);

// 2. Add to cart
$cart = auth()->user()->currentCart();
$cart->addToCart($product, 1);

// 3. Create Stripe checkout session
$response = Http::post('/api/shop/stripe/checkout/' . $cart->id);
$checkoutUrl = $response->json('url');

// 4. Redirect user to Stripe
return redirect($checkoutUrl);

// 5. Stripe redirects back to success URL
// 6. Webhook processes payment
// 7. Cart is converted, purchases are completed
```

## Testing

Mock Stripe in your tests:

```php
use Stripe\Stripe;
use Stripe\Checkout\Session as StripeSession;

// Mock Stripe session creation
Stripe::setApiKey('sk_test_fake');
StripeSession::create([...]); // Use test mode
```

## Security Considerations

1. **Always verify webhook signatures** - Set `STRIPE_WEBHOOK_SECRET` in production
2. **Use HTTPS** - Stripe requires HTTPS for webhooks
3. **Validate cart ownership** - Ensure users can only checkout their own carts
4. **Test mode first** - Use Stripe test keys during development
5. **Monitor webhooks** - Check Stripe Dashboard for webhook delivery issues
