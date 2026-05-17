<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Console\Commands\ShopListCartsCommand;
use Blax\Shop\Console\Commands\ShopListCategoriesCommand;
use Blax\Shop\Console\Commands\ShopListCommand;
use Blax\Shop\Console\Commands\ShopListOrdersCommand;
use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
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
}
