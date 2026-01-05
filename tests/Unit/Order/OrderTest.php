<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Models\Order;
use Blax\Shop\Models\OrderNote;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // ORDER CREATION TESTS
    // =========================================================================

    #[Test]
    public function order_can_be_created_with_factory()
    {
        $order = Order::factory()->create();

        $this->assertInstanceOf(Order::class, $order);
        $this->assertNotNull($order->id);
        $this->assertNotNull($order->order_number);
        $this->assertEquals(OrderStatus::PENDING, $order->status);
    }

    #[Test]
    public function order_generates_unique_order_number_automatically()
    {
        $order1 = Order::factory()->create();
        $order2 = Order::factory()->create();
        $order3 = Order::factory()->create();

        $this->assertNotEquals($order1->order_number, $order2->order_number);
        $this->assertNotEquals($order2->order_number, $order3->order_number);
        $this->assertStringStartsWith('ORD-', $order1->order_number);
    }

    #[Test]
    public function order_number_format_includes_date_and_sequence()
    {
        $order = Order::factory()->create();

        // Format: ORD-YYYYMMDD0001
        $expectedPrefix = 'ORD-' . now()->format('Ymd');
        $this->assertStringStartsWith($expectedPrefix, $order->order_number);
        $this->assertMatchesRegularExpression('/^ORD-\d{8}\d{4}$/', $order->order_number);
    }

    #[Test]
    public function order_can_be_created_for_customer()
    {
        $user = User::factory()->create();
        $order = Order::factory()->forCustomer($user)->create();

        $this->assertEquals(get_class($user), $order->customer_type);
        $this->assertEquals($user->id, $order->customer_id);
        $this->assertTrue($order->customer->is($user));
    }

    #[Test]
    public function order_can_be_created_for_cart()
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->forCustomer($user)->create([
            'currency' => 'EUR',
            'converted_at' => now(),
        ]);

        $order = Order::factory()->forCart($cart)->create();

        $this->assertEquals($cart->id, $order->cart_id);
        $this->assertEquals($user->id, $order->customer_id);
        $this->assertEquals('EUR', $order->currency);
    }

    // =========================================================================
    // ORDER STATUS TESTS
    // =========================================================================

    #[Test]
    public function order_default_status_is_pending()
    {
        $order = Order::factory()->create();

        $this->assertEquals(OrderStatus::PENDING, $order->status);
    }

    #[Test]
    public function order_can_transition_to_processing_from_pending()
    {
        $order = Order::factory()->pending()->create();

        $order->updateStatus(OrderStatus::PROCESSING);

        $this->assertEquals(OrderStatus::PROCESSING, $order->fresh()->status);
    }

    #[Test]
    public function order_cannot_transition_to_invalid_status()
    {
        $order = Order::factory()->pending()->create();

        $this->expectException(\InvalidArgumentException::class);
        $order->updateStatus(OrderStatus::SHIPPED);
    }

    #[Test]
    public function order_can_force_status_without_validation()
    {
        $order = Order::factory()->pending()->create();

        $order->forceStatus(OrderStatus::SHIPPED);

        $this->assertEquals(OrderStatus::SHIPPED, $order->fresh()->status);
    }

    #[Test]
    public function order_status_change_is_logged()
    {
        $order = Order::factory()->pending()->create();

        $order->updateStatus(OrderStatus::PROCESSING);

        $this->assertTrue(
            $order->notes()
                ->where('type', 'status_change')
                ->exists()
        );
    }

    #[Test]
    public function order_status_enum_has_label()
    {
        $this->assertEquals('Pending Payment', OrderStatus::PENDING->label());
        $this->assertEquals('Processing', OrderStatus::PROCESSING->label());
        $this->assertEquals('Completed', OrderStatus::COMPLETED->label());
        $this->assertEquals('Shipped', OrderStatus::SHIPPED->label());
    }

    #[Test]
    public function order_status_has_allowed_transitions()
    {
        $this->assertTrue(OrderStatus::PENDING->canTransitionTo(OrderStatus::PROCESSING));
        $this->assertTrue(OrderStatus::PENDING->canTransitionTo(OrderStatus::CANCELLED));
        $this->assertFalse(OrderStatus::PENDING->canTransitionTo(OrderStatus::SHIPPED));
        $this->assertFalse(OrderStatus::CANCELLED->canTransitionTo(OrderStatus::PROCESSING));
    }

    #[Test]
    public function order_status_is_active_check()
    {
        $this->assertTrue(OrderStatus::PENDING->isActive());
        $this->assertTrue(OrderStatus::PROCESSING->isActive());
        $this->assertTrue(OrderStatus::SHIPPED->isActive());
        $this->assertFalse(OrderStatus::COMPLETED->isActive());
        $this->assertFalse(OrderStatus::CANCELLED->isActive());
    }

    #[Test]
    public function order_status_is_final_check()
    {
        $this->assertFalse(OrderStatus::PENDING->isFinal());
        $this->assertFalse(OrderStatus::PROCESSING->isFinal());
        $this->assertTrue(OrderStatus::COMPLETED->isFinal());
        $this->assertTrue(OrderStatus::CANCELLED->isFinal());
        $this->assertTrue(OrderStatus::REFUNDED->isFinal());
    }

    // =========================================================================
    // ORDER CONVENIENCE STATUS METHODS
    // =========================================================================

    #[Test]
    public function order_can_mark_as_processing()
    {
        $order = Order::factory()->pending()->create();

        $order->markAsProcessing();

        $this->assertEquals(OrderStatus::PROCESSING, $order->fresh()->status);
    }

    #[Test]
    public function order_can_mark_as_shipped_with_tracking()
    {
        $order = Order::factory()->processing()->create();

        $order->markAsShipped('TRACK123', 'FedEx');

        $order->refresh();
        $this->assertEquals(OrderStatus::SHIPPED, $order->status);
        $this->assertNotNull($order->shipped_at);
        $this->assertEquals('TRACK123', $order->getMeta('tracking_number'));
        $this->assertEquals('FedEx', $order->getMeta('shipping_carrier'));
    }

    #[Test]
    public function order_can_mark_as_completed()
    {
        $order = Order::factory()->processing()->create();

        $order->markAsCompleted();

        $order->refresh();
        $this->assertEquals(OrderStatus::COMPLETED, $order->status);
        $this->assertNotNull($order->completed_at);
    }

    #[Test]
    public function order_can_be_cancelled()
    {
        $order = Order::factory()->pending()->create();

        $order->cancel('Customer request');

        $order->refresh();
        $this->assertEquals(OrderStatus::CANCELLED, $order->status);
        $this->assertNotNull($order->cancelled_at);
    }

    #[Test]
    public function order_can_be_put_on_hold()
    {
        $order = Order::factory()->processing()->create();

        $order->hold('Waiting for stock');

        $order->refresh();
        $this->assertEquals(OrderStatus::ON_HOLD, $order->status);
    }

    // =========================================================================
    // ORDER AMOUNTS TESTS
    // =========================================================================

    #[Test]
    public function order_calculates_amount_outstanding()
    {
        $order = Order::factory()->withAmounts(
            subtotal: 10000,
            discount: 0,
            shipping: 500,
            tax: 1050
        )->create([
            'amount_paid' => 5000,
        ]);

        $this->assertEquals(11550, $order->amount_total);
        $this->assertEquals(6550, $order->amount_outstanding);
    }

    #[Test]
    public function order_is_paid_when_any_payment_received()
    {
        $order = Order::factory()->create([
            'amount_total' => 10000,
            'amount_paid' => 0,
        ]);

        $this->assertFalse($order->is_paid);

        $order->update(['amount_paid' => 1000]);

        $this->assertTrue($order->fresh()->is_paid);
    }

    #[Test]
    public function order_is_fully_paid_when_amount_paid_equals_total()
    {
        $order = Order::factory()->create([
            'amount_total' => 10000,
            'amount_paid' => 5000,
        ]);

        $this->assertFalse($order->is_fully_paid);

        $order->update(['amount_paid' => 10000]);

        $this->assertTrue($order->fresh()->is_fully_paid);
    }

    // =========================================================================
    // ORDER PAYMENT TESTS
    // =========================================================================

    #[Test]
    public function order_can_record_payment()
    {
        $order = Order::factory()->pending()->create([
            'amount_total' => 10000,
            'amount_paid' => 0,
        ]);

        $order->recordPayment(10000, 'pi_123', 'card', 'stripe');

        $order->refresh();
        $this->assertEquals(10000, $order->amount_paid);
        $this->assertEquals('pi_123', $order->payment_reference);
        $this->assertEquals('card', $order->payment_method);
        $this->assertEquals('stripe', $order->payment_provider);
        $this->assertNotNull($order->paid_at);
        $this->assertEquals(OrderStatus::PROCESSING, $order->status);
    }

    #[Test]
    public function order_can_record_partial_payment()
    {
        $order = Order::factory()->pending()->create([
            'amount_total' => 10000,
            'amount_paid' => 0,
        ]);

        $order->recordPayment(5000);

        $order->refresh();
        $this->assertEquals(5000, $order->amount_paid);
        $this->assertNull($order->paid_at); // Not fully paid yet
        $this->assertEquals(5000, $order->amount_outstanding);
    }

    #[Test]
    public function order_payment_creates_note()
    {
        $order = Order::factory()->pending()->create([
            'amount_total' => 10000,
        ]);

        $order->recordPayment(10000);

        $this->assertTrue(
            $order->notes()
                ->where('type', 'payment')
                ->exists()
        );
    }

    #[Test]
    public function order_can_record_refund()
    {
        $order = Order::factory()->paid()->create([
            'amount_total' => 10000,
            'amount_paid' => 10000,
        ]);

        $order->recordRefund(5000, 'Partial refund for damaged item');

        $order->refresh();
        $this->assertEquals(5000, $order->amount_refunded);
    }

    #[Test]
    public function order_becomes_refunded_when_fully_refunded()
    {
        $order = Order::factory()->paid()->create([
            'amount_total' => 10000,
            'amount_paid' => 10000,
        ]);

        $order->recordRefund(10000);

        $order->refresh();
        $this->assertEquals(OrderStatus::REFUNDED, $order->status);
        $this->assertNotNull($order->refunded_at);
    }

    // =========================================================================
    // ORDER NOTES TESTS
    // =========================================================================

    #[Test]
    public function order_can_add_note()
    {
        $order = Order::factory()->create();

        $note = $order->addNote('Test note', 'note', false);

        $this->assertInstanceOf(OrderNote::class, $note);
        $this->assertEquals('Test note', $note->content);
        $this->assertEquals('note', $note->type);
        $this->assertFalse($note->is_customer_note);
    }

    #[Test]
    public function order_can_add_customer_note()
    {
        $order = Order::factory()->create();

        $note = $order->addNote('Customer visible note', 'customer', true);

        $this->assertTrue($note->is_customer_note);
        $this->assertCount(1, $order->customerNotes);
    }

    #[Test]
    public function order_can_filter_customer_notes()
    {
        $order = Order::factory()->create();

        $order->addNote('Internal note', 'note', false);
        $order->addNote('Customer note', 'customer', true);
        $order->addNote('Another internal', 'note', false);

        $this->assertCount(1, $order->customerNotes);
        $this->assertCount(2, $order->internalNotes);
    }

    #[Test]
    public function order_note_has_type_label()
    {
        $order = Order::factory()->create();
        $note = $order->addNote('Test', OrderNote::TYPE_PAYMENT);

        $this->assertEquals('Payment', $note->type_label);
    }

    #[Test]
    public function order_creation_logs_system_note()
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->forCustomer($user)->create([
            'converted_at' => now(),
        ]);
        $cart->update(['converted_at' => now()]);

        $order = Order::createFromCart($cart);

        $this->assertTrue(
            $order->notes()
                ->where('type', 'system')
                ->where('content', 'Order created from cart checkout')
                ->exists()
        );
    }

    // =========================================================================
    // ORDER RELATIONSHIPS TESTS
    // =========================================================================

    #[Test]
    public function order_has_cart_relationship()
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->forCustomer($user)->create([
            'converted_at' => now(),
        ]);
        $order = Order::factory()->forCart($cart)->create();

        $this->assertTrue($order->cart->is($cart));
    }

    #[Test]
    public function order_has_customer_relationship()
    {
        $user = User::factory()->create();
        $order = Order::factory()->forCustomer($user)->create();

        $this->assertTrue($order->customer->is($user));
    }

    #[Test]
    public function order_has_notes_relationship()
    {
        $order = Order::factory()->create();
        $order->addNote('Note 1');
        $order->addNote('Note 2');
        $order->addNote('Note 3');

        $this->assertCount(3, $order->notes);
    }

    // =========================================================================
    // ORDER SCOPES TESTS
    // =========================================================================

    #[Test]
    public function order_can_be_scoped_by_status()
    {
        Order::factory()->pending()->count(2)->create();
        Order::factory()->processing()->count(3)->create();
        Order::factory()->completed()->count(1)->create();

        $this->assertCount(2, Order::withStatus(OrderStatus::PENDING)->get());
        $this->assertCount(3, Order::withStatus(OrderStatus::PROCESSING)->get());
        $this->assertCount(1, Order::completed()->get());
    }

    #[Test]
    public function order_can_be_scoped_by_active_status()
    {
        Order::factory()->pending()->create();
        Order::factory()->processing()->create();
        Order::factory()->shipped()->create();
        Order::factory()->completed()->create();
        Order::factory()->cancelled()->create();

        $this->assertCount(3, Order::active()->get());
    }

    #[Test]
    public function order_can_be_scoped_by_paid_status()
    {
        Order::factory()->create(['amount_total' => 10000, 'amount_paid' => 10000]);
        Order::factory()->create(['amount_total' => 10000, 'amount_paid' => 5000]);
        Order::factory()->create(['amount_total' => 10000, 'amount_paid' => 0]);

        $this->assertCount(1, Order::paid()->get());
        $this->assertCount(2, Order::unpaid()->get());
    }

    #[Test]
    public function order_can_be_scoped_by_customer()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        Order::factory()->forCustomer($user1)->count(3)->create();
        Order::factory()->forCustomer($user2)->count(2)->create();

        $this->assertCount(3, Order::forCustomer($user1)->get());
        $this->assertCount(2, Order::forCustomer($user2)->get());
    }

    #[Test]
    public function order_can_be_scoped_by_date_range()
    {
        Order::factory()->create(['created_at' => now()->subDays(10)]);
        Order::factory()->create(['created_at' => now()->subDays(5)]);
        Order::factory()->create(['created_at' => now()]);

        $result = Order::createdBetween(now()->subDays(7), now())->get();

        $this->assertCount(2, $result);
    }

    // =========================================================================
    // ORDER META TESTS
    // =========================================================================

    #[Test]
    public function order_can_store_and_retrieve_meta()
    {
        $order = Order::factory()->create();

        $order->updateMetaKey('custom_field', 'custom_value');

        $this->assertEquals('custom_value', $order->getMeta('custom_field'));
    }

    #[Test]
    public function order_can_store_addresses()
    {
        $order = Order::factory()->withBillingAddress()->withShippingAddress()->create();

        $this->assertNotNull($order->billing_address);
        $this->assertNotNull($order->shipping_address);
        $this->assertNotNull($order->billing_address->first_name);
        $this->assertNotNull($order->shipping_address->city);
    }

    // =========================================================================
    // ORDER FACTORY STATES TESTS
    // =========================================================================

    #[Test]
    public function order_factory_creates_paid_state_correctly()
    {
        $order = Order::factory()->paid()->create();

        $this->assertTrue($order->is_fully_paid);
        $this->assertEquals(OrderStatus::PROCESSING, $order->status);
        $this->assertNotNull($order->paid_at);
    }

    #[Test]
    public function order_factory_creates_refunded_state_correctly()
    {
        $order = Order::factory()->refunded()->create();

        $this->assertEquals(OrderStatus::REFUNDED, $order->status);
        $this->assertEquals($order->amount_paid, $order->amount_refunded);
        $this->assertNotNull($order->refunded_at);
    }

    // =========================================================================
    // ORDER MONEY FORMATTING TESTS
    // =========================================================================

    #[Test]
    public function order_formats_money_correctly()
    {
        $this->assertEquals('USD 100.00', Order::formatMoney(10000, 'usd'));
        $this->assertEquals('EUR 50.50', Order::formatMoney(5050, 'eur'));
    }

    // =========================================================================
    // ORDER BOOKING DATE RANGE TESTS
    // =========================================================================

    #[Test]
    public function order_can_have_from_and_until_dates()
    {
        $from = now()->addDay();
        $until = now()->addDays(5);

        $order = Order::factory()->withDateRange($from, $until)->create();

        $this->assertEquals($from->format('Y-m-d H:i:s'), $order->from->format('Y-m-d H:i:s'));
        $this->assertEquals($until->format('Y-m-d H:i:s'), $order->until->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function order_factory_booking_state_sets_default_dates()
    {
        $order = Order::factory()->booking()->create();

        $this->assertNotNull($order->from);
        $this->assertNotNull($order->until);
        $this->assertTrue($order->from->lt($order->until));
    }

    #[Test]
    public function order_created_from_cart_inherits_booking_dates()
    {
        $user = User::factory()->create();
        $from = now()->addDay();
        $until = now()->addDays(3);

        $cart = Cart::factory()->forCustomer($user)->create([
            'converted_at' => now(),
            'from' => $from,
            'until' => $until,
        ]);

        $order = Order::createFromCart($cart);

        $this->assertEquals($from->format('Y-m-d H:i:s'), $order->from->format('Y-m-d H:i:s'));
        $this->assertEquals($until->format('Y-m-d H:i:s'), $order->until->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function order_without_booking_dates_has_null_from_until()
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->forCustomer($user)->create([
            'converted_at' => now(),
        ]);

        $order = Order::createFromCart($cart);

        $this->assertNull($order->from);
        $this->assertNull($order->until);
    }

    // =========================================================================
    // ORDER AUTOMATIC LOG CREATION TESTS
    // =========================================================================

    #[Test]
    public function order_status_change_automatically_creates_log()
    {
        $order = Order::factory()->pending()->create();

        $order->updateStatus(OrderStatus::PROCESSING);

        $statusNote = $order->notes()
            ->where('type', OrderNote::TYPE_STATUS_CHANGE)
            ->first();

        $this->assertNotNull($statusNote);
        $this->assertStringContainsString('Pending Payment', $statusNote->content);
        $this->assertStringContainsString('Processing', $statusNote->content);
    }

    #[Test]
    public function order_payment_automatically_creates_log()
    {
        $order = Order::factory()->pending()->create([
            'amount_total' => 10000,
            'amount_paid' => 0,
        ]);

        $order->recordPayment(5000, 'pi_test123', 'card', 'stripe');

        $paymentNote = $order->notes()
            ->where('type', OrderNote::TYPE_PAYMENT)
            ->first();

        $this->assertNotNull($paymentNote);
        $this->assertStringContainsString('50.00', $paymentNote->content);
    }

    #[Test]
    public function order_refund_automatically_creates_log()
    {
        $order = Order::factory()->paid()->create([
            'amount_total' => 10000,
            'amount_paid' => 10000,
        ]);

        $order->recordRefund(3000, 'Partial refund');

        $refundNote = $order->notes()
            ->where('type', OrderNote::TYPE_REFUND)
            ->first();

        $this->assertNotNull($refundNote);
        $this->assertStringContainsString('30.00', $refundNote->content);
    }

    #[Test]
    public function order_shipping_creates_log_with_tracking()
    {
        $order = Order::factory()->processing()->create();

        $order->markAsShipped('TRACK123456', 'FedEx');

        $shippingNote = $order->notes()
            ->where('type', OrderNote::TYPE_STATUS_CHANGE)
            ->orderBy('created_at', 'desc')
            ->first();

        $this->assertNotNull($shippingNote);
        $this->assertStringContainsString('TRACK123456', $shippingNote->content);
        $this->assertStringContainsString('FedEx', $shippingNote->content);
    }

    #[Test]
    public function order_cancellation_creates_log_with_reason()
    {
        $order = Order::factory()->pending()->create();

        $order->cancel('Customer changed their mind');

        $cancelNote = $order->notes()
            ->where('type', OrderNote::TYPE_STATUS_CHANGE)
            ->orderBy('created_at', 'desc')
            ->first();

        $this->assertNotNull($cancelNote);
        $this->assertStringContainsString('Customer changed their mind', $cancelNote->content);
    }

    // =========================================================================
    // ORDER MANUAL LOG CREATION TESTS
    // =========================================================================

    #[Test]
    public function order_can_add_manual_internal_note()
    {
        $order = Order::factory()->create();

        $note = $order->addNote('This is a manual internal note', OrderNote::TYPE_NOTE, false);

        $this->assertEquals('This is a manual internal note', $note->content);
        $this->assertEquals(OrderNote::TYPE_NOTE, $note->type);
        $this->assertFalse($note->is_customer_note);
    }

    #[Test]
    public function order_can_add_manual_customer_visible_note()
    {
        $order = Order::factory()->create();

        $note = $order->addNote('Thank you for your order!', OrderNote::TYPE_CUSTOMER, true);

        $this->assertEquals('Thank you for your order!', $note->content);
        $this->assertTrue($note->is_customer_note);
        $this->assertCount(1, $order->customerNotes);
    }

    #[Test]
    public function order_can_add_note_with_author()
    {
        $order = Order::factory()->create();
        $admin = User::factory()->create();

        $note = $order->addNote(
            'Admin reviewed this order',
            OrderNote::TYPE_NOTE,
            false,
            $admin
        );

        $this->assertEquals($admin->id, $note->author_id);
        $this->assertEquals(get_class($admin), $note->author_type);
        $this->assertTrue($note->author->is($admin));
    }

    #[Test]
    public function order_can_add_note_with_meta()
    {
        $order = Order::factory()->create();

        $note = $order->addNote('Note with metadata', OrderNote::TYPE_SYSTEM, false, null, [
            'source' => 'api',
            'request_id' => 'req_12345',
        ]);

        $this->assertEquals('api', $note->meta->source);
        $this->assertEquals('req_12345', $note->meta->request_id);
    }

    #[Test]
    public function order_notes_are_ordered_by_newest_first()
    {
        $order = Order::factory()->create();

        $note1 = $order->addNote('First note');
        sleep(1); // Ensure different timestamps
        $note2 = $order->addNote('Second note');

        $notes = $order->notes()->get();

        $this->assertEquals($note2->id, $notes->first()->id);
        $this->assertEquals($note1->id, $notes->last()->id);
    }

    #[Test]
    public function order_logs_multiple_status_changes()
    {
        $order = Order::factory()->pending()->create();

        $order->updateStatus(OrderStatus::PROCESSING);
        $order->updateStatus(OrderStatus::IN_PREPARATION);
        $order->updateStatus(OrderStatus::SHIPPED);

        $statusNotes = $order->notes()
            ->where('type', OrderNote::TYPE_STATUS_CHANGE)
            ->get();

        // Should have 3 status change notes
        $this->assertCount(3, $statusNotes);
    }

    #[Test]
    public function order_logs_multiple_partial_payments()
    {
        $order = Order::factory()->pending()->create([
            'amount_total' => 10000,
            'amount_paid' => 0,
        ]);

        $order->recordPayment(3000);
        $order->recordPayment(3000);
        $order->recordPayment(4000);

        $paymentNotes = $order->notes()
            ->where('type', OrderNote::TYPE_PAYMENT)
            ->get();

        $this->assertCount(3, $paymentNotes);
    }

    // =========================================================================
    // ORDER LOG FILTERING TESTS
    // =========================================================================

    #[Test]
    public function order_can_filter_internal_notes()
    {
        $order = Order::factory()->create();

        $order->addNote('Internal 1', OrderNote::TYPE_NOTE, false);
        $order->addNote('Internal 2', OrderNote::TYPE_NOTE, false);
        $order->addNote('Customer visible', OrderNote::TYPE_CUSTOMER, true);

        $this->assertCount(2, $order->internalNotes);
    }

    #[Test]
    public function order_can_get_notes_by_type()
    {
        $order = Order::factory()->pending()->create([
            'amount_total' => 10000,
        ]);

        $order->addNote('Manual note', OrderNote::TYPE_NOTE);
        $order->recordPayment(5000);
        $order->updateStatus(OrderStatus::PROCESSING);

        $notesByType = $order->notes()->where('type', OrderNote::TYPE_NOTE)->get();
        $paymentsByType = $order->notes()->where('type', OrderNote::TYPE_PAYMENT)->get();
        $statusChangesByType = $order->notes()->where('type', OrderNote::TYPE_STATUS_CHANGE)->get();

        $this->assertCount(1, $notesByType);
        $this->assertCount(1, $paymentsByType);
        $this->assertCount(1, $statusChangesByType);
    }
}
