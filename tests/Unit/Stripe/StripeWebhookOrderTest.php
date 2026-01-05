<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Enums\CartStatus;
use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Http\Controllers\StripeWebhookController;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Order;
use Blax\Shop\Models\OrderNote;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Workbench\App\Models\User;

class StripeWebhookOrderTest extends TestCase
{
    use RefreshDatabase;

    protected StripeWebhookController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.stripe.enabled' => true]);
        $this->controller = new StripeWebhookController();
    }

    /**
     * Call a protected method on the controller
     */
    protected function invokeMethod(string $method, array $args = [])
    {
        $reflection = new ReflectionClass($this->controller);
        $method = $reflection->getMethod($method);
        $method->setAccessible(true);
        return $method->invokeArgs($this->controller, $args);
    }

    /**
     * Create a mock session object
     */
    protected function createMockSession(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 'cs_test_' . uniqid(),
            'payment_intent' => 'pi_test_' . uniqid(),
            'metadata' => (object) ['cart_id' => null],
            'client_reference_id' => null,
            'amount_total' => 10000, // 100.00
            'currency' => 'usd',
            'payment_status' => 'paid',
            'customer' => 'cus_test_123',
        ], $overrides);
    }

    /**
     * Create a mock charge object
     */
    protected function createMockCharge(array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 'ch_test_' . uniqid(),
            'payment_intent' => 'pi_test_' . uniqid(),
            'amount' => 10000, // 100.00
            'amount_refunded' => 0,
            'currency' => 'usd',
            'failure_message' => null,
            'failure_code' => null,
            'receipt_url' => 'https://receipt.stripe.com/test',
        ], $overrides);
    }

    /**
     * Create a product for testing
     */
    protected function createProduct(int $price = 10000): Product
    {
        return Product::factory()->withPrices(unit_amount: $price)->create([
            'manage_stock' => false,
        ]);
    }

    #[Test]
    public function checkout_session_completed_creates_order_payment()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(10000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;
        $this->assertNotNull($order);
        $this->assertEquals(0, $order->amount_paid);

        // Simulate checkout session completed
        $session = $this->createMockSession([
            'metadata' => (object) ['cart_id' => $cart->id],
            'amount_total' => 10000, // 100.00
        ]);

        $this->invokeMethod('handleCheckoutSessionCompleted', [$session]);

        $order->refresh();
        $this->assertEquals(10000, $order->amount_paid);
        $this->assertEquals(OrderStatus::PROCESSING, $order->status);
    }

    #[Test]
    public function checkout_session_completed_logs_payment_note()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(5000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;
        $initialNoteCount = $order->notes()->count();

        $session = $this->createMockSession([
            'metadata' => (object) ['cart_id' => $cart->id],
            'amount_total' => 5000,
            'payment_intent' => 'pi_test_12345',
        ]);

        $this->invokeMethod('handleCheckoutSessionCompleted', [$session]);

        $order->refresh();
        $this->assertGreaterThan($initialNoteCount, $order->notes()->count());

        $paymentNote = $order->notes()->where('type', OrderNote::TYPE_PAYMENT)->first();
        $this->assertNotNull($paymentNote);
    }

    #[Test]
    public function checkout_session_failed_updates_order_status()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(10000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;

        $session = $this->createMockSession([
            'metadata' => (object) ['cart_id' => $cart->id],
        ]);

        $this->invokeMethod('handleCheckoutSessionFailed', [$session]);

        $order->refresh();
        $this->assertEquals(OrderStatus::FAILED, $order->status);
    }

    #[Test]
    public function checkout_session_failed_adds_payment_note()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(5000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;

        $session = $this->createMockSession([
            'metadata' => (object) ['cart_id' => $cart->id],
        ]);

        $this->invokeMethod('handleCheckoutSessionFailed', [$session]);

        $failedNote = $order->notes()
            ->where('type', OrderNote::TYPE_PAYMENT)
            ->where('content', 'like', '%failed%')
            ->first();

        $this->assertNotNull($failedNote);
    }

    #[Test]
    public function checkout_session_expired_adds_system_note()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(5000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;

        $session = $this->createMockSession([
            'metadata' => (object) ['cart_id' => $cart->id],
        ]);

        $this->invokeMethod('handleCheckoutSessionExpired', [$session]);

        $expiredNote = $order->notes()
            ->where('type', OrderNote::TYPE_SYSTEM)
            ->where('content', 'like', '%expired%')
            ->first();

        $this->assertNotNull($expiredNote);
    }

    #[Test]
    public function charge_refunded_records_refund_on_order()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(10000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;

        // First record a payment (amount in cents: 100.00 * 100 = 10000)
        $order->recordPayment(10000, 'pi_test_123', 'stripe', 'stripe');
        $this->assertTrue($order->is_fully_paid);

        // Update the order's payment_reference so we can find it
        $order->update(['payment_reference' => 'pi_test_123']);

        // Now simulate a refund
        $charge = $this->createMockCharge([
            'payment_intent' => 'pi_test_123',
            'amount_refunded' => 5000, // 50.00 in cents
        ]);

        $this->invokeMethod('handleChargeRefunded', [$charge]);

        $order->refresh();
        $this->assertEquals(50, $order->amount_refunded);
    }

    #[Test]
    public function charge_dispute_created_puts_order_on_hold()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(10000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;
        $order->recordPayment(100, 'ch_test_dispute', 'stripe', 'stripe');
        $order->update([
            'status' => OrderStatus::PROCESSING,
            'payment_reference' => 'ch_test_dispute',
        ]);

        $dispute = (object) [
            'id' => 'dp_test_123',
            'charge' => 'ch_test_dispute',
            'amount' => 10000,
            'reason' => 'fraudulent',
        ];

        $this->invokeMethod('handleChargeDisputeCreated', [$dispute]);

        $order->refresh();
        $this->assertEquals(OrderStatus::ON_HOLD, $order->status);
    }

    #[Test]
    public function charge_dispute_created_adds_payment_note()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(10000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;
        $order->update(['payment_reference' => 'ch_test_dispute2']);

        $dispute = (object) [
            'id' => 'dp_test_456',
            'charge' => 'ch_test_dispute2',
            'amount' => 10000,
            'reason' => 'product_not_received',
        ];

        $this->invokeMethod('handleChargeDisputeCreated', [$dispute]);

        $disputeNote = $order->notes()
            ->where('type', OrderNote::TYPE_PAYMENT)
            ->where('content', 'like', '%dispute%')
            ->first();

        $this->assertNotNull($disputeNote);
    }

    #[Test]
    public function charge_dispute_closed_restores_order_if_won()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(10000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;
        $order->update([
            'status' => OrderStatus::ON_HOLD,
            'payment_reference' => 'ch_test_won',
        ]);

        $dispute = (object) [
            'id' => 'dp_test_789',
            'charge' => 'ch_test_won',
            'status' => 'won',
        ];

        $this->invokeMethod('handleChargeDisputeClosed', [$dispute]);

        $order->refresh();
        $this->assertEquals(OrderStatus::PROCESSING, $order->status);
    }

    #[Test]
    public function charge_dispute_closed_refunds_order_if_lost()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(10000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;
        $order->update([
            'status' => OrderStatus::ON_HOLD,
            'payment_reference' => 'ch_test_lost',
        ]);

        $dispute = (object) [
            'id' => 'dp_test_000',
            'charge' => 'ch_test_lost',
            'status' => 'lost',
        ];

        $this->invokeMethod('handleChargeDisputeClosed', [$dispute]);

        $order->refresh();
        $this->assertEquals(OrderStatus::REFUNDED, $order->status);
    }

    #[Test]
    public function refund_created_records_refund_on_order()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(10000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;
        $order->recordPayment(100, 'ch_refund_test', 'stripe', 'stripe');
        $order->update(['payment_reference' => 'ch_refund_test']);

        $refund = (object) [
            'id' => 're_test_123',
            'charge' => 'ch_refund_test',
            'amount' => 2500, // 25.00
            'reason' => 'requested_by_customer',
            'status' => 'succeeded',
        ];

        $this->invokeMethod('handleRefundCreated', [$refund]);

        $order->refresh();
        $this->assertEquals(25, $order->amount_refunded);
    }

    #[Test]
    public function refund_updated_adds_note_to_order()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(10000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;
        $order->update(['payment_reference' => 'ch_refund_update']);

        $refund = (object) [
            'id' => 're_test_456',
            'charge' => 'ch_refund_update',
            'status' => 'succeeded',
        ];

        $this->invokeMethod('handleRefundUpdated', [$refund]);

        $refundNote = $order->notes()
            ->where('type', OrderNote::TYPE_REFUND)
            ->first();

        $this->assertNotNull($refundNote);
    }

    #[Test]
    public function find_order_by_payment_intent_works()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(10000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;
        $order->update(['payment_reference' => 'pi_find_test']);

        $foundOrder = $this->invokeMethod('findOrderByPaymentIntent', ['pi_find_test']);

        $this->assertNotNull($foundOrder);
        $this->assertEquals($order->id, $foundOrder->id);
    }

    #[Test]
    public function find_order_by_charge_id_works()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(10000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;
        $order->update(['payment_reference' => 'ch_find_test']);

        $foundOrder = $this->invokeMethod('findOrderByChargeId', ['ch_find_test']);

        $this->assertNotNull($foundOrder);
        $this->assertEquals($order->id, $foundOrder->id);
    }

    #[Test]
    public function find_order_returns_null_for_unknown_payment_intent()
    {
        $foundOrder = $this->invokeMethod('findOrderByPaymentIntent', ['pi_unknown_123']);
        $this->assertNull($foundOrder);
    }

    #[Test]
    public function handlers_return_false_for_missing_cart()
    {
        $session = $this->createMockSession([
            'metadata' => (object) ['cart_id' => 'nonexistent-cart-id'],
        ]);

        $result = $this->invokeMethod('handleCheckoutSessionCompleted', [$session]);
        $this->assertFalse($result);
    }

    #[Test]
    public function handlers_return_false_for_missing_cart_id()
    {
        $session = $this->createMockSession([
            'metadata' => (object) ['cart_id' => null],
            'client_reference_id' => null,
        ]);

        $result = $this->invokeMethod('handleCheckoutSessionCompleted', [$session]);
        $this->assertFalse($result);
    }

    #[Test]
    public function handler_uses_client_reference_id_as_fallback()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(5000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $session = $this->createMockSession([
            'metadata' => (object) [], // No cart_id in metadata
            'client_reference_id' => $cart->id, // But it's in client_reference_id
            'amount_total' => 5000,
        ]);

        $result = $this->invokeMethod('handleCheckoutSessionCompleted', [$session]);

        $this->assertTrue($result);

        $order = $cart->fresh()->order;
        $this->assertEquals(5000, $order->amount_paid);
    }

    #[Test]
    public function payment_intent_canceled_adds_note()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(10000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;
        $order->update(['payment_reference' => 'pi_canceled_test']);

        $paymentIntent = (object) [
            'id' => 'pi_canceled_test',
        ];

        $this->invokeMethod('handlePaymentIntentCanceled', [$paymentIntent]);

        $cancelNote = $order->notes()
            ->where('type', OrderNote::TYPE_PAYMENT)
            ->where('content', 'like', '%canceled%')
            ->first();

        $this->assertNotNull($cancelNote);
    }

    #[Test]
    public function charge_failed_adds_failure_note_to_order()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(10000);

        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $order = $cart->fresh()->order;
        $order->update(['payment_reference' => 'pi_charge_fail']);

        $charge = $this->createMockCharge([
            'id' => 'ch_failed_test',
            'payment_intent' => 'pi_charge_fail',
            'failure_message' => 'Your card was declined.',
            'failure_code' => 'card_declined',
        ]);

        $this->invokeMethod('handleChargeFailed', [$charge]);

        $failNote = $order->notes()
            ->where('type', OrderNote::TYPE_PAYMENT)
            ->where('content', 'like', '%failed%')
            ->first();

        $this->assertNotNull($failNote);
        $this->assertStringContainsString('declined', $failNote->content);
    }

    // =========================================================================
    // STRIPE CHECKOUT SESSION FLOW TESTS (No pre-existing order)
    // =========================================================================

    #[Test]
    public function checkout_session_completed_creates_order_when_none_exists()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(10000);

        // Add to cart but DON'T call checkoutCart() - simulate checkoutSession() flow
        $customer->addToCart($product);
        $cart = $customer->currentCart();

        // Verify no order exists yet
        $this->assertNull($cart->order);

        // Simulate what checkoutSession() does: mark cart as converted
        $cart->update([
            'status' => CartStatus::CONVERTED,
            'converted_at' => now(),
        ]);

        // Now simulate checkout session completed webhook
        $session = $this->createMockSession([
            'metadata' => (object) ['cart_id' => $cart->id],
            'amount_total' => 10000, // 100.00
            'payment_status' => 'paid',
        ]);

        $result = $this->invokeMethod('handleCheckoutSessionCompleted', [$session]);

        $this->assertTrue($result);

        // Verify order was created
        $cart->refresh();
        $order = $cart->order;
        $this->assertNotNull($order, 'Order should be created by webhook');
        $this->assertEquals($cart->id, $order->cart_id);
        $this->assertEquals($customer->id, $order->customer_id);
    }

    #[Test]
    public function checkout_session_completed_creates_order_and_records_payment()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(15000);

        // Add to cart but DON'T call checkoutCart()
        $customer->addToCart($product);
        $cart = $customer->currentCart();

        // Simulate checkoutSession() conversion
        $cart->update([
            'status' => CartStatus::CONVERTED,
            'converted_at' => now(),
        ]);

        $session = $this->createMockSession([
            'metadata' => (object) ['cart_id' => $cart->id],
            'amount_total' => 15000, // 150.00
            'payment_status' => 'paid',
            'payment_intent' => 'pi_stripe_checkout_test',
        ]);

        $this->invokeMethod('handleCheckoutSessionCompleted', [$session]);

        $cart->refresh();
        $order = $cart->order;

        $this->assertNotNull($order);
        $this->assertEquals(15000, $order->amount_paid);
        $this->assertEquals(OrderStatus::PROCESSING, $order->status);
    }

    #[Test]
    public function checkout_session_completed_creates_order_with_correct_totals()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(7550);

        $customer->addToCart($product, 2); // 2 items = 151.00
        $cart = $customer->currentCart();

        $cart->update([
            'status' => CartStatus::CONVERTED,
            'converted_at' => now(),
        ]);

        $session = $this->createMockSession([
            'metadata' => (object) ['cart_id' => $cart->id],
            'amount_total' => 15100, // 151.00
            'payment_status' => 'paid',
        ]);

        $this->invokeMethod('handleCheckoutSessionCompleted', [$session]);

        $order = $cart->fresh()->order;

        $this->assertNotNull($order);
        // Order total should match cart total (already in cents)
        $this->assertEquals((int) $cart->getTotal(), $order->amount_total);
    }

    #[Test]
    public function checkout_session_completed_adds_payment_note_when_creating_order()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(5000);

        $customer->addToCart($product);
        $cart = $customer->currentCart();

        $cart->update([
            'status' => CartStatus::CONVERTED,
            'converted_at' => now(),
        ]);

        $session = $this->createMockSession([
            'metadata' => (object) ['cart_id' => $cart->id],
            'amount_total' => 5000,
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_payment_note',
        ]);

        $this->invokeMethod('handleCheckoutSessionCompleted', [$session]);

        $order = $cart->fresh()->order;

        $this->assertNotNull($order);

        $paymentNote = $order->notes()->where('type', OrderNote::TYPE_PAYMENT)->first();
        $this->assertNotNull($paymentNote, 'Payment note should be created');
        $this->assertStringContainsString('50', $paymentNote->content);
        $this->assertStringContainsString('Stripe checkout', $paymentNote->content);
    }

    #[Test]
    public function checkout_session_completed_does_not_duplicate_order()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(10000);

        // Use checkoutCart() which creates an order
        $customer->addToCart($product);
        $cart = $customer->checkoutCart();

        $existingOrder = $cart->fresh()->order;
        $this->assertNotNull($existingOrder);
        $originalOrderId = $existingOrder->id;

        // Now call webhook - should NOT create a duplicate order
        $session = $this->createMockSession([
            'metadata' => (object) ['cart_id' => $cart->id],
            'amount_total' => 10000,
            'payment_status' => 'paid',
        ]);

        $this->invokeMethod('handleCheckoutSessionCompleted', [$session]);

        $cart->refresh();

        // Should still be the same order
        $this->assertEquals($originalOrderId, $cart->order->id);

        // Should only have one order for this cart
        $orderCount = Order::where('cart_id', $cart->id)->count();
        $this->assertEquals(1, $orderCount);
    }

    #[Test]
    public function checkout_session_completed_without_prior_conversion_creates_order()
    {
        $customer = User::factory()->create();
        $product = $this->createProduct(20000);

        // Add to cart - cart is NOT converted yet (simulates edge case)
        $customer->addToCart($product);
        $cart = $customer->currentCart();

        $this->assertEquals(CartStatus::ACTIVE, $cart->status);
        $this->assertNull($cart->order);

        // Webhook fires - should convert cart AND create order
        $session = $this->createMockSession([
            'metadata' => (object) ['cart_id' => $cart->id],
            'amount_total' => 20000,
            'payment_status' => 'paid',
        ]);

        $this->invokeMethod('handleCheckoutSessionCompleted', [$session]);

        $cart->refresh();

        // Cart should be converted
        $this->assertEquals(CartStatus::CONVERTED, $cart->status);
        $this->assertNotNull($cart->converted_at);

        // Order should exist
        $this->assertNotNull($cart->order);
        $this->assertEquals(20000, $cart->order->amount_paid);
    }
}
