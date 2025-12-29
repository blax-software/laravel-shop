<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Facades\Shop;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Order;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

class ShopServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shop_facade_can_get_products()
    {
        Product::factory()->count(3)->create();

        $this->assertCount(3, Shop::products()->get());
    }

    #[Test]
    public function shop_facade_can_get_single_product()
    {
        $product = Product::factory()->create();

        $found = Shop::product($product->id);

        $this->assertNotNull($found);
        $this->assertEquals($product->id, $found->id);
    }

    #[Test]
    public function shop_facade_can_get_orders()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $user->checkoutCart();

        $this->assertCount(1, Shop::orders()->get());
    }

    #[Test]
    public function shop_facade_can_get_order_by_number()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $order = $cart->order;

        $found = Shop::orderByNumber($order->order_number);

        $this->assertNotNull($found);
        $this->assertEquals($order->id, $found->id);
    }

    #[Test]
    public function shop_facade_can_get_orders_today()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $user->checkoutCart();

        $this->assertCount(1, Shop::ordersToday()->get());
    }

    #[Test]
    public function shop_facade_can_get_orders_this_week()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $user->checkoutCart();

        $this->assertCount(1, Shop::ordersThisWeek()->get());
    }

    #[Test]
    public function shop_facade_can_get_orders_this_month()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $user->checkoutCart();

        $this->assertCount(1, Shop::ordersThisMonth()->get());
    }

    #[Test]
    public function shop_facade_can_get_pending_orders()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 25.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $user->checkoutCart();

        $this->assertCount(1, Shop::pendingOrders()->get());
    }

    #[Test]
    public function shop_facade_can_calculate_total_revenue()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $cart->order->recordPayment(5000, 'ref123', 'stripe', 'stripe');

        $this->assertEquals(5000, Shop::totalRevenue());
    }

    #[Test]
    public function shop_facade_can_calculate_revenue_today()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 100.00)->create([
            'manage_stock' => false,
        ]);

        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $cart->order->recordPayment(10000, 'ref123', 'stripe', 'stripe');

        $this->assertEquals(10000, Shop::revenueToday());
    }

    #[Test]
    public function shop_facade_can_get_stats()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create([
            'manage_stock' => false,
            'status' => 'published',
        ]);

        $user->addToCart($product);
        $cart = $user->checkoutCart();
        $cart->order->recordPayment(5000, 'ref123', 'stripe', 'stripe');

        $stats = Shop::stats();

        $this->assertArrayHasKey('products', $stats);
        $this->assertArrayHasKey('orders', $stats);
        $this->assertArrayHasKey('revenue', $stats);
        $this->assertArrayHasKey('carts', $stats);
        $this->assertEquals(1, $stats['products']['total']);
        $this->assertEquals(1, $stats['orders']['total']);
        $this->assertEquals(5000, $stats['revenue']['total']);
    }

    #[Test]
    public function shop_facade_can_format_money()
    {
        $formatted = Shop::formatMoney(12345);

        $this->assertEquals('123.45 USD', $formatted);
    }

    #[Test]
    public function shop_facade_can_format_money_with_custom_currency()
    {
        $formatted = Shop::formatMoney(9999, 'EUR');

        $this->assertEquals('99.99 EUR', $formatted);
    }

    #[Test]
    public function shop_facade_can_get_top_products()
    {
        $product1 = Product::factory()->withPrices()->create(['manage_stock' => false]);
        $product2 = Product::factory()->withPrices()->create(['manage_stock' => false]);

        $user = User::factory()->create();

        // Buy product1 three times
        for ($i = 0; $i < 3; $i++) {
            $user->addToCart($product1);
            $user->checkoutCart();
        }

        // Buy product2 once
        $user->addToCart($product2);
        $user->checkoutCart();

        $topProducts = Shop::topProducts(2);

        $this->assertCount(2, $topProducts);
        $this->assertEquals($product1->id, $topProducts->first()->id);
    }

    #[Test]
    public function shop_facade_can_get_active_carts()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create(['manage_stock' => false]);

        $user->addToCart($product);

        $this->assertCount(1, Shop::activeCarts()->get());
    }

    #[Test]
    public function shop_facade_can_expire_stale_carts()
    {
        $cart = Cart::factory()->create([
            'status' => 'active',
            'last_activity_at' => now()->subHours(2), // 2 hours ago
        ]);

        $expiredCount = Shop::expireStaleCarts();

        $this->assertEquals(1, $expiredCount);
        $this->assertEquals('expired', $cart->fresh()->status->value);
    }

    #[Test]
    public function shop_facade_can_delete_old_carts()
    {
        $cart = Cart::factory()->create([
            'status' => 'abandoned',
            'last_activity_at' => now()->subDays(2), // 2 days ago
            'converted_at' => null,
        ]);

        $deletedCount = Shop::deleteOldCarts();

        $this->assertEquals(1, $deletedCount);
        $this->assertNull(Cart::find($cart->id));
    }

    #[Test]
    public function shop_facade_does_not_delete_converted_carts()
    {
        $cart = Cart::factory()->create([
            'status' => 'converted',
            'last_activity_at' => now()->subDays(2), // 2 days ago
            'converted_at' => now()->subDay(),
        ]);

        $deletedCount = Shop::deleteOldCarts();

        $this->assertEquals(0, $deletedCount);
        $this->assertNotNull(Cart::find($cart->id));
    }
}
