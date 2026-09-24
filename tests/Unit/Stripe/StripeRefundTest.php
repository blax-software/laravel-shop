<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Http\Controllers\StripeWebhookController;
use Blax\Shop\Models\Order;
use Blax\Shop\Models\Product;
use Blax\Shop\Services\ShopService;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Workbench\App\Models\User;

/**
 * Stripe refunds are announced twice (refund.created + charge.refunded, in any order)
 * and may already have been recorded by an admin refund. Every path must land on the
 * same amount_refunded, in cents.
 */
class StripeRefundTest extends TestCase
{
    use RefreshDatabase;

    protected StripeWebhookController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.stripe.enabled' => true]);
        $this->controller = new StripeWebhookController;
    }

    protected function invoke(string $method, array $args = [])
    {
        $reflection = new ReflectionClass($this->controller);
        $m = $reflection->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($this->controller, $args);
    }

    /** A 100.00 order paid through a Checkout Session (payment_reference = pi_…). */
    protected function paidOrder(string $intent = 'pi_refund_test'): Order
    {
        $customer = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 10000)->create(['manage_stock' => false]);
        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;
        $order->recordPayment(10000, $intent, 'stripe', 'stripe');

        return $order->fresh();
    }

    protected function refundEvent(string $id, int $amount, string $intent = 'pi_refund_test'): object
    {
        return (object) [
            'id' => $id,
            'charge' => 'ch_refund_test',
            'payment_intent' => $intent,
            'amount' => $amount,
            'reason' => 'requested_by_customer',
            'status' => 'succeeded',
        ];
    }

    protected function chargeEvent(int $amountRefunded, string $intent = 'pi_refund_test'): object
    {
        return (object) [
            'id' => 'ch_refund_test',
            'payment_intent' => $intent,
            'amount' => 10000,
            'amount_refunded' => $amountRefunded,
        ];
    }

    #[Test]
    public function refund_created_finds_a_checkout_order_by_payment_intent_and_records_cents()
    {
        $order = $this->paidOrder();

        $this->invoke('handleRefundCreated', [$this->refundEvent('re_1', 2500)]);

        $this->assertSame(2500, $order->fresh()->amount_refunded);
    }

    #[Test]
    public function refund_created_then_charge_refunded_counts_the_refund_once()
    {
        $order = $this->paidOrder();

        $this->invoke('handleRefundCreated', [$this->refundEvent('re_1', 2500)]);
        $this->invoke('handleChargeRefunded', [$this->chargeEvent(2500)]);

        $this->assertSame(2500, $order->fresh()->amount_refunded);
    }

    #[Test]
    public function charge_refunded_then_refund_created_counts_the_refund_once()
    {
        $order = $this->paidOrder();

        $this->invoke('handleChargeRefunded', [$this->chargeEvent(2500)]);
        $this->invoke('handleRefundCreated', [$this->refundEvent('re_1', 2500)]);

        $this->assertSame(2500, $order->fresh()->amount_refunded);
    }

    #[Test]
    public function repeated_webhooks_are_idempotent_and_a_second_refund_adds_up()
    {
        $order = $this->paidOrder();

        $this->invoke('handleRefundCreated', [$this->refundEvent('re_1', 2500)]);
        $this->invoke('handleRefundCreated', [$this->refundEvent('re_1', 2500)]);
        $this->invoke('handleChargeRefunded', [$this->chargeEvent(2500)]);
        $this->invoke('handleRefundCreated', [$this->refundEvent('re_2', 1000)]);
        $this->invoke('handleChargeRefunded', [$this->chargeEvent(3500)]);

        $order = $order->fresh();
        $this->assertSame(3500, $order->amount_refunded);
        $this->assertSame(2, $order->notes()->where('type', 'refund')->count());
        $this->assertNotSame(OrderStatus::REFUNDED, $order->status);
    }

    #[Test]
    public function a_full_refund_marks_the_order_refunded()
    {
        $order = $this->paidOrder();

        $this->invoke('handleChargeRefunded', [$this->chargeEvent(10000)]);

        $order = $order->fresh();
        $this->assertSame(10000, $order->amount_refunded);
        $this->assertSame(OrderStatus::REFUNDED, $order->status);
    }

    #[Test]
    public function charge_succeeded_records_payment_in_cents_and_remembers_the_charge()
    {
        $customer = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 10000)->create(['manage_stock' => false]);
        $customer->addToCart($product);
        $order = $customer->checkoutCart()->fresh()->order;
        $order->update(['payment_reference' => 'pi_charge_test']);

        $this->invoke('handleChargeSucceeded', [(object) [
            'id' => 'ch_charge_test',
            'payment_intent' => 'pi_charge_test',
            'amount' => 10000,
        ]]);

        $order = $order->fresh();
        $this->assertSame(10000, $order->amount_paid);
        $this->assertTrue($order->is_fully_paid);
        $this->assertSame('ch_charge_test', $order->getMeta('stripe_charge_id'));
    }

    #[Test]
    public function refund_order_calls_stripe_and_the_following_webhooks_add_nothing()
    {
        $order = $this->paidOrder();

        $fake = new class
        {
            public array $calls = [];

            public object $refunds;

            public function __construct()
            {
                $outer = $this;
                $this->refunds = new class($outer)
                {
                    public function __construct(private object $outer) {}

                    public function create(array $params, array $opts = []): object
                    {
                        $this->outer->calls[] = [$params, $opts];

                        return (object) ['id' => 're_admin_1', 'amount' => $params['amount'], 'status' => 'succeeded'];
                    }
                };
            }
        };
        $this->app->instance(\Stripe\StripeClient::class, $fake);

        $refund = app(ShopService::class)->refundOrder($order, 4000, 'Damaged in transit');

        $this->assertSame('re_admin_1', $refund->id);
        $this->assertCount(1, $fake->calls);
        [$params, $opts] = $fake->calls[0];
        $this->assertSame('pi_refund_test', $params['payment_intent']);
        $this->assertSame(4000, $params['amount']);
        $this->assertSame((string) $order->id, $params['metadata']['order_id']);
        $this->assertStringStartsWith('shop-refund-', $opts['idempotency_key']);
        $this->assertSame(4000, $order->fresh()->amount_refunded);

        // Stripe then announces the same refund twice.
        $this->invoke('handleRefundCreated', [$this->refundEvent('re_admin_1', 4000)]);
        $this->invoke('handleChargeRefunded', [$this->chargeEvent(4000)]);

        $this->assertSame(4000, $order->fresh()->amount_refunded);
    }

    #[Test]
    public function refund_order_rejects_amounts_above_what_is_refundable()
    {
        $order = $this->paidOrder();
        $order->recordRefund(9000, 'manual');

        $this->expectException(\InvalidArgumentException::class);
        app(ShopService::class)->refundOrder($order->fresh(), 2000);
    }

    #[Test]
    public function refund_order_rejects_orders_without_a_stripe_payment()
    {
        $order = $this->paidOrder('manual-transfer');

        $this->expectException(\RuntimeException::class);
        app(ShopService::class)->refundOrder($order, 1000);
    }
}
