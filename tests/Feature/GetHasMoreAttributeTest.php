<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

class GetHasMoreAttributeTest extends TestCase
{
    use RefreshDatabase;

    protected function createUserWithCart(): array
    {
        $user = User::create([
            'id' => Str::uuid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $cart = Cart::factory()->forCustomer($user)->create();

        return [$user, $cart];
    }

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
    public function it_returns_total_capacity_for_pool_regardless_of_claims()
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

        // Pool should show 3 available (TOTAL capacity, claims don't affect has_more)
        // This allows users to add items to cart and adjust dates later
        // Date-based validation happens at checkout
        $this->assertEquals(3, $pool->has_more);
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

        // Single 1: managed stock (5 units)
        $single1 = Product::factory()->create([
            'name' => 'Limited Item',
            'type' => ProductType::SIMPLE,
            'manage_stock' => true,
        ]);
        $single1->increaseStock(5);

        // Single 2: unmanaged stock (unlimited - but only 1 item)
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

        // Pool with mixed managed/unmanaged singles:
        // - Single1 has 5 stock (capacity = 5)
        // - Single2 is unmanaged (no stock entries, capacity contribution = 0)
        // Total pool capacity = 5
        // The unmanaged single doesn't add to pool capacity because it's just 1 item
        $this->assertEquals(5, $pool->has_more);
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

    #[Test]
    public function it_subtracts_cart_items_from_available_stock()
    {
        [$user, $cart] = $this->createUserWithCart();

        $product = Product::factory()->withStocks(10)->withPrices(1, 1000)->create();

        // Add 3 to cart
        $cart->addToCart($product, 3);

        // Use getHasMore with explicit cart - should show 7 remaining
        $this->assertEquals(7, $product->getHasMore($cart));
    }

    #[Test]
    public function it_subtracts_cart_items_from_pool_availability()
    {
        [$user, $cart] = $this->createUserWithCart();

        // Create pool product
        $pool = Product::factory()->create([
            'name' => 'Hotel Rooms',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create a price for the pool
        $pool->prices()->create([
            'unit_amount' => 10000,
            'currency' => 'usd',
            'is_default' => true,
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

            $single->prices()->create([
                'unit_amount' => 10000,
                'currency' => 'usd',
                'is_default' => true,
            ]);

            $singles[] = $single;
        }

        foreach ($singles as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        // Pool should have 3 available initially
        $this->assertEquals(3, $pool->getHasMore($cart));

        // Add 2 rooms to cart
        $cart->addToCart($pool, 1, [], now()->addDays(5), now()->addDays(10));
        $cart->addToCart($pool, 1, [], now()->addDays(5), now()->addDays(10));

        // Now pool should show 1 remaining
        $this->assertEquals(1, $pool->getHasMore($cart));

        // Add the last room
        $cart->addToCart($pool, 1, [], now()->addDays(5), now()->addDays(10));

        // Now pool should show 0 remaining
        $this->assertEquals(0, $pool->getHasMore($cart));
    }

    #[Test]
    public function it_returns_total_stock_for_booking_products()
    {
        [$user, $cart] = $this->createUserWithCart();

        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        $product->prices()->create([
            'unit_amount' => 5000,
            'currency' => 'usd',
            'is_default' => true,
        ]);

        // Claim 5 units for days 5-10
        $product->claimStock(
            quantity: 5,
            from: now()->startOfDay()->addDays(5),
            until: now()->endOfDay()->addDays(10)
        );

        // has_more should show total stock (NOT date-restricted)
        // This allows adding items to cart and adjusting dates later
        $this->assertEquals(10, $product->getHasMore($cart));

        // Use getAvailableForDateRange for date-specific availability
        $this->assertEquals(5, $product->getAvailableForDateRange(now()->addDays(6), now()->addDays(8), $cart));
        $this->assertEquals(10, $product->getAvailableForDateRange(now()->addDays(15), now()->addDays(20), $cart));
    }

    #[Test]
    public function it_returns_total_stock_regardless_of_cart_dates()
    {
        [$user, $cart] = $this->createUserWithCart();

        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        $product->prices()->create([
            'unit_amount' => 5000,
            'currency' => 'usd',
            'is_default' => true,
        ]);

        // Claim 4 units for days 5-10
        $product->claimStock(
            quantity: 4,
            from: now()->startOfDay()->addDays(5),
            until: now()->endOfDay()->addDays(10)
        );

        // Set cart dates to be during the claim period
        $cart->update([
            'from' => now()->addDays(6),
            'until' => now()->addDays(8),
        ]);
        $cart->refresh();

        // has_more should return total stock (NOT restricted by cart dates)
        // Date validation happens at checkout
        $this->assertEquals(10, $product->getHasMore($cart));
    }

    #[Test]
    public function it_returns_total_capacity_for_pool_regardless_of_cart_dates()
    {
        [$user, $cart] = $this->createUserWithCart();

        // Create pool product
        $pool = Product::factory()->create([
            'name' => 'Rental Cars',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $pool->prices()->create([
            'unit_amount' => 15000,
            'currency' => 'usd',
            'is_default' => true,
        ]);

        // Create 5 single items with stock
        $singles = [];
        for ($i = 1; $i <= 5; $i++) {
            $single = Product::factory()->create([
                'name' => "Car {$i}",
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);
            $single->increaseStock(1);

            $single->prices()->create([
                'unit_amount' => 15000,
                'currency' => 'usd',
                'is_default' => true,
            ]);

            $singles[] = $single;
        }

        foreach ($singles as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        // Claim 2 cars for days 5-10
        $singles[0]->claimStock(
            quantity: 1,
            from: now()->startOfDay()->addDays(5),
            until: now()->endOfDay()->addDays(10)
        );
        $singles[1]->claimStock(
            quantity: 1,
            from: now()->startOfDay()->addDays(5),
            until: now()->endOfDay()->addDays(10)
        );

        // Set cart dates during claim period
        $cart->update([
            'from' => now()->addDays(6),
            'until' => now()->addDays(8),
        ]);
        $cart->refresh();

        // has_more should show TOTAL capacity (5), NOT date-restricted (3)
        // This allows adding items freely; date validation happens at checkout
        $this->assertEquals(5, $pool->getHasMore($cart));

        // Add 2 cars to cart
        $cart->addToCart($pool, 1, [], now()->addDays(6), now()->addDays(8));
        $cart->addToCart($pool, 1, [], now()->addDays(6), now()->addDays(8));

        // Now should show 3 remaining (5 total - 2 in cart)
        $this->assertEquals(3, $pool->getHasMore($cart));
    }
}
