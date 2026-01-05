<?php

namespace Blax\Shop\Tests\Feature\Product;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductCategory;
use Blax\Shop\Models\ProductAttribute;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_create_a_product()
    {
        $product = Product::factory()
            ->withPrices(1, 9999)
            ->create([
                'slug' => 'test-product',
                'type' => 'simple',
            ]);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'slug' => 'test-product',
        ]);

        $this->assertCount(1, $product->prices);
        // Factory converts 99.99 euros to 9999 cents
        $this->assertEquals(9999, $product->prices->first()->unit_amount);
    }

    #[Test]
    public function it_automatically_generates_slug_if_not_provided()
    {
        $product = Product::factory()->create(['slug' => null]);

        $this->assertNotNull($product->slug);
        $this->assertStringStartsWith('new-product-', $product->slug);
    }

    #[Test]
    public function it_can_manage_stock()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
        ]);

        $this->assertTrue($product->increaseStock(60));
        $this->assertEquals(60, $product->AvailableStocks);

        $this->assertTrue($product->decreaseStock(5));
        $this->assertEquals(55, $product->AvailableStocks);
    }

    #[Test]
    public function it_cannot_decrease_stock_below_zero()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
        ]);

        $this->assertThrows(function () use ($product) {
            $product->decreaseStock(10);
        }, \Blax\Shop\Exceptions\NotEnoughStockException::class);
    }

    #[Test]
    public function it_returns_available_stock()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
        ]);

        $this->assertEquals(0, $product->getAvailableStock());
        $product->increaseStock(20);
        $this->assertEquals(20, $product->getAvailableStock());
    }

    #[Test]
    public function it_can_check_if_in_stock()
    {
        $productInStock = Product::factory()->create([
            'manage_stock' => true,
        ]);
        $productInStock->increaseStock(10);

        $productOutOfStock = Product::factory()->create([
            'manage_stock' => true,
        ]);

        $this->assertTrue($productInStock->isInStock());
        $this->assertFalse($productOutOfStock->isInStock());
    }

    #[Test]
    public function it_can_attach_categories()
    {
        $product = Product::factory()->create();
        $category = ProductCategory::factory()->create();

        $product->categories()->attach($category);

        $this->assertTrue($product->categories->contains($category));
    }

    #[Test]
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

    #[Test]
    public function it_can_have_multiple_prices()
    {
        $product = Product::factory()->create();

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'type' => 'one_time',
            'unit_amount' => 9999,
            'currency' => 'USD',
            'active' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'type' => 'recurring',
            'unit_amount' => 1999,
            'currency' => 'USD',
            'interval' => 'month',
            'active' => true,
        ]);

        $this->assertCount(2, $product->prices);
    }

    #[Test]
    public function it_can_have_related_products()
    {
        $product = Product::factory()->create();
        $relatedProduct = Product::factory()->create();

        $product->productRelations()->attach($relatedProduct->id, [
            'type' => ProductRelationType::RELATED->value,
        ]);

        $this->assertTrue($product->relatedProducts()->get()->contains($relatedProduct));
    }

    #[Test]
    public function it_can_have_upsell_products()
    {
        $product = Product::factory()->create();
        $upsellProduct = Product::factory()->create();

        $product->productRelations()->attach($upsellProduct->id, [
            'type' => ProductRelationType::UPSELL->value,
        ]);

        $this->assertTrue($product->upsellProducts()->get()->contains($upsellProduct));
    }

    #[Test]
    public function it_can_have_cross_sell_products()
    {
        $product = Product::factory()->create();
        $crossSellProduct = Product::factory()->create();

        $product->productRelations()->attach($crossSellProduct->id, [
            'type' => ProductRelationType::CROSS_SELL->value,
        ]);

        $this->assertTrue($product->crossSellProducts()->get()->contains($crossSellProduct));
    }

    #[Test]
    public function it_can_scope_published_products()
    {
        Product::factory()->create(['status' => 'published']);
        Product::factory()->create(['status' => 'draft']);

        $published = Product::published()->get();

        $this->assertCount(1, $published);
        $this->assertEquals(ProductStatus::PUBLISHED, $published->first()->status);
    }

    #[Test]
    public function it_can_scope_in_stock_products()
    {
        Product::factory()->create([
            'manage_stock' => false,
        ]);

        $productInStock = Product::factory()->create([
            'manage_stock' => true,
        ]);
        $productInStock->increaseStock(10);

        $inStock = Product::inStock()->get();

        $this->assertCount(2, $inStock);
        $this->assertTrue((bool) ($inStock->first()->isInStock()));
        $this->assertNotEquals($inStock->reverse()->first()->id, $inStock->first()->id);
        $this->assertTrue((bool) ($inStock->reverse()->first()->isInStock()));
    }

    #[Test]
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

    #[Test]
    public function it_can_have_parent_child_relationships()
    {
        $parent = Product::factory()->create([
            'type' => ProductType::VARIABLE,
        ]);

        $child = Product::factory()->create([
            'type' => ProductType::VARIABLE,
            'parent_id' => $parent->id,
        ]);

        $this->assertTrue($parent->children->contains($child));
        $this->assertEquals($parent->id, $child->parent->id);
    }

    #[Test]
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

    #[Test]
    public function it_can_check_featured_status()
    {
        $featured = Product::factory()->create(['featured' => true]);
        $regular = Product::factory()->create(['featured' => false]);

        $this->assertTrue($featured->featured);
        $this->assertFalse($regular->featured);
    }
}
