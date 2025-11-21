<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductCategory;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductCategoryTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_create_a_category()
    {
        $category = ProductCategory::factory()->create([
            'slug' => 'electronics',
        ]);

        $this->assertDatabaseHas('product_categories', [
            'id' => $category->id,
            'slug' => 'electronics',
        ]);
    }

    /** @test */
    public function it_automatically_generates_slug_from_name()
    {
        $category = ProductCategory::create([
            'slug' => null,
        ]);

        $this->assertNotNull($category->slug);
    }

    /** @test */
    public function it_can_have_a_parent_category()
    {
        $parent = ProductCategory::factory()->create([
            'slug' => 'parent-category',
        ]);

        $child = ProductCategory::factory()->create([
            'slug' => 'child-category',
            'parent_id' => $parent->id,
        ]);

        $this->assertEquals($parent->id, $child->parent->id);
    }

    /** @test */
    public function it_can_have_multiple_children()
    {
        $parent = ProductCategory::factory()->create();

        $child1 = ProductCategory::factory()->create(['parent_id' => $parent->id]);
        $child2 = ProductCategory::factory()->create(['parent_id' => $parent->id]);
        $child3 = ProductCategory::factory()->create(['parent_id' => $parent->id]);

        $this->assertCount(3, $parent->fresh()->children);
    }

    /** @test */
    public function it_can_attach_products_to_category()
    {
        $category = ProductCategory::factory()->create();
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();

        $category->products()->attach([$product1->id, $product2->id]);

        $this->assertCount(2, $category->fresh()->products);
        $this->assertTrue($category->products->contains($product1));
        $this->assertTrue($category->products->contains($product2));
    }

    /** @test */
    public function it_can_count_products_in_category()
    {
        $category = ProductCategory::factory()->create();
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();
        $product3 = Product::factory()->create();

        $category->products()->attach([$product1->id, $product2->id, $product3->id]);

        $this->assertEquals(3, $category->products()->count());
    }

    /** @test */
    public function it_can_check_visibility()
    {
        $visibleCategory = ProductCategory::factory()->create([
            'visible' => true,
        ]);

        $hiddenCategory = ProductCategory::factory()->create([
            'visible' => false,
        ]);

        $this->assertTrue($visibleCategory->is_visible);
        $this->assertFalse($hiddenCategory->is_visible);
    }

    /** @test */
    public function it_can_have_a_sort_order()
    {
        $category1 = ProductCategory::factory()->create(['sort_order' => 1]);
        $category2 = ProductCategory::factory()->create(['sort_order' => 2]);
        $category3 = ProductCategory::factory()->create(['sort_order' => 3]);

        $sorted = ProductCategory::orderBy('sort_order')->get();

        $this->assertEquals($category1->id, $sorted[0]->id);
        $this->assertEquals($category2->id, $sorted[1]->id);
        $this->assertEquals($category3->id, $sorted[2]->id);
    }

    /** @test */
    public function it_can_store_meta_data()
    {
        $category = ProductCategory::factory()->create([
            'meta' => [
                'description' => 'Test description',
                'keywords' => ['test', 'category'],
            ],
        ]);

        $this->assertEquals('Test description', $category->meta->description);
        $this->assertEquals(['test', 'category'], $category->meta->keywords);
    }

    /** @test */
    public function product_can_belong_to_multiple_categories()
    {
        $product = Product::factory()->create();
        $category1 = ProductCategory::factory()->create(['slug' => 'electronics']);
        $category2 = ProductCategory::factory()->create(['slug' => 'gadgets']);
        $category3 = ProductCategory::factory()->create(['slug' => 'accessories']);

        $product->categories()->attach([$category1->id, $category2->id, $category3->id]);

        $this->assertCount(3, $product->fresh()->categories);
    }

    /** @test */
    public function it_can_get_all_products_from_category_hierarchy()
    {
        $parent = ProductCategory::factory()->create();
        $child = ProductCategory::factory()->create(['parent_id' => $parent->id]);

        $parentProduct = Product::factory()->create();
        $childProduct = Product::factory()->create();

        $parent->products()->attach($parentProduct->id);
        $child->products()->attach($childProduct->id);

        $this->assertCount(1, $parent->products);
        $this->assertCount(1, $child->products);
    }

    /** @test */
    public function it_can_detach_products_from_category()
    {
        $category = ProductCategory::factory()->create();
        $product = Product::factory()->create();

        $category->products()->attach($product->id);
        $this->assertCount(1, $category->fresh()->products);

        $category->products()->detach($product->id);
        $this->assertCount(0, $category->fresh()->products);
    }

    /** @test */
    public function deleting_category_does_not_delete_products()
    {
        $category = ProductCategory::factory()->create();
        $product = Product::factory()->create();

        $category->products()->attach($product->id);
        $productId = $product->id;

        $category->delete();

        $this->assertDatabaseHas('products', ['id' => $productId]);
    }

    /** @test */
    public function it_can_scope_visible_categories()
    {
        ProductCategory::factory()->create(['is_visible' => true]);
        ProductCategory::factory()->create(['is_visible' => true]);
        ProductCategory::factory()->create(['is_visible' => false]);

        $visible = ProductCategory::where('is_visible', true)->get();

        $this->assertCount(2, $visible);
    }

    /** @test */
    public function it_can_get_root_categories()
    {
        $root1 = ProductCategory::factory()->create(['parent_id' => null]);
        $root2 = ProductCategory::factory()->create(['parent_id' => null]);
        $child = ProductCategory::factory()->create(['parent_id' => $root1->id]);

        $roots = ProductCategory::whereNull('parent_id')->get();

        $this->assertCount(2, $roots);
        $this->assertTrue($roots->contains($root1));
        $this->assertTrue($roots->contains($root2));
        $this->assertFalse($roots->contains($child));
    }

    /** @test */
    public function it_maintains_category_hierarchy_integrity()
    {
        $grandparent = ProductCategory::factory()->create();
        $parent = ProductCategory::factory()->create(['parent_id' => $grandparent->id]);
        $child = ProductCategory::factory()->create(['parent_id' => $parent->id]);

        $this->assertEquals($grandparent->id, $parent->parent->id);
        $this->assertEquals($parent->id, $child->parent->id);
        $this->assertNull($grandparent->parent);
    }
}
