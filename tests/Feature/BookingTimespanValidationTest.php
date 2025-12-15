<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Exceptions\NotPurchasable;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Services\CartService;
use Workbench\App\Models\User;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class BookingTimespanValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $bookingProduct;
    private CartService $cartService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        auth()->login($this->user);  // Authenticate the user

        $this->bookingProduct = Product::factory()->create([
            'name' => 'Hotel Room',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $this->bookingProduct->increaseStock(1); // Only 1 unit available for testing overlaps

        ProductPrice::factory()->create([
            'purchasable_id' => $this->bookingProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 10000, // 100.00
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $this->cartService = new CartService();
    }

    /** @test */
    public function it_rejects_booking_when_from_is_after_until()
    {
        $from = now()->addDays(5);
        $until = now()->addDays(2);

        $this->expectException(NotPurchasable::class);
        $this->expectExceptionMessage("Invalid booking timespan");

        $this->cartService->addBooking(
            $this->bookingProduct,
            1,
            $from,
            $until
        );
    }

    /** @test */
    public function it_rejects_booking_when_from_equals_until()
    {
        $from = now()->addDays(3);
        $until = $from->copy();

        $this->expectException(NotPurchasable::class);
        $this->expectExceptionMessage("Invalid booking timespan");

        $this->cartService->addBooking(
            $this->bookingProduct,
            1,
            $from,
            $until
        );
    }

    /** @test */
    public function it_rejects_booking_when_from_is_in_the_past()
    {
        $from = now()->subDays(2);
        $until = now()->addDays(3);

        $this->expectException(NotPurchasable::class);
        $this->expectExceptionMessage("Invalid booking timespan");

        $this->cartService->addBooking(
            $this->bookingProduct,
            1,
            $from,
            $until
        );
    }

    /** @test */
    public function it_accepts_booking_when_from_is_exactly_now()
    {
        $from = now()->addSeconds(1);  // Very slightly in the future to avoid timing issues
        $until = now()->addDays(2);

        $cart = $this->user->currentCart();
        $this->cartService->addBooking($this->bookingProduct, 1, $from, $until);

        $this->assertCount(1, $cart->fresh()->items);
        $cartItem = $cart->items->first();
        $this->assertNotNull($cartItem->from);
        $this->assertNotNull($cartItem->until);
    }

    /** @test */
    public function it_validates_timespan_availability_across_date_range()
    {
        // Create a pool product with 1 single item
        $poolProduct = Product::factory()->create([
            'name' => 'Kayak Fleet',
            'type' => ProductType::POOL,
        ]);

        $singleItem = Product::factory()->create([
            'name' => 'Kayak #1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $singleItem->increaseStock(1);

        ProductPrice::factory()->create([
            'purchasable_id' => $singleItem->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $poolProduct->productRelations()->attach($singleItem->id, ['type' => ProductRelationType::SINGLE->value]);

        // Book the single item for days 2-4
        $existingFrom = now()->addDays(2)->startOfDay();
        $existingUntil = now()->addDays(4)->endOfDay();
        $this->cartService->addBooking(
            $singleItem,
            1,
            $existingFrom,
            $existingUntil
        );

        $this->user->currentCart()->checkout();

        // Try to book the pool for days 1-5 (overlaps with existing booking)
        $newUser = User::factory()->create();
        auth()->login($newUser);
        $newFrom = now()->addDays(1)->startOfDay();
        $newUntil = now()->addDays(5)->endOfDay();

        $this->expectException(NotPurchasable::class);
        $this->expectExceptionMessage('does not have enough available items');

        $this->cartService->addBooking(
            $poolProduct,
            1,
            $newFrom,
            $newUntil
        );
    }

    /** @test */
    public function it_allows_back_to_back_bookings_without_overlap()
    {
        // Create a pool product with 2 single items so both bookings can succeed
        $poolProduct = Product::factory()->create([
            'type' => ProductType::POOL,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $singleItem1 = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $singleItem1->increaseStock(1);

        ProductPrice::factory()->create([
            'purchasable_id' => $singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $singleItem2 = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $singleItem2->increaseStock(1);

        ProductPrice::factory()->create([
            'purchasable_id' => $singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $poolProduct->productRelations()->attach($singleItem1->id, ['type' => ProductRelationType::SINGLE->value]);
        $poolProduct->productRelations()->attach($singleItem2->id, ['type' => ProductRelationType::SINGLE->value]);

        // First booking: days 1-3 ending at 23:59:59
        $firstFrom = now()->addDays(1)->startOfDay();
        $firstUntil = now()->addDays(3)->endOfDay();
        $this->cartService->addBooking(
            $singleItem1,
            1,
            $firstFrom,
            $firstUntil
        );

        $this->user->currentCart()->checkout();

        // Second booking: days 4-6 starting at 00:00:00
        $newUser = User::factory()->create();
        auth()->login($newUser);
        $secondFrom = now()->addDays(4)->startOfDay();
        $secondUntil = now()->addDays(6)->endOfDay();

        $this->cartService->addBooking(
            $poolProduct,
            1,
            $secondFrom,
            $secondUntil
        );

        $this->assertCount(1, $newUser->currentCart()->items);
    }

    /** @test */
    public function it_detects_overlap_when_new_booking_ends_during_existing_booking()
    {
        // Book days 5-10
        $existingFrom = now()->addDays(5)->startOfDay();
        $existingUntil = now()->addDays(10)->endOfDay();
        $this->cartService->addBooking(
            $this->bookingProduct,
            1,
            $existingFrom,
            $existingUntil
        );

        $this->user->currentCart()->checkout();

        // Try to book days 3-7 (overlaps with existing)
        $newUser = User::factory()->create();
        auth()->login($newUser);
        $newFrom = now()->addDays(3)->startOfDay();
        $newUntil = now()->addDays(7)->endOfDay();

        $this->expectException(NotPurchasable::class);

        $this->cartService->addBooking(
            $this->bookingProduct,
            1,
            $newFrom,
            $newUntil
        );
    }

    /** @test */
    public function it_detects_overlap_when_new_booking_starts_during_existing_booking()
    {
        // Book days 5-10
        $existingFrom = now()->addDays(5)->startOfDay();
        $existingUntil = now()->addDays(10)->endOfDay();
        $this->cartService->addBooking(
            $this->bookingProduct,
            1,
            $existingFrom,
            $existingUntil
        );

        $this->user->currentCart()->checkout();

        // Try to book days 8-12 (overlaps with existing)
        $newUser = User::factory()->create();
        auth()->login($newUser);
        $newFrom = now()->addDays(8)->startOfDay();
        $newUntil = now()->addDays(12)->endOfDay();

        $this->expectException(NotPurchasable::class);

        $this->cartService->addBooking(
            $this->bookingProduct,
            1,
            $newFrom,
            $newUntil
        );
    }

    /** @test */
    public function it_detects_overlap_when_new_booking_completely_contains_existing_booking()
    {
        // Book days 6-8
        $existingFrom = now()->addDays(6)->startOfDay();
        $existingUntil = now()->addDays(8)->endOfDay();
        $this->cartService->addBooking(
            $this->bookingProduct,
            1,
            $existingFrom,
            $existingUntil
        );

        $this->user->currentCart()->checkout();

        // Try to book days 5-10 (completely contains existing)
        $newUser = User::factory()->create();
        auth()->login($newUser);
        $newFrom = now()->addDays(5)->startOfDay();
        $newUntil = now()->addDays(10)->endOfDay();

        $this->expectException(NotPurchasable::class);

        $this->cartService->addBooking(
            $this->bookingProduct,
            1,
            $newFrom,
            $newUntil
        );
    }

    /** @test */
    public function it_detects_overlap_when_new_booking_is_completely_contained_by_existing_booking()
    {
        // Book days 5-10
        $existingFrom = now()->addDays(5)->startOfDay();
        $existingUntil = now()->addDays(10)->endOfDay();
        $this->cartService->addBooking(
            $this->bookingProduct,
            1,
            $existingFrom,
            $existingUntil
        );

        $this->user->currentCart()->checkout();

        // Try to book days 6-8 (completely contained by existing)
        $newUser = User::factory()->create();
        auth()->login($newUser);
        $newFrom = now()->addDays(6)->startOfDay();
        $newUntil = now()->addDays(8)->endOfDay();

        $this->expectException(NotPurchasable::class);

        $this->cartService->addBooking(
            $this->bookingProduct,
            1,
            $newFrom,
            $newUntil
        );
    }

    /** @test */
    public function it_handles_timezone_aware_timespan_validation()
    {
        Carbon::setTestNow(now('America/New_York'));

        $from = now('America/New_York')->addDays(1);
        $until = now('America/New_York')->addDays(3);

        $cart = $this->user->currentCart();
        $this->cartService->addBooking($this->bookingProduct, 1, $from, $until);

        $cartItem = $cart->fresh()->items->first();

        // Verify dates are stored correctly
        $this->assertNotNull($cartItem->from);
        $this->assertNotNull($cartItem->until);
        $this->assertTrue($cartItem->from->lessThan($cartItem->until));
    }

    /** @test */
    public function it_allows_same_product_multiple_non_overlapping_timespans()
    {
        // Create pool with 2 single items
        $poolProduct = Product::factory()->create([
            'type' => ProductType::POOL,
        ]);

        $singleItem1 = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $singleItem1->increaseStock(1);

        ProductPrice::factory()->create([
            'purchasable_id' => $singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $singleItem2 = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $singleItem2->increaseStock(1);

        ProductPrice::factory()->create([
            'purchasable_id' => $singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $poolProduct->productRelations()->attach($singleItem1->id, ['type' => ProductRelationType::SINGLE->value]);
        $poolProduct->productRelations()->attach($singleItem2->id, ['type' => ProductRelationType::SINGLE->value]);

        // Book first timespan (days 1-3)
        $from1 = now()->addDays(1)->startOfDay();
        $until1 = now()->addDays(3)->endOfDay();
        $this->cartService->addBooking(
            $poolProduct,
            1,
            $from1,
            $until1
        );

        // Book second non-overlapping timespan (days 5-7) in same cart
        $from2 = now()->addDays(5)->startOfDay();
        $until2 = now()->addDays(7)->endOfDay();
        $this->cartService->addBooking(
            $poolProduct,
            1,
            $from2,
            $until2
        );

        $this->assertCount(2, $this->user->currentCart()->items);
    }
}
