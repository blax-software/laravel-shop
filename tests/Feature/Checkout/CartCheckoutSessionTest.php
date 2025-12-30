<?php

namespace Blax\Shop\Tests\Feature\Checkout;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class CartCheckoutSessionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);
    }

    #[Test]
    public function it_throws_exception_when_stripe_is_disabled()
    {
        config(['shop.stripe.enabled' => false]);

        $product = Product::factory()->create(['manage_stock' => false]);
        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $this->cart->addToCart($product, 1);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Stripe is not enabled');

        $this->cart->checkoutSession();
    }

    #[Test]
    public function it_builds_checkout_session_with_simple_product_without_stripe_api()
    {
        // Enable Stripe but don't actually call the API
        config(['shop.stripe.enabled' => true]);
        config(['shop.currency' => 'usd']);
        config(['services.stripe.secret' => 'sk_test_fake']);

        $product = Product::factory()->create([
            'name' => 'Test Product',
            'short_description' => 'Short desc',
            'manage_stock' => false,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1500, // $15.00
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $this->cart->addToCart($product, 2);

        // Mock the Stripe API to avoid actual calls
        $this->mockStripeCheckoutSession();

        $session = $this->cart->checkoutSession([
            'success_url' => 'https://example.com/success',
            'cancel_url' => 'https://example.com/cancel',
        ]);

        // Verify the session was created with correct parameters
        $this->assertNotNull($session);
        $this->assertEquals('mock_session_id', $session->id);
    }

    #[Test]
    public function it_includes_booking_dates_in_product_name()
    {
        config(['shop.stripe.enabled' => true]);
        config(['services.stripe.secret' => 'sk_test_fake']);

        $bookingProduct = Product::factory()->create([
            'name' => 'Hotel Room',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $bookingProduct->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $bookingProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 10000, // $100 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = now()->addDays(1)->startOfDay();
        $until = now()->addDays(3)->startOfDay(); // 2 days

        $this->cart->addToCart($bookingProduct, 1, [], $from, $until);

        // Capture the session params
        $sessionParams = null;
        \Stripe\Checkout\Session::$createCallback = function ($params) use (&$sessionParams) {
            $sessionParams = $params;
            $mockSession = new \stdClass();
            $mockSession->id = 'mock_session_id';
            return $mockSession;
        };

        $this->cart->checkoutSession([
            'success_url' => 'https://example.com/success',
            'cancel_url' => 'https://example.com/cancel',
        ]);

        $data = $sessionParams['line_items'][0]['price_data']['product_data'];

        $this->assertStringContainsString('Hotel Room', $data['name']);
        $this->assertStringContainsString('from', $data['description']);
        $this->assertStringContainsString('to', $data['description']);
    }

    #[Test]
    public function it_calculates_correct_unit_amount_in_cents()
    {
        config(['shop.stripe.enabled' => true]);
        config(['services.stripe.secret' => 'sk_test_fake']);

        $product = Product::factory()->create(['name' => 'Test Product', 'manage_stock' => false]);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2550, // $25.50
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $this->cart->addToCart($product, 1);

        // Capture the session params
        $sessionParams = null;
        \Stripe\Checkout\Session::$createCallback = function ($params) use (&$sessionParams) {
            $sessionParams = $params;
            $mockSession = new \stdClass();
            $mockSession->id = 'mock_session_id';
            return $mockSession;
        };

        $this->cart->checkoutSession([
            'success_url' => 'https://example.com/success',
            'cancel_url' => 'https://example.com/cancel',
        ]);

        // Price is stored in cents (2550), Stripe expects cents (2550)
        $this->assertEquals(2550, $sessionParams['line_items'][0]['price_data']['unit_amount']);
    }

    #[Test]
    public function it_handles_booking_with_fractional_days()
    {
        config(['shop.stripe.enabled' => true]);
        config(['services.stripe.secret' => 'sk_test_fake']);

        $bookingProduct = Product::factory()->create([
            'name' => 'Parking Spot',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $bookingProduct->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $bookingProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1000, // $10 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // 4 hours booking (0.1667 days)
        $from = now()->addDays(1)->setTime(10, 0);
        $until = now()->addDays(1)->setTime(14, 0);

        $this->cart->addToCart($bookingProduct, 1, [], $from, $until);

        // Capture the session params
        $sessionParams = null;
        \Stripe\Checkout\Session::$createCallback = function ($params) use (&$sessionParams) {
            $sessionParams = $params;
            $mockSession = new \stdClass();
            $mockSession->id = 'mock_session_id';
            return $mockSession;
        };

        $this->cart->checkoutSession([
            'success_url' => 'https://example.com/success',
            'cancel_url' => 'https://example.com/cancel',
        ]);

        // The cart item should have calculated the fractional day price
        $cartItem = $this->cart->items->first();

        // Price is already in cents, no conversion needed
        $this->assertEquals($cartItem->price, $sessionParams['line_items'][0]['price_data']['unit_amount']);
    }

    #[Test]
    public function it_creates_separate_line_items_for_multiple_products()
    {
        config(['shop.stripe.enabled' => true]);
        config(['services.stripe.secret' => 'sk_test_fake']);

        $product1 = Product::factory()->create(['name' => 'Product 1', 'manage_stock' => false]);
        $product2 = Product::factory()->create(['name' => 'Product 2', 'manage_stock' => false]);

        ProductPrice::factory()->create([
            'purchasable_id' => $product1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $product2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $this->cart->addToCart($product1, 2);
        $this->cart->addToCart($product2, 1);

        // Capture the session params
        $sessionParams = null;
        \Stripe\Checkout\Session::$createCallback = function ($params) use (&$sessionParams) {
            $sessionParams = $params;
            $mockSession = new \stdClass();
            $mockSession->id = 'mock_session_id';
            return $mockSession;
        };

        $this->cart->checkoutSession([
            'success_url' => 'https://example.com/success',
            'cancel_url' => 'https://example.com/cancel',
        ]);

        $this->assertCount(2, $sessionParams['line_items']);
        $this->assertEquals(2, $sessionParams['line_items'][0]['quantity']);
        $this->assertEquals(1, $sessionParams['line_items'][1]['quantity']);
    }

    #[Test]
    public function it_uses_configured_currency()
    {
        config(['shop.stripe.enabled' => true]);
        config(['shop.currency' => 'eur']);
        config(['services.stripe.secret' => 'sk_test_fake']);

        $product = Product::factory()->create(['name' => 'Product', 'manage_stock' => false]);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1000,
            'currency' => 'EUR',
            'is_default' => true,
        ]);

        $this->cart->addToCart($product, 1);

        // Capture the session params
        $sessionParams = null;
        \Stripe\Checkout\Session::$createCallback = function ($params) use (&$sessionParams) {
            $sessionParams = $params;
            $mockSession = new \stdClass();
            $mockSession->id = 'mock_session_id';
            return $mockSession;
        };

        $this->cart->checkoutSession([
            'success_url' => 'https://example.com/success',
            'cancel_url' => 'https://example.com/cancel',
        ]);

        $this->assertEquals('eur', $sessionParams['line_items'][0]['price_data']['currency']);
    }

    #[Test]
    public function it_stores_session_id_in_cart_meta()
    {
        config(['shop.stripe.enabled' => true]);
        config(['services.stripe.secret' => 'sk_test_fake']);

        $product = Product::factory()->create(['name' => 'Product', 'manage_stock' => false]);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $this->cart->addToCart($product, 1);

        $this->mockStripeCheckoutSession();

        $this->cart->checkoutSession([
            'success_url' => 'https://example.com/success',
            'cancel_url' => 'https://example.com/cancel',
        ]);

        $this->cart->refresh();
        $meta = $this->cart->meta;

        $this->assertNotNull($meta->stripe_session_id ?? null);
        $this->assertEquals('mock_session_id', $meta->stripe_session_id);
    }

    /**
     * Mock Stripe Checkout Session creation to avoid actual API calls
     */
    protected function mockStripeCheckoutSession()
    {
        // Create a simple mock that returns a session object
        \Stripe\Checkout\Session::$createCallback = function ($params) {
            $mockSession = new \stdClass();
            $mockSession->id = 'mock_session_id';
            $mockSession->url = 'https://checkout.stripe.com/mock';
            return $mockSession;
        };
    }
}

// Add a simple mock capability to Stripe Session class for testing
namespace Stripe\Checkout;

class Session
{
    public static $createCallback = null;

    public static function create($params)
    {
        if (self::$createCallback) {
            return call_user_func(self::$createCallback, $params);
        }

        // If no callback, throw exception (actual Stripe call would be made)
        throw new \Exception('Stripe API call attempted without mock. Set createCallback first.');
    }

    public static function resetMock()
    {
        self::$createCallback = null;
    }
}
