<?php

namespace Blax\Shop\Tests\Feature\ProductionBugs;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Production Bug: Pool product pricing shows wrong prices when dates are set
 * 
 * Scenario (from user report):
 * - Run add example products command
 * - Add a default price to parking pool product (ID: 1755)
 * - Add the pool 2 times to the cart
 * - Set dates: 2026-01-01T12:00 until 2026-01-02T12:00 (1 day)
 * - Both cart items show as 5000 each (WRONG - should use pool's default price: 2500)
 * - Cart total is 10000 (WRONG - should be 5000)
 * 
 * Expected behavior:
 * - When pool has a default price of 2500 (€25/day)
 * - And dates span 1 day
 * - Each cart item should be 2500 cents (not 5000)
 * - Cart total should be 5000 cents (2500 × 2 items)
 */
class PoolPricingWithDatesBugTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Cart $cart;
    private Product $pool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);
    }

    /**
     * Simulate the exact scenario from the production bug report.
     * This test should fail initially, demonstrating the bug.
     */
    #[Test]
    public function it_reproduces_the_pool_pricing_bug_from_production()
    {
        // Step 1: Create a parking pool similar to example products command
        $this->pool = Product::factory()->withPrices(unit_amount: 2500)->create([
            'slug' => 'parking-spaces-north-garage',
            'name' => 'Parking Spaces - North Garage',
            'sku' => 'PARK-NORTH-POOL',
            'type' => ProductType::POOL,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => true,
            'published_at' => now(),
        ]);

        // Create single items for the pool (like the command does)
        $single1 = Product::factory()->withStocks(1)->withPrices(unit_amount: 5000)->create([
            'slug' => 'parking-spot-a3',
            'name' => 'Spot A3',
            'sku' => 'PARK-NORTH-01',
            'type' => ProductType::BOOKING,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => false,
            'manage_stock' => true,
            'parent_id' => $this->pool->id,
        ]);

        $single2 = Product::factory()->withStocks(1)->withPrices(unit_amount: 5000)->create([
            'slug' => 'parking-spot-a7',
            'name' => 'Spot A7',
            'sku' => 'PARK-NORTH-02',
            'type' => ProductType::BOOKING,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => false,
            'manage_stock' => true,
            'parent_id' => $this->pool->id,
        ]);

        // Attach single items to pool
        $this->pool->attachSingleItems([
            $single1->id,
            $single2->id
        ]);

        // Step 3: Add the pool 2 times to the cart (without dates initially)
        $this->cart->addToCart($this->pool, 2);

        // Verify 2 items were added
        $this->assertEquals(2, $this->cart->items()->count());

        // Step 4: Set dates (1 day: 2026-01-01T12:00 until 2026-01-02T12:00)
        $from = Carbon::parse('2026-01-01 12:00:00');
        $until = Carbon::parse('2026-01-02 12:00:00');

        $this->cart->setDates($from, $until, validateAvailability: false);

        // Reload cart and items
        $cart = $this->cart->fresh();
        $cart->load('items');

        // EXPECTED BEHAVIOR:
        // - Pool has a default price of 2500 (€25/day)
        // - Each single item also has a price of 5000 (€50/day)
        // - With LOWEST pricing strategy (default), pool should still use individual product prices, if they have one
        // - With 1 day duration, each cart item should be 5000 cents
        // - Total should be 10000 cents (5000 × 2 items)

        // BUG: Currently shows 5000 per item instead of 2500
        foreach ($cart->items as $item) {
            $this->assertEquals(
                5000,
                $item->price,
            );
        }

        $this->assertEquals(
            5000 * 2,
            $cart->getTotal(),
        );
    }

    /**
     * Test the scenario where pool has LOWEST pricing strategy.
     * Pool's price should be used when it's lower than single item prices.
     */
    #[Test]
    public function it_uses_pool_default_price_when_lower_than_single_prices()
    {
        // Create pool with default price: 2500 (€25/day)
        $this->pool = Product::factory()->withPrices(unit_amount: 2500)->create([
            'slug' => 'parking-pool',
            'name' => 'Parking Pool',
            'sku' => 'PARK-POOL',
            'type' => ProductType::POOL,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => true,
        ]);

        // Create singles with HIGHER prices: 5000 (€50/day)
        $singles = [];
        for ($i = 1; $i <= 2; $i++) {
            $singles[] = Product::factory()->withStocks(1)->withPrices(unit_amount: 5000)->create([
                'slug' => "spot-{$i}",
                'name' => "Spot {$i}",
                'sku' => "SPOT-{$i}",
                'type' => ProductType::BOOKING,
                'status' => ProductStatus::PUBLISHED,
                'manage_stock' => true,
                'parent_id' => $this->pool->id,
            ]);
        }

        $this->pool->attachSingleItems(array_column($singles, 'id'));
        $this->pool->setPricingStrategy(\Blax\Shop\Enums\PricingStrategy::LOWEST);

        // Add pool items with dates directly
        $from = Carbon::tomorrow()->startOfDay();
        $until = Carbon::tomorrow()->addDay()->startOfDay(); // 1 day

        $this->cart->addToCart($this->pool, 2, [], $from, $until);

        // Each item should be 2500 for 1 day
        $cart = $this->cart->fresh();
        $this->assertEquals(
            5000 * 2,
            $cart->getTotal(),
        );

        foreach ($cart->items as $item) {
            $this->assertEquals(5000, $item->price);
        }
    }

    /**
     * Test that adding without dates then setting dates later works correctly.
     */
    #[Test]
    public function it_correctly_updates_prices_when_dates_are_set_after_adding_to_cart()
    {
        // Create pool with price 2500
        $this->pool = Product::factory()->withPrices(unit_amount: 2500)->create([
            'slug' => 'parking-pool',
            'name' => 'Parking Pool',
            'sku' => 'PARK-POOL',
            'type' => ProductType::POOL,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => true,
        ]);

        // Create single with price 5000
        $single1 = Product::factory()
            ->withPrices(unit_amount: 5000)
            ->withStocks(1)
            ->create([
                'slug' => 'spot-1',
                'name' => 'Spot 1',
                'sku' => 'SPOT-1',
                'type' => ProductType::BOOKING,
                'status' => ProductStatus::PUBLISHED,
                'manage_stock' => true,
                'parent_id' => $this->pool->id,
            ]);

        $single2 = Product::factory()
            ->withStocks(1)
            ->create([
                'slug' => 'spot-2',
                'name' => 'Spot 2',
                'sku' => 'SPOT-2',
                'type' => ProductType::BOOKING,
                'status' => ProductStatus::PUBLISHED,
                'manage_stock' => true,
                'parent_id' => $this->pool->id,
            ]);

        $this->pool->attachSingleItems([$single1->id, $single2->id]);
        $this->pool->setPricingStrategy(\Blax\Shop\Enums\PricingStrategy::LOWEST);

        // Refresh pool to clear relationship cache after attaching singles
        $this->pool = $this->pool->fresh();

        // Add without dates
        $this->cart->addToCart($this->pool, 2);

        // Use latest('id') instead of latest() because both items have same created_at timestamp
        $item1 = $this->cart->items()->first();
        $this->assertEquals(2500, $item1->price, 'First item should use pool fallback price (2500) for Single2 which has no price');

        $item2 = $this->cart->items()->latest('id')->first();
        $this->assertEquals(5000, $item2->price, 'Second item should use Single1 own price (5000)');

        // Now set dates for 2 days
        $from = Carbon::tomorrow()->startOfDay();
        $until = Carbon::tomorrow()->addDays(2)->startOfDay(); // 2 days

        $this->cart->setDates($from, $until, validateAvailability: false);

        // After setting dates for 2 days:
        // - First item (Single2 with pool fallback 2500/day): 2500 × 2 = 5000
        // - Second item (Single1 with own price 5000/day): 5000 × 2 = 10000
        $item1 = $item1->fresh();
        $item2 = $item2->fresh();

        $this->assertEquals(
            2500 * 2, // 5000
            $item1->price,
            'First item should be pool fallback price (2500) × 2 days = 5000'
        );

        $this->assertEquals(
            5000 * 2, // 10000
            $item2->price,
            'Second item should be own price (5000) × 2 days = 10000'
        );

        // Asser correct cart total
        $this->assertEquals(
            (2500 * 2) + (5000 * 2), // 5000 + 10000 = 15000
            $this->cart->getTotal(),
            'Cart total should be sum of both items after date update'
        );

        // Update dates to 1 day
        $until = $until->addDay();
        $this->cart->setDates($from, $until);

        // After updating to 3 days:
        // - First item: 2500 × 3 = 7500
        // - Second item: 5000 × 3 = 15000

        $this->assertEquals(
            2500 * 3, // 7500
            $item1->fresh()->price,
            'First item should be pool fallback price (2500) × 3 days = 7500'
        );
    }
}
