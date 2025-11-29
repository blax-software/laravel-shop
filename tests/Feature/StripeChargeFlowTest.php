<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;
use Laravel\Cashier\Cashier;
use Stripe\PaymentMethod;
use Stripe\Customer;
use Stripe\Stripe;

class StripeChargeFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        $key = env('STRIPE_KEY', 'your_stripe_test_key_here');
        $secret = env('STRIPE_SECRET', 'your_stripe_test_secret_here');

        if (strpos($key, 'your_stripe_test_key_here') >= 0 ||
            strpos($secret, 'your_stripe_test_secret_here') >= 0) {
            $this->markTestSkipped('Stripe test keys are not set in environment variables.');
        } 

        // Set Stripe test keys
        config([
            'cashier.key' => $key,
            'cashier.secret' => $secret,
        ]);

        Stripe::setApiKey(config('cashier.secret'));
    }

    /** @test */
    public function user_can_be_created()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
            'name' => 'Test User',
        ]);
        
        $this->assertInstanceOf(User::class, $user);
    }

    /** @test */
    public function user_can_have_stripe_account()
    {
        $user = User::factory()->create();

        // sync with stripe
        $user->createAsStripeCustomer();

        $this->assertNotNull($user->stripe_id);
        $this->assertStringStartsWith('cus_', $user->stripe_id);

        // Retrieve from Stripe to verify
        $stripeCustomer = Customer::retrieve($user->stripe_id);
        $this->assertEquals($user->email, $stripeCustomer->email);

        // Delete the customer from Stripe after test
        $stripeCustomer->delete();
    }

    /** @test */
    public function user_has_stripe_account_trait()
    {
        $user = User::factory()->create();

        $this->assertTrue(
            in_array('Blax\Shop\Traits\HasStripeAccount', class_uses_recursive($user)),
            'User model should use HasStripeAccount trait'
        );
    }

    /** @test */
    public function user_can_update_billing_address()
    {
        $user = User::factory()->create();

        // sync with stripe
        $user->createAsStripeCustomer();

        $payload = [
            'name' => $user->stripeName(),
            'email' => $user->stripeEmail(),
            'phone' => $user->stripePhone(),
            'address' => [
                'line1' => '123 Test St',
                'line2' => 'Apt 4',
                'city' => 'Testville',
                'state' => 'TS',
                'postal_code' => '12345',
                'country' => 'US',
            ],
            'preferred_locales' => $user->stripePreferredLocales(),
            'metadata' => $user->stripeMetadata(),
        ];

        $user->updateStripeCustomer($payload);

        $stripeCustomer = Customer::retrieve($user->stripe_id);

        $this->assertEquals($payload['email'], $stripeCustomer->email);
        $this->assertEquals($payload['name'], $stripeCustomer->name);
        $this->assertEquals($payload['phone'], $stripeCustomer->phone);
        $this->assertEquals($payload['address'], $stripeCustomer->address->toArray());
        $this->assertEquals($payload['preferred_locales'], $stripeCustomer->preferred_locales);
        $this->assertEquals($payload['metadata'], $stripeCustomer->metadata->toArray());

        // Clean up
        $stripeCustomer->delete();
    }

    /** @test */
    public function user_can_checkout_with_stripe()
    {
        $user = User::factory()->create();
        $product = Product::factory()
            ->withPrices()
            ->withStocks(100)
            ->create();

        $user->addToCart($product->prices()->first(), quantity: 2);

        $user->createOrGetStripeCustomer();

        // Attach test payment method
        $pm = \Stripe\PaymentMethod::create([
            'type' => 'card',
            'card' => ['token' => 'tok_visa'],
        ]);

        $user->addPaymentMethod($pm->id);
        $user->updateDefaultPaymentMethod($pm->id);

        // 

        // Perform charge
        $charge = $user->charge(1000, $pm->id, [
            'currency' => 'usd',
            'description' => 'Test Charge',
            'payment_method_types' => ['card'],
        ]);

        $this->assertSame('succeeded', $charge->status);
    }
}