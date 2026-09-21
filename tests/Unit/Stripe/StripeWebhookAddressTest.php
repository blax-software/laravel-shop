<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Http\Controllers\StripeWebhookController;
use Blax\Shop\Models\Order;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Workbench\App\Models\User;

/**
 * checkout.session.completed → Order.billing_address / shipping_address
 * snapshots from the session's customer_details / shipping_details.
 */
class StripeWebhookAddressTest extends TestCase
{
    use RefreshDatabase;

    protected StripeWebhookController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.stripe.enabled' => true]);
        $this->controller = new StripeWebhookController;
    }

    protected function handle(object $session): bool
    {
        $method = (new ReflectionClass($this->controller))->getMethod('handleCheckoutSessionCompleted');
        $method->setAccessible(true);

        return $method->invokeArgs($this->controller, [$session]);
    }

    protected function mockSession(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 'cs_test_'.uniqid(),
            'payment_intent' => 'pi_test_'.uniqid(),
            'metadata' => (object) ['cart_id' => null],
            'client_reference_id' => null,
            'amount_total' => 10000,
            'currency' => 'eur',
            'payment_status' => 'paid',
            'customer' => 'cus_test_123',
        ], $overrides);
    }

    protected function product(int $price = 10000): Product
    {
        return Product::factory()->withPrices(unit_amount: $price)->create(['manage_stock' => false]);
    }

    protected function stripeCustomerDetails(): object
    {
        return (object) [
            'email' => 'anna@example.at',
            'name' => 'Anna Beispiel',
            'phone' => '+43 660 1234567',
            'address' => (object) [
                'line1' => 'Hauptstraße 1',
                'line2' => 'Top 4',
                'postal_code' => '1010',
                'city' => 'Wien',
                'state' => 'Wien',
                'country' => 'AT',
            ],
            'tax_ids' => [(object) ['type' => 'eu_vat', 'value' => 'ATU12345678']],
        ];
    }

    protected function stripeShippingDetails(): object
    {
        return (object) [
            'name' => 'Anna Beispiel (Büro)',
            'address' => (object) [
                'line1' => 'Lagergasse 9',
                'line2' => null,
                'postal_code' => '8010',
                'city' => 'Graz',
                'state' => 'Steiermark',
                'country' => 'AT',
            ],
        ];
    }

    #[Test]
    public function checkout_session_completed_persists_billing_and_shipping_snapshots()
    {
        $customer = User::factory()->create();
        $customer->addToCart($this->product());
        $cart = $customer->currentCart();

        $session = $this->mockSession([
            'metadata' => (object) ['cart_id' => $cart->id],
            'customer_details' => $this->stripeCustomerDetails(),
            'shipping_details' => $this->stripeShippingDetails(),
        ]);

        $this->assertTrue($this->handle($session));

        $order = $cart->fresh()->order;
        $this->assertNotNull($order);

        $this->assertSame([
            'name' => 'Anna Beispiel',
            'email' => 'anna@example.at',
            'phone' => '+43 660 1234567',
            'line1' => 'Hauptstraße 1',
            'line2' => 'Top 4',
            'postal_code' => '1010',
            'city' => 'Wien',
            'state' => 'Wien',
            'country' => 'AT',
            'uid' => 'ATU12345678',
        ], (array) $order->billing_address);

        $this->assertSame([
            'name' => 'Anna Beispiel (Büro)',
            'email' => 'anna@example.at',
            'phone' => '+43 660 1234567',
            'line1' => 'Lagergasse 9',
            'line2' => null,
            'postal_code' => '8010',
            'city' => 'Graz',
            'state' => 'Steiermark',
            'country' => 'AT',
            'uid' => null,
        ], (array) $order->shipping_address);

        // Raw JSON in the DB carries the same shape
        $raw = json_decode(DB::table($order->getTable())->where('id', $order->id)->value('billing_address'), true);
        $this->assertSame('ATU12345678', $raw['uid']);
        $this->assertSame('Wien', $raw['city']);
        $this->assertSame(
            ['name', 'email', 'phone', 'line1', 'line2', 'postal_code', 'city', 'state', 'country', 'uid'],
            array_keys($raw)
        );
    }

    #[Test]
    public function shipping_falls_back_to_billing_when_session_has_no_shipping_details()
    {
        $customer = User::factory()->create();
        $customer->addToCart($this->product());
        $cart = $customer->currentCart();

        $this->handle($this->mockSession([
            'metadata' => (object) ['cart_id' => $cart->id],
            'customer_details' => $this->stripeCustomerDetails(),
            'shipping_details' => null,
        ]));

        $order = $cart->fresh()->order;
        $this->assertEquals((array) $order->billing_address, (array) $order->shipping_address);
        $this->assertSame('Hauptstraße 1', $order->shipping_address->line1);
    }

    #[Test]
    public function session_without_customer_details_leaves_addresses_untouched()
    {
        $customer = User::factory()->create();
        $customer->addToCart($this->product());
        $cart = $customer->checkoutCart();
        $order = $cart->fresh()->order;

        $this->assertTrue($this->handle($this->mockSession([
            'metadata' => (object) ['cart_id' => $cart->id],
        ])));

        $order->refresh();
        $this->assertNull($order->billing_address);
        $this->assertNull($order->shipping_address);
        $this->assertEquals(10000, $order->amount_paid);
    }

    #[Test]
    public function existing_addresses_are_not_overwritten()
    {
        $customer = User::factory()->create();
        $customer->addToCart($this->product());
        $cart = $customer->checkoutCart();
        $order = $cart->fresh()->order;
        $order->update(['billing_address' => ['name' => 'Preset', 'line1' => 'Kept 1']]);

        $this->handle($this->mockSession([
            'metadata' => (object) ['cart_id' => $cart->id],
            'customer_details' => $this->stripeCustomerDetails(),
        ]));

        $order->refresh();
        $this->assertSame('Preset', $order->billing_address->name);
        // shipping was empty, so it is filled from the session
        $this->assertSame('Anna Beispiel', $order->shipping_address->name);
    }

    #[Test]
    public function array_shaped_session_details_are_accepted()
    {
        $customer = User::factory()->create();
        $customer->addToCart($this->product());
        $cart = $customer->currentCart();

        $this->handle($this->mockSession([
            'metadata' => (object) ['cart_id' => $cart->id],
            'customer_details' => [
                'email' => 'jan@example.pl',
                'name' => 'Jan Kowalski',
                'phone' => null,
                'address' => ['line1' => 'ul. Prosta 5', 'postal_code' => '00-001', 'city' => 'Warszawa', 'country' => 'PL'],
            ],
        ]));

        $order = $cart->fresh()->order;
        $this->assertSame('Jan Kowalski', $order->billing_address->name);
        $this->assertSame('PL', $order->billing_address->country);
        $this->assertNull($order->billing_address->line2);
        $this->assertNull($order->billing_address->uid);
    }
}
