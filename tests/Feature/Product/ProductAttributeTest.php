<?php

namespace Blax\Shop\Tests\Feature\Product;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductAttribute;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class ProductAttributeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_create_a_product_attribute()
    {
        $product = Product::factory()->create();

        $attribute = ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'Material',
            'value' => 'Cotton',
        ]);

        $this->assertDatabaseHas('product_attributes', [
            'id' => $attribute->id,
            'product_id' => $product->id,
            'key' => 'Material',
            'value' => 'Cotton',
        ]);
    }

    #[Test]
    public function attribute_belongs_to_product()
    {
        $product = Product::factory()->create();

        $attribute = ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'Color',
            'value' => 'Red',
        ]);

        $this->assertInstanceOf(Product::class, $attribute->product);
        $this->assertEquals($product->id, $attribute->product->id);
    }

    #[Test]
    public function product_can_have_multiple_attributes()
    {
        $product = Product::factory()->create();

        ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'Size',
            'value' => 'Large',
        ]);

        ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'Color',
            'value' => 'Blue',
        ]);

        ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'Material',
            'value' => 'Polyester',
        ]);

        $this->assertCount(3, $product->fresh()->attributes);
    }

    #[Test]
    public function it_can_have_a_sort_order()
    {
        $product = Product::factory()->create();

        $attr1 = ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'First',
            'value' => 'Value1',
            'sort_order' => 1,
        ]);

        $attr2 = ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'Second',
            'value' => 'Value2',
            'sort_order' => 2,
        ]);

        $attributes = ProductAttribute::where('product_id', $product->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertEquals($attr1->id, $attributes[0]->id);
        $this->assertEquals($attr2->id, $attributes[1]->id);
    }

    #[Test]
    public function it_can_store_metadata()
    {
        $product = Product::factory()->create();

        // Attributes now store structured data in value or as separate attributes
        $dimensionAttr = ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'Dimensions',
            'value' => '10x20x30',
        ]);

        $unitAttr = ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'Dimension Unit',
            'value' => 'cm',
        ]);

        $this->assertEquals('10x20x30', $dimensionAttr->value);
        $this->assertEquals('cm', $unitAttr->value);
    }

    #[Test]
    public function it_can_update_attribute_value()
    {
        $product = Product::factory()->create();

        $attribute = ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'Stock Status',
            'value' => 'In Stock',
        ]);

        $attribute->update(['value' => 'Out of Stock']);

        $this->assertEquals('Out of Stock', $attribute->fresh()->value);
    }

    #[Test]
    public function deleting_product_deletes_attributes()
    {
        $product = Product::factory()->create();

        $attribute = ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'Test',
            'value' => 'Value',
        ]);

        $attributeId = $attribute->id;

        $product->delete();

        $this->assertDatabaseMissing('product_attributes', ['id' => $attributeId]);
    }

    #[Test]
    public function it_can_filter_attributes_by_key()
    {
        $product = Product::factory()->create();

        ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'Color',
            'value' => 'Red',
        ]);

        ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'Color',
            'value' => 'Blue',
        ]);

        ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'Size',
            'value' => 'Large',
        ]);

        $colorAttributes = ProductAttribute::where('product_id', $product->id)
            ->where('key', 'Color')
            ->get();

        $this->assertCount(2, $colorAttributes);
    }

    #[Test]
    public function multiple_products_can_have_same_attribute_keys()
    {
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();

        ProductAttribute::create([
            'product_id' => $product1->id,
            'key' => 'Brand',
            'value' => 'Brand A',
        ]);

        ProductAttribute::create([
            'product_id' => $product2->id,
            'key' => 'Brand',
            'value' => 'Brand B',
        ]);

        $this->assertCount(1, $product1->attributes);
        $this->assertCount(1, $product2->attributes);
        $this->assertEquals('Brand A', $product1->attributes->first()->value);
        $this->assertEquals('Brand B', $product2->attributes->first()->value);
    }

    #[Test]
    public function attributes_are_hidden_in_api_responses()
    {
        $product = Product::factory()->create();

        $attribute = ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'Secret',
            'value' => 'Value',
        ]);

        $array = $attribute->toArray();

        $this->assertArrayNotHasKey('id', $array);
        $this->assertArrayNotHasKey('product_id', $array);
        $this->assertArrayNotHasKey('created_at', $array);
        $this->assertArrayNotHasKey('updated_at', $array);
    }
}
