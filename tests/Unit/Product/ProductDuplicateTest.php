<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductCategory;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class ProductDuplicateTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function product_can_be_duplicated()
    {
        $product = Product::factory()->create([
            'name' => 'Original Product',
            'slug' => 'original-product',
        ]);

        $duplicate = $product->duplicate();

        $this->assertNotEquals($product->id, $duplicate->id);
        $this->assertEquals('Original Product', $duplicate->name);
        $this->assertEquals('original-product-copy', $duplicate->slug);
        $this->assertEquals(ProductStatus::DRAFT, $duplicate->status);
    }

    #[Test]
    public function duplicate_generates_unique_slug()
    {
        $product = Product::factory()->create([
            'slug' => 'test-product',
        ]);

        $duplicate1 = $product->duplicate();
        $duplicate2 = $product->duplicate();

        $this->assertEquals('test-product-copy', $duplicate1->slug);
        $this->assertEquals('test-product-copy-2', $duplicate2->slug);
    }

    #[Test]
    public function duplicate_generates_unique_sku()
    {
        $product = Product::factory()->create([
            'sku' => 'SKU-001',
        ]);

        $duplicate1 = $product->duplicate();
        $duplicate2 = $product->duplicate();

        $this->assertEquals('SKU-001-COPY', $duplicate1->sku);
        $this->assertEquals('SKU-001-COPY-2', $duplicate2->sku);
    }

    #[Test]
    public function duplicate_includes_prices()
    {
        $product = Product::factory()->withPrices(unit_amount: 2500)->create();

        $duplicate = $product->duplicate();

        $this->assertCount(1, $duplicate->prices);
        // Factory stores price in dollars, so 25.00 stays as 25.00 (in cents it would be 2500)
        $this->assertEquals($product->prices->first()->unit_amount, $duplicate->prices->first()->unit_amount);
    }

    #[Test]
    public function duplicate_can_exclude_prices()
    {
        $product = Product::factory()->withPrices(unit_amount: 2500)->create();

        $duplicate = $product->duplicate(includePrices: false);

        $this->assertCount(0, $duplicate->prices);
    }

    #[Test]
    public function duplicate_includes_categories()
    {
        $product = Product::factory()->create();
        $category = ProductCategory::factory()->create();
        $product->categories()->attach($category);

        $duplicate = $product->duplicate();

        $this->assertCount(1, $duplicate->categories);
        $this->assertEquals($category->id, $duplicate->categories->first()->id);
    }

    #[Test]
    public function duplicate_can_exclude_categories()
    {
        $product = Product::factory()->create();
        $category = ProductCategory::factory()->create();
        $product->categories()->attach($category);

        $duplicate = $product->duplicate(includeCategories: false);

        $this->assertCount(0, $duplicate->categories);
    }

    #[Test]
    public function duplicate_includes_attributes()
    {
        $product = Product::factory()->create();
        $product->attributes()->create([
            'key' => 'color',
            'value' => 'red',
            'type' => 'text',
        ]);

        $duplicate = $product->duplicate();

        $this->assertCount(1, $duplicate->attributes);
        $this->assertEquals('color', $duplicate->attributes->first()->key);
        $this->assertEquals('red', $duplicate->attributes->first()->value);
    }

    #[Test]
    public function duplicate_can_exclude_attributes()
    {
        $product = Product::factory()->create();
        $product->attributes()->create([
            'key' => 'color',
            'value' => 'red',
            'type' => 'text',
        ]);

        $duplicate = $product->duplicate(includeAttributes: false);

        $this->assertCount(0, $duplicate->attributes);
    }

    #[Test]
    public function duplicate_can_override_attributes()
    {
        $product = Product::factory()->create([
            'name' => 'Original Name',
        ]);

        $duplicate = $product->duplicate([
            'name' => 'New Name',
        ]);

        $this->assertEquals('New Name', $duplicate->name);
    }

    #[Test]
    public function duplicate_does_not_copy_stripe_product_id()
    {
        $product = Product::factory()->create([
            'stripe_product_id' => 'prod_abc123',
        ]);

        $duplicate = $product->duplicate();

        $this->assertNull($duplicate->stripe_product_id);
    }

    #[Test]
    public function duplicate_sets_status_to_draft()
    {
        $product = Product::factory()->create([
            'status' => ProductStatus::PUBLISHED,
        ]);

        $duplicate = $product->duplicate();

        $this->assertEquals(ProductStatus::DRAFT, $duplicate->status);
        $this->assertNull($duplicate->published_at);
    }

    #[Test]
    public function duplicate_includes_children()
    {
        $parent = Product::factory()->create([
            'slug' => 'parent-product',
        ]);

        $child = Product::factory()->create([
            'parent_id' => $parent->id,
            'slug' => 'child-variant',
        ]);

        $duplicate = $parent->duplicate();

        $this->assertCount(1, $duplicate->children);
        $this->assertEquals($duplicate->id, $duplicate->children->first()->parent_id);
    }

    #[Test]
    public function duplicate_can_exclude_children()
    {
        $parent = Product::factory()->create();

        $child = Product::factory()->create([
            'parent_id' => $parent->id,
        ]);

        $duplicate = $parent->duplicate(includeChildren: false);

        $this->assertCount(0, $duplicate->children);
    }

    #[Test]
    public function duplicate_does_not_copy_stripe_price_id()
    {
        $product = Product::factory()->create();
        $product->prices()->create([
            'name' => 'Default Price',
            'unit_amount' => 2500,
            'currency' => 'USD',
            'is_default' => true,
            'stripe_price_id' => 'price_abc123',
        ]);

        $duplicate = $product->duplicate();

        $this->assertNull($duplicate->prices->first()->stripe_price_id);
    }
}
