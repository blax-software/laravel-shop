<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Models\Order;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

class HasOrdersTraitTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_can_have_orders_relationship()
    {
        $user = User::factory()->create();

        $this->assertTrue(method_exists($user, 'orders'));
        $this->assertCount(0, $user->orders);
    }

    #[Test]
    public function user_orders_returns_morph_many()
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\MorphMany::class,
            $user->orders()
        );
    }

    #[Test]
    public function user_can_have_multiple_orders()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 5000)->create([
            'manage_stock' => false,
        ]);

        // Create multiple orders via cart checkout
        for ($i = 0; $i < 3; $i++) {
            $user->addToCart($product);
            $user->checkoutCart();
        }

        $this->assertCount(3, $user->orders);
    }

    #[Test]
    public function user_can_get_pending_orders()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 2500)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $user->checkoutCart();

        $this->assertCount(1, $user->pendingOrders);
        $this->assertEquals(OrderStatus::PENDING, $user->pendingOrders->first()->status);
    }

    #[Test]
    public function user_can_get_processing_orders()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 2500)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $order = $cart->order;
        $order->markAsProcessing();

        $this->assertCount(1, $user->fresh()->processingOrders);
    }

    #[Test]
    public function user_can_get_completed_orders()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 2500)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $order = $cart->order;
        $order->forceStatus(OrderStatus::COMPLETED);

        $this->assertCount(1, $user->fresh()->completedOrders);
    }

    #[Test]
    public function user_can_get_active_orders()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 2500)->create([
            'manage_stock' => false,
        ]);

        // Create one active order
        $user->addToCart($product);
        $cart1 = $user->checkoutCart();

        // Create one completed order
        $user->addToCart($product);
        $cart2 = $user->checkoutCart();
        $cart2->order->forceStatus(OrderStatus::COMPLETED);

        $this->assertCount(1, $user->fresh()->activeOrders);
    }

    #[Test]
    public function user_can_get_paid_orders()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 2500)->create([
            'manage_stock' => false,
        ]);

        // Create unpaid order
        $user->addToCart($product);
        $cart1 = $user->checkoutCart();

        // Create paid order
        $user->addToCart($product);
        $cart2 = $user->checkoutCart();
        $cart2->order->recordPayment(2500, 'ref123', 'stripe', 'stripe');

        $this->assertCount(1, $user->fresh()->paidOrders);
    }

    #[Test]
    public function user_can_get_fully_paid_orders()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 2500)->create([
            'manage_stock' => false,
        ]);

        // Create partially paid order
        $user->addToCart($product);
        $cart1 = $user->checkoutCart();
        $cart1->order->recordPayment(1000, 'ref123', 'stripe', 'stripe'); // Only 10.00

        // Create fully paid order
        $user->addToCart($product);
        $cart2 = $user->checkoutCart();
        $cart2->order->recordPayment(2500, 'ref456', 'stripe', 'stripe'); // Full 25.00

        $this->assertCount(1, $user->fresh()->fullyPaidOrders);
    }

    #[Test]
    public function user_can_get_latest_order()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 2500)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart1 = $user->checkoutCart();

        $user->addToCart($product);
        $cart2 = $user->checkoutCart();

        $latestOrder = $user->latestOrder();

        $this->assertNotNull($latestOrder);
        $this->assertEquals($cart2->order->id, $latestOrder->id);
    }

    #[Test]
    public function user_can_get_total_spent()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 5000)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart1 = $user->checkoutCart();
        $cart1->order->recordPayment(5000, 'ref1', 'stripe', 'stripe');

        $user->addToCart($product);
        $cart2 = $user->checkoutCart();
        $cart2->order->recordPayment(3000, 'ref2', 'stripe', 'stripe');

        $this->assertEquals(8000, $user->fresh()->total_spent); // 80.00 in cents
    }

    #[Test]
    public function user_can_get_order_count()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 2500)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $user->checkoutCart();

        $user->addToCart($product);
        $user->checkoutCart();

        $this->assertEquals(2, $user->order_count);
    }

    #[Test]
    public function user_can_get_completed_order_count()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 2500)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart1 = $user->checkoutCart();
        $cart1->order->forceStatus(OrderStatus::COMPLETED);

        $user->addToCart($product);
        $user->checkoutCart(); // This stays pending

        $this->assertEquals(1, $user->fresh()->completed_order_count);
    }

    #[Test]
    public function user_can_check_has_orders()
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasOrders());

        $product = Product::factory()->withPrices(unit_amount: 2500)->create([
            'manage_stock' => false,
        ]);
        $user->addToCart($product);
        $user->checkoutCart();

        $this->assertTrue($user->fresh()->hasOrders());
    }

    #[Test]
    public function user_can_check_has_active_orders()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 2500)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart = $user->checkoutCart();

        $this->assertTrue($user->fresh()->hasActiveOrders());

        // Complete the order
        $cart->order->forceStatus(OrderStatus::COMPLETED);

        $this->assertFalse($user->fresh()->hasActiveOrders());
    }

    #[Test]
    public function user_can_find_order_by_number()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 2500)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $order = $cart->order;

        $foundOrder = $user->findOrderByNumber($order->order_number);

        $this->assertNotNull($foundOrder);
        $this->assertEquals($order->id, $foundOrder->id);
    }

    #[Test]
    public function user_cannot_find_other_users_order_by_number()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 2500)->create([
            'manage_stock' => false,
        ]);

        $user1->addToCart($product);
        $cart = $user1->checkoutCart();
        $order = $cart->order;

        // User2 should not find user1's order
        $foundOrder = $user2->findOrderByNumber($order->order_number);

        $this->assertNull($foundOrder);
    }

    #[Test]
    public function user_can_get_orders_between_dates()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 2500)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $user->checkoutCart();

        $orders = $user->ordersBetween(
            now()->subDay(),
            now()->addDay()
        );

        $this->assertCount(1, $orders->get());
    }

    #[Test]
    public function user_can_get_orders_with_specific_status()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 2500)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $cart->order->update(['status' => OrderStatus::SHIPPED]);

        $shippedOrders = $user->ordersWithStatus(OrderStatus::SHIPPED);

        $this->assertCount(1, $shippedOrders->get());
    }
}
