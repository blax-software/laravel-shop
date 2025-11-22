<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductCategory;
use Blax\Shop\Models\ProductAttribute;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_create_a_product()
    {
        $product = Product::factory()->create([
            'slug' => 'test-product',
            'type' => 'simple',
            'price' => 99.99,
            'regular_price' => 99.99,
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'slug' => 'test-product',
            'price' => 99.99,
        ]);
    }

    /** @test */
    public function it_automatically_generates_slug_if_not_provided()
    {
        $product = Product::factory()->create(['slug' => null]);

        $this->assertNotNull($product->slug);
        $this->assertStringStartsWith('new-product-', $product->slug);
    }

    /** @test */
    public function it_can_manage_stock()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
        ]);

        $this->assertTrue($product->increaseStock(10));
        $this->assertEquals(60, $product->fresh()->stock_quantity);

        $this->assertTrue($product->decreaseStock(5));
        $this->assertEquals(55, $product->fresh()->stock_quantity);
    }

    /** @test */
    public function it_cannot_decrease_stock_below_zero()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 5,
        ]);

        $this->assertFalse($product->decreaseStock(10));
        $this->assertEquals(5, $product->fresh()->stock_quantity);
    }

    /** @test */
    public function it_returns_available_stock()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 100,
        ]);

        $this->assertEquals(100, $product->getAvailableStock());
    }

    /** @test */
    public function it_can_check_if_in_stock()
    {
        $productInStock = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 10,
            'in_stock' => true,
        ]);

        $productOutOfStock = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 0,
            'in_stock' => false,
        ]);

        $this->assertTrue($productInStock->isInStock());
        $this->assertFalse($productOutOfStock->isInStock());
    }

    /** @test */
    public function it_can_attach_categories()
    {
        $product = Product::factory()->create();
        $category = ProductCategory::factory()->create();

        $product->categories()->attach($category);

        $this->assertTrue($product->categories->contains($category));
    }

    /** @test */
    public function it_can_have_attributes()
    {
        $product = Product::factory()->create();

        $attribute = ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'Color',
            'value' => 'Blue',
        ]);

        $this->assertCount(1, $product->fresh()->attributes);
        $this->assertEquals('Color', $product->attributes->first()->key);
        $this->assertEquals('Blue', $product->attributes->first()->value);
    }

    /** @test */
    public function it_can_have_multiple_prices()
    {
        $product = Product::factory()->create();

        ProductPrice::create([
            'product_id' => $product->id,
            'type' => 'one-time',
            'price' => 9999,
            'currency' => 'USD',
            'active' => true,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'type' => 'recurring',
            'price' => 1999,
            'currency' => 'USD',
            'interval' => 'month',
            'active' => true,
        ]);

        $this->assertCount(2, $product->fresh()->prices);
    }

    /** @test */
    public function it_can_have_related_products()
    {
        $product = Product::factory()->create();
        $relatedProduct = Product::factory()->create();

        $product->relatedProducts()->attach($relatedProduct->id, [
            'type' => 'related',
        ]);

        $this->assertTrue($product->relatedProducts->contains($relatedProduct));
    }

    /** @test */
    public function it_can_have_upsell_products()
    {
        $product = Product::factory()->create();
        $upsellProduct = Product::factory()->create();

        $product->relatedProducts()->attach($upsellProduct->id, [
            'type' => 'upsell',
        ]);

        $this->assertTrue($product->upsells->contains($upsellProduct));
    }

    /** @test */
    public function it_can_have_cross_sell_products()
    {
        $product = Product::factory()->create();
        $crossSellProduct = Product::factory()->create();

        $product->relatedProducts()->attach($crossSellProduct->id, [
            'type' => 'cross-sell',
        ]);

        $this->assertTrue($product->crossSells->contains($crossSellProduct));
    }

    /** @test */
    public function it_can_scope_published_products()
    {
        Product::factory()->create(['status' => 'published']);
        Product::factory()->create(['status' => 'draft']);

        $published = Product::published()->get();

        $this->assertCount(1, $published);
        $this->assertEquals('published', $published->first()->status);
    }

    /** @test */
    public function it_can_scope_in_stock_products()
    {
        Product::factory()->create([
            'in_stock' => true,
            'manage_stock' => true,
            'stock_quantity' => 10,
        ]);

        Product::factory()->create([
            'in_stock' => false,
            'manage_stock' => true,
            'stock_quantity' => 0,
        ]);

        $inStock = Product::inStock()->get();

        $this->assertCount(1, $inStock);
        $this->assertTrue($inStock->first()->in_stock);
    }

    /** @test */
    public function it_can_scope_visible_products()
    {
        Product::factory()->create([
            'is_visible' => true,
            'status' => 'published',
        ]);

        Product::factory()->create([
            'is_visible' => false,
            'status' => 'published',
        ]);

        $visible = Product::visible()->get();

        $this->assertCount(1, $visible);
        $this->assertTrue($visible->first()->is_visible);
    }

    /** @test */
    public function it_can_have_parent_child_relationships()
    {
        $parent = Product::factory()->create([
            'type' => 'variable',
        ]);

        $child = Product::factory()->create([
            'type' => 'variation',
            'parent_id' => $parent->id,
        ]);

        $this->assertTrue($parent->children->contains($child));
        $this->assertEquals($parent->id, $child->parent->id);
    }

    /** @test */
    public function it_validates_virtual_and_downloadable_flags()
    {
        $virtualProduct = Product::factory()->create([
            'virtual' => true,
            'downloadable' => false,
        ]);

        $downloadableProduct = Product::factory()->create([
            'virtual' => false,
            'downloadable' => true,
        ]);

        $this->assertTrue($virtualProduct->virtual);
        $this->assertFalse($virtualProduct->downloadable);
        $this->assertTrue($downloadableProduct->downloadable);
        $this->assertFalse($downloadableProduct->virtual);
    }

    /** @test */
    public function it_can_check_featured_status()
    {
        $featured = Product::factory()->create(['featured' => true]);
        $regular = Product::factory()->create(['featured' => false]);

        $this->assertTrue($featured->featured);
        $this->assertFalse($regular->featured);
    }
}
