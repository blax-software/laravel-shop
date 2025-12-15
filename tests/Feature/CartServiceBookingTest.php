<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Facades\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;

class CartServiceBookingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Product $bookingProduct;
    protected Product $poolProduct;
    protected Product $singleItem1;
    protected Product $singleItem2;
    protected ProductPrice $bookingPrice;
    protected ProductPrice $poolPrice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // Create booking product
        $this->bookingProduct = Product::factory()->create([
            'name' => 'Hotel Room',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $this->bookingProduct->increaseStock(10);

        $this->bookingPrice = ProductPrice::factory()->create([
            'purchasable_id' => $this->bookingProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 10000, // $100.00
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Create pool product with single items
        $this->poolProduct = Product::factory()->create([
            'name' => 'Parking Spaces',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $this->poolPrice = ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000, // $20.00
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $this->singleItem1 = Product::factory()->create([
            'name' => 'Parking Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $this->singleItem1->increaseStock(1);

        $this->singleItem2 = Product::factory()->create([
            'name' => 'Parking Spot 2',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $this->singleItem2->increaseStock(1);

        $this->poolProduct->productRelations()->attach($this->singleItem1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
        $this->poolProduct->productRelations()->attach($this->singleItem2->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
    }

    /** @test */
    public function validate_bookings_returns_error_for_booking_product_without_timespan()
    {
        $cart = $this->user->currentCart();

        // Add booking product without timespan
        $cart->items()->create([
            'purchasable_id' => $this->bookingProduct->id,
            'purchasable_type' => Product::class,
            'quantity' => 1,
            'price' => 100.00,
        ]);

        $errors = Cart::validateBookings();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('requires a timespan', $errors[0]);
        $this->assertStringContainsString('Hotel Room', $errors[0]);
    }

    /** @test */
    public function validate_bookings_returns_error_for_pool_product_without_timespan_when_single_items_are_bookings()
    {
        $cart = $this->user->currentCart();

        // Add pool product without timespan
        $cart->items()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'quantity' => 1,
            'price' => 20.00,
        ]);

        $errors = Cart::validateBookings();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('requires either a timespan', $errors[0]);
        $this->assertStringContainsString('Parking Spaces', $errors[0]);
    }

    /** @test */
    public function validate_bookings_validates_stock_availability_correctly()
    {
        $cart = $this->user->currentCart();
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        // Book all stock first
        $this->bookingProduct->claimStock(10, null, $from, $until);

        // Try to add more than available
        $cart->items()->create([
            'purchasable_id' => $this->bookingProduct->id,
            'purchasable_type' => Product::class,
            'quantity' => 5,
            'price' => 100.00,
            'from' => $from,
            'until' => $until,
        ]);

        $errors = Cart::validateBookings();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('not available for the selected period', $errors[0]);
    }

    /** @test */
    public function validate_bookings_handles_pool_products_with_individual_timespans_in_meta()
    {
        $cart = $this->user->currentCart();

        // Add pool product with individual timespans flag
        $cartItem = $cart->items()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'quantity' => 1,
            'price' => 20.00,
            'meta' => ['individual_timespans' => true],
        ]);

        $errors = Cart::validateBookings();

        // Should not have errors since individual timespans are marked
        $this->assertEmpty($errors);
    }

    /** @test */
    public function has_valid_bookings_returns_true_when_all_bookings_are_valid()
    {
        $cart = $this->user->currentCart();
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cart->items()->create([
            'purchasable_id' => $this->bookingProduct->id,
            'purchasable_type' => Product::class,
            'quantity' => 2,
            'price' => 100.00,
            'from' => $from,
            'until' => $until,
        ]);

        $this->assertTrue(Cart::hasValidBookings());
    }

    /** @test */
    public function has_valid_bookings_returns_false_when_bookings_are_invalid()
    {
        $cart = $this->user->currentCart();

        // Add booking without timespan
        $cart->items()->create([
            'purchasable_id' => $this->bookingProduct->id,
            'purchasable_type' => Product::class,
            'quantity' => 1,
            'price' => 100.00,
        ]);

        $this->assertFalse(Cart::hasValidBookings());
    }

    /** @test */
    public function add_booking_successfully_adds_booking_product_with_timespan()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cartItem = Cart::addBooking($this->bookingProduct, 2, $from, $until);

        $this->assertNotNull($cartItem);
        $this->assertEquals($this->bookingProduct->id, $cartItem->purchasable_id);
        $this->assertEquals(2, $cartItem->quantity);
        $this->assertEquals($from->format('Y-m-d H:i:s'), $cartItem->from->format('Y-m-d H:i:s'));
        $this->assertEquals($until->format('Y-m-d H:i:s'), $cartItem->until->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function add_booking_successfully_adds_pool_product_with_timespan()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cartItem = Cart::addBooking($this->poolProduct, 1, $from, $until);

        $this->assertNotNull($cartItem);
        $this->assertEquals($this->poolProduct->id, $cartItem->purchasable_id);
        $this->assertEquals(1, $cartItem->quantity);
        $this->assertEquals($from->format('Y-m-d H:i:s'), $cartItem->from->format('Y-m-d H:i:s'));
        $this->assertEquals($until->format('Y-m-d H:i:s'), $cartItem->until->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function add_booking_calculates_price_correctly_based_on_days()
    {
        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(4)->startOfDay(); // 3 days
        $days = $from->diffInDays($until);

        $cartItem = Cart::addBooking($this->bookingProduct, 2, $from, $until);

        // Price should be: price_per_day (10000 cents = 100 dollars) × days (3) = 30000 cents per unit
        // Total should be: 30000 × quantity (2) = 60000 cents
        $expectedPricePerUnit = 10000 * $days;  // 30000 cents
        $expectedTotal = $expectedPricePerUnit * 2;  // 60000 cents

        $this->assertEquals($expectedPricePerUnit, $cartItem->price);
        $this->assertEquals($expectedTotal, $cartItem->subtotal);
    }

    /** @test */
    public function add_booking_throws_exception_when_product_is_not_booking_or_pool_type()
    {
        $simpleProduct = Product::factory()->create([
            'name' => 'Simple Product',
            'type' => ProductType::SIMPLE,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $simpleProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not a booking or pool type');

        Cart::addBooking($simpleProduct, 1, $from, $until);
    }

    /** @test */
    public function add_booking_throws_exception_when_insufficient_stock_available_for_booking_period()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        // Claim all stock first
        $this->bookingProduct->claimStock(10, null, $from, $until);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('is not available for the requested period');

        Cart::addBooking($this->bookingProduct, 5, $from, $until);
    }

    /** @test */
    public function add_booking_throws_exception_when_pool_quantity_exceeds_available_single_items()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        // Pool has only 2 single items, trying to book 5
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not have enough available items');

        Cart::addBooking($this->poolProduct, 5, $from, $until);
    }

    /** @test */
    public function add_booking_creates_cart_item_with_correct_from_until_timestamps()
    {
        $from = Carbon::now()->addDays(5)->setTime(14, 30, 0);
        $until = Carbon::now()->addDays(8)->setTime(10, 0, 0);

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

        $this->assertEquals($from->format('Y-m-d H:i:s'), $cartItem->from->format('Y-m-d H:i:s'));
        $this->assertEquals($until->format('Y-m-d H:i:s'), $cartItem->until->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function add_booking_stores_regular_price_correctly()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until);

        $this->assertNotNull($cartItem->regular_price);
        $this->assertEquals($this->bookingProduct->getCurrentPrice(), $cartItem->regular_price);
    }

    /** @test */
    public function validate_bookings_returns_error_when_pool_quantity_exceeds_available_single_items()
    {
        $cart = $this->user->currentCart();
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        // Pool has 2 single items, requesting 3
        $cart->items()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'quantity' => 3,
            'price' => 20.00,
            'from' => $from,
            'until' => $until,
        ]);

        $errors = Cart::validateBookings();

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('2', $errors[0]); // Available count
        $this->assertStringContainsString('3', $errors[0]); // Requested count
        $this->assertStringContainsString('Parking Spaces', $errors[0]);
    }

    /** @test */
    public function validate_bookings_passes_with_valid_pool_product_and_timespan()
    {
        $cart = $this->user->currentCart();
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cart->items()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'quantity' => 2, // Exactly matches available single items
            'price' => 20.00,
            'from' => $from,
            'until' => $until,
        ]);

        $errors = Cart::validateBookings();

        $this->assertEmpty($errors);
    }

    /** @test */
    public function validate_bookings_handles_multiple_booking_products_in_cart()
    {
        $cart = $this->user->currentCart();
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        // Valid booking
        $cart->items()->create([
            'purchasable_id' => $this->bookingProduct->id,
            'purchasable_type' => Product::class,
            'quantity' => 2,
            'price' => 100.00,
            'from' => $from,
            'until' => $until,
        ]);

        // Valid pool booking
        $cart->items()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'quantity' => 1,
            'price' => 20.00,
            'from' => $from,
            'until' => $until,
        ]);

        $errors = Cart::validateBookings();

        $this->assertEmpty($errors);
    }

    /** @test */
    public function add_booking_with_parameters_stores_them_correctly()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);
        $parameters = ['special_request' => 'Late checkout', 'vip' => true];

        $cartItem = Cart::addBooking($this->bookingProduct, 1, $from, $until, $parameters);

        $this->assertEquals($parameters, $cartItem->parameters);
    }
}
