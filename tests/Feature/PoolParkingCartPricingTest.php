<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PricingStrategy;
use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Workbench\App\Models\User;

/**
 * Test pool product cart pricing with comprehensive scenarios.
 * 
 * This test covers four configurations:
 * - A: Pool HAS default price (500), Pool does NOT manage stock
 * - B: Pool does NOT have default price, Pool does NOT manage stock
 * - C: Pool HAS default price (500), Pool MANAGES stock
 * - D: Pool does NOT have default price, Pool MANAGES stock
 * 
 * In all cases:
 * - Pool "Parkings" with 3 single items ("Parking Spot 1-3")
 * - Single item 1 has default price of 300
 * - Single item 2 does NOT have a default price (should fallback to pool price in A/C, or throw exception in B/D)
 * - Single item 3 has default price of 1000
 * - Each single item has 2 stocks
 * 
 * Expected cart totals with LOWEST pricing strategy:
 * - Add 1: 300 (from Spot 1)
 * - Add 2: 600 (300 + 300, both from Spot 1)
 * - Add 3: 1100 (300 + 300 + 500, third from pool or Spot 2 fallback)
 * - Add 4: 1600 (300 + 300 + 500 + 500, fourth from pool or Spot 2 fallback)
 * - Add 5: 2600 (300 + 300 + 500 + 500 + 1000, fifth from Spot 3)
 * - Add 6: 3600 (300 + 300 + 500 + 500 + 1000 + 1000, sixth from Spot 3)
 * - Add 7: NotEnoughStockException (only 6 total)
 * 
 * When dates span 2 days, all totals should double.
 */
class PoolParkingCartPricingTest extends TestCase
{
    protected User $user;
    protected Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        auth()->login($this->user);
    }

    /**
     * Create the pool product with specified configuration
     * 
     * @param bool $hasPoolPrice Whether pool has its own price
     * @param bool $poolManagesStock Whether pool manages stock
     * @return array{pool: Product, spots: array<Product>}
     */
    protected function createParkingPool(bool $hasPoolPrice, bool $poolManagesStock): array
    {
        // Create pool product
        $pool = Product::factory()->create([
            'name' => 'Parkings',
            'type' => ProductType::POOL,
            'manage_stock' => $poolManagesStock,
        ]);

        // Set pricing strategy to lowest
        $pool->setPoolPricingStrategy('lowest');

        // Pool price (500) - only if hasPoolPrice
        if ($hasPoolPrice) {
            ProductPrice::factory()->create([
                'purchasable_id' => $pool->id,
                'purchasable_type' => Product::class,
                'unit_amount' => 500,
                'currency' => 'USD',
                'is_default' => true,
            ]);
        }

        // Create single items
        $spot1 = Product::factory()->create([
            'name' => 'Parking Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(2);

        // Spot 1 has default price of 300
        ProductPrice::factory()->create([
            'purchasable_id' => $spot1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 300,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $spot2 = Product::factory()->create([
            'name' => 'Parking Spot 2',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot2->increaseStock(2);
        // Spot 2 does NOT have a default price - should fallback to pool price

        $spot3 = Product::factory()->create([
            'name' => 'Parking Spot 3',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot3->increaseStock(2);

        // Spot 3 has default price of 1000
        ProductPrice::factory()->create([
            'purchasable_id' => $spot3->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Attach single items to pool
        $pool->attachSingleItems([$spot1->id, $spot2->id, $spot3->id]);

        return [
            'pool' => $pool,
            'spots' => [$spot1, $spot2, $spot3],
        ];
    }

    /**
     * Create a fresh cart for testing
     */
    protected function createCart(): Cart
    {
        return Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);
    }

    // ==========================================
    // Configuration A: Pool HAS price, does NOT manage stock
    // ==========================================

    /** @test */
    public function config_a_progressive_pricing_step_by_step()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool, 'spots' => $spots] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: false);

        // Add 1: Should use lowest price (300 from Spot 1)
        $cartItem = $this->cart->addToCart($pool, 1);
        $this->assertEquals(300, $this->cart->getTotal());
        $this->assertEquals(300, $cartItem->price);

        // Add 2: Still lowest price (300), cumulative 600
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(600, $this->cart->fresh()->getTotal());

        // Add 3: Next lowest is pool price (500), cumulative 1100
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(1100, $this->cart->fresh()->getTotal());

        // Add 4: Pool price again (500), cumulative 1600
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(1600, $this->cart->fresh()->getTotal());

        // Add 5: Spot 3 price (1000), cumulative 2600
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(2600, $this->cart->fresh()->getTotal());

        // Add 6: Spot 3 price again (1000), cumulative 3600
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(3600, $this->cart->fresh()->getTotal());

        // Add 7: Should throw exception - no more stock
        $this->expectException(NotEnoughStockException::class);
        $this->cart->addToCart($pool, 1);
    }

    /** @test */
    public function config_a_cart_items_have_correct_price_ids()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool, 'spots' => $spots] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: false);

        // Get price IDs for reference
        $spot1PriceId = $spots[0]->defaultPrice()->first()->id;
        $poolPriceId = $pool->defaultPrice()->first()->id;
        $spot3PriceId = $spots[2]->defaultPrice()->first()->id;

        // Add 6 items
        $this->cart->addToCart($pool, 6);

        $items = $this->cart->items()->orderBy('price', 'asc')->get();

        // First cart item group (price 300) should have Spot 1's price_id
        $item300 = $items->first(fn($i) => $i->price === 300);
        $this->assertNotNull($item300);
        $this->assertEquals($spot1PriceId, $item300->price_id);

        // Second cart item group (price 500) should have Pool's price_id (for Spot 2 fallback)
        $item500 = $items->first(fn($i) => $i->price === 500);
        $this->assertNotNull($item500);
        $this->assertEquals($poolPriceId, $item500->price_id);

        // Third cart item group (price 1000) should have Spot 3's price_id
        $item1000 = $items->first(fn($i) => $i->price === 1000);
        $this->assertNotNull($item1000);
        $this->assertEquals($spot3PriceId, $item1000->price_id);
    }

    /** @test */
    public function config_a_set_dates_doubles_cart_total()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: false);

        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay(); // 2 days

        // Add items with dates
        $this->cart->addToCart($pool, 6, [], $from, $until);

        // With 2 days: 300*2 + 300*2 + 500*2 + 500*2 + 1000*2 + 1000*2 = 7200
        $this->assertEquals(7200, $this->cart->fresh()->getTotal());
    }

    /** @test */
    public function config_a_set_dates_after_adding_recalculates_prices()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: false);

        // Add items without dates first
        $this->cart->addToCart($pool, 6);

        // 1-day prices: 300 + 300 + 500 + 500 + 1000 + 1000 = 3600
        $this->assertEquals(3600, $this->cart->fresh()->getTotal());

        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay(); // 2 days

        // Set dates - should recalculate to 2-day prices
        $this->cart->setDates($from, $until, validateAvailability: false);

        // 2-day prices: (300 + 300 + 500 + 500 + 1000 + 1000) * 2 = 7200
        $this->assertEquals(7200, $this->cart->fresh()->getTotal());
    }

    /** @test */
    public function config_a_set_from_date_and_until_date_separately()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: false);

        // Add items without dates first
        $this->cart->addToCart($pool, 6);

        $this->assertEquals(3600, $this->cart->fresh()->getTotal());

        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay(); // 2 days

        // Set from date first
        $this->cart->setFromDate($from, validateAvailability: false);

        // Then set until date - this should trigger recalculation
        $this->cart->setUntilDate($until, validateAvailability: false);

        // Apply dates to items
        $this->cart->applyDatesToItems(validateAvailability: false, overwrite: true);

        // Should be 2-day prices
        $this->assertEquals(7200, $this->cart->fresh()->getTotal());
    }

    /** @test */
    public function config_a_set_dates_overwrites_cart_item_dates()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: false);

        $from1 = Carbon::now()->addDay()->startOfDay();
        $until1 = Carbon::now()->addDays(2)->startOfDay(); // 1 day

        // Add items with 1-day dates
        $this->cart->addToCart($pool, 2, [], $from1, $until1);
        $this->assertEquals(600, $this->cart->fresh()->getTotal()); // 300 * 1 * 2

        $from2 = Carbon::now()->addDay()->startOfDay();
        $until2 = Carbon::now()->addDays(4)->startOfDay(); // 3 days

        // Set new cart dates - should overwrite item dates
        $this->cart->setDates($from2, $until2, validateAvailability: false, overwrite_item_dates: true);

        // Should be 3-day prices: 300*3 + 300*3 = 1800
        $this->assertEquals(1800, $this->cart->fresh()->getTotal());

        // Verify item dates were overwritten
        foreach ($this->cart->items as $item) {
            $this->assertEquals($from2->format('Y-m-d'), $item->from->format('Y-m-d'));
            $this->assertEquals($until2->format('Y-m-d'), $item->until->format('Y-m-d'));
        }
    }

    /** @test */
    public function config_a_validates_availability_when_setting_dates()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool, 'spots' => $spots] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: false);

        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(2)->startOfDay();

        // Add items WITH dates first (so they become booking items)
        $from2 = Carbon::now()->addDays(5)->startOfDay();
        $until2 = Carbon::now()->addDays(6)->startOfDay();
        $this->cart->addToCart($pool, 1, [], $from2, $until2);

        // Claim all stock for the NEW period we want to set
        $spots[0]->claimStock(2, null, $from, $until);
        $spots[1]->claimStock(2, null, $from, $until);
        $spots[2]->claimStock(2, null, $from, $until);

        // Try to set dates for period when no stock is available
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughAvailableInTimespanException::class);
        $this->cart->setDates($from, $until, validateAvailability: true);
    }

    // ==========================================
    // Configuration B: Pool does NOT have price, does NOT manage stock
    // ==========================================

    /** @test */
    public function config_b_progressive_pricing_step_by_step()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool, 'spots' => $spots] = $this->createParkingPool(hasPoolPrice: false, poolManagesStock: false);

        // Add 1: Should use lowest price (300 from Spot 1)
        $cartItem = $this->cart->addToCart($pool, 1);
        $this->assertEquals(300, $this->cart->getTotal());
        $this->assertEquals(300, $cartItem->price);

        // Add 2: Still lowest price (300), cumulative 600
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(600, $this->cart->fresh()->getTotal());

        // Add 3: Spot 2 has no price and pool has no price, so next is Spot 3 (1000)
        // Wait - Spot 2 should be skipped since it has no price and no pool fallback
        // Expected: 300 + 300 + 1000 = 1600
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(1600, $this->cart->fresh()->getTotal());

        // Add 4: Still Spot 3 (1000), cumulative 2600
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(2600, $this->cart->fresh()->getTotal());

        // Add 5: No more available (Spot 1 has 2, Spot 3 has 2, Spot 2 has 0 available due to no price)
        // Total available: 4, should throw exception on 5th
        // Note: Throws HasNoPriceException because all PRICED items are exhausted
        // (Spot 2 has stock but no price, so it's not available for sale)
        $this->expectException(\Blax\Shop\Exceptions\HasNoPriceException::class);
        $this->cart->addToCart($pool, 1);
    }

    /** @test */
    public function config_b_cart_items_have_correct_price_ids()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool, 'spots' => $spots] = $this->createParkingPool(hasPoolPrice: false, poolManagesStock: false);

        // Get price IDs for reference
        $spot1PriceId = $spots[0]->defaultPrice()->first()->id;
        $spot3PriceId = $spots[2]->defaultPrice()->first()->id;

        // Add 4 items (max available when Spot 2 has no price)
        $this->cart->addToCart($pool, 4);

        $items = $this->cart->items()->orderBy('price', 'asc')->get();

        // Items with price 300 should have Spot 1's price_id
        $item300 = $items->first(fn($i) => $i->price === 300);
        $this->assertNotNull($item300);
        $this->assertEquals($spot1PriceId, $item300->price_id);

        // Items with price 1000 should have Spot 3's price_id
        $item1000 = $items->first(fn($i) => $i->price === 1000);
        $this->assertNotNull($item1000);
        $this->assertEquals($spot3PriceId, $item1000->price_id);
    }

    /** @test */
    public function config_b_set_dates_doubles_cart_total()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool] = $this->createParkingPool(hasPoolPrice: false, poolManagesStock: false);

        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay(); // 2 days

        // Add 4 items (max available)
        $this->cart->addToCart($pool, 4, [], $from, $until);

        // With 2 days: (300*2 + 300*2 + 1000*2 + 1000*2) = 5200
        $this->assertEquals(5200, $this->cart->fresh()->getTotal());
    }

    /** @test */
    public function config_b_set_dates_after_adding_recalculates_prices()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool] = $this->createParkingPool(hasPoolPrice: false, poolManagesStock: false);

        // Add items without dates first
        $this->cart->addToCart($pool, 4);

        // 1-day prices: 300 + 300 + 1000 + 1000 = 2600
        $this->assertEquals(2600, $this->cart->fresh()->getTotal());

        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay(); // 2 days

        // Set dates - should recalculate to 2-day prices
        $this->cart->setDates($from, $until, validateAvailability: false);

        // 2-day prices: 2600 * 2 = 5200
        $this->assertEquals(5200, $this->cart->fresh()->getTotal());
    }

    // ==========================================
    // Configuration C: Pool HAS price, MANAGES stock
    // ==========================================

    /** @test */
    public function config_c_progressive_pricing_step_by_step()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool, 'spots' => $spots] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: true);

        // Add 1: Should use lowest price (300 from Spot 1)
        $cartItem = $this->cart->addToCart($pool, 1);
        $this->assertEquals(300, $this->cart->getTotal());
        $this->assertEquals(300, $cartItem->price);

        // Add 2: Still lowest price (300), cumulative 600
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(600, $this->cart->fresh()->getTotal());

        // Add 3: Next lowest is pool price (500) for Spot 2, cumulative 1100
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(1100, $this->cart->fresh()->getTotal());

        // Add 4: Pool price again (500), cumulative 1600
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(1600, $this->cart->fresh()->getTotal());

        // Add 5: Spot 3 price (1000), cumulative 2600
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(2600, $this->cart->fresh()->getTotal());

        // Add 6: Spot 3 price again (1000), cumulative 3600
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(3600, $this->cart->fresh()->getTotal());

        // Add 7: Should throw exception - no more stock
        $this->expectException(NotEnoughStockException::class);
        $this->cart->addToCart($pool, 1);
    }

    /** @test */
    public function config_c_cart_items_have_correct_price_ids()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool, 'spots' => $spots] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: true);

        // Get price IDs for reference
        $spot1PriceId = $spots[0]->defaultPrice()->first()->id;
        $poolPriceId = $pool->defaultPrice()->first()->id;
        $spot3PriceId = $spots[2]->defaultPrice()->first()->id;

        // Add 6 items
        $this->cart->addToCart($pool, 6);

        $items = $this->cart->items()->orderBy('price', 'asc')->get();

        // First cart item group (price 300) should have Spot 1's price_id
        $item300 = $items->first(fn($i) => $i->price === 300);
        $this->assertNotNull($item300);
        $this->assertEquals($spot1PriceId, $item300->price_id);

        // Second cart item group (price 500) should have Pool's price_id (for Spot 2 fallback)
        $item500 = $items->first(fn($i) => $i->price === 500);
        $this->assertNotNull($item500);
        $this->assertEquals($poolPriceId, $item500->price_id);

        // Third cart item group (price 1000) should have Spot 3's price_id
        $item1000 = $items->first(fn($i) => $i->price === 1000);
        $this->assertNotNull($item1000);
        $this->assertEquals($spot3PriceId, $item1000->price_id);
    }

    /** @test */
    public function config_c_set_dates_doubles_cart_total()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: true);

        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay(); // 2 days

        // Add items with dates
        $this->cart->addToCart($pool, 6, [], $from, $until);

        // With 2 days: 300*2 + 300*2 + 500*2 + 500*2 + 1000*2 + 1000*2 = 7200
        $this->assertEquals(7200, $this->cart->fresh()->getTotal());
    }

    /** @test */
    public function config_c_set_dates_after_adding_recalculates_prices()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: true);

        // Add items without dates first
        $this->cart->addToCart($pool, 6);

        // 1-day prices: 300 + 300 + 500 + 500 + 1000 + 1000 = 3600
        $this->assertEquals(3600, $this->cart->fresh()->getTotal());

        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay(); // 2 days

        // Set dates - should recalculate to 2-day prices
        $this->cart->setDates($from, $until, validateAvailability: false);

        // 2-day prices: 7200
        $this->assertEquals(7200, $this->cart->fresh()->getTotal());
    }

    /** @test */
    public function config_c_pool_stock_is_ignored_when_single_items_manage_stock()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: true);

        // Pool manages stock but has no stock of its own
        // Availability should still come from single items
        $this->assertEquals(6, $pool->getAvailableQuantity());
    }

    // ==========================================
    // Configuration D: Pool does NOT have price, MANAGES stock
    // ==========================================

    /** @test */
    public function config_d_progressive_pricing_step_by_step()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool, 'spots' => $spots] = $this->createParkingPool(hasPoolPrice: false, poolManagesStock: true);

        // Add 1: Should use lowest price (300 from Spot 1)
        $cartItem = $this->cart->addToCart($pool, 1);
        $this->assertEquals(300, $this->cart->getTotal());
        $this->assertEquals(300, $cartItem->price);

        // Add 2: Still lowest price (300), cumulative 600
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(600, $this->cart->fresh()->getTotal());

        // Add 3: Spot 2 has no price and pool has no price, so next is Spot 3 (1000)
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(1600, $this->cart->fresh()->getTotal());

        // Add 4: Still Spot 3 (1000), cumulative 2600
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(2600, $this->cart->fresh()->getTotal());

        // Add 5: Should throw exception - only 4 available (Spot 1:2 + Spot 3:2)
        // Note: Throws HasNoPriceException because all PRICED items are exhausted
        // (Spot 2 has stock but no price, so it's not available for sale)
        $this->expectException(\Blax\Shop\Exceptions\HasNoPriceException::class);
        $this->cart->addToCart($pool, 1);
    }

    /** @test */
    public function config_d_cart_items_have_correct_price_ids()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool, 'spots' => $spots] = $this->createParkingPool(hasPoolPrice: false, poolManagesStock: true);

        // Get price IDs for reference
        $spot1PriceId = $spots[0]->defaultPrice()->first()->id;
        $spot3PriceId = $spots[2]->defaultPrice()->first()->id;

        // Add 4 items (max available when Spot 2 has no price)
        $this->cart->addToCart($pool, 4);

        $items = $this->cart->items()->orderBy('price', 'asc')->get();

        // Items with price 300 should have Spot 1's price_id
        $item300 = $items->first(fn($i) => $i->price === 300);
        $this->assertNotNull($item300);
        $this->assertEquals($spot1PriceId, $item300->price_id);

        // Items with price 1000 should have Spot 3's price_id
        $item1000 = $items->first(fn($i) => $i->price === 1000);
        $this->assertNotNull($item1000);
        $this->assertEquals($spot3PriceId, $item1000->price_id);
    }

    /** @test */
    public function config_d_set_dates_doubles_cart_total()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool] = $this->createParkingPool(hasPoolPrice: false, poolManagesStock: true);

        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay(); // 2 days

        // Add 4 items (max available)
        $this->cart->addToCart($pool, 4, [], $from, $until);

        // With 2 days: (300*2 + 300*2 + 1000*2 + 1000*2) = 5200
        $this->assertEquals(5200, $this->cart->fresh()->getTotal());
    }

    /** @test */
    public function config_d_set_dates_after_adding_recalculates_prices()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool] = $this->createParkingPool(hasPoolPrice: false, poolManagesStock: true);

        // Add items without dates first
        $this->cart->addToCart($pool, 4);

        // 1-day prices: 300 + 300 + 1000 + 1000 = 2600
        $this->assertEquals(2600, $this->cart->fresh()->getTotal());

        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay(); // 2 days

        // Set dates - should recalculate to 2-day prices
        $this->cart->setDates($from, $until, validateAvailability: false);

        // 2-day prices: 2600 * 2 = 5200
        $this->assertEquals(5200, $this->cart->fresh()->getTotal());
    }

    // ==========================================
    // Additional tests for date management
    // ==========================================

    /** @test */
    public function set_dates_validates_availability_for_each_cart_item()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool, 'spots' => $spots] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: false);

        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(2)->startOfDay();

        // Add items WITH dates (so they become booking items that get validated)
        $from2 = Carbon::now()->addDays(5)->startOfDay();
        $until2 = Carbon::now()->addDays(6)->startOfDay();
        $this->cart->addToCart($pool, 5, [], $from2, $until2);

        // Claim ALL stock for the NEW period we're about to set
        // This leaves 0 available for the new period
        $spots[0]->claimStock(2, null, $from, $until);
        $spots[1]->claimStock(2, null, $from, $until);
        $spots[2]->claimStock(2, null, $from, $until);

        // Setting dates should validate and throw exception
        // because ALL spots are claimed for this period and we need 5
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughAvailableInTimespanException::class);
        $this->cart->setDates($from, $until, validateAvailability: true);
    }

    /** @test */
    public function cart_item_subtotal_updates_when_dates_change()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: false);

        // Add 2 items without dates (same price tier, should merge)
        $this->cart->addToCart($pool, 2);

        $item = $this->cart->items()->first();
        $this->assertEquals(600, $item->subtotal); // 300 * 2

        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(4)->startOfDay(); // 3 days

        // Update dates via cart
        $this->cart->setDates($from, $until, validateAvailability: false);

        $item->refresh();
        // Should be 300 * 3 days * 2 quantity = 1800
        $this->assertEquals(1800, $item->subtotal);
    }

    /** @test */
    public function cart_total_and_item_subtotals_match()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: false);

        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay(); // 2 days

        // Add 6 items
        $this->cart->addToCart($pool, 6, [], $from, $until);

        // Calculate expected total from item subtotals
        $expectedTotal = $this->cart->items()->sum('subtotal');

        $this->assertEquals($expectedTotal, $this->cart->getTotal());
        $this->assertEquals(7200, $this->cart->getTotal());
    }

    /** @test */
    public function removing_items_updates_pool_availability()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: false);

        // Add 6 items
        $this->cart->addToCart($pool, 6);
        $this->assertEquals(3600, $this->cart->getTotal());

        // Remove 1 item (should remove from highest price first - LIFO)
        $this->cart->removeFromCart($pool, 1);

        // Now we should be able to add 1 more
        $this->cart->addToCart($pool, 1);
        $this->assertEquals(3600, $this->cart->fresh()->getTotal());
    }

    /** @test */
    public function adding_quantity_greater_than_one_respects_availability()
    {
        $this->cart = $this->createCart();
        ['pool' => $pool] = $this->createParkingPool(hasPoolPrice: true, poolManagesStock: false);

        $from = Carbon::now()->addDay()->startOfDay();
        $until = Carbon::now()->addDays(2)->startOfDay();

        // Try to add 7 at once - should fail immediately
        $this->expectException(NotEnoughStockException::class);
        $this->cart->addToCart($pool, 7, [], $from, $until);
    }

    /** @test */
    public function pool_with_all_single_items_without_prices_throws_exception()
    {
        $pool = Product::factory()->create([
            'name' => 'No Price Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'No Price Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(2);

        $spot2 = Product::factory()->create([
            'name' => 'No Price Spot 2',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot2->increaseStock(2);

        $pool->attachSingleItems([$spot1->id, $spot2->id]);
        $pool->setPoolPricingStrategy('lowest');

        $this->cart = $this->createCart();

        $this->expectException(\Blax\Shop\Exceptions\HasNoPriceException::class);
        $this->cart->addToCart($pool, 1);
    }
}
