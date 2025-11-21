# Subscriptions

## Creating Subscription Products

### Basic Subscription Product

```php
use Blax\Shop\Models\Product;

$subscription = Product::create([
    'slug' => 'monthly-premium',
    'sku' => 'SUB-PREM-M',
    'type' => 'simple',
    'price' => 29.99,
    'virtual' => true,
    'downloadable' => false,
    'manage_stock' => false, // Subscriptions don't need stock management
    'status' => 'published',
    'meta' => [
        'billing_period' => 'month',
        'billing_interval' => 1,
        'trial_days' => 7,
    ],
]);

$subscription->setLocalized('name', 'Premium Monthly Subscription', 'en');
$subscription->setLocalized('description', 'Access to all premium features', 'en');
```

### Subscription Tiers

```php
// Basic
$basic = Product::create([
    'slug' => 'basic-monthly',
    'price' => 9.99,
    'virtual' => true,
    'meta' => [
        'billing_period' => 'month',
        'features' => ['feature_1', 'feature_2'],
    ],
]);

// Pro
$pro = Product::create([
    'slug' => 'pro-monthly',
    'price' => 29.99,
    'virtual' => true,
    'meta' => [
        'billing_period' => 'month',
        'features' => ['feature_1', 'feature_2', 'feature_3', 'feature_4'],
    ],
]);

// Enterprise
$enterprise = Product::create([
    'slug' => 'enterprise-monthly',
    'price' => 99.99,
    'virtual' => true,
    'meta' => [
        'billing_period' => 'month',
        'features' => ['all_features', 'priority_support', 'custom_branding'],
    ],
]);
```

## Stripe Subscription Integration

### Create Subscription Prices in Stripe

```php
use Stripe\Stripe;
use Stripe\Product as StripeProduct;
use Stripe\Price;

Stripe::setApiKey(config('services.stripe.secret'));

// Create Stripe product
$stripeProduct = StripeProduct::create([
    'name' => $subscription->getLocalized('name'),
    'description' => $subscription->getLocalized('description'),
    'metadata' => [
        'product_id' => $subscription->id,
    ],
]);

// Create recurring price
$price = Price::create([
    'product' => $stripeProduct->id,
    'unit_amount' => $subscription->price * 100,
    'currency' => 'usd',
    'recurring' => [
        'interval' => 'month',
        'interval_count' => 1,
    ],
]);

// Save Stripe IDs
$subscription->update([
    'stripe_product_id' => $stripeProduct->id,
]);

ProductPrice::create([
    'product_id' => $subscription->id,
    'currency' => 'USD',
    'price' => $subscription->price,
    'stripe_price_id' => $price->id,
    'is_default' => true,
]);
```

### Create Subscription Checkout

```php
use Stripe\Checkout\Session;

$subscription = Product::find($subscriptionId);
$priceId = $subscription->prices()
    ->where('currency', 'USD')
    ->first()
    ->stripe_price_id;

$session = Session::create([
    'payment_method_types' => ['card'],
    'line_items' => [[
        'price' => $priceId,
        'quantity' => 1,
    ]],
    'mode' => 'subscription',
    'success_url' => route('subscription.success') . '?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => route('subscription.cancel'),
    'client_reference_id' => auth()->id(),
    'customer_email' => auth()->user()->email,
    'subscription_data' => [
        'trial_period_days' => $subscription->meta['trial_days'] ?? null,
        'metadata' => [
            'product_id' => $subscription->id,
            'user_id' => auth()->id(),
        ],
    ],
]);

return redirect($session->url);
```

## Handling Subscription Webhooks

### Webhook Controller

```php
namespace App\Http\Controllers;

use Stripe\Webhook;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPurchase;

class StripeSubscriptionWebhookController extends Controller
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
            case 'customer.subscription.created':
                $this->handleSubscriptionCreated($event->data->object);
                break;
                
            case 'customer.subscription.updated':
                $this->handleSubscriptionUpdated($event->data->object);
                break;
                
            case 'customer.subscription.deleted':
                $this->handleSubscriptionCancelled($event->data->object);
                break;
                
            case 'invoice.payment_succeeded':
                $this->handlePaymentSucceeded($event->data->object);
                break;
                
            case 'invoice.payment_failed':
                $this->handlePaymentFailed($event->data->object);
                break;
        }

        return response()->json(['status' => 'success']);
    }

    protected function handleSubscriptionCreated($subscription)
    {
        $productId = $subscription->metadata->product_id ?? null;
        $userId = $subscription->metadata->user_id ?? null;

        if (!$productId || !$userId) {
            return;
        }

        $product = Product::find($productId);
        $user = User::find($userId);

        // Create purchase record
        $purchase = ProductPurchase::create([
            'product_id' => $product->id,
            'purchasable_type' => get_class($user),
            'purchasable_id' => $user->id,
            'quantity' => 1,
            'status' => $subscription->status,
            'meta' => [
                'stripe_subscription_id' => $subscription->id,
                'stripe_customer_id' => $subscription->customer,
                'current_period_end' => $subscription->current_period_end,
                'trial_end' => $subscription->trial_end,
            ],
        ]);

        // Trigger subscription started actions
        $product->callActions('subscription_started', $purchase, [
            'subscription' => $subscription,
            'user' => $user,
        ]);

        // Grant access
        $user->subscriptions()->create([
            'product_id' => $product->id,
            'stripe_subscription_id' => $subscription->id,
            'status' => 'active',
            'trial_ends_at' => $subscription->trial_end ? 
                Carbon::createFromTimestamp($subscription->trial_end) : null,
            'ends_at' => Carbon::createFromTimestamp($subscription->current_period_end),
        ]);
    }

    protected function handleSubscriptionUpdated($subscription)
    {
        $purchase = ProductPurchase::where('meta->stripe_subscription_id', $subscription->id)->first();

        if ($purchase) {
            $purchase->update([
                'status' => $subscription->status,
                'meta' => array_merge($purchase->meta, [
                    'current_period_end' => $subscription->current_period_end,
                ]),
            ]);

            // Update user subscription
            $userSubscription = $purchase->purchasable->subscriptions()
                ->where('stripe_subscription_id', $subscription->id)
                ->first();

            if ($userSubscription) {
                $userSubscription->update([
                    'status' => $subscription->status === 'active' ? 'active' : 'inactive',
                    'ends_at' => Carbon::createFromTimestamp($subscription->current_period_end),
                ]);
            }
        }
    }

    protected function handleSubscriptionCancelled($subscription)
    {
        $purchase = ProductPurchase::where('meta->stripe_subscription_id', $subscription->id)->first();

        if ($purchase) {
            $purchase->update([
                'status' => 'cancelled',
            ]);

            // Revoke access
            $userSubscription = $purchase->purchasable->subscriptions()
                ->where('stripe_subscription_id', $subscription->id)
                ->first();

            if ($userSubscription) {
                $userSubscription->update([
                    'status' => 'cancelled',
                    'ends_at' => now(),
                ]);
            }

            // Trigger cancellation actions
            $purchase->product->callActions('subscription_cancelled', $purchase, [
                'subscription' => $subscription,
            ]);
        }
    }

    protected function handlePaymentSucceeded($invoice)
    {
        $subscriptionId = $invoice->subscription;
        $purchase = ProductPurchase::where('meta->stripe_subscription_id', $subscriptionId)->first();

        if ($purchase) {
            // Trigger renewal actions
            $purchase->product->callActions('subscription_renewed', $purchase, [
                'invoice' => $invoice,
            ]);
        }
    }

    protected function handlePaymentFailed($invoice)
    {
        $subscriptionId = $invoice->subscription;
        $purchase = ProductPurchase::where('meta->stripe_subscription_id', $subscriptionId)->first();

        if ($purchase) {
            // Trigger payment failed actions
            $purchase->product->callActions('subscription_payment_failed', $purchase, [
                'invoice' => $invoice,
            ]);
        }
    }
}
```

## User Subscription Model

```php
// app/Models/UserSubscription.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Blax\Shop\Models\Product;

class UserSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'stripe_subscription_id',
        'status',
        'trial_ends_at',
        'ends_at',
    ];

    protected $casts = [
        'trial_ends_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function isActive()
    {
        return $this->status === 'active' && 
               (!$this->ends_at || $this->ends_at->isFuture());
    }

    public function onTrial()
    {
        return $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function cancel()
    {
        if (!$this->stripe_subscription_id) {
            return false;
        }

        try {
            $stripe = new \Stripe\StripeClient(config('services.stripe.secret'));
            $stripe->subscriptions->cancel($this->stripe_subscription_id);

            $this->update([
                'status' => 'cancelled',
                'ends_at' => now(),
            ]);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
```

## Checking Subscription Access

```php
// Add to User model
public function subscriptions()
{
    return $this->hasMany(UserSubscription::class);
}

public function hasActiveSubscription($productSlug = null)
{
    $query = $this->subscriptions()->where('status', 'active');

    if ($productSlug) {
        $query->whereHas('product', function ($q) use ($productSlug) {
            $q->where('slug', $productSlug);
        });
    }

    return $query->where(function ($q) {
            $q->whereNull('ends_at')
                ->orWhere('ends_at', '>', now());
        })
        ->exists();
}

// Usage in controllers/middleware
if (!auth()->user()->hasActiveSubscription('premium-monthly')) {
    abort(403, 'Active subscription required');
}
```

## Subscription Management Routes

```php
// routes/web.php
Route::middleware('auth')->group(function () {
    Route::get('/subscriptions', [SubscriptionController::class, 'index']);
    Route::post('/subscriptions/{product}/subscribe', [SubscriptionController::class, 'subscribe']);
    Route::post('/subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel']);
    Route::post('/subscriptions/{subscription}/resume', [SubscriptionController::class, 'resume']);
});
```

## Product Actions for Subscriptions

```php
use Blax\Shop\Models\ProductAction;

// Grant role on subscription
ProductAction::create([
    'product_id' => $subscription->id,
    'action_type' => 'grant_role',
    'event' => 'subscription_started',
    'config' => [
        'role' => 'premium_member',
    ],
    'active' => true,
]);

// Revoke role on cancellation
ProductAction::create([
    'product_id' => $subscription->id,
    'action_type' => 'revoke_role',
    'event' => 'subscription_cancelled',
    'config' => [
        'role' => 'premium_member',
    ],
    'active' => true,
]);
```

## Annual Subscriptions with Discount

```php
$annual = Product::create([
    'slug' => 'premium-annual',
    'price' => 299.99, // Save $60 vs monthly
    'regular_price' => 359.88,
    'sale_price' => 299.99,
    'virtual' => true,
    'meta' => [
        'billing_period' => 'year',
        'billing_interval' => 1,
        'savings' => 59.89,
    ],
]);

// Create Stripe price
$price = Price::create([
    'product' => $stripeProduct->id,
    'unit_amount' => 29999,
    'currency' => 'usd',
    'recurring' => [
        'interval' => 'year',
        'interval_count' => 1,
    ],
]);
```
