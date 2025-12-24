<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Facades\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;


class BookingPerMinutePricingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Product $bookingProduct;
    protected ProductPrice $price;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // Create a booking product
        $this->bookingProduct = Product::factory()->create([
            'name' => 'Conference Room',
            'slug' => 'conference-room',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
            'stock_quantity' => 0,
        ]);

        // Initialize stock
        $this->bookingProduct->increaseStock(10);

        // Create a price: $100.00 per day (24 hours)
        $this->price = ProductPrice::factory()->create([
            'purchasable_id' => $this->bookingProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 10000, // $100 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);
    }

    #[Test]
    public function it_calculates_price_for_exact_24_hours()
    {
        $from = Carbon::now()->addDays(5)->setTime(9, 0, 0);
        $until = Carbon::now()->addDays(6)->setTime(9, 0, 0); // Exactly 24 hours = 1 day

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

        // Expecting exactly 100.00 for 1 day
        $this->assertEquals('10000', $cartItem->price);
        $this->assertEquals(10000, $cartItem->subtotal);
    }

    #[Test]
    public function it_calculates_price_for_12_hours_as_half_day()
    {
        $from = Carbon::now()->addDays(5)->setTime(9, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(21, 0, 0); // 12 hours = 0.5 days

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

        // Expecting 50.00 for 0.5 days (12 hours)
        $this->assertEquals('5000', $cartItem->price);
        $this->assertEquals(5000, $cartItem->subtotal);
    }

    #[Test]
    public function it_calculates_price_for_36_hours()
    {
        $from = Carbon::now()->addDays(5)->setTime(9, 0, 0);
        $until = Carbon::now()->addDays(6)->setTime(21, 0, 0); // 36 hours = 1.5 days

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

        // Expecting 150.00 for 1.5 days (36 hours)
        $this->assertEquals('15000', $cartItem->price);
        $this->assertEquals(15000, $cartItem->subtotal);
    }

    #[Test]
    public function it_calculates_price_for_6_hours()
    {
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(16, 0, 0); // 6 hours = 0.25 days

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

        // Expecting 25.00 for 0.25 days (6 hours)
        $this->assertEquals('2500', $cartItem->price);
        $this->assertEquals(2500, $cartItem->subtotal);
    }

    #[Test]
    public function it_calculates_price_for_90_minutes()
    {
        $from = Carbon::now()->addDays(5)->setTime(14, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(15, 30, 0); // 90 minutes = 1.5 hours = 0.0625 days

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

        // 90 minutes = 1.5 hours = 0.0625 days
        // Price: 100.00 * 0.0625 = 6.25
        $this->assertEquals('625', $cartItem->price);
        $this->assertEquals(625, $cartItem->subtotal);
    }

    #[Test]
    public function it_calculates_price_for_2_days_and_3_hours()
    {
        $from = Carbon::now()->addDays(5)->setTime(9, 0, 0);
        $until = Carbon::now()->addDays(7)->setTime(12, 0, 0); // 2 days + 3 hours = 51 hours = 2.125 days

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

        // 51 hours = 2.125 days
        // Price: 100.00 * 2.125 = 212.50
        $this->assertEquals('21250', $cartItem->price);
        $this->assertEquals(21250, $cartItem->subtotal);
    }

    #[Test]
    public function it_calculates_price_with_quantity_for_fractional_days()
    {
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(22, 0, 0); // 12 hours = 0.5 days

        $cartItem = Cart::addBooking($this->bookingProduct, 3, $from, $until);

        // 0.5 days * 100.00 = 50.00 per unit
        // 3 units * 50.00 = 150.00 total
        $this->assertEquals('5000', $cartItem->price); // price per unit
        $this->assertEquals(15000, $cartItem->subtotal); // total for 3 units
    }

    #[Test]
    public function it_recalculates_price_when_dates_are_updated()
    {
        $from = Carbon::now()->addDays(5)->setTime(9, 0, 0);
        $until = Carbon::now()->addDays(6)->setTime(9, 0, 0); // 24 hours = 1 day

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

        // Initial price for 1 day
        $this->assertEquals('10000', $cartItem->price);

        // Update to 18 hours (0.75 days)
        $newUntil = Carbon::now()->addDays(6)->setTime(3, 0, 0);
        $cartItem->updateDates($from, $newUntil);

        // Price should now be 75.00 for 0.75 days
        $this->assertEquals(7500, $cartItem->fresh()->price);
        $this->assertEquals(7500, $cartItem->fresh()->subtotal);
    }

    #[Test]
    public function it_calculates_price_for_45_minutes()
    {
        $from = Carbon::now()->addDays(5)->setTime(14, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(14, 45, 0); // 45 minutes = 0.03125 days

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

        // 45 minutes = 0.75 hours = 0.03125 days
        // Price: 100.00 * 0.03125 = 3.125
        $this->assertEquals(313, round($cartItem->price, 2)); // Rounded due to decimal precision
    }

    #[Test]
    public function it_purchases_booking_with_per_minute_pricing()
    {
        $from = Carbon::now()->addDays(5)->setTime(14, 0, 0);
        $until = Carbon::now()->addDays(6)->setTime(2, 0, 0); // 12 hours = 0.5 days

        $purchase = $this->user->purchase(
            $this->price,
            2,
            null,
            $from,
            $until
        );

        $this->assertNotNull($purchase);
        $this->assertTrue($purchase->isBooking());

        // Stock should be decreased
        $this->bookingProduct->refresh();
        $this->assertEquals(8, $this->bookingProduct->getAvailableStock());
    }

    #[Test]
    public function it_updates_cart_item_from_date_recalculates_per_minute_price()
    {
        $from = Carbon::now()->addDays(5)->setTime(12, 0, 0);
        $until = Carbon::now()->addDays(6)->setTime(12, 0, 0); // 24 hours

        $cart = \Blax\Shop\Models\Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);
        $cartItem = $cart->addToCart($this->bookingProduct, 1, [], $from, $until);

        // Initial: 1 day = 100.00
        $this->assertEquals('10000', $cartItem->price);

        // Update from date to make it 30 hours (1.25 days)
        $newFrom = Carbon::now()->addDays(5)->setTime(6, 0, 0);
        $cartItem->setFromDate($newFrom);

        // Price should be 125.00 for 1.25 days
        $this->assertEquals(12500, $cartItem->fresh()->price);
    }

    #[Test]
    public function it_updates_cart_item_until_date_recalculates_per_minute_price()
    {
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(22, 0, 0); // 12 hours

        $cart = \Blax\Shop\Models\Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);
        $cartItem = $cart->addToCart($this->bookingProduct, 1, [], $from, $until);

        // Initial: 0.5 days = 50.00
        $this->assertEquals('5000', $cartItem->price);

        // Update until date to make it 18 hours (0.75 days)
        $newUntil = Carbon::now()->addDays(6)->setTime(4, 0, 0);
        $cartItem->setUntilDate($newUntil);

        // Price should be 75.00 for 0.75 days
        $this->assertEquals(7500, $cartItem->fresh()->price);
    }

    #[Test]
    public function it_calculates_price_for_weekend_booking_friday_to_monday()
    {
        // Friday 6pm to Monday 10am = 64 hours = 2.666... days
        $from = Carbon::now()->addDays(5)->setTime(18, 0, 0); // Friday 6pm
        $until = Carbon::now()->addDays(8)->setTime(10, 0, 0); // Monday 10am

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

        // 64 hours = 2.6667 days (rounded)
        // Price: 100.00 * 2.6667 = 266.67
        $expectedPrice = round(10000 * (64 / 24), 0);
        $this->assertEquals($expectedPrice, $cartItem->price);
    }

    #[Test]
    public function it_handles_multiple_bookings_with_different_durations()
    {
        $cart = \Blax\Shop\Models\Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        // Booking 1: 12 hours
        $from1 = Carbon::now()->addDays(5)->setTime(9, 0, 0);
        $until1 = Carbon::now()->addDays(5)->setTime(21, 0, 0);
        $item1 = $cart->addToCart($this->bookingProduct, 1, [], $from1, $until1);

        // Booking 2: 6 hours
        $from2 = Carbon::now()->addDays(10)->setTime(10, 0, 0);
        $until2 = Carbon::now()->addDays(10)->setTime(16, 0, 0);
        $item2 = $cart->addToCart($this->bookingProduct, 1, [], $from2, $until2);

        $this->assertEquals('5000', $item1->price); // 12 hours = 0.5 days
        $this->assertEquals('2500', $item2->price); // 6 hours = 0.25 days

        // Total cart should be 75.00
        $this->assertEquals(7500, $cart->getTotal());
    }

    #[Test]
    public function it_calculates_precise_price_for_irregular_time_spans()
    {
        // Test various odd time spans
        $testCases = [
            ['hours' => 1, 'expectedDays' => 1 / 24, 'expectedPrice' => 417], // 1 hour
            ['hours' => 3, 'expectedDays' => 3 / 24, 'expectedPrice' => 1250], // 3 hours
            ['hours' => 7, 'expectedDays' => 7 / 24, 'expectedPrice' => 2917], // 7 hours
            ['hours' => 13, 'expectedDays' => 13 / 24, 'expectedPrice' => 5417], // 13 hours
            ['hours' => 25, 'expectedDays' => 25 / 24, 'expectedPrice' => 10417], // 25 hours
        ];

        foreach ($testCases as $testCase) {
            $from = Carbon::now()->addDays(5)->setTime(12, 0, 0);
            $until = $from->copy()->addHours($testCase['hours']);

            $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

            $this->assertEquals(
                $testCase['expectedPrice'],
                $cartItem->price,
                "Failed for {$testCase['hours']} hours"
            );

            // Clean up for next test
            $cartItem->delete();
        }
    }

    #[Test]
    public function it_handles_booking_removal_and_readdition()
    {
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(16, 0, 0); // 6 hours

        $cartItem = Cart::addBooking($this->bookingProduct, 2, $from, $until);

        // Verify per-minute pricing is correct
        $this->assertEquals('2500', $cartItem->price);
        $this->assertEquals(2, $cartItem->quantity);
        $this->assertEquals(5000, $cartItem->subtotal);

        // Remove item
        $cartItemId = $cartItem->id;
        $cartItem->delete();

        // Verify deletion
        $this->assertNull(\Blax\Shop\Models\CartItem::find($cartItemId));

        // Re-add with different quantity - price calculation should be consistent
        $cartItem2 = Cart::addBooking($this->bookingProduct, 3, $from, $until);
        $this->assertEquals('2500', $cartItem2->price);
        $this->assertEquals(3, $cartItem2->quantity);
        $this->assertEquals(7500, $cartItem2->subtotal);
    }

    #[Test]
    public function it_calculates_price_for_half_hour_increments()
    {
        $testCases = [
            ['minutes' => 30, 'expectedPrice' => '208'],   // 0.5 hours
            ['minutes' => 90, 'expectedPrice' => '625'],   // 1.5 hours
            ['minutes' => 150, 'expectedPrice' => '1042'],  // 2.5 hours
            ['minutes' => 210, 'expectedPrice' => '1458'],  // 3.5 hours
            ['minutes' => 270, 'expectedPrice' => '1875'],  // 4.5 hours
        ];

        foreach ($testCases as $testCase) {
            $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
            $until = $from->copy()->addMinutes($testCase['minutes']);

            $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

            $this->assertEquals(
                $testCase['expectedPrice'],
                $cartItem->price,
                "Failed for {$testCase['minutes']} minutes"
            );

            $cartItem->delete();
        }
    }

    #[Test]
    public function it_handles_exact_hour_boundaries()
    {
        // Test exact hour boundaries from 1-10 hours
        for ($hours = 1; $hours <= 10; $hours++) {
            $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
            $until = $from->copy()->addHours($hours);

            $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

            $expectedDays = $hours / 24;
            $expectedPrice = number_format(10000 * $expectedDays, 0, '.', '');

            $this->assertEquals(
                $expectedPrice,
                $cartItem->price,
                "Failed for {$hours} hours"
            );

            $cartItem->delete();
        }
    }

    #[Test]
    public function it_maintains_price_consistency_across_cart_operations()
    {
        $from = Carbon::now()->addDays(5)->setTime(14, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(20, 0, 0); // 6 hours

        // Add to cart
        $cart = $this->user->currentCart();
        $cartItem = $cart->addToCart($this->bookingProduct, 1, [], $from, $until);
        $initialPrice = $cartItem->price;

        // Refresh and check price hasn't changed
        $cartItem->refresh();
        $this->assertEquals($initialPrice, $cartItem->price);

        // Get cart total
        $total = $cart->getTotal();
        $this->assertEquals(2500, $total);

        // Add more quantity
        $cart->addToCart($this->bookingProduct, 1, [], $from, $until);
        $cart->refresh();

        // Total should double
        $this->assertEquals(5000, $cart->getTotal());
    }

    #[Test]
    public function it_handles_very_long_booking_periods()
    {
        // 7 days = 168 hours
        $from = Carbon::now()->addDays(5)->setTime(12, 0, 0);
        $until = Carbon::now()->addDays(12)->setTime(12, 0, 0);

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

        // 7 days * $100 = $700.00
        $this->assertEquals('70000', $cartItem->price);
        $this->assertEquals(70000, $cartItem->subtotal);
    }

    #[Test]
    public function it_calculates_price_for_7_hour_30_minute_booking()
    {
        $from = Carbon::now()->addDays(5)->setTime(9, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(16, 30, 0); // 7.5 hours

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

        // 7.5 hours = 0.3125 days, $100 * 0.3125 = $31.25
        $this->assertEquals('3125', $cartItem->price);
    }

    #[Test]
    public function it_handles_booking_crossing_midnight()
    {
        // 11 PM to 3 AM = 4 hours
        $from = Carbon::now()->addDays(5)->setTime(23, 0, 0);
        $until = Carbon::now()->addDays(6)->setTime(3, 0, 0);

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

        // 4 hours = 0.166667 days, $100 * 0.166667 = $16.67
        $this->assertEquals('1667', $cartItem->price);
    }

    #[Test]
    public function it_validates_minimum_price_for_very_short_bookings()
    {
        // 2 minutes
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(5)->setTime(10, 2, 0);

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

        // 2 minutes = 0.001389 days, $100 * 0.001389 = $0.1389, rounds to $0.14
        $this->assertEquals('14', $cartItem->price);

        // Should still be less than 1 dollar
        $this->assertLessThan(100, (float)$cartItem->price);
    }

    #[Test]
    public function it_handles_15_minute_interval_bookings()
    {
        $intervals = [15, 30, 45, 60, 75, 90, 105, 120];

        foreach ($intervals as $minutes) {
            $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
            $until = $from->copy()->addMinutes($minutes);

            $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

            $expectedDays = $minutes / 1440;
            $expectedPrice = number_format(10000 * $expectedDays, 0, '.', '');

            $this->assertEquals(
                $expectedPrice,
                $cartItem->price,
                "Failed for {$minutes} minutes"
            );

            $cartItem->delete();
        }
    }

    #[Test]
    public function it_does_only_adjust_price_for_booking_not_for_other_products()
    {
        // 2 minutes
        $from = Carbon::now()->addDays(5)->setTime(10, 0, 0);
        $until = Carbon::now()->addDays(6)->setTime(10, 2, 0);

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);
        $cart = Cart::current();

        $cart->setDates($from, $until);

        $cart->refresh();

        // 2 minutes = 0.001389 days, $100 * 0.001389 = $0.1389, rounds to $0.14
        $this->assertEquals(10014, $cartItem->price);

        $single_product = Product::factory()
            ->withStocks(5)
            ->withPrices(1, 5000) // $50.00
            ->create([
                'name' => 'Wine Bottle',
                'slug' => 'wine-bottle',
                'type' => ProductType::SIMPLE,
                'manage_stock' => true,
            ]);

        $this->assertEquals(5, $single_product->getAvailableStock());
        $this->assertEquals(5000, $single_product->getCurrentPrice());

        $this->assertEquals(1, $cart->items()->count());
        $this->assertEquals(10014, $cart->getTotal());

        $cart->addToCart($single_product, 1);

        $this->assertEquals(2, $cart->items()->count());
        $this->assertEquals(15014, $cart->getTotal());

        $cart->addToCart($single_product, 1);

        $this->assertEquals(2, $cart->items()->count());
        $this->assertEquals(20014, $cart->getTotal());


        $until = $until->copy()->addDays(10);

        $cart->setDates($from, $until);

        $cart->refresh();

        $this->assertEquals(110014, $cartItem->fresh()->price);
        $this->assertEquals(5000, $single_product->getCurrentPrice());
        $this->assertEquals(120014, $cart->getTotal());
    }
}
