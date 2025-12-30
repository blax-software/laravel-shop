<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductCategory;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class ProductScopeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_scope_by_category()
    {
        $category1 = ProductCategory::factory()->create();
        $category2 = ProductCategory::factory()->create();

        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();
        $product3 = Product::factory()->create();

        $product1->categories()->attach($category1->id);
        $product2->categories()->attach($category1->id);
        $product3->categories()->attach($category2->id);

        $productsInCategory1 = Product::byCategory($category1->id)->get();

        $this->assertCount(2, $productsInCategory1);
        $this->assertTrue($productsInCategory1->contains($product1));
        $this->assertTrue($productsInCategory1->contains($product2));
    }

    #[Test]
    public function it_can_search_products_by_slug()
    {
        Product::factory()->create(['slug' => 'awesome-product']);
        Product::factory()->create(['slug' => 'another-product']);
        Product::factory()->create(['slug' => 'different-item']);

        $results = Product::search('product')->get();

        $this->assertCount(2, $results);
    }

    #[Test]
    public function it_can_search_products_by_sku()
    {
        Product::factory()->create(['sku' => 'SKU-001']);
        Product::factory()->create(['sku' => 'SKU-002']);
        Product::factory()->create(['sku' => 'DIFF-001']);

        $results = Product::search('SKU')->get();

        $this->assertCount(2, $results);
    }

    #[Test]
    public function it_can_filter_by_price_range()
    {
        $product1 = Product::factory()->withPrices(1, 50)->create();
        $product2 = Product::factory()->withPrices(1, 100)->create();
        $product3 = Product::factory()->withPrices(1, 150)->create();

        $inRange = Product::priceRange(75, 125)->get();

        $this->assertCount(1, $inRange);
        $this->assertTrue($inRange->contains($product2));
    }

    #[Test]
    public function it_can_filter_by_minimum_price_only()
    {
        $product1 = Product::factory()->withPrices(1, 50)->create();
        $product2 = Product::factory()->withPrices(1, 100)->create();
        $product3 = Product::factory()->withPrices(1, 150)->create();

        $minPrice = Product::priceRange(100)->get();

        $this->assertCount(2, $minPrice);
        $this->assertTrue($minPrice->contains($product2));
        $this->assertTrue($minPrice->contains($product3));
    }

    #[Test]
    public function it_can_filter_by_maximum_price_only()
    {
        $product1 = Product::factory()->withPrices(1, 50)->create();
        $product2 = Product::factory()->withPrices(1, 100)->create();
        $product3 = Product::factory()->withPrices(1, 150)->create();

        $maxPrice = Product::priceRange(null, 100)->get();

        $this->assertCount(2, $maxPrice);
        $this->assertTrue($maxPrice->contains($product1));
        $this->assertTrue($maxPrice->contains($product2));
    }

    #[Test]
    public function it_can_order_products_by_price_ascending()
    {
        $product1 = Product::factory()->withPrices(1, 150)->create(['name' => 'Expensive']);
        $product2 = Product::factory()->withPrices(1, 50)->create(['name' => 'Cheap']);
        $product3 = Product::factory()->withPrices(1, 100)->create(['name' => 'Medium']);

        $ordered = Product::orderByPrice('asc')->get();

        $this->assertEquals($product2->id, $ordered->first()->id);
        $this->assertEquals($product1->id, $ordered->last()->id);
    }

    #[Test]
    public function it_can_order_products_by_price_descending()
    {
        $product1 = Product::factory()->withPrices(1, 150)->create(['name' => 'Expensive']);
        $product2 = Product::factory()->withPrices(1, 50)->create(['name' => 'Cheap']);
        $product3 = Product::factory()->withPrices(1, 100)->create(['name' => 'Medium']);

        $ordered = Product::orderByPrice('desc')->get();

        $this->assertEquals($product1->id, $ordered->first()->id);
        $this->assertEquals($product2->id, $ordered->last()->id);
    }

    #[Test]
    public function it_can_combine_price_range_and_order_by_price()
    {
        $product1 = Product::factory()->withPrices(1, 50)->create();
        $product2 = Product::factory()->withPrices(1, 100)->create();
        $product3 = Product::factory()->withPrices(1, 150)->create();
        $product4 = Product::factory()->withPrices(1, 200)->create();

        $filtered = Product::priceRange(75, 175)->orderByPrice('asc')->get();

        $this->assertCount(2, $filtered);
        $this->assertEquals($product2->id, $filtered->first()->id);
        $this->assertEquals($product3->id, $filtered->last()->id);
    }

    #[Test]
    public function it_can_scope_low_stock_products()
    {
        $lowStockProduct = Product::factory()->create([
            'manage_stock' => true,
            'low_stock_threshold' => 10,
        ]);
        $lowStockProduct->increaseStock(5);

        $normalStockProduct = Product::factory()->create([
            'manage_stock' => true,
            'low_stock_threshold' => 10,
        ]);
        $normalStockProduct->increaseStock(20);

        $lowStock = Product::lowStock()->get();

        $this->assertTrue($lowStock->contains($lowStockProduct));
        $this->assertFalse($lowStock->contains($normalStockProduct));
    }

    #[Test]
    public function it_can_scope_featured_products()
    {
        Product::factory()->create(['featured' => true]);
        Product::factory()->create(['featured' => true]);
        Product::factory()->create(['featured' => false]);

        $featured = Product::featured()->get();

        $this->assertCount(2, $featured);
        $this->assertTrue($featured->every(fn($p) => $p->featured === true));
    }

    #[Test]
    public function visible_scope_excludes_unpublished_products()
    {
        Product::factory()->create([
            'is_visible' => true,
            'status' => 'published',
        ]);

        Product::factory()->create([
            'is_visible' => true,
            'status' => 'draft',
        ]);

        Product::factory()->create([
            'is_visible' => false,
            'status' => 'published',
        ]);

        $visible = Product::visible()->get();

        $this->assertCount(1, $visible);
    }

    #[Test]
    public function visible_scope_respects_published_at_date()
    {
        Product::factory()->create([
            'is_visible' => true,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        Product::factory()->create([
            'is_visible' => true,
            'status' => 'published',
            'published_at' => now()->addDay(),
        ]);

        $visible = Product::visible()->get();

        $this->assertCount(1, $visible);
    }

    #[Test]
    public function in_stock_scope_includes_products_without_stock_management()
    {
        Product::factory()->create(['manage_stock' => false]);

        $managedProduct = Product::factory()->create(['manage_stock' => true]);
        $managedProduct->increaseStock(10);

        $inStock = Product::inStock()->get();

        $this->assertGreaterThanOrEqual(2, $inStock->count());
    }

    #[Test]
    public function in_stock_scope_excludes_out_of_stock_products()
    {
        $outOfStock = Product::factory()->create(['manage_stock' => true]);

        $inStock = Product::factory()->create(['manage_stock' => true]);
        $inStock->increaseStock(10);

        $products = Product::inStock()->get();

        $this->assertFalse($products->contains($outOfStock));
        $this->assertTrue($products->contains($inStock));
    }

    #[Test]
    public function it_can_combine_multiple_scopes()
    {
        Product::factory()->create([
            'featured' => true,
            'is_visible' => true,
            'status' => 'published',
            'manage_stock' => false,
        ]);

        Product::factory()->create([
            'featured' => true,
            'is_visible' => false,
            'status' => 'published',
        ]);

        Product::factory()->create([
            'featured' => false,
            'is_visible' => true,
            'status' => 'published',
        ]);

        $products = Product::featured()
            ->visible()
            ->inStock()
            ->get();

        $this->assertCount(1, $products);
    }

    #[Test]
    public function it_can_scope_products_by_type()
    {
        Product::factory()->create(['type' => ProductType::SIMPLE]);
        Product::factory()->create(['type' => ProductType::SIMPLE]);
        Product::factory()->create(['type' => ProductType::VARIABLE]);
        Product::factory()->create(['type' => ProductType::VARIABLE]);

        $simpleProducts = Product::where('type', ProductType::SIMPLE)->get();

        $this->assertCount(2, $simpleProducts);
    }

    #[Test]
    public function it_can_scope_downloadable_products()
    {
        Product::factory()->create(['downloadable' => true]);
        Product::factory()->create(['downloadable' => true]);
        Product::factory()->create(['downloadable' => false]);

        $downloadable = Product::where('downloadable', true)->get();

        $this->assertCount(2, $downloadable);
    }

    #[Test]
    public function it_can_scope_virtual_products()
    {
        Product::factory()->create(['virtual' => true]);
        Product::factory()->create(['virtual' => false]);
        Product::factory()->create(['virtual' => false]);

        $virtual = Product::where('virtual', true)->get();

        $this->assertCount(1, $virtual);
    }
}
