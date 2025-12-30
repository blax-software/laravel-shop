<?php

namespace Blax\Shop\Tests\Feature\Checkout;

use Blax\Shop\Enums\CartStatus;
use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Order;
use Blax\Shop\Models\OrderNote;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

class OrderCheckoutFlowTest extends TestCase
{
    use RefreshDatabase;

    // =========================================================================
    // CHECKOUT ORDER CREATION TESTS
    // =========================================================================

    #[Test]
    public function checkout_creates_order_from_cart()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 2);

        $cart = $user->checkoutCart();

        $this->assertNotNull($cart->converted_at);
        $this->assertNotNull($cart->order);
        $this->assertInstanceOf(Order::class, $cart->order);
    }

    #[Test]
    public function order_has_correct_cart_id()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $order = $cart->order;

        $this->assertEquals($cart->id, $order->cart_id);
    }

    #[Test]
    public function order_has_correct_customer_info()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $order = $cart->order;

        $this->assertEquals(get_class($user), $order->customer_type);
        $this->assertEquals($user->id, $order->customer_id);
        $this->assertTrue($order->customer->is($user));
    }

    #[Test]
    public function order_has_correct_currency()
    {
        $user = User::factory()->create();
        $cart = Cart::factory()->forCustomer($user)->create([
            'currency' => 'EUR',
        ]);
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        $cart->addToCart($product, quantity: 1);
        $cart->checkout();

        $order = $cart->fresh()->order;

        $this->assertEquals('EUR', $order->currency);
    }

    #[Test]
    public function order_has_correct_total_amount()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
        ]);
        $product2 = Product::factory()->withPrices(unit_amount: 30.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product1, quantity: 2); // 100.00
        $user->addToCart($product2, quantity: 3); // 90.00

        $cart = $user->checkoutCart();
        $order = $cart->order;

        // Total should be 190.00 (19000 cents)
        $this->assertEquals(19000, $order->amount_total);
        $this->assertEquals(19000, $order->amount_subtotal);
    }

    #[Test]
    public function order_starts_with_pending_status()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $order = $cart->order;

        $this->assertEquals(OrderStatus::PENDING, $order->status);
    }

    #[Test]
    public function order_starts_with_zero_paid_amount()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $order = $cart->order;

        $this->assertEquals(0, $order->amount_paid);
        $this->assertFalse($order->is_paid);
        $this->assertFalse($order->is_fully_paid);
    }

    #[Test]
    public function order_has_unique_order_number()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart1 = $user->checkoutCart();
        $order1 = $cart1->order;

        // Create another cart and checkout
        $user->addToCart($product, quantity: 1);
        $cart2 = $user->checkoutCart();
        $order2 = $cart2->order;

        $this->assertNotEquals($order1->order_number, $order2->order_number);
    }

    #[Test]
    public function order_creation_adds_system_note()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $order = $cart->order;

        $this->assertTrue(
            $order->notes()
                ->where('type', OrderNote::TYPE_SYSTEM)
                ->where('content', 'like', '%created from cart%')
                ->exists()
        );
    }

    // =========================================================================
    // ORDER PURCHASES RELATIONSHIP TESTS
    // =========================================================================

    #[Test]
    public function order_has_purchases_through_cart()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
        ]);
        $product2 = Product::factory()->withPrices(unit_amount: 30.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product1, quantity: 2);
        $user->addToCart($product2, quantity: 1);

        $cart = $user->checkoutCart();
        $order = $cart->order;

        $this->assertCount(2, $order->directPurchases);
    }

    #[Test]
    public function order_purchases_have_correct_status()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $purchase = $cart->purchases()->first();

        $this->assertEquals(PurchaseStatus::UNPAID, $purchase->status);
    }

    // =========================================================================
    // ORDER PAYMENT FLOW TESTS
    // =========================================================================

    #[Test]
    public function order_payment_updates_status()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 100.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $order = $cart->order;
        $order->recordPayment(10000, 'pi_test123', 'card', 'stripe');

        $order->refresh();

        $this->assertEquals(10000, $order->amount_paid);
        $this->assertEquals(OrderStatus::PROCESSING, $order->status);
        $this->assertTrue($order->is_fully_paid);
        $this->assertNotNull($order->paid_at);
    }

    #[Test]
    public function order_payment_updates_purchase_status()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 100.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $order = $cart->order;
        $order->recordPayment(10000);

        $purchase = $cart->purchases()->first();

        $this->assertEquals(PurchaseStatus::COMPLETED, $purchase->status);
        $this->assertEquals($purchase->amount, $purchase->amount_paid);
    }

    #[Test]
    public function order_partial_payment_does_not_complete_order()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 100.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $order = $cart->order;
        $order->recordPayment(5000); // 50%

        $order->refresh();

        $this->assertEquals(5000, $order->amount_paid);
        $this->assertEquals(5000, $order->amount_outstanding);
        $this->assertFalse($order->is_fully_paid);
        $this->assertNull($order->paid_at);
    }

    // =========================================================================
    // ORDER STATUS WORKFLOW TESTS
    // =========================================================================

    #[Test]
    public function order_can_be_processed_after_payment()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 100.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $order = $cart->order;
        $order->recordPayment(10000);
        $order->markAsInPreparation();

        $this->assertEquals(OrderStatus::IN_PREPARATION, $order->fresh()->status);
    }

    #[Test]
    public function order_can_be_shipped_with_tracking()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 100.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $order = $cart->order;
        $order->recordPayment(10000);
        $order->markAsShipped('TRACK123', 'FedEx');

        $order->refresh();

        $this->assertEquals(OrderStatus::SHIPPED, $order->status);
        $this->assertEquals('TRACK123', $order->getMeta('tracking_number'));
        $this->assertNotNull($order->shipped_at);
    }

    #[Test]
    public function order_can_be_completed()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 100.00)->create([
            'manage_stock' => false,
            'virtual' => true, // Virtual product
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $order = $cart->order;
        $order->recordPayment(10000);
        $order->markAsCompleted();

        $order->refresh();

        $this->assertEquals(OrderStatus::COMPLETED, $order->status);
        $this->assertNotNull($order->completed_at);
    }

    // =========================================================================
    // ORDER CANCELLATION TESTS
    // =========================================================================

    #[Test]
    public function order_can_be_cancelled()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 100.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $order = $cart->order;
        $order->cancel('Customer changed their mind');

        $order->refresh();

        $this->assertEquals(OrderStatus::CANCELLED, $order->status);
        $this->assertNotNull($order->cancelled_at);

        // Verify the reason is logged
        $this->assertTrue(
            $order->notes()
                ->where('content', 'Customer changed their mind')
                ->exists()
        );
    }

    // =========================================================================
    // ORDER REFUND TESTS
    // =========================================================================

    #[Test]
    public function order_can_be_refunded()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 100.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $order = $cart->order;
        $order->recordPayment(10000);
        $order->recordRefund(10000, 'Full refund');

        $order->refresh();

        $this->assertEquals(OrderStatus::REFUNDED, $order->status);
        $this->assertEquals(10000, $order->amount_refunded);
        $this->assertNotNull($order->refunded_at);
    }

    #[Test]
    public function order_partial_refund_does_not_change_status()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 100.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $order = $cart->order;
        $order->recordPayment(10000);
        $order->recordRefund(3000, 'Partial refund');

        $order->refresh();

        $this->assertEquals(OrderStatus::PROCESSING, $order->status);
        $this->assertEquals(3000, $order->amount_refunded);
    }

    // =========================================================================
    // ORDER NOTES DURING LIFECYCLE TESTS
    // =========================================================================

    #[Test]
    public function order_logs_status_changes()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 100.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $order = $cart->order;
        $order->recordPayment(10000);
        $order->markAsShipped('TRACK123');
        $order->markAsCompleted();

        $statusNotes = $order->notes()
            ->where('type', OrderNote::TYPE_STATUS_CHANGE)
            ->get();

        $this->assertGreaterThanOrEqual(3, $statusNotes->count());
    }

    #[Test]
    public function order_logs_payment_notes()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 100.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $order = $cart->order;
        $order->recordPayment(5000);
        $order->recordPayment(5000);

        $paymentNotes = $order->notes()
            ->where('type', OrderNote::TYPE_PAYMENT)
            ->get();

        $this->assertCount(2, $paymentNotes);
    }

    // =========================================================================
    // CART STATUS UPDATE TESTS
    // =========================================================================

    #[Test]
    public function cart_status_is_converted_after_checkout()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 100.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->checkoutCart();

        $this->assertEquals(CartStatus::CONVERTED, $cart->status);
    }

    // =========================================================================
    // MULTIPLE PRODUCTS CHECKOUT TESTS
    // =========================================================================

    #[Test]
    public function checkout_handles_multiple_products_correctly()
    {
        $user = User::factory()->create();

        $products = Product::factory()
            ->withPrices(unit_amount: 25.00)
            ->count(5)
            ->create(['manage_stock' => false]);

        foreach ($products as $product) {
            $user->addToCart($product, quantity: 2);
        }

        $cart = $user->checkoutCart();
        $order = $cart->order;

        $this->assertCount(5, $order->directPurchases);
        $this->assertEquals(25000, $order->amount_total); // 5 products * 2 qty * 25.00 = 250.00
    }

    // =========================================================================
    // ORDER QUERY TESTS
    // =========================================================================

    #[Test]
    public function can_find_orders_for_customer()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        // Create 3 orders for user1
        for ($i = 0; $i < 3; $i++) {
            $user1->addToCart($product, quantity: 1);
            $user1->checkoutCart();
        }

        // Create 2 orders for user2
        for ($i = 0; $i < 2; $i++) {
            $user2->addToCart($product, quantity: 1);
            $user2->checkoutCart();
        }

        $this->assertCount(3, Order::forCustomer($user1)->get());
        $this->assertCount(2, Order::forCustomer($user2)->get());
    }

    #[Test]
    public function can_find_paid_orders()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
        ]);

        // Create and pay one order
        $user->addToCart($product, quantity: 1);
        $cart1 = $user->checkoutCart();
        $cart1->order->recordPayment(5000);

        // Create unpaid order
        $user->addToCart($product, quantity: 1);
        $user->checkoutCart();

        $this->assertCount(1, Order::paid()->get());
        $this->assertCount(1, Order::unpaid()->get());
    }
}
