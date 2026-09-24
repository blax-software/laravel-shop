<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Http\Controllers\StripeWebhookController;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Workbench\App\Models\User;

/**
 * Shipping, discounts and tax picked on the Stripe Checkout page end up on the
 * order, so what was charged and what the order says agree.
 */
class StripeCheckoutTotalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shop.stripe.enabled' => true]);
    }

    protected function complete(object $session): void
    {
        $controller = new StripeWebhookController;
        $m = (new ReflectionClass($controller))->getMethod('handleCheckoutSessionCompleted');
        $m->setAccessible(true);
        $m->invoke($controller, $session);
    }

    protected function cart(int $price = 2400)
    {
        $customer = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: $price)->create(['manage_stock' => false]);
        $customer->addToCart($product);

        return $customer->checkoutCart();
    }

    #[Test]
    public function shipping_from_the_session_is_added_to_the_order_and_the_order_is_fully_paid()
    {
        $cart = $this->cart(2400);

        $this->complete((object) [
            'id' => 'cs_ship',
            'payment_intent' => 'pi_ship',
            'metadata' => (object) ['cart_id' => $cart->id],
            'client_reference_id' => $cart->id,
            'amount_subtotal' => 2400,
            'amount_total' => 2890,
            'total_details' => (object) ['amount_shipping' => 490, 'amount_discount' => 0, 'amount_tax' => 0],
            'shipping_cost' => (object) ['shipping_rate' => 'shr_123'],
            'currency' => 'eur',
            'payment_status' => 'paid',
            'collected_information' => (object) ['shipping_details' => (object) [
                'name' => 'Ola Kowalska',
                'address' => (object) ['line1' => 'ul. Długa 5', 'postal_code' => '00-238', 'city' => 'Warszawa', 'country' => 'PL'],
            ]],
            'customer_details' => (object) [
                'name' => 'Ola Kowalska',
                'email' => 'ola@example.test',
                'address' => (object) ['line1' => 'ul. Długa 5', 'postal_code' => '00-238', 'city' => 'Warszawa', 'country' => 'PL'],
            ],
        ]);

        $order = $cart->fresh()->order;
        $this->assertSame(490, $order->amount_shipping);
        $this->assertSame(2400, $order->amount_subtotal);
        $this->assertSame(2890, $order->amount_total);
        $this->assertSame(2890, $order->amount_paid);
        $this->assertTrue($order->is_fully_paid);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(OrderStatus::PROCESSING, $order->status);
        $this->assertSame('shr_123', $order->getMeta('stripe_shipping_rate'));
        // Newer Stripe API versions carry the shipping address under collected_information.
        $this->assertSame('Warszawa', $order->shipping_address->city);
    }

    #[Test]
    public function a_discount_code_lowers_the_total_and_still_settles_the_order()
    {
        $cart = $this->cart(5000);

        $this->complete((object) [
            'id' => 'cs_disc',
            'payment_intent' => 'pi_disc',
            'metadata' => (object) ['cart_id' => $cart->id],
            'amount_subtotal' => 5000,
            'amount_total' => 4500,
            'total_details' => (object) ['amount_shipping' => 0, 'amount_discount' => 500, 'amount_tax' => 0],
            'currency' => 'eur',
            'payment_status' => 'paid',
        ]);

        $order = $cart->fresh()->order;
        $this->assertSame(500, $order->amount_discount);
        $this->assertSame(4500, $order->amount_total);
        $this->assertTrue($order->is_fully_paid);
    }
}
