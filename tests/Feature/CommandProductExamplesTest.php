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

        // Parent products (no parent_id) should be 6 types * 2 count = 12
        $parents = Product::whereNull('parent_id')->get();
        $this->assertCount(12, $parents, 'Expected 12 parent example products (6 types * 2 each)');

        // Total products should include variations, grouped children, and pool items
        $this->assertGreaterThanOrEqual(30, Product::count(), 'Expected at least 30 total products including children');

        // Categories are created (6 hotel categories) and attached to parents
        $this->assertGreaterThanOrEqual(6, \Blax\Shop\Models\ProductCategory::count(), 'Expected at least 6 example categories');

        // Each product (including variants/children) must have a default price
        /** @var Product $p */
        foreach (Product::all() as $p) {
            $this->assertTrue($p->defaultPrice()->exists(), 'Each product should have a default price');
        }

        $variation = Product::whereNotNull('parent_id')->first();
        $this->assertNotNull($variation, 'There should be at least one variation');

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

        // All example products removed, categories recreated (6 hotel categories)
        $this->assertEquals(0, Product::where('slug', 'like', 'example-%')->count());
        $this->assertEquals(6, \Blax\Shop\Models\ProductCategory::where('slug', 'like', 'example-%')->count());
    }

    /** @test */
    public function it_honors_the_count_option_for_each_type(): void
    {
        $this->artisan(ShopAddExampleProducts::class, ['--clean' => true, '--count' => 3])
            ->assertExitCode(0);

        // For each of the 6 types, expect 3 parent products
        $parents = Product::whereNull('parent_id')->get();
        $this->assertCount(18, $parents);

        $byType = $parents->groupBy('type');
        $this->assertEquals(3, $byType['simple']->count());
        $this->assertEquals(3, $byType['variable']->count());
        $this->assertEquals(3, $byType['grouped']->count());
        $this->assertEquals(3, $byType['external']->count());
        $this->assertEquals(3, $byType['booking']->count());
        $this->assertEquals(3, $byType['pool']->count());

        // Sanity: external products do not manage stock
        $this->assertTrue($byType['external']->every(fn($p) => $p->manage_stock === false));
    }
}
