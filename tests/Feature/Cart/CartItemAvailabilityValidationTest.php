<?php

namespace Blax\Shop\Tests\Feature\Cart;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for cart item validation when dates change and items become unavailable.
 * 
 * Bug: When adjusting dates in cart, some cart items show null/0 price because they
 * are not available for the new dates. But IsReadyToCheckout incorrectly returns true.
 * 
 * Expected behavior:
 * - setDates() should NOT throw - it should allow users to fiddle with dates
 * - Items that become unavailable should have price = null
 * - Items with null price should NOT be ready for checkout  
 * - Cart.isReadyForCheckout() should return false if any items are unavailable
 * - Exception should only be thrown at checkout time, not when changing dates
 */
class CartItemAvailabilityValidationTest extends TestCase
{
    protected User $user;
    protected Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        auth()->login($this->user);
        $this->cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);
    }

    /**
     * Create a pool with limited singles for testing
     */
    protected function createPoolWithLimitedSingles(int $numSingles = 3): Product
    {
        $pool = Product::factory()
            ->withPrices(1, 5000)
            ->create([
                'name' => 'Limited Pool',
                'type' => ProductType::POOL,
                'manage_stock' => false,
            ]);

        $pool->setPoolPricingStrategy('lowest');

        // Create singles with 1 stock each
        for ($i = 1; $i <= $numSingles; $i++) {
            $single = Product::factory()
                ->withStocks(1)
                ->withPrices(1, 5000)
                ->create([
                    'name' => "Single {$i}",
                    'type' => ProductType::BOOKING,
                    'manage_stock' => true,
                ]);

            $pool->attachSingleItems([$single->id]);
        }

        return $pool;
    }

    #[Test]
    public function cart_item_with_null_price_is_not_ready_for_checkout()
    {
        $pool = $this->createPoolWithLimitedSingles(3);

        // Add 3 items without dates
        $this->cart->addToCart($pool, 3);

        // Manually set one item's price to null to simulate unavailable item
        $item = $this->cart->items()->first();
        $item->update(['price' => null, 'subtotal' => null]);
        $item->refresh();

        // Item with null price should NOT be ready for checkout
        $this->assertNull($item->price);
        $this->assertFalse($item->is_ready_to_checkout, 'Item with null price should not be ready for checkout');

        // Cart should NOT be ready for checkout
        $this->assertFalse($this->cart->fresh()->is_ready_to_checkout, 'Cart with null-price item should not be ready');
    }

    #[Test]
    public function cart_item_with_zero_price_is_not_ready_for_checkout()
    {
        $pool = $this->createPoolWithLimitedSingles(3);

        // Add 3 items without dates
        $this->cart->addToCart($pool, 3);

        // Manually set one item's price to 0 to simulate unavailable item
        $item = $this->cart->items()->first();
        $item->update(['price' => 0, 'subtotal' => 0]);
        $item->refresh();

        // Item with 0 price should NOT be ready for checkout
        $this->assertEquals(0, $item->price);
        $this->assertFalse($item->is_ready_to_checkout, 'Item with price 0 should not be ready for checkout');

        // Cart should NOT be ready for checkout
        $this->assertFalse($this->cart->fresh()->is_ready_to_checkout, 'Cart with 0-price item should not be ready');
    }

    #[Test]
    public function unallocated_pool_item_with_null_price_is_not_ready_for_checkout()
    {
        $pool = $this->createPoolWithLimitedSingles(3);

        $from = now()->addDays(1);
        $until = now()->addDays(2);

        // Add 3 items with dates - all should be allocated
        $this->cart->addToCart($pool, 3, [], $from, $until);

        // Manually simulate an item becoming unavailable:
        // - Remove allocation (product_id = null)
        // - Set price to null (the real indicator of unavailability)
        $item = $this->cart->items()->first();
        $item->update([
            'product_id' => null,
            'price' => null,
            'subtotal' => null,
        ]);
        $item->refresh();

        // Item with null price should NOT be ready for checkout
        $this->assertFalse($item->is_ready_to_checkout, 'Item with null price should not be ready for checkout');

        // Cart should NOT be ready for checkout
        $this->assertFalse($this->cart->fresh()->is_ready_to_checkout, 'Cart with unavailable item should not be ready');
    }

    #[Test]
    public function setDates_does_not_throw_when_items_become_unavailable()
    {
        $pool = $this->createPoolWithLimitedSingles(3);

        // First user books all 3 singles for specific dates
        $user1 = User::factory()->create();
        $user1Cart = $user1->currentCart();

        $bookedFrom = now()->addDays(5);
        $bookedUntil = now()->addDays(6);

        $user1Cart->addToCart($pool, 3, [], $bookedFrom, $bookedUntil);
        $user1Cart->checkout(); // Claims the stock

        // Our user adds items without dates (should work - we have 3 total capacity)
        $this->cart->addToCart($pool, 3);

        // All items should have prices > 0 initially
        foreach ($this->cart->items as $item) {
            $this->assertGreaterThan(0, $item->price, 'Item should have positive price initially');
        }

        // Now set dates that conflict with the booked period
        // This should NOT throw - it should just mark items as unavailable
        $this->cart->setDates($bookedFrom, $bookedUntil);

        $this->cart->refresh();
        $this->cart->load('items');

        // Cart should NOT be ready for checkout (items are unavailable)
        $this->assertFalse(
            $this->cart->is_ready_to_checkout,
            'Cart should not be ready when items are unavailable for selected dates'
        );
    }

    #[Test]
    public function partial_availability_marks_some_items_unavailable()
    {
        $pool = $this->createPoolWithLimitedSingles(3);

        // First user books 2 of 3 singles for specific dates
        $user1 = User::factory()->create();
        $user1Cart = $user1->currentCart();

        $bookedFrom = now()->addDays(5);
        $bookedUntil = now()->addDays(6);

        $user1Cart->addToCart($pool, 2, [], $bookedFrom, $bookedUntil);
        $user1Cart->checkout(); // Claims 2 singles

        // Verify that only 1 single is available for the booked period
        $available = $pool->getPoolMaxQuantity($bookedFrom, $bookedUntil);
        $this->assertEquals(1, $available, 'Only 1 single should be available after booking 2');

        // Our user adds 3 items without dates
        $this->cart->addToCart($pool, 3);

        $this->assertEquals(3, $this->cart->items()->sum('quantity'));

        // Set dates where only 1 single is available
        // Should NOT throw - just mark some items as unavailable
        $this->cart->setDates($bookedFrom, $bookedUntil);

        $this->cart->refresh();
        $this->cart->load('items');

        // Check how many items are available vs unavailable
        $availableItems = $this->cart->items->filter(
            fn($item) =>
            $item->price !== null && $item->price > 0
        );
        $unavailableItems = $this->cart->items->filter(
            fn($item) =>
            $item->price === null || $item->price <= 0
        );

        // Should have 1 available and 2 unavailable
        $this->assertEquals(1, $availableItems->count(), 'Should have 1 available item');
        $this->assertEquals(2, $unavailableItems->count(), 'Should have 2 unavailable items');

        // Cart should NOT be ready for checkout
        $this->assertFalse($this->cart->is_ready_to_checkout, 'Cart with unavailable items should not be ready');
    }

    #[Test]
    public function cart_item_without_allocated_single_for_pool_is_not_ready()
    {
        $pool = $this->createPoolWithLimitedSingles(3);

        $from = now()->addDays(1);
        $until = now()->addDays(2);

        // Add 3 items with dates
        $this->cart->addToCart($pool, 3, [], $from, $until);

        // Verify all items are allocated and ready
        foreach ($this->cart->items as $item) {
            $this->assertNotNull($item->product_id, 'Item should have product_id allocated');
            $this->assertTrue($item->is_ready_to_checkout, 'Allocated item should be ready');
        }

        // All items ready - cart is ready
        $this->assertTrue($this->cart->fresh()->is_ready_to_checkout);
    }

    #[Test]
    public function removing_unavailable_items_makes_cart_ready()
    {
        $pool = $this->createPoolWithLimitedSingles(3);

        // Add 3 items without dates
        $this->cart->addToCart($pool, 3);

        // Manually make one item unavailable (price = null)
        $unavailableItem = $this->cart->items()->first();
        $unavailableItem->update(['price' => null, 'subtotal' => null]);

        // Cart should NOT be ready
        $this->assertFalse($this->cart->fresh()->is_ready_to_checkout);

        // Remove the unavailable item
        $unavailableItem->delete();

        // Set dates for remaining items
        $from = now()->addDays(1);
        $until = now()->addDays(2);
        $this->cart->setDates($from, $until);

        // Now cart should be ready
        $this->assertTrue($this->cart->fresh()->is_ready_to_checkout);
    }

    #[Test]
    public function getItemsRequiringAdjustments_includes_null_price_items()
    {
        $pool = $this->createPoolWithLimitedSingles(3);

        $from = now()->addDays(1);
        $until = now()->addDays(2);

        // Add 3 items with dates
        $this->cart->addToCart($pool, 3, [], $from, $until);

        // Make one item have null price
        $item = $this->cart->items()->first();
        $item->update(['price' => null, 'subtotal' => null]);

        $this->cart->refresh();
        $this->cart->load('items');

        // Get items requiring adjustments
        $itemsNeedingAdjustment = $this->cart->getItemsRequiringAdjustments();

        // The null-price item should be in the list
        $this->assertGreaterThanOrEqual(
            1,
            $itemsNeedingAdjustment->count(),
            'Null price item should require adjustment'
        );

        // Check that it has 'unavailable' as the price adjustment reason
        $nullPriceItem = $itemsNeedingAdjustment->first(fn($i) => $i->price === null);
        $this->assertNotNull($nullPriceItem, 'Should find the null-price item');

        $adjustments = $nullPriceItem->requiredAdjustments();
        $this->assertArrayHasKey('price', $adjustments);
        $this->assertEquals('unavailable', $adjustments['price']);
    }

    #[Test]
    public function changing_dates_to_available_period_makes_items_available_again()
    {
        $pool = $this->createPoolWithLimitedSingles(3);

        // First user books all 3 singles for specific dates
        $user1 = User::factory()->create();
        $user1Cart = $user1->currentCart();

        $bookedFrom = now()->addDays(5);
        $bookedUntil = now()->addDays(6);

        $user1Cart->addToCart($pool, 3, [], $bookedFrom, $bookedUntil);
        $user1Cart->checkout();

        // Our user adds 3 items without dates
        $this->cart->addToCart($pool, 3);

        // Set dates that conflict - items become unavailable
        $this->cart->setDates($bookedFrom, $bookedUntil);
        $this->assertFalse($this->cart->fresh()->is_ready_to_checkout);

        // Change to different dates where all singles are available
        $availableFrom = now()->addDays(10);
        $availableUntil = now()->addDays(11);

        $this->cart->setDates($availableFrom, $availableUntil);

        $this->cart->refresh();
        $this->cart->load('items');

        // All items should now have valid prices
        foreach ($this->cart->items as $item) {
            $this->assertNotNull($item->price, 'Item should have price after changing to available dates');
            $this->assertGreaterThan(0, $item->price, 'Item should have positive price');
        }

        // Cart should be ready for checkout
        $this->assertTrue($this->cart->is_ready_to_checkout, 'Cart should be ready after changing to available dates');
    }

    #[Test]
    public function checkout_throws_when_items_are_unavailable()
    {
        $pool = $this->createPoolWithLimitedSingles(3);

        // Add items and make one unavailable
        $this->cart->addToCart($pool, 3);

        $item = $this->cart->items()->first();
        $item->update(['price' => null, 'subtotal' => null]);

        // Trying to checkout should throw CartItemMissingInformationException
        // because the item has 'price' => 'unavailable' in requiredAdjustments()
        $this->expectException(\Blax\Shop\Exceptions\CartItemMissingInformationException::class);
        $this->cart->checkout();
    }

    #[Test]
    public function checkoutSessionLink_throws_when_items_have_null_price()
    {
        $pool = $this->createPoolWithLimitedSingles(3);

        $from = now()->addDays(1);
        $until = now()->addDays(2);

        // Add items
        $this->cart->addToCart($pool, 3, [], $from, $until);

        // Manually make one unavailable
        $item = $this->cart->items()->first();
        $item->update(['price' => null, 'subtotal' => null]);

        // checkoutSessionLink should throw because item is unavailable
        $this->expectException(\Blax\Shop\Exceptions\CartItemMissingInformationException::class);
        $this->cart->checkoutSessionLink();
    }

    #[Test]
    public function checkoutSessionLink_throws_when_items_have_zero_price()
    {
        $pool = $this->createPoolWithLimitedSingles(3);

        $from = now()->addDays(1);
        $until = now()->addDays(2);

        // Add items
        $this->cart->addToCart($pool, 3, [], $from, $until);

        // Manually set price to 0 (should also be considered unavailable)
        $item = $this->cart->items()->first();
        $item->update(['price' => 0, 'subtotal' => 0]);

        // checkoutSessionLink should throw because item has 0 price
        $this->expectException(\Blax\Shop\Exceptions\CartItemMissingInformationException::class);
        $this->cart->checkoutSessionLink();
    }

    #[Test]
    public function pool_items_maintain_consistent_pricing_after_date_changes()
    {
        $pool = $this->createPoolWithLimitedSingles(3);

        $from1 = now()->addDays(1);
        $until1 = now()->addDays(2);

        // Add 3 items with dates
        $this->cart->addToCart($pool, 3, [], $from1, $until1);

        // Get initial prices
        $initialPrices = $this->cart->items->pluck('price')->sort()->values()->toArray();

        // Change to different dates (same duration)
        $from2 = now()->addDays(5);
        $until2 = now()->addDays(6);

        $this->cart->setDates($from2, $until2);
        $this->cart->refresh();
        $this->cart->load('items');

        // Prices should be the same (only dates changed, not duration)
        $newPrices = $this->cart->items->pluck('price')->sort()->values()->toArray();

        $this->assertEquals(
            $initialPrices,
            $newPrices,
            'Prices should remain consistent when only dates change (same duration)'
        );
    }

    #[Test]
    public function cart_item_is_not_ready_for_checkout_if_already_booked_on_same_dates()
    {
        $pool = $this->createPoolWithLimitedSingles(3);

        $from = now()->addDays(1);
        $until = now()->addDays(4);
        $this->assertEquals(3, $pool->getPoolMaxQuantity($from, $until), 'No singles should be available after booking');

        $cart = $this->user->currentCart();
        $cart->addToCart($pool, 2, [], $from, $until);

        foreach ($cart->items as $item) {
            $this->assertTrue($item->is_ready_to_checkout, 'Item should be ready before booking');
        }

        $this->assertTrue($cart->is_ready_to_checkout, 'Cart should be ready before booking');
        $cart->checkout();

        $this->assertEquals(1, $pool->getPoolMaxQuantity($from, $until), 'No singles should be available after booking');

        $cart = $this->user->currentCart();
        $cart->addToCart($pool, 3);

        foreach ($cart->items as $item) {
            $this->assertFalse($item->is_ready_to_checkout, 'Item should not be ready after singles are booked');
        }

        $this->assertFalse($cart->is_ready_to_checkout, 'Cart should not be ready after singles are booked');

        $cart->setDates($from, $until);

        // After setting dates where only 1 single is available but we have 3 items,
        // only 1 item should be ready (the first one up to the available capacity)
        $readies = 0;
        foreach ($cart->items as $item) {
            if ($item->is_ready_to_checkout) {
                $readies++;
            }
        }

        $this->assertEquals(1, $readies, '1 item should be ready (1 single available)');
        $this->assertFalse($cart->is_ready_to_checkout);

        $offset = 4;
        $cart->setDates(
            $from->copy()->addDays($offset),
            $until->copy()->addDays($offset)
        );

        $readies = 0;
        foreach ($cart->items as $item) {
            if ($item->is_ready_to_checkout) {
                $readies++;
            }
        }

        $this->assertEquals(3, $readies, '3 items should be ready');
        $this->assertTrue($cart->is_ready_to_checkout);

        $offset = 3;
        $cart->setDates(
            $from->copy()->addDays($offset),
            $until->copy()->addDays($offset)
        );

        $readies = 0;
        foreach ($cart->items as $item) {
            if ($item->is_ready_to_checkout) {
                $readies++;
            }
        }

        // With offset 3, the new period starts exactly when the booked period ends.
        // In hotel-style bookings, checkout day = checkin day does NOT overlap,
        // so all 3 singles should be available.
        $this->assertEquals(3, $readies, '3 items should be ready (no overlap with offset 3)');
        $this->assertTrue($cart->is_ready_to_checkout);

        $offset = 2;
        $cart->setDates(
            $from->copy()->addDays($offset),
            $until->copy()->addDays($offset)
        );

        $readies = 0;
        foreach ($cart->items as $item) {
            if ($item->is_ready_to_checkout) {
                $readies++;
            }
        }

        $this->assertEquals(1, $readies, '1 item should be ready (no overlap with offset 2)');
        $this->assertFalse($cart->is_ready_to_checkout);
    }
}
