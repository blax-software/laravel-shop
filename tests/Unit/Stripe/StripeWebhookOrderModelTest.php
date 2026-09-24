<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Enums\CartStatus;
use Blax\Shop\Events\OrderPaid;
use Blax\Shop\Http\Controllers\StripeWebhookController;
use Blax\Shop\Models\Order;
use Blax\Shop\Models\Product;
use Blax\Shop\Services\ShopService;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Workbench\App\Models\User;

/**
 * A host app's Order subclass with its own boot hook — the shape nirioci and
 * other consumers use. The webhook path must build THIS class, not the base.
 */
class HostWebhookOrder extends Order
{
    public static int $bootHookRuns = 0;

    protected static function booted()
    {
        parent::booted();

        static::creating(function (HostWebhookOrder $order) {
            $order->internal_note = 'host boot hook ran';
        });

        static::created(function () {
            static::$bootHookRuns++;
        });
    }
}

/**
 * `shop.models.order` is honoured on the real Stripe webhook path and by the
 * ShopService / Cart finders and creators that previously used `Order::`.
 */
class StripeWebhookOrderModelTest extends TestCase
{
    use RefreshDatabase;

    protected StripeWebhookController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'shop.stripe.enabled' => true,
            'shop.models.order' => HostWebhookOrder::class,
        ]);
        HostWebhookOrder::$bootHookRuns = 0;

        $this->controller = new StripeWebhookController;
    }

    protected function invoke(string $method, array $args = [])
    {
        $ref = (new ReflectionClass($this->controller))->getMethod($method);
        $ref->setAccessible(true);

        return $ref->invokeArgs($this->controller, $args);
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

    #[Test]
    public function checkout_session_completed_builds_the_configured_order_subclass(): void
    {
        $customer = User::factory()->create();
        $customer->addToCart($this->product(10000));
        $cart = $customer->currentCart();

        $this->assertEquals(CartStatus::ACTIVE, $cart->status);
        $this->assertNull($cart->order);

        Event::fake([OrderPaid::class]);

        $result = $this->invoke('handleCheckoutSessionCompleted', [
            $this->mockSession(['metadata' => (object) ['cart_id' => $cart->id], 'amount_total' => 10000]),
        ]);

        $this->assertTrue($result);

        $order = $cart->fresh()->order;
        $this->assertInstanceOf(HostWebhookOrder::class, $order);
        $this->assertSame('host boot hook ran', $order->internal_note, 'the subclass creating hook must have run');
        $this->assertSame(1, HostWebhookOrder::$bootHookRuns);
        $this->assertSame(1, HostWebhookOrder::count());

        $this->assertEquals(10000, $order->amount_paid);
        $this->assertNotNull($order->paid_at);

        Event::assertDispatchedTimes(OrderPaid::class, 1);
        Event::assertDispatched(
            OrderPaid::class,
            fn (OrderPaid $e) => $e->order instanceof HostWebhookOrder && $e->order->is($order)
        );
    }

    #[Test]
    public function webhook_finders_return_the_configured_order_subclass(): void
    {
        $order = HostWebhookOrder::create(Order::factory()->pending()->raw([
            'payment_reference' => 'pi_host_ref',
        ]));

        $byIntent = $this->invoke('findOrderByPaymentIntent', ['pi_host_ref']);
        $byCharge = $this->invoke('findOrderByChargeId', ['pi_host_ref']);

        $this->assertInstanceOf(HostWebhookOrder::class, $byIntent);
        $this->assertInstanceOf(HostWebhookOrder::class, $byCharge);
        $this->assertTrue($byIntent->is($order));
    }

    #[Test]
    public function shop_service_finders_return_the_configured_order_subclass(): void
    {
        $order = HostWebhookOrder::create(Order::factory()->pending()->raw());
        $service = new ShopService;

        $this->assertInstanceOf(HostWebhookOrder::class, $service->order($order->id));
        $this->assertInstanceOf(HostWebhookOrder::class, $service->orderByNumber($order->order_number));
        $this->assertInstanceOf(HostWebhookOrder::class, $service->orders()->first());
        $this->assertInstanceOf(HostWebhookOrder::class, $service->pendingOrders()->first());
        $this->assertSame(1, $service->ordersToday()->count());
    }

    #[Test]
    public function cart_checkout_builds_the_configured_order_subclass(): void
    {
        $customer = User::factory()->create();
        $customer->addToCart($this->product(5000));

        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;
        $this->assertInstanceOf(HostWebhookOrder::class, $order);
        $this->assertSame('host boot hook ran', $order->internal_note);
    }
}
