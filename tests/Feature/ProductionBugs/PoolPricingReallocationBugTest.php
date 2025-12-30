<?php

namespace Blax\Shop\Tests\Feature\ProductionBugs;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Production Bug: Pool pricing jumps to 5000 when adjusting dates
 * 
 * Scenario:
 * - Pool: Parkings (price: 2800)
 * - Singles: Vip 1, Vip 2, Vip 3 (NO price - should use pool's 2800)
 * - Singles: Executive 1, Executive 2 (price: 5000 each)
 * - All singles have 1 stock
 * 
 * Bug: getCurrentPrice on pool shows 2800 (correct), but when dates are adjusted,
 * the cart item price jumps to 5000.
 * 
 * Expected: With LOWEST pricing strategy, items should be allocated to Vip items first
 * (using pool fallback price of 2800), not Executive items (5000).
 */
class PoolPricingReallocationBugTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Product $pool;
    protected array $vipItems = [];
    protected array $executiveItems = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        auth()->login($this->user);

        $this->createParkingPool();
    }

    /**
     * Create the parking pool with Vip (no price) and Executive (5000) items
     */
    protected function createParkingPool(): void
    {
        // Create pool product with price 2800
        $this->pool = Product::factory()->create([
            'name' => 'Parkings',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Set pricing strategy to lowest
        $this->pool->setPoolPricingStrategy('lowest');

        // Pool has price of 2800
        ProductPrice::factory()->create([
            'purchasable_id' => $this->pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2800,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Create 3 Vip items WITHOUT prices (should fallback to pool price 2800)
        for ($i = 1; $i <= 3; $i++) {
            $vip = Product::factory()->create([
                'name' => "Vip $i",
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);
            $vip->increaseStock(1);
            // NO price - should use pool fallback
            $this->vipItems[] = $vip;
        }

        // Create 2 Executive items WITH prices of 5000
        for ($i = 1; $i <= 2; $i++) {
            $exec = Product::factory()->create([
                'name' => "Executive $i",
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);
            $exec->increaseStock(1);

            ProductPrice::factory()->create([
                'purchasable_id' => $exec->id,
                'purchasable_type' => Product::class,
                'unit_amount' => 5000,
                'currency' => 'USD',
                'is_default' => true,
            ]);

            $this->executiveItems[] = $exec;
        }

        // Attach all singles to pool (Vip items first, then Executive)
        $allSingles = array_merge(
            array_map(fn($p) => $p->id, $this->vipItems),
            array_map(fn($p) => $p->id, $this->executiveItems)
        );
        $this->pool->attachSingleItems($allSingles);
    }

    // =========================================================================
    // Basic price verification tests
    // =========================================================================

    #[Test]
    public function pool_get_current_price_returns_2800()
    {
        // getCurrentPrice should return 2800 (the pool's price)
        $price = $this->pool->getCurrentPrice();

        $this->assertEquals(2800, $price, 'Pool getCurrentPrice should return 2800');
    }

    #[Test]
    public function vip_items_have_no_direct_price()
    {
        foreach ($this->vipItems as $vip) {
            $price = $vip->defaultPrice()->first();
            $this->assertNull($price, "Vip item {$vip->name} should have no price");
        }
    }

    #[Test]
    public function executive_items_have_price_5000()
    {
        foreach ($this->executiveItems as $exec) {
            $priceModel = $exec->defaultPrice()->first();
            $this->assertNotNull($priceModel, "Executive item {$exec->name} should have a price");
            $this->assertEquals(
                5000,
                $priceModel->getCurrentPrice(),
                "Executive item {$exec->name} should have price 5000"
            );
        }
    }

    // =========================================================================
    // Cart pricing tests - adding to cart
    // =========================================================================

    #[Test]
    public function add_first_item_to_cart_should_use_lowest_price_2800()
    {
        $from = Carbon::now()->addDay();
        $until = Carbon::now()->addDays(2);

        $cart = $this->user->currentCart();
        $cart->addToCart($this->pool, 1, [], $from, $until);
        $cart->refresh();

        $item = $cart->items->first();

        // Should be allocated to a Vip item (using pool fallback price 2800)
        $this->assertNotNull($item, 'Cart should have an item');
        $this->assertEquals(
            2800,
            $item->price,
            'First item should use lowest price (2800 from pool fallback)'
        );

        // Verify allocated to a Vip item (now stored in product_id column)
        $allocatedSingleId = $item->product_id;

        $vipIds = array_map(fn($p) => $p->id, $this->vipItems);
        $this->assertContains(
            $allocatedSingleId,
            $vipIds,
            'Item should be allocated to a Vip single (lowest price)'
        );
    }

    #[Test]
    public function add_multiple_items_should_fill_vip_before_executive()
    {
        $from = Carbon::now()->addDay();
        $until = Carbon::now()->addDays(2);

        // Add 3 items - should fill all 3 Vip spots at 2800 each
        $cart = $this->user->currentCart();
        $cart->addToCart($this->pool, 3, [], $from, $until);
        $cart->refresh();

        $totalPrice = $cart->items->sum('price');

        // 3 x 2800 = 8400
        $this->assertEquals(
            8400,
            $totalPrice,
            'Three items should cost 8400 (3 x 2800 from Vip items)'
        );
    }

    #[Test]
    public function adding_4th_item_should_use_executive_at_5000()
    {
        $from = Carbon::now()->addDay();
        $until = Carbon::now()->addDays(2);

        // Add 4 items - 3 Vip at 2800 + 1 Executive at 5000
        $cart = $this->user->currentCart();
        $cart->addToCart($this->pool, 4, [], $from, $until);
        $cart->refresh();

        $totalPrice = $cart->items->sum('price');

        // 3 x 2800 + 1 x 5000 = 8400 + 5000 = 13400
        $this->assertEquals(
            13400,
            $totalPrice,
            'Four items should cost 13400 (3 x 2800 + 1 x 5000)'
        );
    }

    // =========================================================================
    // BUG REPRODUCTION: Date adjustment causes price jump
    // =========================================================================

    #[Test]
    public function adjusting_dates_should_maintain_2800_price_for_vip_allocation()
    {
        $from = Carbon::now()->addDay();
        $until = Carbon::now()->addDays(2);

        // Add 1 item - should be allocated to Vip at 2800
        $cart = $this->user->currentCart();
        $cart->addToCart($this->pool, 1, [], $from, $until);
        $cart->refresh();

        $item = $cart->items->first();
        $this->assertEquals(2800, $item->price, 'Initial price should be 2800');

        // Now adjust dates
        $newFrom = Carbon::now()->addDays(3);
        $newUntil = Carbon::now()->addDays(4);

        $cart->setFromDate($newFrom);
        $cart->setUntilDate($newUntil);
        $cart->refresh();

        $item = $cart->items->first();

        // BUG: Price should still be 2800, NOT 5000
        $this->assertEquals(
            2800,
            $item->price,
            'After adjusting dates, price should still be 2800 (not jump to 5000)'
        );
    }

    #[Test]
    public function adjusting_until_date_should_maintain_lowest_price()
    {
        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(2)->startOfDay();

        // First set cart dates, then add item
        $cart = $this->user->currentCart();
        $cart->setFromDate($from);
        $cart->setUntilDate($until);
        $cart->addToCart($this->pool, 1, [], $from, $until);
        $cart->refresh();

        $initialPrice = $cart->items->first()->price;
        $this->assertEquals(2800, $initialPrice, 'Initial price for 1 day should be 2800');

        // Now extend the until date to add more days
        $newUntil = Carbon::now()->addDays(4)->startOfDay();
        $cart->setUntilDate($newUntil);
        $cart->refresh();

        $item = $cart->items->first();
        $days = 3; // from addDay() (day 1) to addDays(4) (day 4) = 3 days
        $expectedPrice = 2800 * $days;

        // Price should scale with days but base should still be 2800
        $this->assertEquals(
            $expectedPrice,
            $item->price,
            "After extending until date, price should be {$expectedPrice} (2800 x {$days} days)"
        );
    }

    #[Test]
    public function updating_cart_item_dates_directly_should_maintain_lowest_price()
    {
        $from = Carbon::now()->addDay();
        $until = Carbon::now()->addDays(2);

        $cart = $this->user->currentCart();
        $cart->addToCart($this->pool, 1, [], $from, $until);
        $cart->refresh();

        $item = $cart->items->first();
        $this->assertEquals(2800, $item->price, 'Initial price should be 2800');

        // Update dates directly on the cart item
        $newFrom = Carbon::now()->addDays(5);
        $newUntil = Carbon::now()->addDays(6);
        $item->updateDates($newFrom, $newUntil);
        $item->refresh();

        // BUG: Price should still be 2800, NOT 5000
        $this->assertEquals(
            2800,
            $item->price,
            'After updating item dates directly, price should still be 2800'
        );
    }

    #[Test]
    public function price_should_stay_2800_when_reallocating_with_dates_where_vip_is_available()
    {
        $from = Carbon::now()->addDay();
        $until = Carbon::now()->addDays(2);

        // Add 1 item
        $cart = $this->user->currentCart();
        $cart->addToCart($this->pool, 1, [], $from, $until);
        $cart->refresh();

        $item = $cart->items->first();
        $originalAllocation = $item->product_id;

        // Record original price
        $originalPrice = $item->price;
        $this->assertEquals(2800, $originalPrice);

        // Change to different dates where same Vip should still be available
        $newFrom = Carbon::now()->addDays(10);
        $newUntil = Carbon::now()->addDays(11);

        $cart->setFromDate($newFrom);
        $cart->setUntilDate($newUntil);
        $cart->refresh();

        $item = $cart->items->first();

        // Price should remain 2800 (still allocated to a Vip)
        $this->assertEquals(
            2800,
            $item->price,
            'Price should remain 2800 after date change when Vip items are available'
        );

        // Should still be allocated to a Vip item
        $allocatedSingleId = $item->product_id;
        $vipIds = array_map(fn($p) => $p->id, $this->vipItems);

        $this->assertContains(
            $allocatedSingleId,
            $vipIds,
            'Should remain allocated to a Vip item after date change'
        );
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    #[Test]
    public function when_all_vip_claimed_new_item_gets_executive_at_5000()
    {
        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(2)->startOfDay();

        // Fill all 3 Vip spots by claiming stock
        foreach ($this->vipItems as $vip) {
            $vip->claimStock(1, null, $from, $until, 'Test claim');
        }

        // Now add 1 item - should get Executive at 5000 (since all Vips are claimed)
        $cart = $this->user->currentCart();
        $cart->addToCart($this->pool, 1, [], $from, $until);
        $cart->refresh();

        $item = $cart->items->first();
        $this->assertEquals(
            5000,
            $item->price,
            'Item should be allocated to Executive at 5000 when all Vips are claimed'
        );

        // Verify allocated to an Executive item
        $allocatedSingleId = $item->product_id;

        $execIds = array_map(fn($p) => $p->id, $this->executiveItems);
        $this->assertContains(
            $allocatedSingleId,
            $execIds,
            'Item should be allocated to an Executive single'
        );
    }

    #[Test]
    public function reallocation_after_date_change_respects_pricing_strategy()
    {
        // Use different dates to avoid conflicts
        $from1 = Carbon::now()->addDays(1);
        $until1 = Carbon::now()->addDays(2);
        $from2 = Carbon::now()->addDays(10);
        $until2 = Carbon::now()->addDays(11);

        // Add 2 items at dates1
        $cart = $this->user->currentCart();
        $cart->addToCart($this->pool, 2, [], $from1, $until1);
        $cart->refresh();

        $prices = $cart->items->pluck('price')->toArray();
        $this->assertEquals(
            [2800, 2800],
            $prices,
            'Both items should be 2800 (allocated to Vip items)'
        );

        // Change dates to dates2 where all singles should be available
        $cart->setFromDate($from2);
        $cart->setUntilDate($until2);
        $cart->refresh();

        $prices = $cart->items->pluck('price')->toArray();

        // Should still be allocated to lowest-priced items (Vip at 2800)
        $this->assertEquals(
            [2800, 2800],
            $prices,
            'After date change, both items should still be 2800'
        );
    }

    #[Test]
    public function multiple_date_adjustments_maintain_correct_pricing()
    {
        $from = Carbon::now()->addDay();
        $until = Carbon::now()->addDays(2);

        $cart = $this->user->currentCart();
        $cart->addToCart($this->pool, 1, [], $from, $until);
        $cart->refresh();

        $this->assertEquals(2800, $cart->items->first()->price);

        // Adjust dates multiple times
        for ($i = 0; $i < 5; $i++) {
            $newFrom = Carbon::now()->addDays(10 + $i * 5);
            $newUntil = Carbon::now()->addDays(11 + $i * 5);

            $cart->setFromDate($newFrom);
            $cart->setUntilDate($newUntil);
            $cart->refresh();

            $this->assertEquals(
                2800,
                $cart->items->first()->price,
                "After adjustment #{$i}, price should still be 2800"
            );
        }
    }

    // =========================================================================
    // Additional edge case tests for production bug investigation
    // =========================================================================

    #[Test]
    public function adding_item_without_dates_then_setting_dates_uses_lowest_price()
    {
        // Add item WITHOUT dates first
        $cart = $this->user->currentCart();
        $cart->addToCart($this->pool, 1);
        $cart->refresh();

        $item = $cart->items->first();

        // Item should exist but may not have a full price yet (no dates)
        $this->assertNotNull($item);

        // Now set dates on the cart
        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(2)->startOfDay();

        $cart->setFromDate($from);
        $cart->setUntilDate($until);
        $cart->refresh();

        $item = $cart->items->first();

        // Should be allocated to Vip (lowest price 2800)
        $this->assertEquals(
            2800,
            $item->price,
            'After setting dates, price should be 2800 (lowest via Vip)'
        );

        // Verify allocated to a Vip item
        $allocatedSingleId = $item->product_id;
        $vipIds = array_map(fn($p) => $p->id, $this->vipItems);

        $this->assertContains(
            $allocatedSingleId,
            $vipIds,
            'Item should be allocated to a Vip single (lowest price)'
        );
    }

    #[Test]
    public function setting_dates_on_cart_with_pool_item_allocates_to_lowest()
    {
        $cart = $this->user->currentCart();

        // Set cart dates first
        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(2)->startOfDay();
        $cart->update(['from' => $from, 'until' => $until]);

        // Then add item without explicitly passing dates
        $cart->addToCart($this->pool, 1);
        $cart->refresh();

        $item = $cart->items->first();

        // Should be allocated to Vip (lowest price 2800)
        $this->assertEquals(
            2800,
            $item->price,
            'Item should use lowest price 2800 from Vip'
        );
    }

    #[Test]
    public function debugging_reallocation_order()
    {
        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(2)->startOfDay();

        $cart = $this->user->currentCart();
        $cart->addToCart($this->pool, 1, [], $from, $until);
        $cart->refresh();

        $item = $cart->items->first();
        $initialAllocation = $item->product_id;
        $initialPrice = $item->price;

        // Verify initial state
        $this->assertEquals(2800, $initialPrice, 'Initial price should be 2800');
        $vipIds = array_map(fn($p) => $p->id, $this->vipItems);
        $this->assertContains($initialAllocation, $vipIds, 'Should be allocated to Vip');

        // Change dates multiple times and track what happens
        for ($i = 1; $i <= 3; $i++) {
            $newFrom = Carbon::now()->addDays($i * 10)->startOfDay();
            $newUntil = Carbon::now()->addDays($i * 10 + 1)->startOfDay();

            $cart->setFromDate($newFrom);
            $cart->setUntilDate($newUntil);
            $cart->refresh();

            $item = $cart->items->first();
            $newAllocation = $item->product_id;
            $newPrice = $item->price;

            // Verify still allocated to Vip and still 2800
            $this->assertContains(
                $newAllocation,
                $vipIds,
                "After iteration {$i}, should still be allocated to Vip"
            );
            $this->assertEquals(
                2800,
                $newPrice,
                "After iteration {$i}, price should still be 2800"
            );
        }
    }

    #[Test]
    public function cross_sell_pool_pricing_uses_lowest()
    {
        // Create a hotel room product
        $hotelRoom = Product::factory()->create([
            'name' => 'Hotel Room',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $hotelRoom->increaseStock(5);
        ProductPrice::factory()->create([
            'purchasable_id' => $hotelRoom->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 10000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Attach pool as cross-sell to hotel room
        $hotelRoom->productRelations()->attach($this->pool->id, [
            'type' => \Blax\Shop\Enums\ProductRelationType::CROSS_SELL->value,
        ]);

        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(2)->startOfDay();

        // Add hotel room first
        $cart = $this->user->currentCart();
        $cart->addToCart($hotelRoom, 1, [], $from, $until);

        // Add the cross-sell pool (parking)
        $cart->addToCart($this->pool, 1, [], $from, $until);
        $cart->refresh();

        // Find the parking item
        $parkingItem = $cart->items->first(fn($item) => $item->purchasable_id === $this->pool->id);

        $this->assertNotNull($parkingItem, 'Parking item should exist');
        $this->assertEquals(
            2800,
            $parkingItem->price,
            'Parking cross-sell should use lowest price 2800'
        );

        // Adjust dates
        $newFrom = Carbon::now()->addDays(5)->startOfDay();
        $newUntil = Carbon::now()->addDays(6)->startOfDay();

        $cart->setFromDate($newFrom);
        $cart->setUntilDate($newUntil);
        $cart->refresh();

        $parkingItem = $cart->items->first(fn($item) => $item->purchasable_id === $this->pool->id);

        $this->assertEquals(
            2800,
            $parkingItem->price,
            'After date adjustment, parking should still be 2800'
        );
    }

    #[Test]
    public function when_allocated_vip_becomes_unavailable_reallocates_to_next_cheapest()
    {
        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(2)->startOfDay();

        // Add 1 item - should get allocated to Vip 1 at 2800
        $cart = $this->user->currentCart();
        $cart->addToCart($this->pool, 1, [], $from, $until);
        $cart->refresh();

        $item = $cart->items->first();
        $allocatedVipId = $item->product_id;

        $this->assertEquals(2800, $item->price);

        // Now claim that specific Vip for different dates (simulating another booking)
        $newFrom = Carbon::now()->addDays(5)->startOfDay();
        $newUntil = Carbon::now()->addDays(6)->startOfDay();

        // Claim the allocated Vip for the new date range
        $allocatedVip = Product::find($allocatedVipId);
        $allocatedVip->claimStock(1, null, $newFrom, $newUntil, 'Other booking');

        // Now change cart dates to the new range where that Vip is claimed
        $cart->setFromDate($newFrom);
        $cart->setUntilDate($newUntil);
        $cart->refresh();

        $item = $cart->items->first();

        // Should be reallocated to another Vip (there are 3 Vips)
        // Price should still be 2800 (another Vip is available)
        $this->assertEquals(
            2800,
            $item->price,
            'When original Vip is claimed, should reallocate to another Vip at 2800'
        );

        $newAllocatedId = $item->product_id;

        // Should be a different Vip
        $this->assertNotEquals(
            $allocatedVipId,
            $newAllocatedId,
            'Should be reallocated to a different single item'
        );

        $vipIds = array_map(fn($p) => $p->id, $this->vipItems);
        $this->assertContains(
            $newAllocatedId,
            $vipIds,
            'Should still be allocated to a Vip item'
        );
    }

    #[Test]
    public function when_all_vips_unavailable_reallocates_to_executive()
    {
        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(2)->startOfDay();

        // Add 1 item - should get allocated to Vip 1 at 2800
        $cart = $this->user->currentCart();
        $cart->addToCart($this->pool, 1, [], $from, $until);
        $cart->refresh();

        $item = $cart->items->first();
        $this->assertEquals(2800, $item->price);

        // Now claim ALL Vips for different dates
        $newFrom = Carbon::now()->addDays(5)->startOfDay();
        $newUntil = Carbon::now()->addDays(6)->startOfDay();

        foreach ($this->vipItems as $vip) {
            $vip->claimStock(1, null, $newFrom, $newUntil, 'Other booking');
        }

        // Change cart dates to the new range where all Vips are claimed
        $cart->setFromDate($newFrom);
        $cart->setUntilDate($newUntil);
        $cart->refresh();

        $item = $cart->items->first();

        // Should be reallocated to Executive at 5000 (only option left)
        $this->assertEquals(
            5000,
            $item->price,
            'When all Vips are claimed, should reallocate to Executive at 5000'
        );

        $allocatedId = $item->product_id;

        $execIds = array_map(fn($p) => $p->id, $this->executiveItems);
        $this->assertContains(
            $allocatedId,
            $execIds,
            'Should be allocated to an Executive item'
        );
    }
}
