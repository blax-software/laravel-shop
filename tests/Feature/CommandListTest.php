<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Console\Commands\ShopListCartsCommand;
use Blax\Shop\Console\Commands\ShopListCategoriesCommand;
use Blax\Shop\Console\Commands\ShopListCommand;
use Blax\Shop\Console\Commands\ShopListOrdersCommand;
use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Models\Order;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductCategory;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

class CommandListTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shop_list_dispatcher_shows_every_listable_with_a_total_count(): void
    {
        Product::create(['name' => 'P1', 'sku' => 'P1', 'type' => ProductType::SIMPLE, 'status' => ProductStatus::PUBLISHED, 'manage_stock' => false, 'is_visible' => true]);
        Product::create(['name' => 'P2', 'sku' => 'P2', 'type' => ProductType::SIMPLE, 'status' => ProductStatus::PUBLISHED, 'manage_stock' => false, 'is_visible' => true]);
        ProductCategory::create(['name' => 'Fiction', 'slug' => 'fiction']);

        $exit = Artisan::call(ShopListCommand::class);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Shop listings', $output);
        $this->assertStringContainsString('shop:list:products', $output);
        $this->assertStringContainsString('shop:list:purchases', $output);
        $this->assertStringContainsString('shop:list:categories', $output);
        $this->assertStringContainsString('shop:list:orders', $output);
        $this->assertStringContainsString('shop:list:carts', $output);
        // Counts: 2 products, 1 category
        $this->assertMatchesRegularExpression('/shop:list:products\s+\|\s+2 entries/', $output);
        $this->assertMatchesRegularExpression('/shop:list:categories\s+\|\s+1 entries/', $output);
    }

    #[Test]
    public function shop_list_categories_renders_the_catalogue_with_total_count(): void
    {
        ProductCategory::create(['name' => 'Fiction', 'slug' => 'fiction']);
        ProductCategory::create(['name' => 'Non-fiction', 'slug' => 'non-fiction']);

        $exit = Artisan::call(ShopListCategoriesCommand::class);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Fiction', $output);
        $this->assertStringContainsString('Non-fiction', $output);
        $this->assertStringContainsString('Total categories: 2', $output);
    }

    #[Test]
    public function shop_list_orders_handles_empty_state(): void
    {
        $exit = Artisan::call(ShopListOrdersCommand::class);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No orders found.', $output);
    }

    #[Test]
    public function shop_list_carts_filters_to_guest_carts_with_the_flag(): void
    {
        Cart::create([
            'customer_type' => 'App\\Models\\User',
            'customer_id' => 'user-1',
        ]);
        Cart::create(['session_id' => 'guest-sess-1']);

        $exit = Artisan::call(ShopListCartsCommand::class, ['--guest' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('<guest>', $output);
        $this->assertStringContainsString('Showing 1 cart', $output);
    }

    /* ───────────────────── shop:list:categories gaps ───────────────────── */

    #[Test]
    public function shop_list_categories_reports_empty_state_when_no_rows(): void
    {
        $exit = Artisan::call(ShopListCategoriesCommand::class);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No categories found.', $output);
    }

    #[Test]
    public function shop_list_categories_with_products_adds_a_count_column(): void
    {
        $fiction = ProductCategory::create(['name' => 'Fiction', 'slug' => 'fiction']);
        ProductCategory::create(['name' => 'Empty', 'slug' => 'empty']);

        $p1 = Product::create(['name' => 'Book A', 'sku' => 'BA', 'type' => ProductType::SIMPLE, 'status' => ProductStatus::PUBLISHED, 'manage_stock' => false, 'is_visible' => true]);
        $p2 = Product::create(['name' => 'Book B', 'sku' => 'BB', 'type' => ProductType::SIMPLE, 'status' => ProductStatus::PUBLISHED, 'manage_stock' => false, 'is_visible' => true]);
        $fiction->products()->attach([$p1->id, $p2->id]);

        Artisan::call(ShopListCategoriesCommand::class, ['--with-products' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Products', $output, 'column header is present');
        $this->assertMatchesRegularExpression('/Fiction.*?\b2\b/', $output);
        $this->assertMatchesRegularExpression('/Empty.*?\b0\b/', $output);
    }

    /* ───────────────────── shop:list:orders gaps ───────────────────────── */

    #[Test]
    public function shop_list_orders_renders_orders_with_status_and_currency(): void
    {
        Order::create([
            'order_number' => 'ORD-001',
            'currency' => 'EUR',
            'status' => OrderStatus::PENDING,
            'amount_total' => 1500,
        ]);
        Order::create([
            'order_number' => 'ORD-002',
            'currency' => 'EUR',
            'status' => OrderStatus::COMPLETED,
            'amount_total' => 9999,
        ]);

        Artisan::call(ShopListOrdersCommand::class);
        $output = Artisan::output();

        $this->assertStringContainsString('ORD-001', $output);
        $this->assertStringContainsString('ORD-002', $output);
        $this->assertStringContainsString('Showing 2 order(s)', $output);
        // 9999 cents → "99.99" rendered with thousand separators.
        $this->assertStringContainsString('99.99', $output);
    }

    #[Test]
    public function shop_list_orders_status_filter_narrows_the_set(): void
    {
        Order::create(['order_number' => 'ORD-PENDING', 'currency' => 'EUR', 'status' => OrderStatus::PENDING, 'amount_total' => 100]);
        Order::create(['order_number' => 'ORD-DONE', 'currency' => 'EUR', 'status' => OrderStatus::COMPLETED, 'amount_total' => 200]);

        Artisan::call(ShopListOrdersCommand::class, ['--status' => OrderStatus::COMPLETED->value]);
        $output = Artisan::output();

        $this->assertStringContainsString('ORD-DONE', $output);
        $this->assertStringNotContainsString('ORD-PENDING', $output);
    }

    #[Test]
    public function shop_list_orders_customer_filter_narrows_to_one_buyer(): void
    {
        Order::create(['order_number' => 'ORD-MINE', 'currency' => 'EUR', 'customer_id' => 'buyer-1', 'customer_type' => 'App\\Models\\User', 'amount_total' => 0]);
        Order::create(['order_number' => 'ORD-OTHER', 'currency' => 'EUR', 'customer_id' => 'buyer-2', 'customer_type' => 'App\\Models\\User', 'amount_total' => 0]);

        Artisan::call(ShopListOrdersCommand::class, ['--customer' => 'buyer-1']);
        $output = Artisan::output();

        $this->assertStringContainsString('ORD-MINE', $output);
        $this->assertStringNotContainsString('ORD-OTHER', $output);
    }

    /* ───────────────────── shop:list:carts gaps ────────────────────────── */

    #[Test]
    public function shop_list_carts_default_lists_every_cart(): void
    {
        Cart::create(['customer_type' => 'App\\Models\\User', 'customer_id' => 'u-1']);
        Cart::create(['session_id' => 'guest-1']);

        Artisan::call(ShopListCartsCommand::class);
        $output = Artisan::output();

        $this->assertStringContainsString('<guest>', $output);
        $this->assertStringContainsString('Showing', $output);
    }

    #[Test]
    public function shop_list_carts_reports_empty_state(): void
    {
        $exit = Artisan::call(ShopListCartsCommand::class);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No carts found.', $output);
    }

    #[Test]
    public function shop_list_carts_with_items_adds_a_count_column(): void
    {
        $cart = Cart::create(['session_id' => 'guest-with-items']);
        $product = Product::create(['name' => 'Widget', 'sku' => 'W-1', 'type' => ProductType::SIMPLE, 'status' => ProductStatus::PUBLISHED, 'manage_stock' => false, 'is_visible' => true]);
        CartItem::create([
            'cart_id' => $cart->id,
            'purchasable_type' => Product::class,
            'purchasable_id' => $product->id,
            'quantity' => 2,
            'unit_price' => 100,
        ]);

        Artisan::call(ShopListCartsCommand::class, ['--with-items' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('Items', $output, 'Items column header appears');
        // The session column is truncated to 12 chars (guest-with-i…) by the
        // command, so we match the truncated prefix; the single cart has 1
        // item row, since withCount('items') tallies relations (not units).
        $this->assertMatchesRegularExpression('/guest-with-i.*?\b1\b/', $output);
    }
}
