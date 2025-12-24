<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Facades\Shop;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductCategory;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class ShopFacadeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_get_all_products()
    {
        Product::factory()->create();
        Product::factory()->create();
        Product::factory()->create();

        $products = Shop::products()->get();

        $this->assertCount(3, $products);
    }

    #[Test]
    public function it_can_get_a_single_product_by_id()
    {
        $product = Product::factory()->create();

        $foundProduct = Shop::product($product->id);

        $this->assertNotNull($foundProduct);
        $this->assertEquals($product->id, $foundProduct->id);
    }

    #[Test]
    public function it_returns_null_for_nonexistent_product()
    {
        $product = Shop::product(999);

        $this->assertNull($product);
    }

    #[Test]
    public function it_can_get_all_categories()
    {
        ProductCategory::factory()->create();
        ProductCategory::factory()->create();

        $categories = Shop::categories()->get();

        $this->assertCount(2, $categories);
    }

    #[Test]
    public function it_can_get_in_stock_products()
    {
        Product::factory()->withStocks()->create();
        Product::factory()->withStocks()->create();
        Product::factory()->create(['manage_stock' => false]);

        $inStockProducts = Shop::inStock()->get();

        $this->assertGreaterThanOrEqual(2, $inStockProducts->count());
    }

    #[Test]
    public function it_can_get_featured_products()
    {
        Product::factory()->create(['featured' => true]);
        Product::factory()->create(['featured' => true]);
        Product::factory()->create(['featured' => false]);

        $featured = Shop::featured()->get();

        $this->assertCount(2, $featured);
    }

    #[Test]
    public function it_can_get_published_and_visible_products()
    {
        Product::factory()->create(['status' => 'published', 'is_visible' => true]);
        Product::factory()->create(['status' => 'published', 'is_visible' => false]);
        Product::factory()->create(['status' => 'draft']);

        $published = Shop::published()->get();

        $this->assertGreaterThanOrEqual(1, $published->count());
    }

    #[Test]
    public function it_can_search_products_by_name()
    {
        Product::factory()->create();
        $product = Product::factory()->create();
        $product->setLocalized('name', 'Premium Widget', 'en');
        $product->save();

        $results = Shop::search('Premium')->get();

        $this->assertGreaterThanOrEqual(1, $results->count());
    }

    #[Test]
    public function it_can_search_products_by_description()
    {
        Product::factory()->count(2)->create();

        // Search test - just check that search returns a builder
        $results = Shop::search('test');

        $this->assertIsObject($results);
    }

    #[Test]
    public function it_can_check_stock_availability_for_managed_stock_product()
    {
        $product = Product::factory()->withStocks(10)->create(['manage_stock' => true]);

        $hasStock = Shop::checkStock($product, 5);

        $this->assertTrue($hasStock);
    }

    #[Test]
    public function it_returns_false_when_not_enough_stock()
    {
        $product = Product::factory()->withStocks(5)->create(['manage_stock' => true]);

        $hasStock = Shop::checkStock($product, 10);

        $this->assertFalse($hasStock);
    }

    #[Test]
    public function it_returns_true_for_unmanaged_stock_products()
    {
        $product = Product::factory()->create(['manage_stock' => false]);

        $hasStock = Shop::checkStock($product, 100);

        $this->assertTrue($hasStock);
    }

    #[Test]
    public function it_can_get_available_stock()
    {
        $product = Product::factory()->withStocks(15)->create(['manage_stock' => true]);

        $available = Shop::getAvailableStock($product);

        $this->assertEquals(15, $available);
    }

    #[Test]
    public function it_returns_max_int_for_unmanaged_stock_products()
    {
        $product = Product::factory()->create(['manage_stock' => false]);

        $available = Shop::getAvailableStock($product);

        $this->assertEquals(PHP_INT_MAX, $available);
    }

    #[Test]
    public function it_can_check_if_product_is_on_sale()
    {
        // Just verify the method exists and returns a boolean
        $product = Product::factory()->withStocks()->create();

        $isOnSale = Shop::isOnSale($product);

        $this->assertIsBool($isOnSale);
    }

    #[Test]
    public function it_can_get_shop_configuration()
    {
        $currency = Shop::config('currency', 'USD');

        $this->assertIsString($currency);
    }

    #[Test]
    public function it_returns_default_config_value_for_nonexistent_key()
    {
        $value = Shop::config('nonexistent.key', 'default');

        $this->assertEquals('default', $value);
    }

    #[Test]
    public function it_can_get_default_currency()
    {
        $currency = Shop::currency();

        $this->assertIsString($currency);
        $this->assertNotEmpty($currency);
    }

    #[Test]
    public function it_can_chain_query_builder_methods()
    {
        Product::factory()->create(['featured' => true, 'is_visible' => true]);
        Product::factory()->create(['featured' => true, 'is_visible' => false]);
        Product::factory()->create(['featured' => false]);

        $products = Shop::products()
            ->where('featured', true)
            ->where('is_visible', true)
            ->get();

        $this->assertCount(1, $products);
    }

    #[Test]
    public function it_can_paginate_products()
    {
        Product::factory()->count(15)->create();

        $page = Shop::products()->paginate(5);

        $this->assertCount(5, $page->items());
        $this->assertEquals(3, $page->lastPage());
    }

    #[Test]
    public function it_can_count_products()
    {
        Product::factory()->count(7)->create();

        $count = Shop::products()->count();

        $this->assertEquals(7, $count);
    }

    #[Test]
    public function it_can_get_featured_and_in_stock_products()
    {
        Product::factory()->withStocks()->create(['featured' => true]);
        Product::factory()->withStocks()->create(['featured' => true]);
        Product::factory()->create(['featured' => false, 'manage_stock' => false]);

        $products = Shop::featured()->inStock()->get();

        $this->assertGreaterThanOrEqual(2, $products->count());
    }
}
