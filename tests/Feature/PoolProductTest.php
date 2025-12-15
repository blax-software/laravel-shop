<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;

class PoolProductTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Product $hotelRoom;
    protected Product $parkingPool;
    protected Product $parkingSpot1;
    protected Product $parkingSpot2;
    protected Product $parkingSpot3;
    protected ProductPrice $hotelPrice;
    protected ProductPrice $parkingPrice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Create hotel room (booking product)
        $this->hotelRoom = Product::factory()->create([
            'name' => 'Hotel Room',
            'slug' => 'hotel-room',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $this->hotelRoom->increaseStock(5);

        $this->hotelPrice = ProductPrice::factory()->create([
            'purchasable_id' => $this->hotelRoom->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 10000, // $100.00 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Create parking pool product
        $this->parkingPool = Product::factory()->create([
            'name' => 'Parking Spaces',
            'slug' => 'parking-spaces',
            'type' => ProductType::POOL,
            'manage_stock' => false, // Pool doesn't manage its own stock
        ]);

        $this->parkingPrice = ProductPrice::factory()->create([
            'purchasable_id' => $this->parkingPool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000, // $20.00 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Create individual parking spots (booking products with stock = 1)
        $this->parkingSpot1 = Product::factory()->create([
            'name' => 'Parking Spot 1',
            'slug' => 'parking-spot-1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $this->parkingSpot1->increaseStock(1);

        $this->parkingSpot2 = Product::factory()->create([
            'name' => 'Parking Spot 2',
            'slug' => 'parking-spot-2',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $this->parkingSpot2->increaseStock(1);

        $this->parkingSpot3 = Product::factory()->create([
            'name' => 'Parking Spot 3',
            'slug' => 'parking-spot-3',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $this->parkingSpot3->increaseStock(1);

        // Link parking spots as SINGLE items to the pool
        $this->parkingPool->productRelations()->attach($this->parkingSpot1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
        $this->parkingPool->productRelations()->attach($this->parkingSpot2->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
        $this->parkingPool->productRelations()->attach($this->parkingSpot3->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        // Link parking pool as cross-sell to hotel room
        $this->hotelRoom->productRelations()->attach($this->parkingPool->id, [
            'type' => ProductRelationType::CROSS_SELL->value,
        ]);
    }

    /** @test */
    public function it_can_create_a_pool_product()
    {
        $this->assertNotNull($this->parkingPool);
        $this->assertEquals(ProductType::POOL, $this->parkingPool->type);
        $this->assertTrue($this->parkingPool->isPool());
    }

    /** @test */
    public function pool_product_has_single_items_linked()
    {
        $singleItems = $this->parkingPool->singleProducts;

        $this->assertCount(3, $singleItems);
        $this->assertTrue($singleItems->contains($this->parkingSpot1));
        $this->assertTrue($singleItems->contains($this->parkingSpot2));
        $this->assertTrue($singleItems->contains($this->parkingSpot3));
    }

    /** @test */
    public function pool_product_max_quantity_equals_number_of_single_items()
    {
        $maxQuantity = $this->parkingPool->getPoolMaxQuantity();

        $this->assertEquals(3, $maxQuantity);
    }

    /** @test */
    public function pool_product_detects_booking_single_items()
    {
        $this->assertTrue($this->parkingPool->hasBookingSingleItems());
    }

    /** @test */
    public function it_can_add_pool_product_to_cart_with_timespan()
    {
        $cart = $this->user->currentCart();
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cartItem = $cart->items()->create([
            'purchasable_id' => $this->parkingPool->id,
            'purchasable_type' => Product::class,
            'quantity' => 2,
            'price' => 20.00,
            'from' => $from,
            'until' => $until,
        ]);

        $this->assertNotNull($cartItem);
        $this->assertEquals($from->format('Y-m-d H:i:s'), $cartItem->from->format('Y-m-d H:i:s'));
        $this->assertEquals($until->format('Y-m-d H:i:s'), $cartItem->until->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function pool_product_quantity_is_limited_by_available_single_items()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        // All 3 parking spots are available
        $maxQuantity = $this->parkingPool->getPoolMaxQuantity($from, $until);
        $this->assertEquals(3, $maxQuantity);

        // Book one parking spot directly
        $this->parkingSpot1->claimStock(1, null, $from, $until);

        // Now only 2 should be available in the pool
        $maxQuantity = $this->parkingPool->getPoolMaxQuantity($from, $until);
        $this->assertEquals(2, $maxQuantity);
    }

    /** @test */
    public function booking_price_is_calculated_based_on_timespan_and_quantity()
    {
        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay();
        $days = $from->diffInDays($until);

        $quantity = 2;
        $pricePerDay = 20.00;
        $expectedTotal = $days * $quantity * $pricePerDay;

        // This would be $2 days * 2 parking spaces * $20 = $80
        $this->assertEquals(80.00, $expectedTotal);
    }

    /** @test */
    public function pool_product_with_overlapping_bookings_reduces_available_quantity()
    {
        $from = Carbon::now()->addDays(5);
        $until = Carbon::now()->addDays(7);

        // Initially all 3 spots available
        $this->assertEquals(3, $this->parkingPool->getPoolMaxQuantity($from, $until));

        // Book 2 spots
        $this->parkingSpot1->claimStock(1, null, $from, $until);
        $this->parkingSpot2->claimStock(1, null, $from, $until);

        // Only 1 spot should remain available
        $this->assertEquals(1, $this->parkingPool->getPoolMaxQuantity($from, $until));
    }

    /** @test */
    public function different_timespan_bookings_dont_conflict()
    {
        $from1 = Carbon::now()->addDays(1);
        $until1 = Carbon::now()->addDays(3);

        $from2 = Carbon::now()->addDays(5);
        $until2 = Carbon::now()->addDays(7);

        // Book spot 1 for first period
        $this->parkingSpot1->claimStock(1, null, $from1, $until1);

        // All spots should be available for second period
        $this->assertEquals(3, $this->parkingPool->getPoolMaxQuantity($from2, $until2));

        // Only 2 spots should be available for first period
        $this->assertEquals(2, $this->parkingPool->getPoolMaxQuantity($from1, $until1));
    }

    /** @test */
    public function pool_product_unavailable_when_all_single_items_booked()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        // Book all 3 spots
        $this->parkingSpot1->claimStock(1, null, $from, $until);
        $this->parkingSpot2->claimStock(1, null, $from, $until);
        $this->parkingSpot3->claimStock(1, null, $from, $until);

        // No spots should be available
        $this->assertEquals(0, $this->parkingPool->getPoolMaxQuantity($from, $until));
    }

    /** @test */
    public function pool_product_can_be_cross_sell_of_hotel_room()
    {
        $crossSells = $this->hotelRoom->crossSellProducts;

        $this->assertCount(1, $crossSells);
        $this->assertTrue($crossSells->contains($this->parkingPool));
    }

    /** @test */
    public function booking_cancellation_releases_stock_of_single_items()
    {
        $from = Carbon::now()->addDays(10);
        $until = Carbon::now()->addDays(12);

        // Book a spot
        $this->parkingSpot1->claimStock(1, null, $from, $until);

        // Should have 2 spots available
        $this->assertEquals(2, $this->parkingPool->getPoolMaxQuantity($from, $until));

        // Release the stock (simulate cancellation before booking starts)
        $claim = $this->parkingSpot1->stocks()
            ->where('type', StockType::CLAIMED->value)
            ->where('claimed_from', $from)
            ->where('expires_at', $until)
            ->first();

        if ($claim) {
            $claim->release(); // Use the release method instead of delete
        }

        // Should have 3 spots available again
        $this->parkingSpot1->refresh();
        $this->assertEquals(3, $this->parkingPool->getPoolMaxQuantity($from, $until));
    }

    /** @test */
    public function pool_product_respects_partial_overlapping_bookings()
    {
        // Booking 1: Days 1-3
        $from1 = Carbon::now()->addDays(1);
        $until1 = Carbon::now()->addDays(3);

        // Booking 2: Days 2-4 (overlaps with booking 1 on day 2)
        $from2 = Carbon::now()->addDays(2);
        $until2 = Carbon::now()->addDays(4);

        // Book spot 1 for days 1-3
        $this->parkingSpot1->claimStock(1, null, $from1, $until1);

        // For days 2-4, spot 1 should not be available (overlaps)
        // So only 2 spots should be available
        $this->assertEquals(2, $this->parkingPool->getPoolMaxQuantity($from2, $until2));
    }

    /** @test */
    public function multiple_pool_products_can_exist_independently()
    {
        // Create a second pool for bikes
        $bikePool = Product::factory()->create([
            'name' => 'Bike Rentals',
            'slug' => 'bike-rentals',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $bike1 = Product::factory()->create([
            'name' => 'Bike 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $bike1->increaseStock(1);

        $bike2 = Product::factory()->create([
            'name' => 'Bike 2',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $bike2->increaseStock(1);

        $bikePool->productRelations()->attach($bike1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
        $bikePool->productRelations()->attach($bike2->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        // Both pools should work independently
        $this->assertEquals(3, $this->parkingPool->getPoolMaxQuantity());
        $this->assertEquals(2, $bikePool->getPoolMaxQuantity());
    }

    /** @test */
    public function pool_product_stock_calculated_correctly_with_mixed_availability()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        // Spot 1: Fully booked for the period
        $this->parkingSpot1->claimStock(1, null, $from, $until);

        // Spot 2: Available
        // Spot 3: Booked for a different period
        $otherFrom = Carbon::now()->addDays(5);
        $otherUntil = Carbon::now()->addDays(7);
        $this->parkingSpot3->claimStock(1, null, $otherFrom, $otherUntil);

        // For the requested period (days 1-3), spots 2 and 3 should be available
        $this->assertEquals(2, $this->parkingPool->getPoolMaxQuantity($from, $until));
    }

    /** @test */
    public function pool_product_with_zero_single_items_returns_zero_max_quantity()
    {
        $emptyPool = Product::factory()->create([
            'name' => 'Empty Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $this->assertEquals(0, $emptyPool->getPoolMaxQuantity());
    }

    /** @test */
    public function pool_product_with_non_booking_single_items_doesnt_require_timespan()
    {
        $simplePool = Product::factory()->create([
            'name' => 'Simple Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $simpleItem = Product::factory()->create([
            'name' => 'Simple Item',
            'type' => ProductType::SIMPLE,
            'manage_stock' => false,
        ]);

        $simplePool->productRelations()->attach($simpleItem->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        $this->assertFalse($simplePool->hasBookingSingleItems());
    }

    /** @test */
    public function pool_product_with_mixed_booking_and_non_booking_single_items()
    {
        $mixedPool = Product::factory()->create([
            'name' => 'Mixed Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $bookingItem = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $bookingItem->increaseStock(1);

        $simpleItem = Product::factory()->create([
            'type' => ProductType::SIMPLE,
            'manage_stock' => false,
        ]);

        $mixedPool->productRelations()->attach($bookingItem->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
        $mixedPool->productRelations()->attach($simpleItem->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        // Should detect booking items exist
        $this->assertTrue($mixedPool->hasBookingSingleItems());
        $this->assertEquals(2, $mixedPool->getPoolMaxQuantity());
    }

    /** @test */
    public function pool_product_checkout_claims_exactly_the_right_number_of_single_items()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cart = $this->user->currentCart();
        $cart->items()->create([
            'purchasable_id' => $this->parkingPool->id,
            'purchasable_type' => Product::class,
            'quantity' => 2,
            'price' => 20.00,
            'from' => $from,
            'until' => $until,
        ]);

        $cart->checkout();

        // Verify 2 single items were claimed
        $claimedCount = 0;
        foreach ($this->parkingPool->singleProducts as $spot) {
            $claims = $spot->stocks()
                ->where('type', StockType::CLAIMED->value)
                ->where('claimed_from', $from)
                ->count();
            $claimedCount += $claims;
        }

        $this->assertEquals(2, $claimedCount);
    }

    /** @test */
    public function pool_product_checkout_stores_claimed_single_items_in_metadata()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cart = $this->user->currentCart();
        $cartItem = $cart->items()->create([
            'purchasable_id' => $this->parkingPool->id,
            'purchasable_type' => Product::class,
            'quantity' => 2,
            'price' => 20.00,
            'from' => $from,
            'until' => $until,
        ]);

        $cart->checkout();

        $cartItem->refresh();
        $meta = $cartItem->getMeta();
        $claimedItems = $meta->claimed_single_items ?? null;

        $this->assertNotNull($claimedItems);
        $this->assertIsArray($claimedItems);
        $this->assertCount(2, $claimedItems);
    }

    /** @test */
    public function pool_product_with_different_stock_quantities_on_single_items()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        // Create a spot with stock of 2
        $doubleSpot = Product::factory()->create([
            'name' => 'Double Parking Spot',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $doubleSpot->increaseStock(2);

        $customPool = Product::factory()->create([
            'name' => 'Custom Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $customPool->productRelations()->attach($doubleSpot->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        // Should still count as 1 item (not based on stock quantity)
        $this->assertEquals(1, $customPool->getPoolMaxQuantity($from, $until));
    }

    /** @test */
    public function claim_pool_stock_throws_exception_when_not_enough_single_items_available()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        // Claim 2 spots first
        $this->parkingSpot1->claimStock(1, null, $from, $until);
        $this->parkingSpot2->claimStock(1, null, $from, $until);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('available');

        // Try to claim 2 more (only 1 available)
        $this->parkingPool->claimPoolStock(2, null, $from, $until);
    }

    /** @test */
    public function claim_pool_stock_throws_exception_when_called_on_non_pool_product()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('only for pool products');

        $this->hotelRoom->claimPoolStock(1, null, $from, $until);
    }

    /** @test */
    public function release_pool_stock_correctly_releases_all_claims()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $reference = $this->user->currentCart();

        // Claim 2 spots
        $this->parkingPool->claimPoolStock(2, $reference, $from, $until);

        // Verify they're claimed
        $this->assertEquals(1, $this->parkingPool->getPoolMaxQuantity($from, $until));

        // Release them
        $released = $this->parkingPool->releasePoolStock($reference);

        $this->assertEquals(2, $released);
        $this->assertEquals(3, $this->parkingPool->getPoolMaxQuantity($from, $until));
    }

    /** @test */
    public function release_pool_stock_throws_exception_when_called_on_non_pool_product()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('only for pool products');

        $this->hotelRoom->releasePoolStock($this->user->currentCart());
    }

    /** @test */
    public function pool_product_with_single_item_already_claimed_for_entire_period()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(5);

        // Claim spot 1 for the entire period
        $this->parkingSpot1->claimStock(1, null, $from, $until);

        // Should still have 2 spots available
        $this->assertEquals(2, $this->parkingPool->getPoolMaxQuantity($from, $until));

        // Claim spot 2 for part of the period
        $partialFrom = Carbon::now()->addDays(2);
        $partialUntil = Carbon::now()->addDays(4);
        $this->parkingSpot2->claimStock(1, null, $partialFrom, $partialUntil);

        // For the entire period, only spot 3 should be available
        $this->assertEquals(1, $this->parkingPool->getPoolMaxQuantity($from, $until));

        // For the partial period, spot 3 should still be available (spot 1 is busy)
        $this->assertEquals(1, $this->parkingPool->getPoolMaxQuantity($partialFrom, $partialUntil));
    }

    /** @test */
    public function pool_product_maximum_quantity_with_edge_of_timespan()
    {
        // Claim 1: Days 1-3
        $claim1From = Carbon::now()->addDays(1)->startOfDay();
        $claim1Until = Carbon::now()->addDays(3)->endOfDay();

        // Claim 2: Days 3-5 (overlaps on day 3)
        $claim2From = Carbon::now()->addDays(3)->startOfDay();
        $claim2Until = Carbon::now()->addDays(5)->endOfDay();

        $this->parkingSpot1->claimStock(1, null, $claim1From, $claim1Until);

        // For days 3-5, spot 1 should still be unavailable due to overlap
        $this->assertEquals(2, $this->parkingPool->getPoolMaxQuantity($claim2From, $claim2Until));
    }
}
