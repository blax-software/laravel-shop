<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Exceptions\InvalidDateRangeException;
use Blax\Shop\Exceptions\NotEnoughAvailableInTimespanException;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PriceType;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;

class CartDateManagementTest extends TestCase
{
    /** @test */
    public function it_can_set_cart_dates()
    {
        $cart = Cart::factory()->create();
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cart->setDates($from, $until, validateAvailability: false);

        $cart->refresh();
        $this->assertEquals($from->toDateTimeString(), $cart->from->toDateTimeString());
        $this->assertEquals($until->toDateTimeString(), $cart->until->toDateTimeString());
    }

    /** @test */
    public function it_stores_dates_as_provided_even_if_backwards()
    {
        $cart = Cart::factory()->create();
        $from = Carbon::now()->addDays(3);
        $until = Carbon::now()->addDays(1);

        // Dates are stored as provided (backwards)
        $cart->setDates($from, $until, validateAvailability: false);

        $cart->refresh();
        // Database stores the dates as provided
        $this->assertEquals($from->toDateTimeString(), $cart->from->toDateTimeString());
        $this->assertEquals($until->toDateTimeString(), $cart->until->toDateTimeString());
    }

    /** @test */
    public function it_can_set_from_date_individually()
    {
        $cart = Cart::factory()->create();
        $from = Carbon::now()->addDays(1);

        $cart->setFromDate($from, validateAvailability: false);

        $cart->refresh();
        $this->assertEquals($from->toDateTimeString(), $cart->from->toDateTimeString());
    }

    /** @test */
    public function it_can_set_until_date_individually()
    {
        $cart = Cart::factory()->create();
        $until = Carbon::now()->addDays(3);

        $cart->setUntilDate($until, validateAvailability: false);

        $cart->refresh();
        $this->assertEquals($until->toDateTimeString(), $cart->until->toDateTimeString());
    }

    /** @test */
    public function it_stores_from_date_even_if_after_existing_until_date()
    {
        $until = Carbon::now()->addDays(2);
        $cart = Cart::factory()->create([
            'until' => $until,
        ]);

        $from = Carbon::now()->addDays(3);
        $cart->setFromDate($from, validateAvailability: false);

        $cart->refresh();
        // Database stores the dates as provided (backwards order)
        $this->assertEquals($from->toDateTimeString(), $cart->from->toDateTimeString());
        $this->assertEquals($until->toDateTimeString(), $cart->until->toDateTimeString());
    }

    /** @test */
    public function it_stores_until_date_even_if_before_existing_from_date()
    {
        $from = Carbon::now()->addDays(3);
        $cart = Cart::factory()->create([
            'from' => $from,
        ]);

        $until = Carbon::now()->addDays(2);
        $cart->setUntilDate($until, validateAvailability: false);

        $cart->refresh();
        // Database stores the dates as provided (backwards order)
        $this->assertEquals($from->toDateTimeString(), $cart->from->toDateTimeString());
        $this->assertEquals($until->toDateTimeString(), $cart->until->toDateTimeString());
    }

    /** @test */
    public function cart_item_uses_own_dates_when_set()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => false,
        ]);

        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'is_default' => true,

        ]);

        $cart = Cart::factory()->create([
            'from' => Carbon::now()->addDays(1),
            'until' => Carbon::now()->addDays(3),
        ]);

        $itemFromDate = Carbon::now()->addDays(5);
        $itemUntilDate = Carbon::now()->addDays(7);

        $item = $cart->addToCart($product, 1);
        $item->updateDates($itemFromDate, $itemUntilDate);

        $this->assertEquals($itemFromDate->toDateString(), $item->getEffectiveFromDate()->toDateString());
        $this->assertEquals($itemUntilDate->toDateString(), $item->getEffectiveUntilDate()->toDateString());
    }

    /** @test */
    public function cart_item_falls_back_to_cart_dates_when_no_own_dates()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => false,
        ]);

        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'is_default' => true,

        ]);

        $cartFromDate = Carbon::now()->addDays(1);
        $cartUntilDate = Carbon::now()->addDays(3);

        $cart = Cart::factory()->create([
            'from' => $cartFromDate,
            'until' => $cartUntilDate,
        ]);

        $item = $cart->addToCart($product, 1);

        $this->assertEquals($cartFromDate->toDateString(), $item->getEffectiveFromDate()->toDateString());
        $this->assertEquals($cartUntilDate->toDateString(), $item->getEffectiveUntilDate()->toDateString());
    }

    /** @test */
    public function cart_item_returns_null_when_no_dates_available()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => false,
        ]);

        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'is_default' => true,

        ]);

        $cart = Cart::factory()->create();
        $item = $cart->addToCart($product, 1);

        $this->assertNull($item->getEffectiveFromDate());
        $this->assertNull($item->getEffectiveUntilDate());
        $this->assertFalse($item->hasEffectiveDates());
    }

    /** @test */
    public function cart_item_has_effective_dates_returns_true_when_dates_are_set()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => false,
        ]);

        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'is_default' => true,

        ]);

        $cart = Cart::factory()->create([
            'from' => Carbon::now()->addDays(1),
            'until' => Carbon::now()->addDays(3),
        ]);

        $item = $cart->addToCart($product, 1);

        $this->assertTrue($item->hasEffectiveDates());
    }

    /** @test */
    public function apply_dates_to_items_sets_dates_on_items_without_dates()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => false,
        ]);

        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'is_default' => true,

        ]);

        $cart = Cart::factory()->create();
        $item = $cart->addToCart($product, 1);

        $this->assertNull($item->from);
        $this->assertNull($item->until);

        $fromDate = Carbon::now()->addDays(1);
        $untilDate = Carbon::now()->addDays(3);

        $cart->setDates($fromDate, $untilDate, validateAvailability: false);
        $cart->applyDatesToItems(validateAvailability: false);

        $item->refresh();
        $this->assertNotNull($item->from);
        $this->assertNotNull($item->until);
        $this->assertEquals($fromDate->toDateString(), $item->from->toDateString());
        $this->assertEquals($untilDate->toDateString(), $item->until->toDateString());
    }

    /** @test */
    public function apply_dates_to_items_does_not_override_existing_item_dates()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => false,
        ]);

        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'is_default' => true,

        ]);

        $cart = Cart::factory()->create();
        $item = $cart->addToCart($product, 1);

        $itemFromDate = Carbon::now()->addDays(5);
        $itemUntilDate = Carbon::now()->addDays(7);
        $item->updateDates($itemFromDate, $itemUntilDate);

        $cartFromDate = Carbon::now()->addDays(1);
        $cartUntilDate = Carbon::now()->addDays(3);

        $cart->setDates($cartFromDate, $cartUntilDate, validateAvailability: false, overwrite_item_dates: false);
        $cart->applyDatesToItems(validateAvailability: false, overwrite: false);

        $item->refresh();
        // Item dates should remain unchanged when overwrite is false
        $this->assertEquals($itemFromDate->toDateString(), $item->from->toDateString());
        $this->assertEquals($itemUntilDate->toDateString(), $item->until->toDateString());
    }

    /** @test */
    public function apply_dates_to_items_overwrites_when_overwrite_is_true()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => false,
        ]);

        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'is_default' => true,

        ]);

        $cart = Cart::factory()->create();
        $item = $cart->addToCart($product, 1);

        $itemFromDate = Carbon::now()->addDays(5);
        $itemUntilDate = Carbon::now()->addDays(7);
        $item->updateDates($itemFromDate, $itemUntilDate);

        $cartFromDate = Carbon::now()->addDays(1);
        $cartUntilDate = Carbon::now()->addDays(3);

        $cart->setDates($cartFromDate, $cartUntilDate, validateAvailability: false);
        $cart->applyDatesToItems(validateAvailability: false, overwrite: true);

        $item->refresh();
        // Item dates should be overwritten with cart dates when overwrite is true
        $this->assertEquals($cartFromDate->toDateString(), $item->from->toDateString());
        $this->assertEquals($cartUntilDate->toDateString(), $item->until->toDateString());
    }

    /** @test */
    public function apply_dates_to_items_fills_only_null_from_date_when_overwrite_false()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => false,
        ]);

        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'is_default' => true,

        ]);

        $cart = Cart::factory()->create();
        $item = $cart->addToCart($product, 1);

        // Only set 'until' date on the item, leave 'from' as null
        $itemUntilDate = Carbon::now()->addDays(7);
        $item->until = $itemUntilDate;
        $item->save();

        $cartFromDate = Carbon::now()->addDays(1);
        $cartUntilDate = Carbon::now()->addDays(3);

        $cart->setDates($cartFromDate, $cartUntilDate, validateAvailability: false, overwrite_item_dates: false);
        $cart->applyDatesToItems(validateAvailability: false, overwrite: false);

        $item->refresh();
        // 'from' should be filled from cart, 'until' should remain unchanged
        $this->assertEquals($cartFromDate->toDateString(), $item->from->toDateString());
        $this->assertEquals($itemUntilDate->toDateString(), $item->until->toDateString());
    }

    /** @test */
    public function apply_dates_to_items_fills_only_null_until_date_when_overwrite_false()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => false,
        ]);

        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'is_default' => true,

        ]);

        $cart = Cart::factory()->create();
        $item = $cart->addToCart($product, 1);

        // Only set 'from' date on the item, leave 'until' as null
        $itemFromDate = Carbon::now()->addDays(1);
        $item->from = $itemFromDate;
        $item->save();

        $cartFromDate = Carbon::now()->addDays(5);
        $cartUntilDate = Carbon::now()->addDays(7);

        $cart->setDates($cartFromDate, $cartUntilDate, validateAvailability: false, overwrite_item_dates: false);
        $cart->applyDatesToItems(validateAvailability: false, overwrite: false);

        $item->refresh();
        // 'from' should remain unchanged, 'until' should be filled from cart
        $this->assertEquals($itemFromDate->toDateString(), $item->from->toDateString());
        $this->assertEquals($cartUntilDate->toDateString(), $item->until->toDateString());
    }

    /** @test */
    public function is_ready_to_checkout_uses_cart_fallback_dates()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => false,
        ]);

        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'is_default' => true,

        ]);

        $cart = Cart::factory()->create([
            'from' => Carbon::now()->addDays(1),
            'until' => Carbon::now()->addDays(3),
        ]);

        $item = $cart->addToCart($product, 1);

        // Item should be ready because it uses cart dates
        $this->assertTrue($item->is_ready_to_checkout);
    }

    /** @test */
    public function cart_item_set_from_date_throws_invalid_date_range_exception()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => false,
        ]);

        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'is_default' => true,

        ]);

        $cart = Cart::factory()->create();
        $item = $cart->addToCart($product, 1);

        $item->setUntilDate(Carbon::now()->addDays(2));

        $this->expectException(InvalidDateRangeException::class);
        $item->setFromDate(Carbon::now()->addDays(3));
    }

    /** @test */
    public function cart_item_set_until_date_throws_invalid_date_range_exception()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => false,
        ]);

        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'is_default' => true,

        ]);

        $cart = Cart::factory()->create();
        $item = $cart->addToCart($product, 1);

        $item->setFromDate(Carbon::now()->addDays(3));

        $this->expectException(InvalidDateRangeException::class);
        $item->setUntilDate(Carbon::now()->addDays(2));
    }

    /** @test */
    public function validate_date_availability_marks_items_unavailable_when_product_not_available()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
            'stock_quantity' => 1,
        ]);

        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'is_default' => true,

        ]);

        $cart = Cart::factory()->create();
        $item = $cart->addToCart($product, 1);

        // Set item dates that consume the stock
        $item->updateDates(Carbon::now()->addDays(1), Carbon::now()->addDays(3));

        // Try to set cart dates that overlap - should NOT throw, instead mark items unavailable
        $cart->setDates(Carbon::now()->addDays(2), Carbon::now()->addDays(4), validateAvailability: true);

        // Item should now be marked as unavailable (null price)
        $item->refresh();
        $this->assertNull($item->price, 'Unavailable item should have null price');
        $this->assertFalse($item->is_ready_to_checkout, 'Unavailable item should not be ready for checkout');
    }

    /** @test */
    public function apply_dates_to_items_marks_items_unavailable_when_product_not_available()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
            'stock_quantity' => 1,
        ]);

        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'is_default' => true,

        ]);

        // Create cart WITHOUT dates first (so addToCart doesn't validate)
        $cart = Cart::factory()->create();

        // Add item that would exceed available stock (qty=2 but stock=1)
        // This succeeds because cart has no dates yet, so no availability validation
        $item = $cart->addToCart($product, 2);

        // Now set dates on the cart with validation enabled
        // This triggers applyDatesToItems which should mark items as unavailable
        // rather than throwing an exception
        $cart->setDates(
            Carbon::now()->addDays(1),
            Carbon::now()->addDays(3),
            validateAvailability: true
        );

        // Item should be marked as unavailable (null price)
        $item->refresh();
        $this->assertNull($item->price, 'Unavailable item should have null price');
        $this->assertFalse($item->is_ready_to_checkout, 'Unavailable item should not be ready for checkout');
    }

    /** @test */
    public function can_skip_validation_when_setting_dates()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
            'stock_quantity' => 0, // No stock available
        ]);

        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'is_default' => true,

        ]);

        $cart = Cart::factory()->create();
        $item = $cart->addToCart($product, 1);

        // Should not throw exception when validation is disabled
        $cart->setDates(
            Carbon::now()->addDays(1),
            Carbon::now()->addDays(3),
            validateAvailability: false
        );

        $this->assertNotNull($cart->from);
        $this->assertNotNull($cart->until);
    }
}
