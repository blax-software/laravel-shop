<?php

namespace Blax\Shop\Tests\Feature\Product;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductCategory;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class ProductCategoryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
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

    #[Test]
    public function it_automatically_generates_slug_from_name()
    {
        $category = ProductCategory::create([
            'slug' => null,
        ]);

        $this->assertNotNull($category->slug);
    }

    #[Test]
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

    #[Test]
    public function it_can_have_multiple_children()
    {
        $parent = ProductCategory::factory()->create();

        $child1 = ProductCategory::factory()->create(['parent_id' => $parent->id]);
        $child2 = ProductCategory::factory()->create(['parent_id' => $parent->id]);
        $child3 = ProductCategory::factory()->create(['parent_id' => $parent->id]);

        $this->assertCount(3, $parent->fresh()->children);
    }

    #[Test]
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

    #[Test]
    public function it_can_count_products_in_category()
    {
        $category = ProductCategory::factory()->create();
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();
        $product3 = Product::factory()->create();

        $category->products()->attach([$product1->id, $product2->id, $product3->id]);

        $this->assertEquals(3, $category->products()->count());
    }

    #[Test]
    public function it_can_check_visibility()
    {
        $visibleCategory = ProductCategory::factory()->create([
            'is_visible' => true,
        ]);

        $hiddenCategory = ProductCategory::factory()->create([
            'is_visible' => false,
        ]);

        $this->assertTrue($visibleCategory->is_visible);
        $this->assertFalse($hiddenCategory->is_visible);
    }

    #[Test]
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

    #[Test]
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

    #[Test]
    public function product_can_belong_to_multiple_categories()
    {
        $product = Product::factory()->create();
        $category1 = ProductCategory::factory()->create(['slug' => 'electronics']);
        $category2 = ProductCategory::factory()->create(['slug' => 'gadgets']);
        $category3 = ProductCategory::factory()->create(['slug' => 'accessories']);

        $product->categories()->attach([$category1->id, $category2->id, $category3->id]);

        $this->assertCount(3, $product->fresh()->categories);
    }

    #[Test]
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

    #[Test]
    public function it_can_detach_products_from_category()
    {
        $category = ProductCategory::factory()->create();
        $product = Product::factory()->create();

        $category->products()->attach($product->id);
        $this->assertCount(1, $category->fresh()->products);

        $category->products()->detach($product->id);
        $this->assertCount(0, $category->fresh()->products);
    }

    #[Test]
    public function deleting_category_does_not_delete_products()
    {
        $category = ProductCategory::factory()->create();
        $product = Product::factory()->create();

        $category->products()->attach($product->id);
        $productId = $product->id;

        $category->delete();

        $this->assertDatabaseHas('products', ['id' => $productId]);
    }

    #[Test]
    public function it_can_scope_visible_categories()
    {
        ProductCategory::factory()->create(['is_visible' => true]);
        ProductCategory::factory()->create(['is_visible' => true]);
        ProductCategory::factory()->create(['is_visible' => false]);

        $visible = ProductCategory::where('is_visible', true)->get();

        $this->assertCount(2, $visible);
    }

    #[Test]
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

    #[Test]
    public function it_maintains_category_hierarchy_integrity()
    {
        $grandparent = ProductCategory::factory()->create();
        $parent = ProductCategory::factory()->create(['parent_id' => $grandparent->id]);
        $child = ProductCategory::factory()->create(['parent_id' => $parent->id]);

        $this->assertEquals($grandparent->id, $parent->parent->id);
        $this->assertEquals($parent->id, $child->parent->id);
        $this->assertNull($grandparent->parent);
    }

    #[Test]
    public function it_can_filter_products_by_category_using_instance()
    {
        $category1 = ProductCategory::factory()->create();
        $category2 = ProductCategory::factory()->create();

        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();
        $product3 = Product::factory()->create();

        $product1->categories()->attach($category1->id);
        $product2->categories()->attach($category1->id);
        $product3->categories()->attach($category2->id);

        $results = Product::byCategory($category1)->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains($product1));
        $this->assertTrue($results->contains($product2));
        $this->assertFalse($results->contains($product3));
    }

    #[Test]
    public function it_can_filter_products_by_category_using_id_string()
    {
        $category = ProductCategory::factory()->create();

        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();
        $product3 = Product::factory()->create();

        $product1->categories()->attach($category->id);
        $product2->categories()->attach($category->id);

        $results = Product::byCategory($category->id)->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains($product1));
        $this->assertTrue($results->contains($product2));
        $this->assertFalse($results->contains($product3));
    }

    #[Test]
    public function it_can_filter_products_by_multiple_categories()
    {
        $category1 = ProductCategory::factory()->create();
        $category2 = ProductCategory::factory()->create();
        $category3 = ProductCategory::factory()->create();

        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();
        $product3 = Product::factory()->create();
        $product4 = Product::factory()->create();

        // Product 1 has category1 and category2
        $product1->categories()->attach([$category1->id, $category2->id]);
        // Product 2 has only category1
        $product2->categories()->attach($category1->id);
        // Product 3 has only category2
        $product3->categories()->attach($category2->id);
        // Product 4 has category3
        $product4->categories()->attach($category3->id);

        $results = Product::byCategories([$category1->id, $category2->id])->get();

        // Only product1 should match (has both categories)
        $this->assertCount(1, $results);
        $this->assertTrue($results->contains($product1));
        $this->assertFalse($results->contains($product2));
        $this->assertFalse($results->contains($product3));
        $this->assertFalse($results->contains($product4));
    }

    #[Test]
    public function it_can_filter_products_without_specific_category()
    {
        $category1 = ProductCategory::factory()->create();
        $category2 = ProductCategory::factory()->create();

        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();
        $product3 = Product::factory()->create();

        $product1->categories()->attach($category1->id);
        $product2->categories()->attach($category2->id);
        // product3 has no categories

        $results = Product::withoutCategory($category1)->get();

        $this->assertCount(2, $results);
        $this->assertFalse($results->contains($product1));
        $this->assertTrue($results->contains($product2));
        $this->assertTrue($results->contains($product3));
    }

    #[Test]
    public function it_can_filter_products_without_category_using_instance()
    {
        $category = ProductCategory::factory()->create();

        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();

        $product1->categories()->attach($category->id);

        $results = Product::withoutCategory($category)->get();

        $this->assertCount(1, $results);
        $this->assertFalse($results->contains($product1));
        $this->assertTrue($results->contains($product2));
    }

    #[Test]
    public function it_can_filter_products_without_multiple_categories()
    {
        $category1 = ProductCategory::factory()->create();
        $category2 = ProductCategory::factory()->create();
        $category3 = ProductCategory::factory()->create();

        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();
        $product3 = Product::factory()->create();
        $product4 = Product::factory()->create();

        // Product 1 has category1 and category2
        $product1->categories()->attach([$category1->id, $category2->id]);
        // Product 2 has only category1
        $product2->categories()->attach($category1->id);
        // Product 3 has only category3
        $product3->categories()->attach($category3->id);
        // Product 4 has no categories

        $results = Product::withoutCategories([$category1->id, $category2->id])->get();

        // Only products without both category1 AND category2 should match
        $this->assertCount(2, $results);
        $this->assertFalse($results->contains($product1));
        $this->assertFalse($results->contains($product2));
        $this->assertTrue($results->contains($product3));
        $this->assertTrue($results->contains($product4));
    }

    #[Test]
    public function it_can_assign_a_category_to_product()
    {
        $product = Product::factory()->create();
        $category = ProductCategory::factory()->create();

        $product->assignCategory($category);

        $this->assertCount(1, $product->fresh()->categories);
        $this->assertTrue($product->categories->contains($category));
    }

    #[Test]
    public function it_can_assign_multiple_categories_to_product()
    {
        $product = Product::factory()->create();
        $category1 = ProductCategory::factory()->create();
        $category2 = ProductCategory::factory()->create();
        $category3 = ProductCategory::factory()->create();

        $product->assignCategories([$category1, $category2, $category3]);

        $this->assertCount(3, $product->fresh()->categories);
        $this->assertTrue($product->categories->contains($category1));
        $this->assertTrue($product->categories->contains($category2));
        $this->assertTrue($product->categories->contains($category3));
    }

    #[Test]
    public function it_can_remove_a_category_from_product()
    {
        $product = Product::factory()->create();
        $category1 = ProductCategory::factory()->create();
        $category2 = ProductCategory::factory()->create();

        $product->categories()->attach([$category1->id, $category2->id]);
        $this->assertCount(2, $product->fresh()->categories);

        $product->removeCategory($category1);

        $this->assertCount(1, $product->fresh()->categories);
        $this->assertFalse($product->categories->contains($category1));
        $this->assertTrue($product->categories->contains($category2));
    }

    #[Test]
    public function it_can_remove_multiple_categories_from_product()
    {
        $product = Product::factory()->create();
        $category1 = ProductCategory::factory()->create();
        $category2 = ProductCategory::factory()->create();
        $category3 = ProductCategory::factory()->create();

        $product->categories()->attach([$category1->id, $category2->id, $category3->id]);
        $this->assertCount(3, $product->fresh()->categories);

        $product->removeCategories([$category1, $category2]);

        $this->assertCount(1, $product->fresh()->categories);
        $this->assertFalse($product->categories->contains($category1));
        $this->assertFalse($product->categories->contains($category2));
        $this->assertTrue($product->categories->contains($category3));
    }

    #[Test]
    public function it_can_sync_categories_on_product()
    {
        $product = Product::factory()->create();
        $category1 = ProductCategory::factory()->create();
        $category2 = ProductCategory::factory()->create();
        $category3 = ProductCategory::factory()->create();

        // Initially assign category1 and category2
        $product->categories()->attach([$category1->id, $category2->id]);
        $this->assertCount(2, $product->fresh()->categories);

        // Sync to category2 and category3 (removes category1, keeps category2, adds category3)
        $product->syncCategories([$category2->id, $category3->id]);

        $this->assertCount(2, $product->fresh()->categories);
        $this->assertFalse($product->categories->contains($category1));
        $this->assertTrue($product->categories->contains($category2));
        $this->assertTrue($product->categories->contains($category3));
    }

    #[Test]
    public function it_can_assign_category_by_name()
    {
        $product = Product::factory()->create();

        $product->assignCategoryByName('Electronics');

        $this->assertCount(1, $product->fresh()->categories);
        $category = $product->categories->first();
        $this->assertEquals('Electronics', $category->name);
    }

    #[Test]
    public function it_can_assign_category_by_name_without_creating_duplicates()
    {
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();

        $product1->assignCategoryByName('Electronics');
        $product2->assignCategoryByName('Electronics');

        $this->assertCount(1, ProductCategory::where('name', 'Electronics')->get());
    }

    #[Test]
    public function it_can_assign_multiple_categories_by_names()
    {
        $product = Product::factory()->create();

        $product->assignCategoriesByNames(['Electronics', 'Gadgets', 'Accessories']);

        $this->assertCount(3, $product->fresh()->categories);
        $categoryNames = $product->categories->pluck('name')->toArray();
        $this->assertContains('Electronics', $categoryNames);
        $this->assertContains('Gadgets', $categoryNames);
        $this->assertContains('Accessories', $categoryNames);
    }

    #[Test]
    public function it_can_assign_category_by_slug()
    {
        $product = Product::factory()->create();

        $product->asssignCategoryBySlug('electronics');

        $this->assertCount(1, $product->fresh()->categories);
        $category = $product->categories->first();
        $this->assertEquals('electronics', $category->slug);
    }

    #[Test]
    public function it_can_assign_category_by_slug_without_creating_duplicates()
    {
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();

        $product1->asssignCategoryBySlug('electronics');
        $product2->asssignCategoryBySlug('electronics');

        $this->assertCount(1, ProductCategory::where('slug', 'electronics')->get());
    }

    #[Test]
    public function it_can_assign_multiple_categories_by_slugs()
    {
        $product = Product::factory()->create();

        $product->assignCategoriesBySlugs(['electronics', 'gadgets', 'accessories']);

        $this->assertCount(3, $product->fresh()->categories);
        $categorySlugs = $product->categories->pluck('slug')->toArray();
        $this->assertContains('electronics', $categorySlugs);
        $this->assertContains('gadgets', $categorySlugs);
        $this->assertContains('accessories', $categorySlugs);
    }
}
