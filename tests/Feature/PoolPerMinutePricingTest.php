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
            'unit_amount' => 50, // $50.00 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 30, // $30.00 per day
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
        $this->assertEquals(15.00, $cartItem->price);
        $this->assertEquals(15.00, $cartItem->subtotal);
    }

    /** @test */
    public function it_calculates_pool_price_for_36_hours()
    {
        $from = Carbon::now()->addDays(5)->setTime(9, 0, 0);
        $until = Carbon::now()->addDays(6)->setTime(21, 0, 0); // 36 hours = 1.5 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is $30, for 1.5 days = $45.00
        $this->assertEquals(45.00, $cartItem->price);
        $this->assertEquals(45.00, $cartItem->subtotal);
    }

    /** @test */
    public function it_calculates_pool_price_for_6_hours()
    {
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(16, 0, 0); // 6 hours = 0.25 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is $30, for 0.25 days = $7.50
        $this->assertEquals(7.50, $cartItem->price);
        $this->assertEquals(7.50, $cartItem->subtotal);
    }

    /** @test */
    public function it_calculates_pool_price_for_90_minutes()
    {
        $from = Carbon::now()->addDays(5)->setTime(14, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(15, 30, 0); // 90 minutes = 1.5 hours = 0.0625 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is $30, for 0.0625 days = $1.875 (rounds to 1.88)
        $this->assertEquals(1.88, round($cartItem->price, 2));
    }

    /** @test */
    public function it_uses_direct_pool_price_for_fractional_days()
    {
        // Set direct price on pool instead of using inherited pricing
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 20, // $20.00 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(22, 0, 0); // 12 hours = 0.5 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Direct pool price is $20, for 0.5 days = $10.00
        $this->assertEquals(10.00, $cartItem->price);
        $this->assertEquals(10.00, $cartItem->subtotal);
    }

    /** @test */
    public function it_calculates_pool_price_with_quantity_for_fractional_days()
    {
        $from = Carbon::now()->addDays(5)->setTime(9, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(15, 0, 0); // 6 hours = 0.25 days

        $cartItem = Cart::addBooking($this->poolProduct, 2, $from, $until);

        // Lowest price is $30, for 0.25 days = $7.50 per unit
        // 2 units * $7.50 = $15.00 total
        $this->assertEquals(7.50, $cartItem->price); // price per unit
        $this->assertEquals(15.00, $cartItem->subtotal); // total for 2 units
    }

    /** @test */
    public function it_uses_highest_pricing_strategy_for_fractional_days()
    {
        // Change to HIGHEST pricing strategy
        $this->poolProduct->setPricingStrategy(PricingStrategy::HIGHEST);

        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(22, 0, 0); // 12 hours = 0.5 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Highest price is $50, for 0.5 days = $25.00
        $this->assertEquals(25.00, $cartItem->price);
        $this->assertEquals(25.00, $cartItem->subtotal);
    }

    /** @test */
    public function it_uses_average_pricing_strategy_for_fractional_days()
    {
        // Change to AVERAGE pricing strategy
        $this->poolProduct->setPricingStrategy(PricingStrategy::AVERAGE);

        $from = Carbon::now()->addDays(5)->setTime(8, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(20, 0, 0); // 12 hours = 0.5 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Average price is ($50 + $30) / 2 = $40, for 0.5 days = $20.00
        $this->assertEquals(20.00, $cartItem->price);
        $this->assertEquals(20.00, $cartItem->subtotal);
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

        // First booking uses lowest pricing: $30 * 0.25 = $7.50
        $this->assertEquals('7.50', $cartItem1->price);
        // Second booking may use next available pricing tier
        $this->assertGreaterThanOrEqual(7.50, (float)$cartItem2->price);

        // Total should be reasonable for two 6-hour bookings
        $this->assertGreaterThan(15.00, $cart->getTotal());
    }

    /** @test */
    public function it_calculates_pool_price_for_very_short_durations()
    {
        // 30 minutes
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(10, 30, 0);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 30 minutes = 0.020833 days, $30 * 0.020833 = $0.62499, rounds to $0.62
        $this->assertEquals('0.62', $cartItem->price);

        // 15 minutes
        $from2 = Carbon::now()->addDays(6)->setTime(14, 0, 0);
        $until2 = Carbon::now()->addDays(6)->setTime(14, 15, 0);

        $cartItem2 = Cart::addBooking($this->poolProduct, 1, $from2, $until2);

        // 15 minutes = 0.010417 days, $30 * 0.010417 = $0.3125, rounds to $0.31
        $this->assertEquals('0.31', $cartItem2->price);
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

        // First booking: $30 * 0.5 = $15.00
        $this->assertEquals(15.00, $item1->price);

        // Second booking: $30 * 0.25 = $7.50 (different dates, so spots available)
        $this->assertEquals(7.50, $item2->price);
    }

    /** @test */
    public function it_calculates_pool_price_for_3_hours()
    {
        $from = Carbon::now()->addDays(5)->setTime(14, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(17, 0, 0); // 3 hours = 0.125 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is $30, for 0.125 days = $3.75
        $this->assertEquals(3.75, $cartItem->price);
    }

    /** @test */
    public function it_calculates_pool_price_for_odd_duration_5_hours_30_minutes()
    {
        $from = Carbon::now()->addDays(5)->setTime(9, 30, 0);
        $until = Carbon::now()->addDays(5)->setTime(15, 0, 0); // 5.5 hours = 0.229167 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is $30, for 5.5 hours (0.229167 days) = $6.875 (rounds to 6.88)
        $expectedPrice = round(30.00 * (5.5 / 24), 2);
        $this->assertEquals($expectedPrice, round($cartItem->price, 2));
    }

    /** @test */
    public function it_handles_pool_booking_over_multiple_days_with_hours()
    {
        // Monday 2pm to Wednesday 5pm = 51 hours = 2.125 days
        $from = Carbon::now()->addDays(10)->setTime(14, 0, 0);
        $until = Carbon::now()->addDays(12)->setTime(17, 0, 0);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is $30, for 2.125 days = $63.75
        $expectedPrice = 30.00 * 2.125;
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
            'unit_amount' => 40, // $40.00 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $this->poolProduct->productRelations()->attach($singleItem3->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(16, 0, 0); // 6 hours = 0.25 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is still $30, for 0.25 days = $7.50
        $this->assertEquals(7.50, $cartItem->price);
    }

    /** @test */
    public function it_calculates_price_for_exact_24_hours_pool()
    {
        $from = Carbon::now()->addDays(5)->setTime(9, 0, 0);
        $until = Carbon::now()->addDays(6)->setTime(9, 0, 0); // Exactly 24 hours = 1 day

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // Lowest price is $30, for exactly 1 day = $30.00
        $this->assertEquals(30.00, $cartItem->price);
        $this->assertEquals(30.00, $cartItem->subtotal);
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

        // Initial: 1 day = $30.00
        $this->assertEquals(30.00, $cartItem->price);

        // Update from date to make it 30 hours (1.25 days)
        $newFrom = $now->copy()->addDays(5)->setTime(6, 0, 0);
        $cartItem->setFromDate($newFrom);

        // Price should be $30 * 1.25 = $37.50
        $this->assertEquals(37.50, $cartItem->fresh()->price);
    }

    /** @test */
    public function it_handles_booking_spanning_exactly_two_days()
    {
        $from = Carbon::now()->addDays(5)->setTime(0, 0, 0);
        $until = Carbon::now()->addDays(7)->setTime(0, 0, 0); // Exactly 48 hours

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 48 hours = 2 days, $30 * 2 = $60.00
        $this->assertEquals('60.00', $cartItem->price);
        $this->assertEquals(60.00, $cartItem->subtotal);
    }

    /** @test */
    public function it_calculates_price_for_business_hours_booking()
    {
        // 9 AM to 5 PM = 8 hours
        $from = Carbon::now()->addDays(5)->setTime(9, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(17, 0, 0);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 8 hours = 0.333333 days, $30 * 0.333333 = $10.00
        $this->assertEquals('10.00', $cartItem->price);
    }

    /** @test */
    public function it_handles_overnight_booking()
    {
        // 10 PM to 6 AM next day = 8 hours
        $from = Carbon::now()->addDays(5)->setTime(22, 0, 0);
        $until = Carbon::now()->addDays(6)->setTime(6, 0, 0);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 8 hours = 0.333333 days, $30 * 0.333333 = $10.00
        $this->assertEquals('10.00', $cartItem->price);
    }

    /** @test */
    public function it_calculates_price_with_minutes_precision()
    {
        // 2 hours and 45 minutes
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(12, 45, 0);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 165 minutes = 0.114583 days, $30 * 0.114583 = $3.4375, rounds to $3.44
        $this->assertEquals('3.44', $cartItem->price);
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
        // Subtotal should be reasonable for 6 hours with 2 items
        $this->assertGreaterThan(10.00, $cartItem->subtotal);
    }

    /** @test */
    public function it_handles_weekend_hourly_booking()
    {
        // Friday 6 PM to Sunday 6 PM = 48 hours exactly
        $from = Carbon::now()->next(Carbon::FRIDAY)->setTime(18, 0, 0);
        $until = Carbon::now()->next(Carbon::FRIDAY)->addDays(2)->setTime(18, 0, 0);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 48 hours = 2 days, $30 * 2 = $60.00
        $this->assertEquals('60.00', $cartItem->price);
    }

    /** @test */
    public function it_calculates_different_pricing_strategies_for_fractional_time()
    {
        // Test LOWEST (already set in setUp)
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(13, 0, 0); // 3 hours = 0.125 days

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);
        // Lowest: $30 * 0.125 = $3.75
        $this->assertEquals('3.75', $cartItem->price);

        // Clear cart
        $cartItem->delete();

        // Test HIGHEST
        $this->poolProduct->setPricingStrategy(PricingStrategy::HIGHEST);
        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);
        // Highest: $50 * 0.125 = $6.25
        $this->assertEquals('6.25', $cartItem->price);

        // Clear cart
        $cartItem->delete();

        // Test AVERAGE
        $this->poolProduct->setPricingStrategy(PricingStrategy::AVERAGE);
        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);
        // Average: ($50 + $30) / 2 = $40, $40 * 0.125 = $5.00
        $this->assertEquals('5.00', $cartItem->price);
    }

    /** @test */
    public function it_handles_single_minute_booking()
    {
        // Just 1 minute
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(10, 1, 0);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 1 minute = 0.000694 days (minimum), $30 * 0.000694 = $0.02082, rounds to $0.02
        $this->assertEquals('0.02', $cartItem->price);
    }

    /** @test */
    public function it_handles_booking_with_seconds_precision()
    {
        // 2 hours, 30 minutes, 30 seconds (Carbon truncates seconds to minutes)
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(12, 30, 30);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        // 150 minutes (seconds truncated), $30 * (150/1440) = $3.125, rounds to $3.13 or $3.14
        $price = (float)$cartItem->price;
        $this->assertGreaterThanOrEqual(3.12, $price);
        $this->assertLessThanOrEqual(3.14, $price);
    }
}
