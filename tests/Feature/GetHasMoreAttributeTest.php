<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class GetHasMoreAttributeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_php_int_max_when_stock_management_is_disabled()
    {
        $product = Product::factory()->create([
            'manage_stock' => false,
        ]);

        $this->assertEquals(PHP_INT_MAX, $product->has_more);
    }

    #[Test]
    public function it_returns_available_stock_for_simple_product()
    {
        $product = Product::factory()->withStocks(50)->create();

        $this->assertEquals(50, $product->has_more);
    }

    #[Test]
    public function it_returns_zero_when_no_stock_available()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
        ]);

        $this->assertEquals(0, $product->has_more);
    }

    #[Test]
    public function it_returns_remaining_stock_after_claims()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Claim 30 units
        $product->claimStock(
            quantity: 30,
            from: now(),
            until: now()->addDays(5)
        );

        $this->assertEquals(70, $product->has_more);
    }

    #[Test]
    public function it_returns_available_stock_for_booking_product()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        // Claim 3 units for a future period
        $product->claimStock(
            quantity: 3,
            from: now()->addDays(5),
            until: now()->addDays(10)
        );

        // Has more should reflect available stock at current time
        $this->assertEquals(10, $product->has_more);
    }

    #[Test]
    public function it_returns_aggregated_availability_for_pool_product()
    {
        // Create pool product
        $pool = Product::factory()->create([
            'name' => 'Hotel Rooms',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create 3 single items with stock
        $single1 = Product::factory()->create([
            'name' => 'Room 101',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single1->increaseStock(1);

        $single2 = Product::factory()->create([
            'name' => 'Room 102',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single2->increaseStock(1);

        $single3 = Product::factory()->create([
            'name' => 'Room 103',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single3->increaseStock(1);

        // Attach singles to pool
        foreach ([$single1, $single2, $single3] as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        // Pool should aggregate availability from all singles: 1 + 1 + 1 = 3
        $this->assertEquals(3, $pool->has_more);
    }

    #[Test]
    public function it_returns_aggregated_availability_for_pool_with_claims()
    {
        // Create pool product
        $pool = Product::factory()->create([
            'name' => 'Hotel Rooms',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create 3 single items with stock
        $singles = [];
        for ($i = 1; $i <= 3; $i++) {
            $single = Product::factory()->create([
                'name' => "Room 10{$i}",
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);
            $single->increaseStock(1);
            $singles[] = $single;
        }

        foreach ($singles as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        // Claim single1 from now until next week (active claim)
        $singles[0]->claimStock(
            quantity: 1,
            from: now(),
            until: now()->addDays(7)
        );

        // Pool should show 2 available (singles 2 and 3)
        $this->assertEquals(2, $pool->has_more);
    }

    #[Test]
    public function it_returns_aggregated_availability_for_pool_with_future_claims()
    {
        // Create pool product
        $pool = Product::factory()->create([
            'name' => 'Rental Cars',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create 2 single items with stock
        $single1 = Product::factory()->create([
            'name' => 'Car 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single1->increaseStock(1);

        $single2 = Product::factory()->create([
            'name' => 'Car 2',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single2->increaseStock(1);

        foreach ([$single1, $single2] as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        // Claim single1 for a FUTURE period (not yet active)
        $single1->claimStock(
            quantity: 1,
            from: now()->addDays(5),
            until: now()->addDays(10)
        );

        // Pool should show 2 available (both cars available NOW, claim starts in future)
        $this->assertEquals(2, $pool->has_more);
    }

    #[Test]
    public function it_returns_php_int_max_for_pool_with_unmanaged_singles()
    {
        // Create pool product
        $pool = Product::factory()->create([
            'name' => 'Digital Products Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create single items WITHOUT stock management (unlimited)
        $single1 = Product::factory()->create([
            'name' => 'Digital Item 1',
            'type' => ProductType::SIMPLE,
            'manage_stock' => false,
        ]);

        $single2 = Product::factory()->create([
            'name' => 'Digital Item 2',
            'type' => ProductType::SIMPLE,
            'manage_stock' => false,
        ]);

        foreach ([$single1, $single2] as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        // Pool with all unlimited singles should return a very large number
        // (sum of PHP_INT_MAX values, which indicates unlimited availability)
        $this->assertGreaterThanOrEqual(PHP_INT_MAX, $pool->has_more);
    }

    #[Test]
    public function it_returns_zero_for_empty_pool()
    {
        $pool = Product::factory()->create([
            'name' => 'Empty Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $this->assertEquals(0, $pool->has_more);
    }

    #[Test]
    public function it_returns_zero_when_all_stock_is_claimed()
    {
        $product = Product::factory()->withStocks(10)->create();

        // Claim all 10 units
        $product->claimStock(
            quantity: 10,
            from: now(),
            until: now()->addDays(5)
        );

        $this->assertEquals(0, $product->has_more);
    }

    #[Test]
    public function it_correctly_handles_mixed_managed_and_unmanaged_pool_singles()
    {
        $pool = Product::factory()->create([
            'name' => 'Mixed Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Single 1: managed stock
        $single1 = Product::factory()->create([
            'name' => 'Limited Item',
            'type' => ProductType::SIMPLE,
            'manage_stock' => true,
        ]);
        $single1->increaseStock(5);

        // Single 2: unmanaged stock (unlimited)
        $single2 = Product::factory()->create([
            'name' => 'Unlimited Item',
            'type' => ProductType::SIMPLE,
            'manage_stock' => false,
        ]);

        foreach ([$single1, $single2] as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        // Pool should sum: 5 (limited) + PHP_INT_MAX (unlimited)
        // Result will be very large, indicating effectively unlimited availability
        $this->assertGreaterThanOrEqual(PHP_INT_MAX, $pool->has_more);
    }

    #[Test]
    public function it_returns_correct_stock_after_multiple_increases_and_decreases()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
        ]);

        $product->increaseStock(100);
        $product->decreaseStock(20);
        $product->increaseStock(50);
        $product->decreaseStock(30);

        // 100 - 20 + 50 - 30 = 100
        $this->assertEquals(100, $product->has_more);
    }
}
