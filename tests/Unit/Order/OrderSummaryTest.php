<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Models\Order;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

class OrderSummaryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function order_can_get_total_revenue()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $cart->order->recordPayment(5000, 'ref1', 'stripe', 'stripe');

        $user->addToCart($product);
        $cart2 = $user->checkoutCart();
        $cart2->order->recordPayment(5000, 'ref2', 'stripe', 'stripe');

        $this->assertEquals(10000, Order::getTotalRevenue());
    }

    #[Test]
    public function order_can_get_revenue_between_dates()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 100.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $cart->order->recordPayment(10000, 'ref1', 'stripe', 'stripe');

        $revenue = Order::getRevenueBetween(
            now()->subDay(),
            now()->addDay()
        );

        $this->assertEquals(10000, $revenue);
    }

    #[Test]
    public function order_can_get_total_refunded()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $cart->order->recordPayment(5000, 'ref1', 'stripe', 'stripe');
        $cart->order->recordRefund(2000, 'Partial refund');

        $this->assertEquals(2000, Order::getTotalRefunded());
    }

    #[Test]
    public function order_can_get_net_revenue()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $cart->order->recordPayment(5000, 'ref1', 'stripe', 'stripe');
        $cart->order->recordRefund(1000, 'Partial refund');

        $this->assertEquals(4000, Order::getNetRevenue());
    }

    #[Test]
    public function order_can_get_average_order_value()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $user->checkoutCart();

        $user->addToCart($product);
        $user->checkoutCart();

        // Both orders have 5000 cents total
        $this->assertEquals(5000.0, Order::getAverageOrderValue());
    }

    #[Test]
    public function order_can_get_counts_by_status()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        // Create pending order
        $user->addToCart($product);
        $user->checkoutCart();

        // Create processing order
        $user->addToCart($product);
        $cart2 = $user->checkoutCart();
        $cart2->order->markAsProcessing();

        // Create completed order
        $user->addToCart($product);
        $cart3 = $user->checkoutCart();
        $cart3->order->forceStatus(OrderStatus::COMPLETED);

        $counts = Order::getCountsByStatus();

        $this->assertEquals(1, $counts['pending']);
        $this->assertEquals(1, $counts['processing']);
        $this->assertEquals(1, $counts['completed']);
    }

    #[Test]
    public function order_can_get_revenue_summary()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $cart->order->recordPayment(5000, 'ref1', 'stripe', 'stripe');
        $cart->order->recordRefund(1000, 'Partial refund');

        $summary = Order::getRevenueSummary(now()->subDay(), now()->addDay());

        $this->assertArrayHasKey('period', $summary);
        $this->assertArrayHasKey('orders', $summary);
        $this->assertArrayHasKey('revenue', $summary);
        $this->assertArrayHasKey('averages', $summary);

        $this->assertEquals(1, $summary['orders']['total']);
        $this->assertEquals(5000, $summary['revenue']['paid']);
        $this->assertEquals(1000, $summary['revenue']['refunded']);
        $this->assertEquals(4000, $summary['revenue']['net']);
    }

    #[Test]
    public function order_can_get_daily_revenue()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $cart->order->recordPayment(5000, 'ref1', 'stripe', 'stripe');

        $dailyRevenue = Order::getDailyRevenue(now()->subDays(7), now()->addDay());

        $this->assertCount(1, $dailyRevenue);
        $this->assertEquals(1, $dailyRevenue->first()->order_count);
        $this->assertEquals(5000, $dailyRevenue->first()->paid_amount);
    }

    #[Test]
    public function order_scope_today_returns_todays_orders()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $user->checkoutCart();

        $this->assertCount(1, Order::today()->get());
    }

    #[Test]
    public function order_scope_this_week_returns_this_weeks_orders()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $user->checkoutCart();

        $this->assertCount(1, Order::thisWeek()->get());
    }

    #[Test]
    public function order_scope_this_month_returns_this_months_orders()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $user->checkoutCart();

        $this->assertCount(1, Order::thisMonth()->get());
    }

    #[Test]
    public function order_scope_by_payment_provider_returns_filtered_orders()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $cart->order->recordPayment(2500, 'ref1', 'card', 'stripe');

        $user->addToCart($product);
        $cart2 = $user->checkoutCart();
        $cart2->order->recordPayment(2500, 'ref2', 'bank_transfer', 'paypal');

        $stripeOrders = Order::byPaymentProvider('stripe')->get();
        $paypalOrders = Order::byPaymentProvider('paypal')->get();

        $this->assertCount(1, $stripeOrders);
        $this->assertCount(1, $paypalOrders);
    }

    #[Test]
    public function order_scope_with_refunds_returns_orders_with_refunds()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
        ]);

        // Order with refund
        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $cart->order->recordPayment(5000, 'ref1', 'stripe', 'stripe');
        $cart->order->recordRefund(1000, 'Partial refund');

        // Order without refund
        $user->addToCart($product);
        $cart2 = $user->checkoutCart();
        $cart2->order->recordPayment(5000, 'ref2', 'stripe', 'stripe');

        $ordersWithRefunds = Order::withRefunds()->get();

        $this->assertCount(1, $ordersWithRefunds);
    }

    #[Test]
    public function order_scope_fully_refunded_returns_fully_refunded_orders()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
        ]);

        // Fully refunded order
        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $cart->order->recordPayment(5000, 'ref1', 'stripe', 'stripe');
        $cart->order->recordRefund(5000, 'Full refund');

        // Partially refunded order
        $user->addToCart($product);
        $cart2 = $user->checkoutCart();
        $cart2->order->recordPayment(5000, 'ref2', 'stripe', 'stripe');
        $cart2->order->recordRefund(1000, 'Partial refund');

        $fullyRefundedOrders = Order::fullyRefunded()->get();

        $this->assertCount(1, $fullyRefundedOrders);
    }
}
