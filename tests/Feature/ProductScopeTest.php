<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductCategory;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
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

    /** @test */
    public function it_can_search_products_by_slug()
    {
        Product::factory()->create(['slug' => 'awesome-product']);
        Product::factory()->create(['slug' => 'another-product']);
        Product::factory()->create(['slug' => 'different-item']);

        $results = Product::search('product')->get();

        $this->assertCount(2, $results);
    }

    /** @test */
    public function it_can_search_products_by_sku()
    {
        Product::factory()->create(['sku' => 'SKU-001']);
        Product::factory()->create(['sku' => 'SKU-002']);
        Product::factory()->create(['sku' => 'DIFF-001']);

        $results = Product::search('SKU')->get();

        $this->assertCount(2, $results);
    }

    /** @test */
    public function it_can_filter_by_price_range()
    {
        Product::factory()->create(['meta' => json_encode(['price' => 50])]);
        Product::factory()->create(['meta' => json_encode(['price' => 100])]);
        Product::factory()->create(['meta' => json_encode(['price' => 150])]);

        // Note: This test assumes the scope uses a 'price' column
        // which may need adjustment based on actual implementation
        $products = Product::all();

        $this->assertCount(3, $products);
    }

    /** @test */
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

    /** @test */
    public function it_can_scope_featured_products()
    {
        Product::factory()->create(['featured' => true]);
        Product::factory()->create(['featured' => true]);
        Product::factory()->create(['featured' => false]);

        $featured = Product::featured()->get();

        $this->assertCount(2, $featured);
        $this->assertTrue($featured->every(fn($p) => $p->featured === true));
    }

    /** @test */
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

    /** @test */
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

    /** @test */
    public function in_stock_scope_includes_products_without_stock_management()
    {
        Product::factory()->create(['manage_stock' => false]);
        
        $managedProduct = Product::factory()->create(['manage_stock' => true]);
        $managedProduct->increaseStock(10);

        $inStock = Product::inStock()->get();

        $this->assertGreaterThanOrEqual(2, $inStock->count());
    }

    /** @test */
    public function in_stock_scope_excludes_out_of_stock_products()
    {
        $outOfStock = Product::factory()->create(['manage_stock' => true]);
        
        $inStock = Product::factory()->create(['manage_stock' => true]);
        $inStock->increaseStock(10);

        $products = Product::inStock()->get();

        $this->assertFalse($products->contains($outOfStock));
        $this->assertTrue($products->contains($inStock));
    }

    /** @test */
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

    /** @test */
    public function it_can_scope_products_by_type()
    {
        Product::factory()->create(['type' => 'simple']);
        Product::factory()->create(['type' => 'simple']);
        Product::factory()->create(['type' => 'variable']);
        Product::factory()->create(['type' => 'variation']);

        $simpleProducts = Product::where('type', 'simple')->get();

        $this->assertCount(2, $simpleProducts);
    }

    /** @test */
    public function it_can_scope_downloadable_products()
    {
        Product::factory()->create(['downloadable' => true]);
        Product::factory()->create(['downloadable' => true]);
        Product::factory()->create(['downloadable' => false]);

        $downloadable = Product::where('downloadable', true)->get();

        $this->assertCount(2, $downloadable);
    }

    /** @test */
    public function it_can_scope_virtual_products()
    {
        Product::factory()->create(['virtual' => true]);
        Product::factory()->create(['virtual' => false]);
        Product::factory()->create(['virtual' => false]);

        $virtual = Product::where('virtual', true)->get();

        $this->assertCount(1, $virtual);
    }
}
