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
 * Tests for smart pool allocation and flexible cart behavior
 * 
 * Key behaviors:
 * 1. Items can be added to cart even if not currently available (if they'll be available later)
 * 2. Cart is not ready for checkout until all items are available at the specified dates
 * 3. Pool should prioritize currently/soon available items when adding to cart
 * 4. When dates change, cart should reallocate to follow pricing strategy if better options exist
 */
class PoolSmartAllocationTest extends TestCase
{
    protected User $user;
    protected Product $pool;
    protected array $singles;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        auth()->login($this->user);
    }

    /**
     * Create a pool with varying prices for testing allocation strategies
     */
    protected function createPoolWithVaryingPrices(): void
    {
        $this->pool = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $this->pool->setPoolPricingStrategy('lowest');

        $this->singles = [];

        // Create singles with different prices
        $prices = [10000, 20000, 30000, 40000, 50000, 60000];

        foreach ($prices as $index => $price) {
            $single = Product::factory()->create([
                'name' => "Spot " . ($index + 1) . " - {$price}",
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);
            $single->increaseStock(1);

            ProductPrice::factory()->create([
                'purchasable_id' => $single->id,
                'purchasable_type' => Product::class,
                'unit_amount' => $price,
                'currency' => 'USD',
                'is_default' => true,
            ]);

            $this->singles[] = $single;
        }

        $this->pool->attachSingleItems(array_map(fn($s) => $s->id, $this->singles));
    }

    /**
     * Test: Items can be added to cart without dates
     */
    /** @test */
    public function items_can_be_added_to_cart_without_dates()
    {
        $this->createPoolWithVaryingPrices();
        $cart = $this->user->currentCart();

        // Should be able to add items without dates
        $cart->addToCart($this->pool, 3);

        $this->assertEquals(3, $cart->fresh()->items->sum('quantity'));
        // Should get lowest prices: 10000, 20000, 30000 = 60000
        $this->assertEquals(60000, $cart->fresh()->getTotal());
    }

    /**
     * Test: Items can be added even if currently claimed but will be available in future
     */
    /** @test */
    public function items_can_be_added_even_if_currently_claimed_but_available_in_future()
    {
        $this->createPoolWithVaryingPrices();

        // Claim 3 cheapest items for current period (yesterday to in 2 days)
        $claimFrom = Carbon::yesterday()->startOfDay();
        $claimUntil = Carbon::tomorrow()->addDay()->startOfDay();

        $this->singles[0]->claimStock(1, null, $claimFrom, $claimUntil); // 10000
        $this->singles[1]->claimStock(1, null, $claimFrom, $claimUntil); // 20000
        $this->singles[2]->claimStock(1, null, $claimFrom, $claimUntil); // 30000

        $cart = $this->user->currentCart();

        // Add items for future date AFTER claims expire
        $futureFrom = Carbon::tomorrow()->addDays(5)->startOfDay();
        $futureUntil = Carbon::tomorrow()->addDays(6)->startOfDay();

        // Should be able to add all 6 items for future date
        $cart->addToCart($this->pool, 6, [], $futureFrom, $futureUntil);

        $this->assertEquals(6, $cart->fresh()->items->sum('quantity'));
        // Should get all 6 in order: 10000+20000+30000+40000+50000+60000 = 210000
        $this->assertEquals(210000, $cart->fresh()->getTotal());
        $this->assertTrue($cart->fresh()->isReadyForCheckout());
    }

    /**
     * Test: Cart is not ready for checkout if items added without dates
     */
    /** @test */
    public function cart_is_not_ready_for_checkout_without_dates_for_booking_products()
    {
        $this->createPoolWithVaryingPrices();
        $cart = $this->user->currentCart();

        // Add items without dates
        $cart->addToCart($this->pool, 3);

        $this->assertEquals(3, $cart->fresh()->items->sum('quantity'));
        $this->assertFalse($cart->fresh()->isReadyForCheckout());
    }

    /**
     * Test: Cart becomes ready after setting dates
     */
    /** @test */
    public function cart_becomes_ready_after_setting_valid_dates()
    {
        $this->createPoolWithVaryingPrices();
        $cart = $this->user->currentCart();

        // Add items without dates
        $cart->addToCart($this->pool, 3);
        $this->assertFalse($cart->fresh()->isReadyForCheckout());

        // Set dates for future availability
        $from = Carbon::tomorrow()->addDays(5)->startOfDay();
        $until = Carbon::tomorrow()->addDays(6)->startOfDay();

        $cart->setDates($from, $until);

        $this->assertTrue($cart->fresh()->isReadyForCheckout());
    }

    /**
     * Test: User1 purchases items, User2 can add same items for different dates
     */
    /** @test */
    public function user2_can_book_same_items_for_different_dates_after_user1_purchase()
    {
        $this->createPoolWithVaryingPrices();

        // User1 purchases
        $user1Cart = $this->user->currentCart();
        $purchaseFrom = Carbon::yesterday()->startOfDay();
        $purchaseUntil = Carbon::tomorrow()->addDay()->startOfDay();

        $user1Cart->addToCart($this->pool, 5, [], $purchaseFrom, $purchaseUntil);
        $user1Cart->checkout();

        $this->assertTrue($user1Cart->fresh()->isConverted());

        // User2 adds items WITHOUT dates first
        $user2 = User::factory()->create();
        $user2Cart = $user2->currentCart();

        // Should be able to add items even though they're currently claimed
        $user2Cart->addToCart($this->pool, 6);

        $this->assertEquals(6, $user2Cart->fresh()->items->sum('quantity'));
        $this->assertFalse($user2Cart->fresh()->isReadyForCheckout(), 'Cart should not be ready without dates');

        // User2 tries to set dates that conflict with User1
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughAvailableInTimespanException::class);
        $user2Cart->setDates($purchaseFrom, $purchaseUntil);
    }

    /**
     * Test: User2 can successfully book after setting different dates
     */
    /** @test */
    public function user2_can_successfully_book_after_setting_different_dates()
    {
        $this->createPoolWithVaryingPrices();

        // User1 purchases
        $user1Cart = $this->user->currentCart();
        $purchaseFrom = Carbon::yesterday()->startOfDay();
        $purchaseUntil = Carbon::tomorrow()->addDay()->startOfDay();

        $user1Cart->addToCart($this->pool, 5, [], $purchaseFrom, $purchaseUntil);
        $user1Cart->checkout();

        // User2 workflow
        $user2 = User::factory()->create();
        $user2Cart = $user2->currentCart();

        // Add items without dates
        $user2Cart->addToCart($this->pool, 6);
        $this->assertFalse($user2Cart->fresh()->isReadyForCheckout());

        // Set different dates (after User1's booking)
        $differentFrom = Carbon::tomorrow()->addDays(5)->startOfDay();
        $differentUntil = Carbon::tomorrow()->addDays(6)->startOfDay();

        $user2Cart->setDates($differentFrom, $differentUntil);

        $this->assertTrue($user2Cart->fresh()->isReadyForCheckout());
        $this->assertEquals(210000, $user2Cart->fresh()->getTotal());

        // Should be able to checkout
        $user2Cart->checkout();
        $this->assertTrue($user2Cart->fresh()->isConverted());
    }

    /**
     * Test: Pool prioritizes currently available items when adding to cart
     * 
     * Scenario: 3 items claimed for future, 3 available now
     * When adding 3 items, should get the 3 currently available ones
     */
    /** @test */
    public function pool_prioritizes_currently_available_items_when_adding_to_cart()
    {
        $this->createPoolWithVaryingPrices();

        // Claim the 3 cheapest items for FUTURE dates
        $futureFrom = Carbon::tomorrow()->addDays(10)->startOfDay();
        $futureUntil = Carbon::tomorrow()->addDays(11)->startOfDay();

        $this->singles[0]->claimStock(1, null, $futureFrom, $futureUntil); // 10000
        $this->singles[1]->claimStock(1, null, $futureFrom, $futureUntil); // 20000
        $this->singles[2]->claimStock(1, null, $futureFrom, $futureUntil); // 30000

        $cart = $this->user->currentCart();

        // Add 3 items for dates BEFORE the future claims
        $nearFrom = Carbon::tomorrow()->addDays(2)->startOfDay();
        $nearUntil = Carbon::tomorrow()->addDays(3)->startOfDay();

        $cart->addToCart($this->pool, 3, [], $nearFrom, $nearUntil);

        // Should get the 3 cheapest AVAILABLE items: 10000, 20000, 30000
        // (they're available for near dates even though claimed for future)
        $this->assertEquals(60000, $cart->fresh()->getTotal());
    }

    /**
     * Test: When dates change making cheaper items available, cart reallocates
     * 
     * Scenario with LOWEST strategy:
     * - Initially add 3 items for future date when only expensive items available
     * - Change to different date when cheaper items become available
     * - Cart should reallocate to cheaper items
     */
    /** @test */
    public function cart_reallocates_to_cheaper_items_when_dates_change_with_lowest_strategy()
    {
        $this->createPoolWithVaryingPrices();

        // Claim 3 cheapest items for near-future
        $claimFrom = Carbon::tomorrow()->addDays(1)->startOfDay();
        $claimUntil = Carbon::tomorrow()->addDays(2)->startOfDay();

        $this->singles[0]->claimStock(1, null, $claimFrom, $claimUntil); // 10000
        $this->singles[1]->claimStock(1, null, $claimFrom, $claimUntil); // 20000
        $this->singles[2]->claimStock(1, null, $claimFrom, $claimUntil); // 30000

        $cart = $this->user->currentCart();

        // Add 3 items for dates when cheap items are claimed
        // Should get more expensive items: 40000, 50000, 60000 = 150000
        $cart->addToCart($this->pool, 3, [], $claimFrom, $claimUntil);

        $this->assertEquals(150000, $cart->fresh()->getTotal());

        // Now change dates to AFTER claims expire
        $newFrom = Carbon::tomorrow()->addDays(5)->startOfDay();
        $newUntil = Carbon::tomorrow()->addDays(6)->startOfDay();

        $cart->setDates($newFrom, $newUntil, validateAvailability: true, overwrite_item_dates: true);

        // Cart should reallocate to cheapest available: 10000, 20000, 30000 = 60000
        $this->assertEquals(60000, $cart->fresh()->getTotal());
    }

    /**
     * Test: Verify allocated items change when reallocating
     */
    /** @test */
    public function allocated_single_items_change_when_reallocating_to_better_prices()
    {
        $this->createPoolWithVaryingPrices();

        // Claim 3 cheapest for near dates
        $claimFrom = Carbon::tomorrow()->addDays(1)->startOfDay();
        $claimUntil = Carbon::tomorrow()->addDays(2)->startOfDay();

        $this->singles[0]->claimStock(1, null, $claimFrom, $claimUntil);
        $this->singles[1]->claimStock(1, null, $claimFrom, $claimUntil);
        $this->singles[2]->claimStock(1, null, $claimFrom, $claimUntil);

        $cart = $this->user->currentCart();
        $cart->addToCart($this->pool, 3, [], $claimFrom, $claimUntil);

        $initialItems = $cart->fresh()->items->sortBy('price')->values();
        $initialAllocations = $initialItems->map(fn($i) => $i->getMeta()->allocated_single_item_name)->toArray();

        // Should have expensive items allocated
        $this->assertContains('Spot 4 - 40000', $initialAllocations);

        // Change to dates when cheap items available
        $newFrom = Carbon::tomorrow()->addDays(5)->startOfDay();
        $newUntil = Carbon::tomorrow()->addDays(6)->startOfDay();

        $cart->setDates($newFrom, $newUntil);

        $newItems = $cart->fresh()->items->sortBy('price')->values();
        $newAllocations = $newItems->map(fn($i) => $i->getMeta()->allocated_single_item_name)->toArray();

        // Should now have cheap items allocated
        $this->assertContains('Spot 1 - 10000', $newAllocations);
        $this->assertContains('Spot 2 - 20000', $newAllocations);
        $this->assertContains('Spot 3 - 30000', $newAllocations);
    }
}
