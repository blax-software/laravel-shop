<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Models\Order;
use Blax\Shop\Models\OrderNote;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

class OrderNoteTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // ORDER NOTE CREATION TESTS
    // =========================================================================

    #[Test]
    public function order_note_can_be_created()
    {
        $order = Order::factory()->create();
        $note = OrderNote::factory()->forOrder($order)->create([
            'content' => 'Test note content',
        ]);

        $this->assertInstanceOf(OrderNote::class, $note);
        $this->assertEquals('Test note content', $note->content);
        $this->assertEquals($order->id, $note->order_id);
    }

    #[Test]
    public function order_note_default_type_is_note()
    {
        $order = Order::factory()->create();
        $note = $order->addNote('Test note');

        $this->assertEquals(OrderNote::TYPE_NOTE, $note->type);
    }

    #[Test]
    public function order_note_default_is_not_customer_note()
    {
        $order = Order::factory()->create();
        $note = $order->addNote('Test note');

        $this->assertFalse($note->is_customer_note);
    }

    // =========================================================================
    // ORDER NOTE TYPES TESTS
    // =========================================================================

    #[Test]
    public function order_note_can_have_different_types()
    {
        $order = Order::factory()->create();

        $noteTypes = [
            OrderNote::TYPE_NOTE => 'Note',
            OrderNote::TYPE_STATUS_CHANGE => 'Status Change',
            OrderNote::TYPE_PAYMENT => 'Payment',
            OrderNote::TYPE_REFUND => 'Refund',
            OrderNote::TYPE_SHIPPING => 'Shipping',
            OrderNote::TYPE_CUSTOMER => 'Customer Message',
            OrderNote::TYPE_SYSTEM => 'System',
            OrderNote::TYPE_EMAIL => 'Email',
            OrderNote::TYPE_WEBHOOK => 'Webhook',
        ];

        foreach ($noteTypes as $type => $expectedLabel) {
            $note = $order->addNote("Test {$type}", $type);
            $this->assertEquals($type, $note->type);
            $this->assertEquals($expectedLabel, $note->type_label);
        }
    }

    #[Test]
    public function order_note_has_type_icon()
    {
        $order = Order::factory()->create();

        $note = $order->addNote('Test', OrderNote::TYPE_PAYMENT);
        $this->assertEquals('credit-card', $note->type_icon);

        $note2 = $order->addNote('Test', OrderNote::TYPE_SHIPPING);
        $this->assertEquals('truck', $note2->type_icon);

        $note3 = $order->addNote('Test', OrderNote::TYPE_SYSTEM);
        $this->assertEquals('cog', $note3->type_icon);
    }

    #[Test]
    public function order_note_has_type_color()
    {
        $order = Order::factory()->create();

        $note = $order->addNote('Test', OrderNote::TYPE_PAYMENT);
        $this->assertEquals('green', $note->type_color);

        $note2 = $order->addNote('Test', OrderNote::TYPE_REFUND);
        $this->assertEquals('red', $note2->type_color);

        $note3 = $order->addNote('Test', OrderNote::TYPE_STATUS_CHANGE);
        $this->assertEquals('blue', $note3->type_color);
    }

    // =========================================================================
    // ORDER NOTE CUSTOMER VISIBILITY TESTS
    // =========================================================================

    #[Test]
    public function order_note_can_be_customer_visible()
    {
        $order = Order::factory()->create();

        $note = $order->addNote('Customer visible note', 'customer', true);

        $this->assertTrue($note->is_customer_note);
    }

    #[Test]
    public function order_note_can_be_internal_only()
    {
        $order = Order::factory()->create();

        $note = $order->addNote('Internal only note', 'note', false);

        $this->assertFalse($note->is_customer_note);
    }

    // =========================================================================
    // ORDER NOTE RELATIONSHIPS TESTS
    // =========================================================================

    #[Test]
    public function order_note_belongs_to_order()
    {
        $order = Order::factory()->create();
        $note = $order->addNote('Test note');

        $this->assertTrue($note->order->is($order));
    }

    #[Test]
    public function order_note_can_have_author()
    {
        $order = Order::factory()->create();
        $user = User::factory()->create();

        $note = $order->addNote(
            'Note from user',
            'note',
            false,
            get_class($user),
            $user->id
        );

        $this->assertEquals(get_class($user), $note->author_type);
        $this->assertEquals($user->id, $note->author_id);
        $this->assertTrue($note->author->is($user));
    }

    // =========================================================================
    // ORDER NOTE SCOPES TESTS
    // =========================================================================

    #[Test]
    public function order_note_can_be_scoped_to_customer_notes()
    {
        $order = Order::factory()->create();

        $order->addNote('Internal 1', 'note', false);
        $order->addNote('Customer 1', 'customer', true);
        $order->addNote('Internal 2', 'note', false);
        $order->addNote('Customer 2', 'customer', true);

        $customerNotes = $order->notes()->forCustomer()->get();
        $internalNotes = $order->notes()->internal()->get();

        $this->assertCount(2, $customerNotes);
        $this->assertCount(2, $internalNotes);
    }

    #[Test]
    public function order_note_can_be_scoped_by_type()
    {
        $order = Order::factory()->create();

        $order->addNote('Note 1', OrderNote::TYPE_NOTE);
        $order->addNote('Payment 1', OrderNote::TYPE_PAYMENT);
        $order->addNote('Payment 2', OrderNote::TYPE_PAYMENT);
        $order->addNote('Shipping 1', OrderNote::TYPE_SHIPPING);

        $this->assertCount(2, $order->notes()->ofType(OrderNote::TYPE_PAYMENT)->get());
        $this->assertCount(1, $order->notes()->ofType(OrderNote::TYPE_SHIPPING)->get());
        $this->assertCount(1, $order->notes()->ofType(OrderNote::TYPE_NOTE)->get());
    }

    #[Test]
    public function order_note_can_be_scoped_by_multiple_types()
    {
        $order = Order::factory()->create();

        $order->addNote('Note', OrderNote::TYPE_NOTE);
        $order->addNote('Payment', OrderNote::TYPE_PAYMENT);
        $order->addNote('Refund', OrderNote::TYPE_REFUND);
        $order->addNote('Shipping', OrderNote::TYPE_SHIPPING);

        $paymentRelated = $order->notes()->paymentRelated()->get();

        $this->assertCount(2, $paymentRelated);
    }

    #[Test]
    public function order_note_can_be_scoped_to_system_notes()
    {
        $order = Order::factory()->create();

        $order->addNote('System note', OrderNote::TYPE_SYSTEM);
        $order->addNote('Regular note', OrderNote::TYPE_NOTE);

        $this->assertCount(1, $order->notes()->system()->get());
    }

    // =========================================================================
    // ORDER NOTE META TESTS
    // =========================================================================

    #[Test]
    public function order_note_can_store_meta()
    {
        $order = Order::factory()->create();

        $note = OrderNote::factory()->forOrder($order)->create([
            'meta' => (object) ['key' => 'value'],
        ]);

        $this->assertEquals('value', $note->getMeta('key'));
    }

    #[Test]
    public function order_note_can_update_meta_key()
    {
        $order = Order::factory()->create();
        $note = OrderNote::factory()->forOrder($order)->create();

        $note->updateMetaKey('custom', 'data');

        $this->assertEquals('data', $note->fresh()->getMeta('custom'));
    }

    #[Test]
    public function order_note_returns_default_for_missing_meta()
    {
        $order = Order::factory()->create();
        $note = OrderNote::factory()->forOrder($order)->create();

        $this->assertNull($note->getMeta('nonexistent'));
        $this->assertEquals('default', $note->getMeta('nonexistent', 'default'));
    }

    // =========================================================================
    // ORDER NOTE FACTORY METHODS TESTS
    // =========================================================================

    #[Test]
    public function order_note_can_create_system_note()
    {
        $order = Order::factory()->create();

        $note = OrderNote::createSystemNote($order, 'System action occurred');

        $this->assertEquals(OrderNote::TYPE_SYSTEM, $note->type);
        $this->assertFalse($note->is_customer_note);
        $this->assertEquals('System action occurred', $note->content);
    }

    #[Test]
    public function order_note_can_create_customer_note()
    {
        $order = Order::factory()->create();
        $user = User::factory()->create();

        $note = OrderNote::createCustomerNote($order, 'Hello, I have a question', $user);

        $this->assertEquals(OrderNote::TYPE_CUSTOMER, $note->type);
        $this->assertTrue($note->is_customer_note);
        $this->assertEquals($user->id, $note->author_id);
    }

    #[Test]
    public function order_note_can_create_payment_note()
    {
        $order = Order::factory()->create();

        $note = OrderNote::createPaymentNote($order, 'Payment received', 'pi_123', 10000);

        $this->assertEquals(OrderNote::TYPE_PAYMENT, $note->type);
        $this->assertEquals('pi_123', $note->getMeta('payment_reference'));
        $this->assertEquals(10000, $note->getMeta('amount'));
    }

    #[Test]
    public function order_note_can_create_shipping_note()
    {
        $order = Order::factory()->create();

        $note = OrderNote::createShippingNote($order, 'Order shipped', 'TRACK123', 'FedEx');

        $this->assertEquals(OrderNote::TYPE_SHIPPING, $note->type);
        $this->assertTrue($note->is_customer_note);
        $this->assertEquals('TRACK123', $note->getMeta('tracking_number'));
        $this->assertEquals('FedEx', $note->getMeta('carrier'));
    }

    // =========================================================================
    // ORDER NOTE FACTORY STATES TESTS
    // =========================================================================

    #[Test]
    public function order_note_factory_creates_status_change()
    {
        $order = Order::factory()->create();
        $note = OrderNote::factory()->forOrder($order)->statusChange()->create();

        $this->assertEquals(OrderNote::TYPE_STATUS_CHANGE, $note->type);
    }

    #[Test]
    public function order_note_factory_creates_payment_note()
    {
        $order = Order::factory()->create();
        $note = OrderNote::factory()->forOrder($order)->payment(5000)->create();

        $this->assertEquals(OrderNote::TYPE_PAYMENT, $note->type);
        $this->assertEquals(5000, $note->getMeta('amount'));
    }

    #[Test]
    public function order_note_factory_creates_refund_note()
    {
        $order = Order::factory()->create();
        $note = OrderNote::factory()->forOrder($order)->refund(3000, 'Damaged item')->create();

        $this->assertEquals(OrderNote::TYPE_REFUND, $note->type);
        $this->assertStringContainsString('30.00', $note->content);
        $this->assertStringContainsString('Damaged item', $note->content);
    }

    #[Test]
    public function order_note_factory_creates_shipping_note()
    {
        $order = Order::factory()->create();
        $note = OrderNote::factory()->forOrder($order)->shipping('ABC123', 'UPS')->create();

        $this->assertEquals(OrderNote::TYPE_SHIPPING, $note->type);
        $this->assertTrue($note->is_customer_note);
        $this->assertStringContainsString('ABC123', $note->content);
        $this->assertStringContainsString('UPS', $note->content);
    }

    #[Test]
    public function order_note_factory_creates_customer_message()
    {
        $order = Order::factory()->create();
        $note = OrderNote::factory()->forOrder($order)->customerMessage()->create([
            'content' => 'Customer inquiry',
        ]);

        $this->assertEquals(OrderNote::TYPE_CUSTOMER, $note->type);
        $this->assertTrue($note->is_customer_note);
    }

    // =========================================================================
    // ORDER NOTES ORDERING TESTS
    // =========================================================================

    #[Test]
    public function order_notes_are_ordered_by_created_at_desc()
    {
        $order = Order::factory()->create();

        $note1 = OrderNote::factory()->forOrder($order)->create(['created_at' => now()->subHours(2)]);
        $note2 = OrderNote::factory()->forOrder($order)->create(['created_at' => now()->subHour()]);
        $note3 = OrderNote::factory()->forOrder($order)->create(['created_at' => now()]);

        $notes = $order->notes;

        $this->assertEquals($note3->id, $notes->first()->id);
        $this->assertEquals($note1->id, $notes->last()->id);
    }
}
