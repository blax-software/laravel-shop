<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Events\OrderPaid;
use Blax\Shop\Models\Order;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * A host app's Order subclass, as configured via `shop.models.order`.
 * OrderPaid must be dispatched for it exactly like for the base model.
 */
class HostPaidOrder extends Order {}

/**
 * OrderPaid is announced exactly once: on the save where paid_at goes from
 * null to a value. Never on partial payments, never again on later saves.
 */
class OrderPaidEventTest extends TestCase
{
    use RefreshDatabase;

    protected function pendingOrder(int $total = 10000): Order
    {
        return Order::factory()->pending()->create([
            'amount_total' => $total,
            'amount_paid' => 0,
        ]);
    }

    #[Test]
    public function record_payment_reaching_total_dispatches_order_paid_once(): void
    {
        $order = $this->pendingOrder(10000);

        Event::fake([OrderPaid::class]);

        $order->recordPayment(10000, 'pi_paid', 'card', 'stripe');

        Event::assertDispatchedTimes(OrderPaid::class, 1);
        Event::assertDispatched(OrderPaid::class, fn (OrderPaid $e) => $e->order->is($order));

        // Overpayment / a second recordPayment on an already-paid order stays quiet.
        $order->recordPayment(500);
        // ... and so do unrelated later saves (status move, meta touch).
        $order->updateMetaKey('tracking_number', 'TRK-1');
        $order->markAsShipped('TRK-1', 'DHL');

        Event::assertDispatchedTimes(OrderPaid::class, 1);
        $this->assertNotNull($order->fresh()->paid_at);
    }

    #[Test]
    public function partial_payment_does_not_dispatch_until_total_is_reached(): void
    {
        $order = $this->pendingOrder(10000);

        Event::fake([OrderPaid::class]);

        $order->recordPayment(4000);
        Event::assertNotDispatched(OrderPaid::class);
        $this->assertNull($order->fresh()->paid_at);

        $order->recordPayment(6000);
        Event::assertDispatchedTimes(OrderPaid::class, 1);
        $this->assertNotNull($order->fresh()->paid_at);
    }

    #[Test]
    public function direct_update_setting_paid_at_dispatches_order_paid_once(): void
    {
        $order = $this->pendingOrder(10000);

        Event::fake([OrderPaid::class]);

        $order->update(['paid_at' => now()]);
        Event::assertDispatchedTimes(OrderPaid::class, 1);

        // Moving an existing paid_at (value -> value) is not a "became paid" transition.
        $order->update(['paid_at' => now()->addMinute()]);
        $order->fresh()->update(['internal_note' => 'touched']);

        Event::assertDispatchedTimes(OrderPaid::class, 1);
    }

    #[Test]
    public function order_paid_fires_for_a_configured_host_subclass_with_that_instance(): void
    {
        config(['shop.models.order' => HostPaidOrder::class]);

        $order = HostPaidOrder::create(Order::factory()->pending()->raw([
            'amount_total' => 10000,
            'amount_paid' => 0,
        ]));

        Event::fake([OrderPaid::class]);

        $order->recordPayment(10000, 'pi_host', 'card', 'stripe');
        $order->recordPayment(1);

        Event::assertDispatchedTimes(OrderPaid::class, 1);
        Event::assertDispatched(
            OrderPaid::class,
            fn (OrderPaid $e) => $e->order instanceof HostPaidOrder && $e->order->is($order)
        );
    }
}
