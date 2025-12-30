<?php

namespace Blax\Shop\Tests\Feature\Booking;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;

class BookingFeatureTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Product $bookingProduct;
    protected ProductPrice $price;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Create a booking product
        $this->bookingProduct = Product::factory()->create([
            'name' => 'Hotel Room',
            'slug' => 'hotel-room',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
            'stock_quantity' => 0,
        ]);

        // Initialize stock
        $this->bookingProduct->increaseStock(10);

        // Create a price
        $this->price = ProductPrice::factory()->create([
            'purchasable_id' => $this->bookingProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 15000, // $150.00
            'currency' => 'USD',
            'is_default' => true,
        ]);
    }

    #[Test]
    public function it_can_create_a_booking_product()
    {
        $this->assertNotNull($this->bookingProduct);
        $this->assertEquals(ProductType::BOOKING, $this->bookingProduct->type);
        $this->assertTrue($this->bookingProduct->isBooking());
    }

    #[Test]
    public function it_can_purchase_a_booking_with_dates()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $purchase = $this->user->purchase(
            $this->price,
            1,
            null,
            $from,
            $until
        );

        $this->assertNotNull($purchase);
        $this->assertEquals($this->bookingProduct->id, $purchase->purchasable_id);
        $this->assertTrue($purchase->isBooking());
        $this->assertEquals($from->format('Y-m-d H:i:s'), $purchase->from->format('Y-m-d H:i:s'));
        $this->assertEquals($until->format('Y-m-d H:i:s'), $purchase->until->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_throws_exception_when_booking_without_dates()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Booking products require 'from' and 'until' dates");

        $this->user->purchase($this->price, 1);
    }

    #[Test]
    public function it_decreases_stock_for_booking_duration()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $initialStock = $this->bookingProduct->getAvailableStock();

        $purchase = $this->user->purchase(
            $this->price,
            2,
            null,
            $from,
            $until
        );

        // Stock should be decreased
        $this->bookingProduct->refresh();
        $remainingStock = $this->bookingProduct->getAvailableStock();
        $this->assertEquals($initialStock - 2, $remainingStock);
    }

    #[Test]
    public function it_releases_stock_after_booking_period()
    {
        $from = Carbon::now()->subDays(3);
        $until = Carbon::now()->subDays(1); // Booking ended yesterday

        $initialStock = $this->bookingProduct->getAvailableStock();

        $purchase = $this->user->purchase(
            $this->price,
            2,
            null,
            $from,
            $until
        );

        // Find the stock claim
        $claim = $this->bookingProduct->stocks()
            ->where('type', 'decrease')
            ->where('expires_at', $until)
            ->first();

        $this->assertNotNull($claim);
        $this->assertEquals($until->format('Y-m-d H:i:s'), $claim->expires_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_can_check_booking_availability()
    {
        $from = Carbon::now()->addDays(5);
        $until = Carbon::now()->addDays(7);

        // Should be available (stock is 10)
        $this->assertTrue($this->bookingProduct->isAvailableForBooking($from, $until, 5));

        // Should not be available (requesting more than available)
        $this->assertFalse($this->bookingProduct->isAvailableForBooking($from, $until, 15));
    }

    #[Test]
    public function it_can_add_booking_to_cart_with_dates()
    {
        $cart = $this->user->currentCart();

        $from = Carbon::now()->addDays(10);
        $until = Carbon::now()->addDays(12);

        $cartItem = $cart->addToCart(
            $this->bookingProduct,
            1,
            [
                'from' => $from->toDateTimeString(),
                'until' => $until->toDateTimeString(),
            ]
        );

        $this->assertNotNull($cartItem);
        $this->assertEquals($this->bookingProduct->id, $cartItem->purchasable_id);
        $this->assertNotNull($cartItem->parameters);
        $this->assertIsArray($cartItem->parameters);
        $this->assertEquals($from->toDateTimeString(), $cartItem->parameters['from']);
        $this->assertEquals($until->toDateTimeString(), $cartItem->parameters['until']);
    }

    #[Test]
    public function it_can_checkout_cart_with_booking_product()
    {
        $cart = $this->user->currentCart();

        $from = Carbon::now()->addDays(15);
        $until = Carbon::now()->addDays(17);

        $cart->addToCart(
            $this->bookingProduct,
            2,
            [
                'from' => $from->toDateTimeString(),
                'until' => $until->toDateTimeString(),
            ]
        );

        $initialStock = $this->bookingProduct->getAvailableStock();

        $cart->checkout();

        $this->assertTrue($cart->isConverted());

        // Check that purchase was created with dates
        $purchase = ProductPurchase::where('cart_id', $cart->id)->first();
        $this->assertNotNull($purchase);
        $this->assertTrue($purchase->isBooking());
        $this->assertEquals($from->format('Y-m-d H:i:s'), $purchase->from->format('Y-m-d H:i:s'));
        $this->assertEquals($until->format('Y-m-d H:i:s'), $purchase->until->format('Y-m-d H:i:s'));

        // Stock should be decreased
        $this->bookingProduct->refresh();
        $this->assertEquals($initialStock - 2, $this->bookingProduct->getAvailableStock());
    }

    #[Test]
    public function it_prevents_overbooking()
    {
        $from = Carbon::now()->addDays(20);
        $until = Carbon::now()->addDays(22);

        // Book 8 units
        $this->user->purchase(
            $this->price,
            8,
            null,
            $from,
            $until
        );

        // Try to book 5 more units (would exceed stock of 10)
        $this->expectException(\Exception::class);

        $this->user->purchase(
            $this->price,
            5,
            null,
            $from,
            $until
        );
    }

    #[Test]
    public function it_can_scope_booking_purchases()
    {
        $from = Carbon::now()->addDays(25);
        $until = Carbon::now()->addDays(27);

        // Create a regular product purchase
        $regularProduct = Product::factory()->create([
            'type' => ProductType::SIMPLE,
            'manage_stock' => false,
        ]);
        $regularPrice = ProductPrice::factory()->create([
            'purchasable_id' => $regularProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'is_default' => true,
        ]);
        $this->user->purchase($regularPrice, 1);

        // Create a booking purchase
        $this->user->purchase(
            $this->price,
            1,
            null,
            $from,
            $until
        );

        $bookingPurchases = ProductPurchase::bookings()->get();
        $this->assertCount(1, $bookingPurchases);
        $this->assertTrue($bookingPurchases->first()->isBooking());
    }

    #[Test]
    public function it_can_scope_ended_bookings()
    {
        $pastFrom = Carbon::now()->subDays(5);
        $pastUntil = Carbon::now()->subDays(2);

        $futureFrom = Carbon::now()->addDays(1);
        $futureUntil = Carbon::now()->addDays(3);

        // Create past booking
        $pastPurchase = $this->user->purchase(
            $this->price,
            1,
            null,
            $pastFrom,
            $pastUntil
        );

        // Create future booking
        $futurePurchase = $this->user->purchase(
            $this->price,
            1,
            null,
            $futureFrom,
            $futureUntil
        );

        $endedBookings = ProductPurchase::endedBookings()->get();

        $this->assertCount(1, $endedBookings);
        $this->assertEquals($pastPurchase->id, $endedBookings->first()->id);
        $this->assertTrue($endedBookings->first()->isBookingEnded());
        $this->assertFalse($futurePurchase->isBookingEnded());
    }

    #[Test]
    public function it_can_scope_booking_products()
    {
        // Create regular product
        Product::factory()->create([
            'type' => ProductType::SIMPLE,
        ]);

        $bookingProducts = Product::bookings()->get();
        $this->assertCount(1, $bookingProducts);
        $this->assertEquals($this->bookingProduct->id, $bookingProducts->first()->id);
    }

    #[Test]
    public function booking_stock_expires_after_until_date()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(2);

        $purchase = $this->user->purchase(
            $this->price,
            3,
            null,
            $from,
            $until
        );

        // Find the stock decrease record
        $stockRecord = $this->bookingProduct->stocks()
            ->where('type', 'decrease')
            ->latest()
            ->first();

        $this->assertNotNull($stockRecord);
        $this->assertNotNull($stockRecord->expires_at);
        $this->assertEquals($until->format('Y-m-d H:i:s'), $stockRecord->expires_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function multiple_bookings_with_different_dates_work_independently()
    {
        $booking1From = Carbon::now()->addDays(1);
        $booking1Until = Carbon::now()->addDays(3);

        $booking2From = Carbon::now()->addDays(5);
        $booking2Until = Carbon::now()->addDays(7);

        $purchase1 = $this->user->purchase(
            $this->price,
            2,
            null,
            $booking1From,
            $booking1Until
        );

        $purchase2 = $this->user->purchase(
            $this->price,
            3,
            null,
            $booking2From,
            $booking2Until
        );

        $this->assertNotNull($purchase1);
        $this->assertNotNull($purchase2);
        $this->assertNotEquals($purchase1->from, $purchase2->from);
        $this->assertNotEquals($purchase1->until, $purchase2->until);

        // Both should have decreased stock
        $this->bookingProduct->refresh();
        $this->assertEquals(5, $this->bookingProduct->getAvailableStock()); // 10 - 2 - 3
    }
}
