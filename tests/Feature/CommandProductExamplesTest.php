<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Console\Commands\ShopAddExampleProducts;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CommandProductExamplesTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_creates_example_products_and_related_data(): void
    {
        $this->artisan(ShopAddExampleProducts::class, ['--clean' => true, '--count' => 2])
            ->assertExitCode(0);

        // Parent products (no parent_id) should be 4 types * 2 count = 8
        $parents = Product::whereNull('parent_id')->get();
        $this->assertCount(8, $parents, 'Expected 8 parent example products');

        // Total products should include variations (3 per variable) and grouped children (>=2 each)
        $this->assertGreaterThanOrEqual(18, Product::count(), 'Expected at least 18 total products including children');

        // Categories are created (5 predefined) and attached to parents (1-3 each)
        $this->assertGreaterThanOrEqual(5, \Blax\Shop\Models\ProductCategory::count(), 'Expected at least 5 example categories');
        foreach ($parents as $product) {
            $this->assertGreaterThanOrEqual(1, $product->categories()->count(), 'Parent product should have at least one category');
        }

        // Each parent product has 3 actions as per command
        foreach ($parents as $product) {
            $this->assertEquals(3, $product->actions()->count(), 'Parent product should have exactly 3 actions');
            // Events field present and is array
            $this->assertIsArray($product->actions()->first()->events);
        }

        // Each product (including variants/children) must have a default price
        /** @var Product $p */
        foreach (Product::all() as $p) {
            $this->assertTrue($p->defaultPrice()->exists(), 'Each product should have a default price');
        }

        // Attributes exist for parents (>=2) and variations (Size)
        foreach ($parents as $product) {
            $this->assertGreaterThanOrEqual(2, $product->attributes()->count(), 'Parent should have attributes');
        }
        $variation = Product::whereNotNull('parent_id')->first();
        $this->assertNotNull($variation, 'There should be at least one variation');
        $this->assertTrue($variation->attributes()->where('key', 'Size')->exists());

        // Localization for name is populated
        $this->assertNotEmpty(Product::first()->getLocalized('name'));
    }

    /** @test */
    public function it_cleans_existing_examples_when_option_provided(): void
    {
        // Seed examples
        $this->artisan(ShopAddExampleProducts::class, ['--clean' => true, '--count' => 1])->assertExitCode(0);
        $this->assertGreaterThan(0, Product::where('slug', 'like', 'example-%')->count());

        // Clean again (count=0 will create categories but no products)
        $this->artisan(ShopAddExampleProducts::class, ['--clean' => true, '--count' => 0])->assertExitCode(0);

        // All example products removed, categories recreated (5 default)
        $this->assertEquals(0, Product::where('slug', 'like', 'example-%')->count());
        $this->assertEquals(5, \Blax\Shop\Models\ProductCategory::where('slug', 'like', 'example-%')->count());
    }

    /** @test */
    public function it_honors_the_count_option_for_each_type(): void
    {
        $this->artisan(ShopAddExampleProducts::class, ['--clean' => true, '--count' => 3])
            ->assertExitCode(0);

        // For each of the 4 types, expect 3 parent products
        $parents = Product::whereNull('parent_id')->get();
        $this->assertCount(12, $parents);

        $byType = $parents->groupBy('type');
        $this->assertEquals(3, $byType['simple']->count());
        $this->assertEquals(3, $byType['variable']->count());
        $this->assertEquals(3, $byType['grouped']->count());
        $this->assertEquals(3, $byType['external']->count());

        // Sanity: external products do not manage stock
        $this->assertTrue($byType['external']->every(fn($p) => $p->manage_stock === false));
    }
}
