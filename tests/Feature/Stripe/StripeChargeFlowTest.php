<?php

namespace Blax\Shop\Tests\Feature\Stripe;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\PaymentProviderIdentity;
use Blax\Shop\Models\PaymentMethod as ShopPaymentMethod;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;
use Stripe\PaymentMethod;
use Stripe\Customer;
use Stripe\Stripe;
use PHPUnit\Framework\Attributes\Test;

class StripeChargeFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $key = env('STRIPE_KEY', 'your_stripe_test_key_here');
        $secret = env('STRIPE_SECRET', 'your_stripe_test_secret_here');

        if (
            $key === 'your_stripe_test_key_here' ||
            $secret === 'your_stripe_test_secret_here'
        ) {
            $this->markTestSkipped('Stripe test keys are not set in environment variables.');
        }

        // Set Stripe test keys
        config([
            'cashier.key' => $key,
            'cashier.secret' => $secret,
        ]);

        Stripe::setApiKey(config('cashier.secret'));
    }

    #[Test]
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

    #[Test]
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

    #[Test]
    public function user_has_stripe_account_trait()
    {
        $user = User::factory()->create();

        $this->assertTrue(
            in_array('Blax\Shop\Traits\HasStripeAccount', class_uses_recursive($user)),
            'User model should use HasStripeAccount trait'
        );
    }

    #[Test]
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

    #[Test]
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
        $pm = PaymentMethod::create([
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

    #[Test]
    public function payment_method_model_can_store_stripe_details()
    {
        $user = User::factory()->create();

        // Ensure Stripe customer exists
        $user->createOrGetStripeCustomer();

        // Create a Stripe payment method (test card)
        $pm = PaymentMethod::create([
            'type' => 'card',
            'card' => ['token' => 'tok_visa'],
        ]);

        // Attach to customer via Cashier to stay compatible
        $user->addPaymentMethod($pm->id);
        $user->updateDefaultPaymentMethod($pm->id);

        // Create provider identity for Stripe
        $identity = PaymentProviderIdentity::findOrCreateForCustomer(
            $user,
            'stripe',
            $user->stripe_id
        );

        // Retrieve full details from Stripe
        $spm = PaymentMethod::retrieve($pm->id);

        // Persist our provider-agnostic payment method record
        $method = ShopPaymentMethod::create([
            'payment_provider_identity_id' => $identity->id,
            'provider_payment_method_id' => $spm->id,
            'type' => $spm->type,
            'name' => null,
            'last_digits' => $spm->card->last4 ?? null,
            'last_alphanumeric' => null,
            'brand' => $spm->card->brand ?? null,
            'exp_month' => $spm->card->exp_month ?? null,
            'exp_year' => $spm->card->exp_year ?? null,
            'is_default' => true,
            'is_active' => true,
            'meta' => [],
        ]);

        $this->assertNotNull($method->id);
        $this->assertSame($pm->id, $method->provider_payment_method_id);
        $this->assertSame('card', $method->type);
        $this->assertNotEmpty($method->last_digits);
        $this->assertNotEmpty($method->brand);
        $this->assertTrue($method->is_default);

        // Ensure default relationship works on identity
        $default = $identity->defaultPaymentMethod()->first();
        $this->assertNotNull($default);
        $this->assertTrue($default->is_default);

        // Clean up: detach and delete Stripe customer
        $stripeCustomer = Customer::retrieve($user->stripe_id);
        $stripeCustomer->delete();
    }

    #[Test]
    public function can_switch_default_payment_method_for_provider_identity()
    {
        // No need to hit Stripe here; use local records
        $identity = PaymentProviderIdentity::factory()->stripe()->create();

        $first = ShopPaymentMethod::factory()->forProviderIdentity($identity)->create([
            'provider_payment_method_id' => 'pm_first',
            'type' => 'card',
            'is_default' => true,
        ]);

        $second = ShopPaymentMethod::factory()->forProviderIdentity($identity)->create([
            'provider_payment_method_id' => 'pm_second',
            'type' => 'card',
            'is_default' => false,
        ]);

        // Switch default to the second method
        $second->setAsDefault();

        $this->assertTrue($second->fresh()->is_default);
        $this->assertFalse($first->fresh()->is_default);
    }

    public function can_buy_and_charge_cart()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()
            ->withPrices(1, 500)
            ->withStocks(10)
            ->create();
        $product2 = Product::factory()
            ->withPrices(1, 200)
            ->withStocks(10)
            ->create();

        $user->addToCart($product1, 1);
        $user->addToCart($product2, 1);

        $cart = $user->getCart();

        $this->assertNotNull($cart);
        $this->assertCount(2, $cart->items);
        $this->assertEquals(700, $cart->getTotalAmount());

        // Proceed to checkout
        $cart = $cart->checkout();
    }
}
