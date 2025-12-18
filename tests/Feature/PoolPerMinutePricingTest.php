<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PricingStrategy;
use Blax\Shop\Facades\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;

class PoolPerMinutePricingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Product $poolProduct;
    protected Product $singleItem1;
    protected Product $singleItem2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // Create pool product
        $this->poolProduct = Product::factory()->create([
            'name' => 'Parking Pool',
            'slug' => 'parking-pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create single items (parking spots)
        $this->singleItem1 = Product::factory()->create([
            'name' => 'Parking Spot A',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $this->singleItem1->increaseStock(1);

        $this->singleItem2 = Product::factory()->create([
            'name' => 'Parking Spot B',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $this->singleItem2->increaseStock(1);

        // Link single items to pool
        $this->poolProduct->productRelations()->attach($this->singleItem1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
        $this->poolProduct->productRelations()->attach($this->singleItem2->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        // Set prices on single items: $50 and $30 per day
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000, // $50.00 per day (in cents)
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000, // $30.00 per day (in cents)
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Set pool to use LOWEST pricing strategy (default)
        $this->poolProduct->setPricingStrategy(PricingStrategy::LOWEST);
    }

    /** @test */
    public function it_calculates_pool_price_for_12_hours()
    {
        $from = Carbon::now()->addDays(5)->setTime(8, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(20, 0, 0); // 12 hours = 0.5 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is $30, for 0.5 days = $15.00
        $this->assertEquals(1500, $cartItem->price);
        $this->assertEquals(1500, $cartItem->subtotal);
    }

    /** @test */
    public function it_calculates_pool_price_for_36_hours()
    {
        $from = Carbon::now()->addDays(5)->setTime(9, 0, 0);
        $until = Carbon::now()->addDays(6)->setTime(21, 0, 0); // 36 hours = 1.5 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is $30, for 1.5 days = $45.00
        $this->assertEquals(4500, $cartItem->price);
        $this->assertEquals(4500, $cartItem->subtotal);
    }

    /** @test */
    public function it_calculates_pool_price_for_6_hours()
    {
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(16, 0, 0); // 6 hours = 0.25 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is $30, for 0.25 days = $7.50
        $this->assertEquals(750, $cartItem->price);
        $this->assertEquals(750, $cartItem->subtotal);
    }

    /** @test */
    public function it_calculates_pool_price_for_90_minutes()
    {
        $from = Carbon::now()->addDays(5)->setTime(14, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(15, 30, 0); // 90 minutes = 1.5 hours = 0.0625 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is $30, for 0.0625 days = $1.875 (rounds to 1.88)
        $this->assertEquals(188, round($cartItem->price, 2));
    }

    /** @test */
    public function it_uses_direct_pool_price_for_fractional_days()
    {
        // Set direct price on pool instead of using inherited pricing
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000, // $20.00 per day (in cents)
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(22, 0, 0); // 12 hours = 0.5 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Direct pool price is $20.00 (2000 cents), for 0.5 days = $10.00 (1000 cents)
        $this->assertEquals(1000, $cartItem->price);
        $this->assertEquals(1000, $cartItem->subtotal);
    }

    /** @test */
    public function it_calculates_pool_price_with_quantity_for_fractional_days()
    {
        $from = Carbon::now()->addDays(5)->setTime(9, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(15, 0, 0); // 6 hours = 0.25 days

        $cartItem = Cart::addBooking($this->poolProduct, 2, $from, $until);

        // Lowest price is $30.00 (3000 cents), for 0.25 days = $7.50 (750 cents) per unit
        // 2 units * 750 cents = 1500 cents total
        $this->assertEquals(750, $cartItem->price); // price per unit
        $this->assertEquals(1500, $cartItem->subtotal); // total for 2 units
    }

    /** @test */
    public function it_uses_highest_pricing_strategy_for_fractional_days()
    {
        // Change to HIGHEST pricing strategy
        $this->poolProduct->setPricingStrategy(PricingStrategy::HIGHEST);

        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(22, 0, 0); // 12 hours = 0.5 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Highest price is $50.00 (5000 cents), for 0.5 days = $25.00 (2500 cents)
        $this->assertEquals(2500, $cartItem->price);
        $this->assertEquals(2500, $cartItem->subtotal);
    }

    /** @test */
    public function it_uses_average_pricing_strategy_for_fractional_days()
    {
        // Change to AVERAGE pricing strategy
        $this->poolProduct->setPricingStrategy(PricingStrategy::AVERAGE);

        $from = Carbon::now()->addDays(5)->setTime(8, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(20, 0, 0); // 12 hours = 0.5 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Average price is (5000 + 3000) / 2 = 4000 cents ($40.00), for 0.5 days = $20.00 (2000 cents)
        $this->assertEquals(2000, $cartItem->price);
        $this->assertEquals(2000, $cartItem->subtotal);
    }

    /** @test */
    public function it_calculates_pool_price_for_multiple_fractional_bookings_in_cart()
    {
        $from1 = Carbon::now()->addDays(10)->setTime(10, 0, 0);
        $until1 = Carbon::now()->addDays(10)->setTime(16, 0, 0); // 6 hours = 0.25 days

        $from2 = Carbon::now()->addDays(12)->setTime(14, 0, 0);
        $until2 = Carbon::now()->addDays(12)->setTime(20, 0, 0); // 6 hours = 0.25 days

        $cart = \Blax\Shop\Models\Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        // Add two different fractional bookings
        $cartItem1 = $cart->addToCart($this->poolProduct, 1, [], $from1, $until1);
        $cartItem2 = $cart->addToCart($this->poolProduct, 1, [], $from2, $until2);

        // First booking uses lowest pricing: 3000 cents * 0.25 = 750 cents ($7.50)
        $this->assertEquals(750, $cartItem1->price);
        // Second booking may use next available pricing tier
        $this->assertGreaterThanOrEqual(750, (int)$cartItem2->price);

        // Total should be reasonable for two 6-hour bookings
        $this->assertGreaterThan(1500, $cart->getTotal());
    }

    /** @test */
    public function it_calculates_pool_price_for_very_short_durations()
    {
        // 30 minutes
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(10, 30, 0);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 30 minutes = 0.020833 days, 3000 cents * 0.020833 = 62.499 cents, rounds to 62 cents
        $this->assertEquals(62, $cartItem->price);

        // 15 minutes
        $from2 = Carbon::now()->addDays(6)->setTime(14, 0, 0);
        $until2 = Carbon::now()->addDays(6)->setTime(14, 15, 0);

        $cartItem2 = Cart::addBooking($this->poolProduct, 1, $from2, $until2);

        // 15 minutes = 0.010417 days, 3000 cents * 0.010417 = 31.25 cents, rounds to 31 cents
        $this->assertEquals(31, $cartItem2->price);
    }

    /** @test */
    public function it_handles_multiple_pool_bookings_with_different_durations()
    {
        $cart = \Blax\Shop\Models\Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        // Booking 1: 12 hours
        $from1 = Carbon::now()->addDays(5)->setTime(9, 0, 0);
        $until1 = Carbon::now()->addDays(5)->setTime(21, 0, 0);
        $item1 = $cart->addToCart($this->poolProduct, 1, [], $from1, $until1);

        // Booking 2: 6 hours (different dates, so spots available)
        $from2 = Carbon::now()->addDays(10)->setTime(10, 0, 0);
        $until2 = Carbon::now()->addDays(10)->setTime(16, 0, 0);
        $item2 = $cart->addToCart($this->poolProduct, 1, [], $from2, $until2);

        // First booking: 3000 cents * 0.5 = 1500 cents ($15.00)
        $this->assertEquals(1500, $item1->price);

        // Second booking: 3000 cents * 0.25 = 750 cents ($7.50) (different dates, so spots available)
        $this->assertEquals(750, $item2->price);
    }

    /** @test */
    public function it_calculates_pool_price_for_3_hours()
    {
        $from = Carbon::now()->addDays(5)->setTime(14, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(17, 0, 0); // 3 hours = 0.125 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is 3000 cents, for 0.125 days = 375 cents ($3.75)
        $this->assertEquals(375, $cartItem->price);
    }

    /** @test */
    public function it_calculates_pool_price_for_odd_duration_5_hours_30_minutes()
    {
        $from = Carbon::now()->addDays(5)->setTime(9, 30, 0);
        $until = Carbon::now()->addDays(5)->setTime(15, 0, 0); // 5.5 hours = 0.229167 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is 3000 cents, for 5.5 hours (0.229167 days) = 687.5 cents, rounds to 688 cents ($6.88)
        $expectedPrice = round(3000 * (5.5 / 24));
        $this->assertEquals($expectedPrice, round($cartItem->price));
    }

    /** @test */
    public function it_handles_pool_booking_over_multiple_days_with_hours()
    {
        // Monday 2pm to Wednesday 5pm = 51 hours = 2.125 days
        $from = Carbon::now()->addDays(10)->setTime(14, 0, 0);
        $until = Carbon::now()->addDays(12)->setTime(17, 0, 0);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is 3000 cents, for 2.125 days = 6375 cents ($63.75)
        $expectedPrice = 3000 * 2.125;
        $this->assertEquals($expectedPrice, $cartItem->price);
    }

    /** @test */
    public function it_prices_pool_correctly_when_both_spots_have_different_prices_for_fractional_time()
    {
        // Create a third spot with an even different price
        $singleItem3 = Product::factory()->create([
            'name' => 'Parking Spot C',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $singleItem3->increaseStock(1);

        ProductPrice::factory()->create([
            'purchasable_id' => $singleItem3->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 4000, // $40.00 per day (in cents)
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $this->poolProduct->productRelations()->attach($singleItem3->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(16, 0, 0); // 6 hours = 0.25 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is still 3000 cents, for 0.25 days = 750 cents ($7.50)
        $this->assertEquals(750, $cartItem->price);
    }

    /** @test */
    public function it_calculates_price_for_exact_24_hours_pool()
    {
        $from = Carbon::now()->addDays(5)->setTime(9, 0, 0);
        $until = Carbon::now()->addDays(6)->setTime(9, 0, 0); // Exactly 24 hours = 1 day

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is 3000 cents, for exactly 1 day = 3000 cents ($30.00)
        $this->assertEquals(3000, $cartItem->price);
        $this->assertEquals(3000, $cartItem->subtotal);
    }

    /** @test */
    public function it_updates_pool_cart_item_from_date_recalculates_per_minute_price()
    {
        $now = Carbon::now();
        $from = $now->copy()->addDays(5)->setTime(12, 0, 0);
        $until = $now->copy()->addDays(6)->setTime(12, 0, 0); // 24 hours

        $cart = \Blax\Shop\Models\Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);
        $cartItem = $cart->addToCart($this->poolProduct, 1, [], $from, $until);

        // Initial: 1 day = 3000 cents ($30.00)
        $this->assertEquals(3000, $cartItem->price);

        // Update from date to make it 30 hours (1.25 days)
        $newFrom = $now->copy()->addDays(5)->setTime(6, 0, 0);
        $cartItem->setFromDate($newFrom);

        // Price should be 3000 cents * 1.25 = 3750 cents ($37.50)
        $this->assertEquals(3750, $cartItem->fresh()->price);
    }

    /** @test */
    public function it_handles_booking_spanning_exactly_two_days()
    {
        $from = Carbon::now()->addDays(5)->setTime(0, 0, 0);
        $until = Carbon::now()->addDays(7)->setTime(0, 0, 0); // Exactly 48 hours

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 48 hours = 2 days, 3000 cents * 2 = 6000 cents ($60.00)
        $this->assertEquals(6000, $cartItem->price);
        $this->assertEquals(6000, $cartItem->subtotal);
    }

    /** @test */
    public function it_calculates_price_for_business_hours_booking()
    {
        // 9 AM to 5 PM = 8 hours
        $from = Carbon::now()->addDays(5)->setTime(9, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(17, 0, 0);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 8 hours = 0.333333 days, 3000 cents * 0.333333 = 1000 cents ($10.00)
        $this->assertEquals(1000, $cartItem->price);
    }

    /** @test */
    public function it_handles_overnight_booking()
    {
        // 10 PM to 6 AM next day = 8 hours
        $from = Carbon::now()->addDays(5)->setTime(22, 0, 0);
        $until = Carbon::now()->addDays(6)->setTime(6, 0, 0);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 8 hours = 0.333333 days, 3000 cents * 0.333333 = 1000 cents ($10.00)
        $this->assertEquals(1000, $cartItem->price);
    }

    /** @test */
    public function it_calculates_price_with_minutes_precision()
    {
        // 2 hours and 45 minutes
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(12, 45, 0);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 165 minutes = 0.114583 days, 3000 cents * 0.114583 = 343.75 cents, rounds to 344 cents ($3.44)
        $this->assertEquals(344, $cartItem->price);
    }

    /** @test */
    public function it_maintains_precision_for_multiple_quantity()
    {
        // 6 hours
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(16, 0, 0);

        // Pool has 2 items, so max quantity is 2
        $cartItem = Cart::addBooking($this->poolProduct, 2, $from, $until);

        // 6 hours = 0.25 days, price per unit varies by pool pricing
        $this->assertEquals(2, $cartItem->quantity);
        // Subtotal should be reasonable for 6 hours with 2 items (at least 1000 cents = $10.00)
        $this->assertGreaterThan(1000, $cartItem->subtotal);
    }

    /** @test */
    public function it_handles_weekend_hourly_booking()
    {
        // Friday 6 PM to Sunday 6 PM = 48 hours exactly
        $from = Carbon::now()->next(Carbon::FRIDAY)->setTime(18, 0, 0);
        $until = Carbon::now()->next(Carbon::FRIDAY)->addDays(2)->setTime(18, 0, 0);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 48 hours = 2 days, 3000 cents * 2 = 6000 cents ($60.00)
        $this->assertEquals(6000, $cartItem->price);
    }

    /** @test */
    public function it_calculates_different_pricing_strategies_for_fractional_time()
    {
        // Test LOWEST (already set in setUp)
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(13, 0, 0); // 3 hours = 0.125 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 3000 cents * 0.125 = 375 cents ($3.75)
        $this->assertEquals(375, $cartItem->price);

        // Clear cart
        $cartItem->delete();

        // Test HIGHEST
        $this->poolProduct->setPricingStrategy(PricingStrategy::HIGHEST);
        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 5000 cents * 0.125 = 625 cents ($6.25)
        $this->assertEquals(625, $cartItem->price);

        // Clear cart
        $cartItem->delete();

        // Test AVERAGE
        $this->poolProduct->setPricingStrategy(PricingStrategy::AVERAGE);
        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // (5000 + 3000) / 2 * 0.125 = 4000 * 0.125 = 500 cents ($5.00)
        $this->assertEquals(500, $cartItem->price);
    }

    /** @test */
    public function it_handles_single_minute_booking()
    {
        // Just 1 minute
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(10, 1, 0);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 1 minute = 0.000694 days (minimum), 3000 cents * 0.000694 = 2.08 cents, rounds to 2 cents
        $this->assertEquals(2, $cartItem->price);
    }

    /** @test */
    public function it_handles_booking_with_seconds_precision()
    {
        // 2 hours, 30 minutes, 30 seconds (Carbon truncates seconds to minutes)
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(12, 30, 30);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 2.5 hours = 0.104167 days, 3000 cents * 0.104167 = 312.5 cents, rounds to 313 cents
        $price = $cartItem->price;
        $this->assertGreaterThanOrEqual(312, $price);
        $this->assertLessThanOrEqual(314, $price);
    }
}
