<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Workbench\App\Models\User;

/**
 * Test to reproduce and fix the production bug where:
 * 
 * Pool (default price: 5000) with 6 singles:
 * 1. price: 50000
 * 2. price: none (should fallback to pool price 5000)
 * 3. price: none (should fallback to pool price 5000)
 * 4. price: none (should fallback to pool price 5000)
 * 5. price: 10001
 * 6. price: 10002
 * 
 * When adding 7 items to cart, expected:
 * - 3x 5000 (from singles 2,3,4 using pool fallback price) = 15000
 * - 1x 10001 (from single 5) = 10001
 * - 1x 10002 (from single 6) = 10002
 * - 1x 50000 (from single 1) = 50000
 * Total: 85003
 * 
 * But actual was:
 * - 7x 5000 = 35000
 * 
 * Also: CartItems from/until and price/subtotal should be updated by cart->setDates
 */
class PoolProductionBugTest extends TestCase
{
    protected User $user;
    protected Cart $cart;
    protected Product $pool;
    protected array $singles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        auth()->login($this->user);
    }

    /**
     * Create the pool product matching production setup
     */
    protected function createProductionPool(): void
    {
        // Create pool product with default price 5000
        $this->pool = Product::factory()->create([
            'name' => 'Production Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false, // Pool doesn't manage stock - it's the responsibility of single items
        ]);

        // Pool default price: 5000
        ProductPrice::factory()->create([
            'purchasable_id' => $this->pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Set pricing strategy to lowest
        $this->pool->setPoolPricingStrategy('lowest');

        // Create 6 single items
        $this->singles = [];

        // Single 1: price 50000
        $single1 = Product::factory()->create([
            'name' => 'Single 1 - 50000',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single1->increaseStock(1);
        ProductPrice::factory()->create([
            'purchasable_id' => $single1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 50000,
            'currency' => 'USD',
            'is_default' => true,
        ]);
        $this->singles[] = $single1;

        // Single 2: NO price (should fallback to pool price 5000)
        $single2 = Product::factory()->create([
            'name' => 'Single 2 - No Price',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single2->increaseStock(1);
        $this->singles[] = $single2;

        // Single 3: NO price (should fallback to pool price 5000)
        $single3 = Product::factory()->create([
            'name' => 'Single 3 - No Price',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single3->increaseStock(1);
        $this->singles[] = $single3;

        // Single 4: NO price (should fallback to pool price 5000)
        $single4 = Product::factory()->create([
            'name' => 'Single 4 - No Price',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single4->increaseStock(1);
        $this->singles[] = $single4;

        // Single 5: price 10001
        $single5 = Product::factory()->create([
            'name' => 'Single 5 - 10001',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single5->increaseStock(1);
        ProductPrice::factory()->create([
            'purchasable_id' => $single5->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 10001,
            'currency' => 'USD',
            'is_default' => true,
        ]);
        $this->singles[] = $single5;

        // Single 6: price 10002
        $single6 = Product::factory()->create([
            'name' => 'Single 6 - 10002',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single6->increaseStock(1);
        ProductPrice::factory()->create([
            'purchasable_id' => $single6->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 10002,
            'currency' => 'USD',
            'is_default' => true,
        ]);
        $this->singles[] = $single6;

        // Attach all singles to pool
        $this->pool->attachSingleItems(array_map(fn($s) => $s->id, $this->singles));
    }

    protected function createCart(): Cart
    {
        return Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);
    }

    /** @test */
    public function pool_max_quantity_returns_sum_of_single_item_stocks()
    {
        $this->createProductionPool();

        // Total stock should be 6 (1 per single item)
        $maxQty = $this->pool->getPoolMaxQuantity();

        $this->assertEquals(6, $maxQty);
    }

    /** @test */
    public function adding_7_items_should_throw_not_enough_stock_exception()
    {
        $this->createProductionPool();
        $this->cart = $this->createCart();

        // Adding 7 items should throw exception since we only have 6 single items
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $this->cart->addToCart($this->pool, 7);
    }

    /** @test */
    public function adding_6_items_gives_correct_progressive_pricing()
    {
        $this->createProductionPool();
        $this->cart = $this->createCart();

        // Add 6 items one at a time to verify progressive pricing
        // Expected order (LOWEST strategy):
        // 1. 5000 (single 2,3,4 using pool fallback - first one)
        // 2. 5000 (single 2,3,4 using pool fallback - second one)
        // 3. 5000 (single 2,3,4 using pool fallback - third one)
        // 4. 10001 (single 5)
        // 5. 10002 (single 6)
        // 6. 50000 (single 1)

        $cartItem1 = $this->cart->addToCart($this->pool, 1);
        $this->assertEquals(5000, $cartItem1->price);
        $this->assertEquals(5000, $this->cart->fresh()->getTotal());

        $cartItem2 = $this->cart->addToCart($this->pool, 1);
        $this->assertEquals(5000, $cartItem2->price);
        $this->assertEquals(10000, $this->cart->fresh()->getTotal());

        $cartItem3 = $this->cart->addToCart($this->pool, 1);
        $this->assertEquals(5000, $cartItem3->price);
        $this->assertEquals(15000, $this->cart->fresh()->getTotal());

        $cartItem4 = $this->cart->addToCart($this->pool, 1);
        $this->assertEquals(10001, $cartItem4->price);
        $this->assertEquals(25001, $this->cart->fresh()->getTotal());

        $cartItem5 = $this->cart->addToCart($this->pool, 1);
        $this->assertEquals(10002, $cartItem5->price);
        $this->assertEquals(35003, $this->cart->fresh()->getTotal());

        $cartItem6 = $this->cart->addToCart($this->pool, 1);
        $this->assertEquals(50000, $cartItem6->price);
        $this->assertEquals(85003, $this->cart->fresh()->getTotal());
    }

    /** @test */
    public function adding_6_items_at_once_gives_correct_pricing()
    {
        $this->createProductionPool();
        $this->cart = $this->createCart();

        // Adding 6 items at once should give same total as adding one at a time
        // Expected: 3x5000 + 10001 + 10002 + 50000 = 85003
        $this->cart->addToCart($this->pool, 6);

        $this->assertEquals(85003, $this->cart->fresh()->getTotal());
    }

    /** @test */
    public function cart_items_have_correct_allocated_single_items()
    {
        $this->createProductionPool();
        $this->cart = $this->createCart();

        $this->cart->addToCart($this->pool, 6);

        $items = $this->cart->fresh()->items->sortBy('price');

        // Should have 4-6 cart items (depending on whether same-price items are merged)
        // The 3x 5000 items might be merged since they have the same price_id (pool price)
        // But different single items should NOT be merged

        // Get all allocated single item names
        $allocatedNames = $items->map(fn($item) => [
            'name' => $item->getMeta()->allocated_single_item_name ?? 'unknown',
            'price' => $item->price,
            'quantity' => $item->quantity,
        ])->toArray();

        // Total quantity should be 6
        $totalQuantity = $items->sum('quantity');
        $this->assertEquals(6, $totalQuantity);

        // Total price should be 85003
        $this->assertEquals(85003, $this->cart->getTotal());
    }

    /** @test */
    public function set_dates_updates_cart_item_dates_and_recalculates_prices()
    {
        $this->createProductionPool();
        $this->cart = $this->createCart();

        $from1 = Carbon::tomorrow()->startOfDay();
        $until1 = Carbon::tomorrow()->addDay()->startOfDay(); // 1 day

        // Add items with initial dates
        $this->cart->addToCart($this->pool, 3, [], $from1, $until1);

        // Verify initial state - 3 items at 5000 each
        $initialTotal = $this->cart->fresh()->getTotal();
        $this->assertEquals(15000, $initialTotal);

        // Change to 2 day booking
        $from2 = Carbon::tomorrow()->startOfDay();
        $until2 = Carbon::tomorrow()->addDays(2)->startOfDay(); // 2 days

        $this->cart->setDates($from2, $until2);

        // Reload cart
        $cart = $this->cart->fresh();
        $cart->load('items');

        // Each cart item should now have:
        // - updated from/until dates
        // - doubled price (2 days instead of 1)
        foreach ($cart->items as $item) {
            $this->assertEquals($from2->format('Y-m-d H:i:s'), $item->from->format('Y-m-d H:i:s'));
            $this->assertEquals($until2->format('Y-m-d H:i:s'), $item->until->format('Y-m-d H:i:s'));
            // Price should be doubled (2 days)
            $this->assertEquals(10000, $item->price, "Item price should be 10000 (5000 * 2 days)");
        }

        // Total should be doubled: 15000 * 2 = 30000
        $this->assertEquals(30000, $cart->getTotal());
    }

    /** @test */
    public function set_dates_updates_all_items_with_different_prices()
    {
        $this->createProductionPool();
        $this->cart = $this->createCart();

        $from1 = Carbon::tomorrow()->startOfDay();
        $until1 = Carbon::tomorrow()->addDay()->startOfDay(); // 1 day

        // Add 6 items with initial 1-day dates
        $this->cart->addToCart($this->pool, 6, [], $from1, $until1);

        // Verify initial state
        $this->assertEquals(85003, $this->cart->fresh()->getTotal());

        // Change to 2 day booking
        $from2 = Carbon::tomorrow()->startOfDay();
        $until2 = Carbon::tomorrow()->addDays(2)->startOfDay(); // 2 days

        $this->cart->setDates($from2, $until2);

        // Reload cart
        $cart = $this->cart->fresh();
        $cart->load('items');

        // Each item should have updated dates
        foreach ($cart->items as $item) {
            $this->assertEquals($from2->format('Y-m-d H:i:s'), $item->from->format('Y-m-d H:i:s'));
            $this->assertEquals($until2->format('Y-m-d H:i:s'), $item->until->format('Y-m-d H:i:s'));
        }

        // Total should be doubled: 85003 * 2 = 170006
        $this->assertEquals(170006, $cart->getTotal());
    }

    /** @test */
    public function adding_items_without_dates_then_setting_dates_works()
    {
        $this->createProductionPool();
        $this->cart = $this->createCart();

        // Add items WITHOUT dates
        $this->cart->addToCart($this->pool, 3);

        // Initial total should be 15000 (3x 5000)
        $this->assertEquals(15000, $this->cart->fresh()->getTotal());

        // Now set dates for 2 days
        $from = Carbon::tomorrow()->startOfDay();
        $until = Carbon::tomorrow()->addDays(2)->startOfDay(); // 2 days

        $this->cart->setDates($from, $until);

        // Reload cart
        $cart = $this->cart->fresh();
        $cart->load('items');

        // Each cart item should now have dates and doubled prices
        foreach ($cart->items as $item) {
            $this->assertEquals($from->format('Y-m-d H:i:s'), $item->from->format('Y-m-d H:i:s'));
            $this->assertEquals($until->format('Y-m-d H:i:s'), $item->until->format('Y-m-d H:i:s'));
            // Price should be doubled (2 days)
            $this->assertEquals(10000, $item->price, "Item price should be 10000 (5000 * 2 days)");
        }

        // Total should be 30000 (3x 5000 x 2 days)
        $this->assertEquals(30000, $cart->getTotal());
    }
}
