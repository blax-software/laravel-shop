<?php

namespace Blax\Shop\Tests\Feature\Http;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductCategory;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Feature coverage for the package's public-facing read API. Before this
 * suite landed the controllers in src/Http/Controllers/Api had zero direct
 * test coverage — these endpoints are what the storefront / frontend hits
 * to render categories and product listings, so any regression on filter
 * handling, scope behaviour or response shape silently broke real apps.
 *
 * Each test exercises a single endpoint with both the happy path and any
 * type-sensitive query-string handling (pagination caps, boolean filters,
 * featured / in_stock / category filtering) so the kind of `bool` / `int`
 * cast bugs that motivated this round can't slip through unnoticed.
 */
class PublicApiTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // GET api/shop/products
    // ------------------------------------------------------------------

    #[Test]
    public function products_index_returns_paginated_published_visible_products(): void
    {
        Product::factory()->count(3)->withPrices()->create([
            'status' => 'published',
            'is_visible' => true,
        ]);

        // Should NOT appear: drafts, hidden, or trashed.
        Product::factory()->create(['status' => 'draft', 'is_visible' => true]);
        Product::factory()->create(['status' => 'published', 'is_visible' => false]);

        $response = $this->getJson(route('shop.products.index'));

        $response->assertOk();
        $this->assertSame(3, $response->json('total'));
        $this->assertCount(3, $response->json('data'));
    }

    #[Test]
    public function products_index_honours_per_page_request_but_caps_to_max(): void
    {
        Product::factory()->count(8)->create([
            'status' => 'published',
            'is_visible' => true,
        ]);

        // Within configured max — honoured verbatim.
        $small = $this->getJson(route('shop.products.index', ['per_page' => 2]));
        $this->assertSame(2, $small->json('per_page'));
        $this->assertCount(2, $small->json('data'));

        // Above the configured max — clamped down.
        $max = (int) config('shop.pagination.max_per_page');
        $clamped = $this->getJson(route('shop.products.index', ['per_page' => $max + 999]));
        $this->assertLessThanOrEqual($max, (int) $clamped->json('per_page'));
    }

    #[Test]
    public function products_index_filters_by_category_slug(): void
    {
        $catA = ProductCategory::create(['name' => 'A', 'slug' => 'cat-a', 'is_visible' => true]);
        $catB = ProductCategory::create(['name' => 'B', 'slug' => 'cat-b', 'is_visible' => true]);

        $inA = Product::factory()->create(['status' => 'published', 'is_visible' => true]);
        $inB = Product::factory()->create(['status' => 'published', 'is_visible' => true]);
        $inA->categories()->attach($catA->id);
        $inB->categories()->attach($catB->id);

        $response = $this->getJson(route('shop.products.index', ['category' => 'cat-a']));

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($inA->id, $ids);
        $this->assertNotContains($inB->id, $ids);
    }

    #[Test]
    public function products_index_filters_by_featured_flag(): void
    {
        $featured = Product::factory()->create([
            'status' => 'published', 'is_visible' => true, 'featured' => true,
        ]);
        $unfeatured = Product::factory()->create([
            'status' => 'published', 'is_visible' => true, 'featured' => false,
        ]);

        $response = $this->getJson(route('shop.products.index', ['featured' => 1]));

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($featured->id, $ids);
        $this->assertNotContains($unfeatured->id, $ids);
    }

    #[Test]
    public function products_index_filters_by_in_stock(): void
    {
        // Has ledger stock → considered in stock.
        $orderable = Product::factory()->withStocks(5)->create([
            'status' => 'published', 'is_visible' => true, 'manage_stock' => true,
        ]);
        // manage_stock=true with no ledger entries → out of stock.
        $depleted = Product::factory()->create([
            'status' => 'published', 'is_visible' => true, 'manage_stock' => true,
        ]);
        // manage_stock=false → always in stock by scope definition.
        $unlimited = Product::factory()->create([
            'status' => 'published', 'is_visible' => true, 'manage_stock' => false,
        ]);

        $response = $this->getJson(route('shop.products.index', ['in_stock' => 1]));

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertContains($orderable->id, $ids);
        $this->assertContains($unlimited->id, $ids);
        $this->assertNotContains($depleted->id, $ids);
    }

    #[Test]
    public function products_show_returns_published_visible_product_by_slug(): void
    {
        $product = Product::factory()->withPrices()->create([
            'slug' => 'my-product',
            'status' => 'published',
            'is_visible' => true,
        ]);

        $response = $this->getJson(route('shop.products.show', ['slug' => 'my-product']));

        $response->assertOk();
        // show() wraps the model under `data` and merges helper fields onto it.
        $this->assertSame($product->id, $response->json('data.id'));
        $this->assertArrayHasKey('current_price', $response->json('data'));
        $this->assertArrayHasKey('on_sale', $response->json('data'));
    }

    #[Test]
    public function products_show_404s_on_unknown_or_invisible_slug(): void
    {
        Product::factory()->create([
            'slug' => 'hidden-product',
            'status' => 'published',
            'is_visible' => false,
        ]);

        $this->getJson(route('shop.products.show', ['slug' => 'hidden-product']))
            ->assertNotFound();

        $this->getJson(route('shop.products.show', ['slug' => 'does-not-exist']))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------
    // GET api/shop/categories
    // ------------------------------------------------------------------

    #[Test]
    public function categories_index_returns_root_visible_categories_with_children(): void
    {
        $root = ProductCategory::create(['name' => 'Root', 'slug' => 'root', 'is_visible' => true, 'sort_order' => 1]);
        ProductCategory::create([
            'name' => 'Child', 'slug' => 'child', 'is_visible' => true, 'parent_id' => $root->id, 'sort_order' => 1,
        ]);
        // Hidden root + child — must not surface.
        ProductCategory::create(['name' => 'Hidden', 'slug' => 'hidden', 'is_visible' => false]);

        $response = $this->getJson(route('shop.categories.index'));

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data, 'Only the visible root should be returned at the top level');
        $this->assertSame('root', $data[0]['slug']);
        $this->assertCount(1, $data[0]['children'], 'Child relation should be eager-loaded onto the root');
        $this->assertSame('child', $data[0]['children'][0]['slug']);
    }

    #[Test]
    public function categories_show_returns_category_by_slug_with_relations(): void
    {
        ProductCategory::create(['name' => 'C', 'slug' => 'c', 'is_visible' => true]);

        $response = $this->getJson(route('shop.categories.show', ['slug' => 'c']));

        $response->assertOk();
        $this->assertSame('c', $response->json('data.slug'));
    }

    #[Test]
    public function categories_show_404s_on_hidden_or_unknown(): void
    {
        ProductCategory::create(['name' => 'Hidden', 'slug' => 'hidden', 'is_visible' => false]);

        $this->getJson(route('shop.categories.show', ['slug' => 'hidden']))->assertNotFound();
        $this->getJson(route('shop.categories.show', ['slug' => 'nope']))->assertNotFound();
    }

    #[Test]
    public function categories_tree_returns_category_tree(): void
    {
        ProductCategory::create(['name' => 'A', 'slug' => 'a', 'is_visible' => true]);
        ProductCategory::create(['name' => 'B', 'slug' => 'b', 'is_visible' => true]);

        $response = $this->getJson(route('shop.categories.tree'));

        $response->assertOk();
        $this->assertIsArray($response->json('data'));
    }
}
