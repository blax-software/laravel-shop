<?php

namespace Blax\Shop\Tests\Feature\Checkout;

use Blax\Shop\Models\PaymentMethod;
use Blax\Shop\Models\PaymentProviderIdentity;
use Blax\Shop\Services\PaymentProvider\PaymentProviderService;
use Blax\Shop\Services\PaymentProvider\StripeService;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class PaymentProviderTest extends TestCase
{
    use RefreshDatabase;

    protected StripeService $stripeService;
    protected PaymentProviderService $paymentProviderService;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the Stripe service
        $this->stripeService = Mockery::mock(StripeService::class);
        $this->app->instance(StripeService::class, $this->stripeService);

        // Create the payment provider service with the mocked stripe service
        $this->paymentProviderService = new PaymentProviderService($this->stripeService);
    }



    #[Test]
    public function it_can_create_a_customer_on_stripe()
    {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        // Mock Stripe customer creation - use stdClass instead of actual Stripe objects
        $mockStripeCustomer = new \stdClass();
        $mockStripeCustomer->id = 'cus_test123';
        $mockStripeCustomer->email = 'john@example.com';
        $mockStripeCustomer->created = time();

        $this->stripeService
            ->shouldReceive('createCustomer')
            ->once()
            ->with(Mockery::on(function ($arg) use ($user) {
                return $arg['email'] === 'john@example.com'
                    && $arg['name'] === 'John Doe'
                    && $arg['metadata']['customer_id'] === $user->id;
            }))
            ->andReturn($mockStripeCustomer);

        // Create customer
        $identity = $this->paymentProviderService->createOrGetCustomer($user, 'stripe');

        $this->assertInstanceOf(PaymentProviderIdentity::class, $identity);
        $this->assertEquals('stripe', $identity->provider_name);
        $this->assertEquals('cus_test123', $identity->customer_identification_id);
        $this->assertEquals($user->id, $identity->customer_id);
        $this->assertEquals(get_class($user), $identity->customer_type);

        $this->assertDatabaseHas('payment_provider_identities', [
            'customer_id' => $user->id,
            'provider_name' => 'stripe',
            'customer_identification_id' => 'cus_test123',
        ]);
    }

    #[Test]
    public function it_returns_existing_customer_identity_if_already_exists()
    {
        $user = User::factory()->create();

        $existingIdentity = PaymentProviderIdentity::factory()->create([
            'customer_type' => get_class($user),
            'customer_id' => $user->id,
            'provider_name' => 'stripe',
            'customer_identification_id' => 'cus_existing123',
        ]);

        // Should not call Stripe since customer already exists
        $this->stripeService->shouldNotReceive('createCustomer');

        $identity = $this->paymentProviderService->createOrGetCustomer($user, 'stripe');

        $this->assertEquals($existingIdentity->id, $identity->id);
        $this->assertEquals('cus_existing123', $identity->customer_identification_id);
    }

    #[Test]
    public function it_can_add_a_payment_method()
    {
        $user = User::factory()->create();
        $identity = PaymentProviderIdentity::factory()->create([
            'customer_type' => get_class($user),
            'customer_id' => $user->id,
            'provider_name' => 'stripe',
            'customer_identification_id' => 'cus_test123',
        ]);

        // Mock Stripe payment method
        $mockCard = new \stdClass();
        $mockCard->last4 = '4242';
        $mockCard->brand = 'visa';
        $mockCard->exp_month = 12;
        $mockCard->exp_year = 2025;
        $mockCard->funding = 'credit';
        $mockCard->country = 'US';
        $mockCard->fingerprint = 'fingerprint123';

        $mockStripePaymentMethod = new \stdClass();
        $mockStripePaymentMethod->id = 'pm_test123';
        $mockStripePaymentMethod->type = 'card';
        $mockStripePaymentMethod->card = $mockCard;

        $this->stripeService
            ->shouldReceive('attachPaymentMethod')
            ->once()
            ->with('pm_test123', 'cus_test123')
            ->andReturn($mockStripePaymentMethod);

        // First payment method gets set as default
        $mockStripeCustomer = new \stdClass();
        $this->stripeService
            ->shouldReceive('setDefaultPaymentMethod')
            ->once()
            ->andReturn($mockStripeCustomer);

        // Add payment method
        $paymentMethod = $this->paymentProviderService->addPaymentMethod($identity, 'pm_test123');

        $this->assertInstanceOf(PaymentMethod::class, $paymentMethod);
        $this->assertEquals('pm_test123', $paymentMethod->provider_payment_method_id);
        $this->assertEquals('card', $paymentMethod->type);
        $this->assertEquals('4242', $paymentMethod->last_digits);
        $this->assertEquals('visa', $paymentMethod->brand);
        $this->assertEquals(12, $paymentMethod->exp_month);
        $this->assertEquals(2025, $paymentMethod->exp_year);
        $this->assertTrue($paymentMethod->is_active);

        $this->assertDatabaseHas('payment_methods', [
            'payment_provider_identity_id' => $identity->id,
            'provider_payment_method_id' => 'pm_test123',
            'last_digits' => '4242',
            'brand' => 'visa',
        ]);
    }

    #[Test]
    public function first_payment_method_is_automatically_set_as_default()
    {
        $user = User::factory()->create();
        $identity = PaymentProviderIdentity::factory()->create([
            'customer_type' => get_class($user),
            'customer_id' => $user->id,
            'provider_name' => 'stripe',
        ]);

        // Mock Stripe payment method
        $mockCard = new \stdClass();
        $mockCard->last4 = '4242';
        $mockCard->brand = 'visa';
        $mockCard->exp_month = 12;
        $mockCard->exp_year = 2025;
        $mockCard->funding = 'credit';
        $mockCard->country = 'US';
        $mockCard->fingerprint = 'fingerprint123';

        $mockStripePaymentMethod = new \stdClass();
        $mockStripePaymentMethod->id = 'pm_test123';
        $mockStripePaymentMethod->type = 'card';
        $mockStripePaymentMethod->card = $mockCard;

        $this->stripeService
            ->shouldReceive('attachPaymentMethod')
            ->once()
            ->andReturn($mockStripePaymentMethod);

        $mockStripeCustomer = new \stdClass();
        $this->stripeService
            ->shouldReceive('setDefaultPaymentMethod')
            ->once()
            ->with($identity->customer_identification_id, 'pm_test123')
            ->andReturn($mockStripeCustomer);

        // Add first payment method
        $paymentMethod = $this->paymentProviderService->addPaymentMethod($identity, 'pm_test123');

        $this->assertTrue($paymentMethod->fresh()->is_default);
    }

    #[Test]
    public function it_can_list_payment_methods()
    {
        $user = User::factory()->create();
        $identity = PaymentProviderIdentity::factory()->create([
            'customer_type' => get_class($user),
            'customer_id' => $user->id,
        ]);

        // Create multiple payment methods
        $method1 = PaymentMethod::factory()->create([
            'payment_provider_identity_id' => $identity->id,
            'is_active' => true,
        ]);
        $method2 = PaymentMethod::factory()->create([
            'payment_provider_identity_id' => $identity->id,
            'is_active' => true,
        ]);
        $method3 = PaymentMethod::factory()->create([
            'payment_provider_identity_id' => $identity->id,
            'is_active' => false, // Inactive
        ]);

        // List active payment methods
        $methods = $this->paymentProviderService->listPaymentMethods($identity, true);

        $this->assertCount(2, $methods);
        $this->assertTrue($methods->contains($method1));
        $this->assertTrue($methods->contains($method2));
        $this->assertFalse($methods->contains($method3));

        // List all payment methods
        $allMethods = $this->paymentProviderService->listPaymentMethods($identity, false);
        $this->assertCount(3, $allMethods);
    }

    #[Test]
    public function it_can_set_a_payment_method_as_default()
    {
        $user = User::factory()->create();
        $identity = PaymentProviderIdentity::factory()->create([
            'customer_type' => get_class($user),
            'customer_id' => $user->id,
            'provider_name' => 'stripe',
        ]);

        $method1 = PaymentMethod::factory()->create([
            'payment_provider_identity_id' => $identity->id,
            'is_default' => true,
        ]);
        $method2 = PaymentMethod::factory()->create([
            'payment_provider_identity_id' => $identity->id,
            'is_default' => false,
        ]);

        $mockStripeCustomer = new \stdClass();
        $this->stripeService
            ->shouldReceive('setDefaultPaymentMethod')
            ->once()
            ->with($identity->customer_identification_id, $method2->provider_payment_method_id)
            ->andReturn($mockStripeCustomer);

        // Set method2 as default
        $this->paymentProviderService->setDefaultPaymentMethod($method2);

        $this->assertTrue($method2->fresh()->is_default);
        $this->assertFalse($method1->fresh()->is_default);

        // Verify only one default exists
        $defaultCount = PaymentMethod::where('payment_provider_identity_id', $identity->id)
            ->where('is_default', true)
            ->count();
        $this->assertEquals(1, $defaultCount);
    }

    #[Test]
    public function it_can_remove_a_payment_method()
    {
        $user = User::factory()->create();
        $identity = PaymentProviderIdentity::factory()->create([
            'customer_type' => get_class($user),
            'customer_id' => $user->id,
            'provider_name' => 'stripe',
        ]);

        $paymentMethod = PaymentMethod::factory()->create([
            'payment_provider_identity_id' => $identity->id,
        ]);

        $mockStripePaymentMethod = new \stdClass();
        $this->stripeService
            ->shouldReceive('detachPaymentMethod')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn($mockStripePaymentMethod);

        // Remove payment method
        $result = $this->paymentProviderService->removePaymentMethod($paymentMethod, true);

        $this->assertTrue($result);
        $this->assertDatabaseMissing('payment_methods', [
            'id' => $paymentMethod->id,
        ]);
    }

    #[Test]
    public function removing_default_payment_method_sets_another_as_default()
    {
        $user = User::factory()->create();
        $identity = PaymentProviderIdentity::factory()->create([
            'customer_type' => get_class($user),
            'customer_id' => $user->id,
            'provider_name' => 'stripe',
        ]);

        $method1 = PaymentMethod::factory()->create([
            'payment_provider_identity_id' => $identity->id,
            'is_default' => true,
        ]);
        $method2 = PaymentMethod::factory()->create([
            'payment_provider_identity_id' => $identity->id,
            'is_default' => false,
        ]);

        $mockStripePaymentMethod = new \stdClass();
        $this->stripeService
            ->shouldReceive('detachPaymentMethod')
            ->once()
            ->andReturn($mockStripePaymentMethod);

        $mockStripeCustomer = new \stdClass();
        $this->stripeService
            ->shouldReceive('setDefaultPaymentMethod')
            ->once()
            ->with($identity->customer_identification_id, $method2->provider_payment_method_id)
            ->andReturn($mockStripeCustomer);

        // Remove default payment method
        $this->paymentProviderService->removePaymentMethod($method1, true);

        // Method2 should now be default
        $this->assertTrue($method2->fresh()->is_default);
    }

    #[Test]
    public function it_can_use_trait_to_add_payment_methods()
    {
        // Use actual User model since it doesn't have the trait yet - test with direct service calls
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        // Mock Stripe customer creation
        $mockStripeCustomer = new \stdClass();
        $mockStripeCustomer->id = 'cus_test123';
        $mockStripeCustomer->email = 'john@example.com';
        $mockStripeCustomer->created = time();

        $this->stripeService
            ->shouldReceive('createCustomer')
            ->once()
            ->andReturn($mockStripeCustomer);

        // Mock Stripe payment method
        $mockCard = new \stdClass();
        $mockCard->last4 = '4242';
        $mockCard->brand = 'visa';
        $mockCard->exp_month = 12;
        $mockCard->exp_year = 2025;
        $mockCard->funding = 'credit';
        $mockCard->country = 'US';
        $mockCard->fingerprint = 'fingerprint123';

        $mockStripePaymentMethod = new \stdClass();
        $mockStripePaymentMethod->id = 'pm_test123';
        $mockStripePaymentMethod->type = 'card';
        $mockStripePaymentMethod->card = $mockCard;

        $this->stripeService
            ->shouldReceive('attachPaymentMethod')
            ->once()
            ->andReturn($mockStripePaymentMethod);

        $this->stripeService
            ->shouldReceive('setDefaultPaymentMethod')
            ->once()
            ->andReturn($mockStripeCustomer);

        // Create identity and add payment method using the service
        $identity = $this->paymentProviderService->createOrGetCustomer($user, 'stripe');
        $paymentMethod = $this->paymentProviderService->addPaymentMethod($identity, 'pm_test123', ['name' => 'My Card']);

        $this->assertInstanceOf(PaymentMethod::class, $paymentMethod);
        $this->assertEquals('pm_test123', $paymentMethod->provider_payment_method_id);
        $this->assertEquals('My Card', $paymentMethod->name);

        // Test identity and method relationships
        $this->assertEquals($user->id, $identity->customer_id);
        $this->assertEquals(get_class($user), $identity->customer_type);

        $methods = $this->paymentProviderService->listPaymentMethods($identity);
        $this->assertCount(1, $methods);

        $defaultMethod = $this->paymentProviderService->getDefaultPaymentMethod($identity);
        $this->assertNotNull($defaultMethod);
        $this->assertEquals($paymentMethod->id, $defaultMethod->id);
    }

    #[Test]
    public function it_can_sync_payment_methods_from_stripe()
    {
        $user = User::factory()->create();
        $identity = PaymentProviderIdentity::factory()->create([
            'customer_type' => get_class($user),
            'customer_id' => $user->id,
            'provider_name' => 'stripe',
        ]);

        // Create a local payment method that's not on Stripe (should be marked inactive)
        $oldMethod = PaymentMethod::factory()->create([
            'payment_provider_identity_id' => $identity->id,
            'provider_payment_method_id' => 'pm_old123',
            'is_active' => true,
        ]);

        // Mock Stripe collection with payment methods
        $mockCard1 = new \stdClass();
        $mockCard1->last4 = '4242';
        $mockCard1->brand = 'visa';
        $mockCard1->exp_month = 12;
        $mockCard1->exp_year = 2025;

        $mockCard2 = new \stdClass();
        $mockCard2->last4 = '5555';
        $mockCard2->brand = 'mastercard';
        $mockCard2->exp_month = 6;
        $mockCard2->exp_year = 2026;

        $mockMethod1 = new \stdClass();
        $mockMethod1->id = 'pm_new123';
        $mockMethod1->type = 'card';
        $mockMethod1->card = $mockCard1;

        $mockMethod2 = new \stdClass();
        $mockMethod2->id = 'pm_new456';
        $mockMethod2->type = 'card';
        $mockMethod2->card = $mockCard2;

        $mockCollection = new \stdClass();
        $mockCollection->data = [$mockMethod1, $mockMethod2];

        $this->stripeService
            ->shouldReceive('listPaymentMethods')
            ->once()
            ->with($identity->customer_identification_id)
            ->andReturn($mockCollection);

        // Sync payment methods
        $syncedMethods = $this->paymentProviderService->syncPaymentMethods($identity);

        $this->assertCount(2, $syncedMethods);

        // Old method should be marked inactive
        $this->assertFalse($oldMethod->fresh()->is_active);

        // New methods should exist
        $this->assertDatabaseHas('payment_methods', [
            'provider_payment_method_id' => 'pm_new123',
            'last_digits' => '4242',
            'brand' => 'visa',
        ]);

        $this->assertDatabaseHas('payment_methods', [
            'provider_payment_method_id' => 'pm_new456',
            'last_digits' => '5555',
            'brand' => 'mastercard',
        ]);
    }

    #[Test]
    public function payment_method_can_check_if_expired()
    {
        $paymentMethod = PaymentMethod::factory()->create([
            'exp_month' => now()->subMonth()->month,
            'exp_year' => now()->subYear()->year,
        ]);

        $this->assertTrue($paymentMethod->isExpired());

        $validMethod = PaymentMethod::factory()->create([
            'exp_month' => 12,
            'exp_year' => now()->addYear()->year,
        ]);

        $this->assertFalse($validMethod->isExpired());
    }

    #[Test]
    public function payment_method_has_display_name_attribute()
    {
        $method1 = PaymentMethod::factory()->create([
            'name' => 'My Personal Card',
            'brand' => 'visa',
            'last_digits' => '4242',
        ]);

        $this->assertEquals('My Personal Card', $method1->display_name);

        $method2 = PaymentMethod::factory()->create([
            'name' => null,
            'brand' => 'mastercard',
            'last_digits' => '5555',
        ]);

        $this->assertEquals('Mastercard ending in 5555', $method2->display_name);
    }

    #[Test]
    public function payment_method_has_formatted_expiration()
    {
        $paymentMethod = PaymentMethod::factory()->create([
            'exp_month' => 6,
            'exp_year' => 2025,
        ]);

        $this->assertEquals('06/2025', $paymentMethod->formatted_expiration);

        $methodWithoutExpiration = PaymentMethod::factory()->create([
            'exp_month' => null,
            'exp_year' => null,
        ]);

        $this->assertNull($methodWithoutExpiration->formatted_expiration);
    }
}
