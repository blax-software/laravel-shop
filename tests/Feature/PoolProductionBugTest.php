<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;

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
     * 
     * Pool default price: 5000
     * Singles:
     * 1. price: 50000
     * 2. price: none (should fallback to pool price 5000)
     * 3. price: none (should fallback to pool price 5000)
     * 4. price: none (should fallback to pool price 5000)
     * 5. price: 10001
     * 6. price: 10002
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

    #[Test]
    public function pool_max_quantity_returns_sum_of_single_item_stocks()
    {
        $this->createProductionPool();

        // Total stock should be 6 (1 per single item)
        $maxQty = $this->pool->getPoolMaxQuantity();

        $this->assertEquals(6, $maxQty);
    }

    #[Test]
    public function adding_7_items_should_throw_not_enough_stock_exception()
    {
        $this->createProductionPool();
        $this->cart = $this->createCart();

        // With new flexible cart behavior: adding without dates is allowed
        // Exception should only be thrown when DATES are provided and there isn't enough stock
        $from = now()->addDays(10);
        $until = now()->addDays(12);

        // Adding 7 items with dates should throw exception since we only have 6 single items
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $this->cart->addToCart($this->pool, 7, [], $from, $until);
    }

    #[Test]
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

    #[Test]
    public function adding_6_items_at_once_gives_correct_pricing()
    {
        $this->createProductionPool();
        $this->cart = $this->createCart();

        // Adding 6 items at once should give same total as adding one at a time
        // Expected: 3x5000 + 10001 + 10002 + 50000 = 85003
        $this->cart->addToCart($this->pool, 6);

        $this->assertEquals(85003, $this->cart->fresh()->getTotal());
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    /**
     * If a user boys 5 single parking items, another can also buy 5 single items on different dates, 
     * but not on the same dates, if stock is claimed on date
     */
    #[Test]
    public function pool_allows_adding_singel_to_cart_again_after_booked()
    {
        $this->createProductionPool();
        $this->cart = $this->createCart();

        $from1 = Carbon::tomorrow()->startOfDay();
        $until1 = Carbon::tomorrow()->addDay()->startOfDay(); // 1 day

        // First user books all 6 single items for specific dates
        $this->cart->addToCart(
            $this->pool,
            6,
            [],
            $from1,
            $until1
        );

        // Simulate checkout with positive purchase
        $this->assertTrue($this->cart->isReadyForCheckout());
        $this->assertTrue($this->cart->IsReadyToCheckout);
        $this->cart->checkout();

        $this->assertGreaterThan(0, $this->cart->purchases()->count());

        // Create a second cart for another user
        $secondUser = User::factory()->create();
        $secondCart = $secondUser->currentCart();

        // Second user adds items WITHOUT dates first
        $secondCart->addToCart($this->pool, 6);

        $this->assertFalse($secondCart->isReadyForCheckout());
        $this->assertFalse($secondCart->IsReadyToCheckout);

        // Setting dates to a fully booked period should NOT throw,
        // but mark items as unavailable instead
        $secondCart->setDates($from1, $until1);

        // All items should be marked as unavailable
        $secondCart->refresh();
        $secondCart->load('items');
        foreach ($secondCart->items as $item) {
            $this->assertNull($item->price, 'Item should have null price for unavailable period');
            $this->assertFalse($item->is_ready_to_checkout);
        }
        $this->assertFalse($secondCart->isReadyForCheckout());

        // Now second user tries different dates - should succeed
        $from2 = Carbon::tomorrow()->addDays(2)->startOfDay();
        $until2 = Carbon::tomorrow()->addDays(3)->startOfDay(); // 1 day later

        // This should work - items become available again with new dates
        $secondCart->setDates($from2, $until2);
        $this->assertTrue($secondCart->isReadyForCheckout());
        $this->assertTrue($secondCart->isReadyToCheckout);

        $this->assertEquals(85003, $secondCart->fresh()->getTotal());

        $secondCart->checkout();

        $this->assertTrue($secondCart->fresh()->isConverted());
    }

    /**
     * Production bug: After purchasing items via Stripe checkout for specific dates,
     * user cannot add items to cart for DIFFERENT dates.
     * 
     * Scenario:
     * 1. User buys 5 singles from yesterday to in 2 days via Stripe checkout
     * 2. Purchase is successful, webhooks handled, stock claimed for those dates
     * 3. User should be able to add items to cart for DIFFERENT dates
     * 4. But currently can only add 2 items (bug!)
     * 
     * Expected: Should be able to add 6 items for different dates
     * Actual: Can only add 2 items
     */
    #[Test]
    public function user_can_add_pool_items_for_different_dates_after_stripe_purchase()
    {
        $this->createProductionPool();
        $this->cart = $this->createCart();

        // Simulate production scenario: purchase 5 items from yesterday to in 2 days
        $purchasedFrom = Carbon::yesterday()->startOfDay();
        $purchasedUntil = Carbon::tomorrow()->addDay()->startOfDay(); // in 2 days

        // Add 5 items to cart with those dates
        $this->cart->addToCart($this->pool, 5, [], $purchasedFrom, $purchasedUntil);

        // Simulate Stripe checkout flow (not regular checkout)
        // This creates PENDING purchases and then webhook claims stock
        $this->simulateStripeCheckout($this->cart, $purchasedFrom, $purchasedUntil);

        // Verify the cart is now converted
        $this->assertTrue($this->cart->fresh()->isConverted());

        // Now user creates a NEW cart for DIFFERENT dates
        $newCart = $this->user->currentCart();
        $this->assertNotEquals($this->cart->id, $newCart->id, 'Should create a new cart after previous one is converted');

        // Try to add 6 items for completely different dates
        $newFrom = Carbon::tomorrow()->addDays(5)->startOfDay();
        $newUntil = Carbon::tomorrow()->addDays(6)->startOfDay();

        // This should work - we should be able to add all 6 items for different dates
        $newCart->addToCart($this->pool, 6, [], $newFrom, $newUntil);

        // Verify we got all 6 items
        $newCart = $newCart->fresh();
        $this->assertEquals(6, $newCart->items->sum('quantity'));
        $this->assertEquals(85003, $newCart->getTotal());
        $this->assertTrue($newCart->fresh()->isReadyForCheckout());
    }

    /**
     * Helper to simulate Stripe checkout flow
     * This mimics what happens when using checkoutSession() and webhook handler
     */
    protected function simulateStripeCheckout(Cart $cart, $from, $until)
    {
        // Step 1: checkoutSession() creates PENDING purchases (without claiming stock yet)
        foreach ($cart->items as $item) {
            $product = $item->purchasable;

            $purchase = \Blax\Shop\Models\ProductPurchase::create([
                'cart_id' => $cart->id,
                'price_id' => $item->price_id,
                'purchasable_id' => $product->id,
                'purchasable_type' => get_class($product),
                'purchaser_id' => $cart->customer_id,
                'purchaser_type' => $cart->customer_type,
                'quantity' => $item->quantity,
                'amount' => $item->subtotal,
                'amount_paid' => 0,
                'status' => \Blax\Shop\Enums\PurchaseStatus::PENDING,
                'from' => $from,
                'until' => $until,
                'meta' => $item->meta,
            ]);

            $item->update(['purchase_id' => $purchase->id]);
        }

        // Step 2: Webhook handler marks cart as converted and updates purchases to COMPLETED
        $cart->update([
            'status' => \Blax\Shop\Enums\CartStatus::CONVERTED,
            'converted_at' => now(),
        ]);

        // Step 3: Webhook handler claims stock for each purchase
        $purchases = \Blax\Shop\Models\ProductPurchase::where('cart_id', $cart->id)->get();
        foreach ($purchases as $purchase) {
            $purchase->update([
                'status' => \Blax\Shop\Enums\PurchaseStatus::COMPLETED,
                'amount_paid' => $purchase->amount,
            ]);

            // Claim stock (this is what the webhook handler does)
            $product = $purchase->purchasable;
            if ($product instanceof Product && $product->isPool() && $purchase->from && $purchase->until) {
                $product->claimPoolStock(
                    $purchase->quantity,
                    $purchase,
                    $purchase->from,
                    $purchase->until,
                    "Purchase #{$purchase->id} completed"
                );
            }
        }
    }

    public function test_date_adjustment_with_one_item()
    {
        $this->createProductionPool();

        $cart = $this->createCart();

        $cart->addToCart(
            $this->pool,
            1
        );

        $this->assertEquals(5000, $cart->getTotal());
        $this->assertFalse($cart->isReadyForCheckout());
        $this->assertFalse($cart->items()->first()->is_ready_to_checkout);

        $from = Carbon::tomorrow()->startOfDay();
        $until = Carbon::tomorrow()->addDay()->startOfDay();

        $cart->setDates($from, $until);

        $this->assertEquals(5000, $cart->getTotal());
        $this->assertTrue($cart->isReadyForCheckout());
        $this->assertTrue($cart->items()->first()->is_ready_to_checkout);

        $until->subHours(5);
        $cart->setUntilDate($until);
        $this->assertLessThan(5000, $cart->getTotal());
        $this->assertTrue($cart->isReadyForCheckout());
        $this->assertTrue($cart->items()->first()->is_ready_to_checkout);

        $until->addHours(24);
        $cart->setUntilDate($until);
        $this->assertGreaterThan(5000, $cart->getTotal());
        $this->assertTrue($cart->isReadyForCheckout());
        $this->assertTrue($cart->items()->first()->is_ready_to_checkout);
    }

    public function test_date_adjustment_with_one_item_day_adjustment()
    {
        // The hotel has parking plots (proxied with the pool)
        $pool = Product::factory()->create([
            'type' => ProductType::POOL,
            'manage_stock' => true,
        ]);

        // In this hotel we have 3 cheap parking plots far from the entrance
        $single_1 = Product::factory()
            ->withStocks(3)
            ->withPrices(1, 1000)
            ->create([
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);

        // 1 medium priced parking plots closer to the entrance
        $single_2 = Product::factory()
            ->withStocks(1)
            ->withPrices(1, 10001)
            ->create([
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);

        // 1 premium parking plot right at the entrance
        $single_3 = Product::factory()
            ->withStocks(1)
            ->withPrices(1, 10002)
            ->create([
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);

        $pool->attachSingleItems([
            $single_1->id,
            $single_2->id,
            $single_3->id,
        ]);

        $cart = $this->createCart();

        // We check nothing is in the cart
        $this->assertEquals(0, $cart->items()->count());

        // We add the pool to the cart and expect the cheapest option to be added
        $cart->addToCart(
            $pool,
            1
        );

        $this->assertEquals(1000, $cart->getTotal());
        $this->assertFalse($cart->isReadyForCheckout());
        $this->assertFalse($cart->items()->first()->is_ready_to_checkout);

        $from = Carbon::tomorrow()->startOfDay();

        $cart->setFromDate($from);

        $until = Carbon::tomorrow()->addDay()->startOfDay();
        $cart->setUntilDate($until);

        $cart->refresh();

        $this->assertEquals(24, $cart->from->diffInHours($cart->until));

        $cart->setDates($cart->from, $cart->until);

        // As dates are now set, we expect the cart to be ready for checkout and it shows the correct total (unit_amount of price is for one day and we check for a full day)
        $this->assertEquals(1000, $cart->getTotal());
        $this->assertTrue($cart->isReadyForCheckout());
        $this->assertTrue($cart->items()->first()->is_ready_to_checkout);

        $cart->setDates($cart->from->copy(), $cart->until->copy()->addHours(24));

        $cart->refresh();
        $this->assertEquals(48, $cart->from->diffInHours($cart->until));

        // We expect the amount to be doubled now, as 2 days are booked
        $this->assertEquals(2000, $cart->getTotal());
        $this->assertTrue($cart->isReadyForCheckout());
        $this->assertTrue($cart->items()->first()->is_ready_to_checkout);

        $cart->addToCart(
            $pool,
            1
        );

        // We have the 2000 2 times now, as we book 2 days with quantity of 2 with unit amount of 1000
        $this->assertEquals(4000, $cart->getTotal());
        $this->assertTrue($cart->isReadyForCheckout());
        $this->assertTrue($cart->items()->first()->is_ready_to_checkout);

        $cart->addToCart(
            $pool,
            1
        );

        $this->assertEquals(6000, $cart->getTotal());
        $this->assertTrue($cart->isReadyForCheckout());
        $this->assertTrue($cart->items()->first()->is_ready_to_checkout);

        $cart->addToCart(
            $pool,
            1
        );

        $cart->refresh();

        // We expect to have 2 days booked and quantity of 3 and as the cheapest option is out of stock now, 
        // the next one is taken (unit amount of 10001)
        $this->assertEquals(48, $cart->from->diffInHours($cart->until));
        $this->assertEquals(6000 + (10001 * 2), $cart->getTotal());
        $this->assertTrue($cart->isReadyForCheckout());
        $this->assertTrue($cart->items()->first()->is_ready_to_checkout);

        $cart->addToCart(
            $pool,
            1
        );

        $this->assertEquals(6000 + (10001 * 2) + (10002 * 2), $cart->getTotal());

        $cart->removeFromCart(
            $pool,
            1
        );

        $this->assertEquals(6000 + (10001 * 2), $cart->getTotal());

        $single_1->adjustStock(
            StockType::CLAIMED,
            1,
            from: now()->subYear(),
            until: now()->addYear(),
            note: 'Booked'
        );

        // After claiming 1 stock from single_1, the capacity is reduced from 5 to 4.
        // We currently have 4 items in cart (3 @ single_1, 1 @ single_2).
        // When setDates is called, reallocation happens:
        // - single_1 now only has 2 capacity (3-1 claim = 2)
        // - 2 items can stay at single_1
        // - 1 item must move to single_3 (the only one with capacity)
        // - 1 item stays at single_2
        // After reallocation: 2 @ single_1 (4000) + 1 @ single_2 (20002) + 1 @ single_3 (20004) = 44006

        // Trigger reallocation by refreshing dates
        $cart->setDates($cart->from, $cart->until);
        $cart->refresh();

        $this->assertEquals(4000 + (10001 * 2) + (10002 * 2), $cart->getTotal());

        // Now try to add another item - this should fail because capacity is full (4 items, 4 capacity)
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $cart->addToCart(
            $pool,
            1
        );
    }

    /**
     * Test that single item allocation is properly tracked when adding multiple pool items.
     * Each single item should only be used up to its stock limit.
     */
    public function test_single_item_allocation_respects_stock_limits()
    {
        $pool = Product::factory()->create([
            'type' => ProductType::POOL,
            'manage_stock' => true,
        ]);

        // single_1: 3 stock @ 1000/day
        $single_1 = Product::factory()
            ->withStocks(3)
            ->withPrices(1, 1000)
            ->create([
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
                'name' => 'Single1-Cheap',
            ]);

        // single_2: 1 stock @ 10001/day
        $single_2 = Product::factory()
            ->withStocks(1)
            ->withPrices(1, 10001)
            ->create([
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
                'name' => 'Single2-Medium',
            ]);

        // single_3: 1 stock @ 10002/day
        $single_3 = Product::factory()
            ->withStocks(1)
            ->withPrices(1, 10002)
            ->create([
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
                'name' => 'Single3-Premium',
            ]);

        $pool->attachSingleItems([
            $single_1->id,
            $single_2->id,
            $single_3->id,
        ]);

        $cart = $this->createCart();

        $from = Carbon::tomorrow()->startOfDay();
        $until = Carbon::tomorrow()->addDays(2)->startOfDay(); // 2 days
        $cart->setDates($from, $until);

        // Add 4 items one by one, tracking each addition
        $items = [];
        for ($i = 1; $i <= 4; $i++) {
            $item = $cart->addToCart($pool, 1);
            $meta = $item->getMeta();
            $items[$i] = [
                'id' => $item->id,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'allocated_id' => $meta->allocated_single_item_id ?? null,
                'allocated_name' => $meta->allocated_single_item_name ?? 'none',
            ];
        }

        $cart->refresh();

        // Debug: check all cart items
        $cartItems = $cart->items;
        $cartItemDetails = [];
        $totalQuantity = 0;
        foreach ($cartItems as $item) {
            $meta = $item->getMeta();
            $cartItemDetails[] = [
                'id' => $item->id,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'allocated_id' => $meta->allocated_single_item_id ?? null,
                'allocated_name' => $meta->allocated_single_item_name ?? 'none',
            ];
            $totalQuantity += $item->quantity;
        }

        // Total quantity should be 4 (may be in fewer cart items if merged)
        $this->assertEquals(
            4,
            $totalQuantity,
            'Should have total quantity of 4. Cart items: ' . json_encode($cartItemDetails)
        );

        // The issue: when items are merged, the allocation tracking might not work correctly
        // Each distinct single item should NOT be merged with others
        // Items from the SAME single CAN be merged (they have same price and same allocated_single_item_id)

        // Check that we have correct allocations:
        // - 3 quantity allocated to single_1
        // - 1 quantity allocated to single_2
        $single1Quantity = 0;
        $single2Quantity = 0;
        $single3Quantity = 0;

        // Verify EACH cart item has allocated_single_item_id set
        foreach ($cartItems as $item) {
            $meta = $item->getMeta();
            $allocatedId = $meta->allocated_single_item_id ?? null;
            $this->assertNotNull(
                $allocatedId,
                'Cart item id=' . $item->id . ' (qty=' . $item->quantity . ', price=' . $item->price .
                    ') should have allocated_single_item_id but has: ' . json_encode($meta)
            );

            if ($allocatedId == $single_1->id) {
                $single1Quantity += $item->quantity;
            } elseif ($allocatedId == $single_2->id) {
                $single2Quantity += $item->quantity;
            } elseif ($allocatedId == $single_3->id) {
                $single3Quantity += $item->quantity;
            }
        }

        $this->assertEquals(
            3,
            $single1Quantity,
            'Should have 3 quantity from single_1. Cart: ' . json_encode($cartItemDetails)
        );
        $this->assertEquals(
            1,
            $single2Quantity,
            'Should have 1 quantity from single_2. Cart: ' . json_encode($cartItemDetails)
        );
        $this->assertEquals(
            0,
            $single3Quantity,
            'Should have 0 quantity from single_3 before adding 5th. Cart: ' . json_encode($cartItemDetails)
        );

        // Before adding item 5, test what getNextAvailablePoolItemWithPrice returns
        $nextBefore = $pool->getNextAvailablePoolItemWithPrice($cart, null, $from, $until);
        $this->assertNotNull($nextBefore, 'Should have next available before adding item 5');
        $this->assertEquals(
            $single_3->id,
            $nextBefore['item']->id,
            'Before adding item 5: Next should be single_3 (single_1 and single_2 exhausted). ' .
                'Got: ' . $nextBefore['item']->name . ' (id=' . $nextBefore['item']->id . '). ' .
                'Price: ' . $nextBefore['price'] . '. ' .
                'Cart items: ' . json_encode($cartItemDetails)
        );

        // Check that after refreshing the pool model, we still get single_3
        $pool->refresh();
        $nextBeforeAfterRefresh = $pool->getNextAvailablePoolItemWithPrice($cart, null, $from, $until);
        $this->assertEquals(
            $single_3->id,
            $nextBeforeAfterRefresh['item']->id,
            'After pool refresh, should still get single_3'
        );

        // Now add 5th item
        $cartItemsBeforeItem5 = $cart->fresh()->items;

        // Debug: Check what getNextAvailablePoolItemWithPrice returns INSIDE the addToCart flow
        // by calling it right before on a fresh pool and cart
        $freshCart = Cart::find($cart->id);
        $freshPool = Product::find($pool->id);
        $nextImmediate = $freshPool->getNextAvailablePoolItemWithPrice($freshCart, null, $from, $until);
        $this->assertEquals(
            $single_3->id,
            $nextImmediate['item']->id,
            'Immediately before addToCart (fresh models), should get single_3. Got: ' .
                $nextImmediate['item']->name . ' (id=' . $nextImmediate['item']->id . ')'
        );

        // Debug: Check what the addToCart flow sees for cart items
        // This replicates the query inside getNextAvailablePoolItemWithPrice
        $cartItemsAsSeenByPool = $freshCart->items()
            ->where('purchasable_id', $pool->getKey())
            ->where('purchasable_type', get_class($pool))
            ->get();
        $usageMap = [];
        foreach ($cartItemsAsSeenByPool as $ci) {
            $meta = $ci->getMeta();
            $allocatedId = $meta->allocated_single_item_id ?? null;
            if ($allocatedId) {
                $usageMap[$allocatedId] = ($usageMap[$allocatedId] ?? 0) + $ci->quantity;
            }
        }
        $this->assertEquals(
            3,
            $usageMap[$single_1->id] ?? 0,
            'Usage map should show 3 for single_1. Map: ' . json_encode($usageMap)
        );
        $this->assertEquals(
            1,
            $usageMap[$single_2->id] ?? 0,
            'Usage map should show 1 for single_2. Map: ' . json_encode($usageMap)
        );
        $this->assertEquals(
            0,
            $usageMap[$single_3->id] ?? 0,
            'Usage map should show 0 for single_3. Map: ' . json_encode($usageMap)
        );

        // Use fresh cart AND fresh pool to call addToCart
        $this->assertEquals($cart->id, $freshCart->id, 'Cart IDs should match');

        // Debug: Check that freshCart can see the items
        $freshCartItems = $freshCart->items()
            ->where('purchasable_id', $pool->getKey())
            ->where('purchasable_type', get_class($pool))
            ->get();
        $this->assertCount(
            2,
            $freshCartItems,
            'Fresh cart should have 2 cart item records. Cart ID: ' . $freshCart->id .
                '. Items found: ' . $freshCartItems->pluck('id')->join(', ')
        );
        $freshCartQty = $freshCartItems->sum('quantity');
        $this->assertEquals(
            4,
            $freshCartQty,
            'Fresh cart should have total quantity 4. Got: ' . $freshCartQty
        );

        $item5 = $freshCart->addToCart($freshPool, 1);
        $meta5 = $item5->getMeta();

        // Verify item 5 is allocated to single_3 (the only one with remaining capacity)
        $this->assertEquals(
            $single_3->id,
            $meta5->allocated_single_item_id,
            'Item 5 should be allocated to single_3 (id=' . $single_3->id . ') since single_1 and single_2 are exhausted. ' .
                'Got allocated_id: ' . ($meta5->allocated_single_item_id ?? 'null')
        );

        // Check if item 5 is actually a new item or a merged item
        $isNewItem = !$cartItemsBeforeItem5->contains('id', $item5->id);
        $cartItemsAfterItem5 = $cart->fresh()->items;

        $this->assertTrue(
            $isNewItem,
            'Item 5 should be a NEW item, not merged. ' .
                'Item5 id=' . $item5->id . ', quantity=' . $item5->quantity . '. ' .
                'Cart items before: ' . $cartItemsBeforeItem5->pluck('id')->join(', ') . '. ' .
                'Cart items after: ' . $cartItemsAfterItem5->pluck('id')->join(', ')
        );

        $this->assertEquals(
            $single_3->id,
            $meta5->allocated_single_item_id,
            'Item 5 should be from single_3 (id=' . $single_3->id . ', name=' . $single_3->name . '). ' .
                'Got allocated_id: ' . ($meta5->allocated_single_item_id ?? 'null') . '. ' .
                'For reference: single_1=' . $single_1->id . ', single_2=' . $single_2->id
        );
        $this->assertEquals(20004, $item5->price, 'Item 5 should cost 20004 (10002 * 2 days)');

        // Total: 3*2000 + 20002 + 20004 = 46006
        $this->assertEquals(46006, $cart->fresh()->getTotal());
    }

    /**
     * Test getNextAvailablePoolItemWithPrice correctly tracks cart item allocations.
     */
    public function test_get_next_available_pool_item_tracks_allocations()
    {
        $pool = Product::factory()->create([
            'type' => ProductType::POOL,
            'manage_stock' => true,
        ]);

        // single_1: 2 stock @ 1000/day
        $single_1 = Product::factory()
            ->withStocks(2)
            ->withPrices(1, 1000)
            ->create([
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
                'name' => 'Single1',
            ]);

        // single_2: 1 stock @ 2000/day
        $single_2 = Product::factory()
            ->withStocks(1)
            ->withPrices(1, 2000)
            ->create([
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
                'name' => 'Single2',
            ]);

        $pool->attachSingleItems([$single_1->id, $single_2->id]);

        $cart = $this->createCart();
        $from = Carbon::tomorrow()->startOfDay();
        $until = Carbon::tomorrow()->addDay()->startOfDay();
        $cart->setDates($from, $until);

        // Before adding any items, next available should be single_1 (cheapest)
        $next = $pool->getNextAvailablePoolItemWithPrice($cart, null, $from, $until);
        $this->assertEquals($single_1->id, $next['item']->id, 'First available should be single_1');
        $this->assertEquals(1000, $next['price']);

        // Add first item - should get single_1
        $item1 = $cart->addToCart($pool, 1);
        $this->assertEquals($single_1->id, $item1->getMeta()->allocated_single_item_id);

        // After 1 item, next should still be single_1 (has 2 stock)
        $cart->refresh();
        $next = $pool->getNextAvailablePoolItemWithPrice($cart, null, $from, $until);
        $this->assertEquals($single_1->id, $next['item']->id, 'Second available should still be single_1');

        // Add second item - should get single_1 again
        $item2 = $cart->addToCart($pool, 1);
        $this->assertEquals($single_1->id, $item2->getMeta()->allocated_single_item_id);

        // After 2 items (both from single_1 which has stock=2), next should be single_2
        $cart->refresh();
        $next = $pool->getNextAvailablePoolItemWithPrice($cart, null, $from, $until);
        $this->assertEquals($single_2->id, $next['item']->id, 'Third available should be single_2 (single_1 exhausted)');
        $this->assertEquals(2000, $next['price']);

        // Add third item - should get single_2
        $item3 = $cart->addToCart($pool, 1);
        $this->assertEquals($single_2->id, $item3->getMeta()->allocated_single_item_id);

        // Total: 1000 + 1000 + 2000 = 4000
        $this->assertEquals(4000, $cart->fresh()->getTotal());
    }
}
