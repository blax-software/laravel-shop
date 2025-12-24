<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;


class CartItemTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cart_item_stores_prices_as_integers_in_cents()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 1550)->create(); // $15.50 in cents
        $price = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($price, quantity: 2);

        // Prices should be stored as integers (cents)
        $this->assertIsInt($cartItem->price);
        $this->assertIsInt($cartItem->regular_price);
        $this->assertIsInt($cartItem->subtotal);
        $this->assertIsInt($cartItem->unit_amount);

        // Verify values
        $this->assertEquals(1550, $cartItem->price);
        $this->assertEquals(1550, $cartItem->regular_price);
        $this->assertEquals(1550, $cartItem->unit_amount);
        $this->assertEquals(3100, $cartItem->subtotal); // 1550 * 2
    }

    #[Test]
    public function cart_item_unit_amount_represents_base_price_per_day()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 5000)->create(); // $50.00 per day
        $price = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($price, quantity: 1);

        // unit_amount should be the base price for 1 quantity, 1 day
        $this->assertEquals(5000, $cartItem->unit_amount);
        $this->assertEquals(5000, $cartItem->price); // Same as unit_amount for 1 day
    }

    #[Test]
    public function cart_item_calculates_price_correctly_for_booking_timespan()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 2000)->create(['type' => ProductType::BOOKING]); // $20.00 per day
        $price = $product->defaultPrice()->first();

        $from = Carbon::parse('2025-01-01 00:00:00');
        $until = Carbon::parse('2025-01-04 00:00:00'); // 3 days

        $cartItem = $cart->addToCart($price, quantity: 1, from: $from, until: $until);

        // unit_amount should still be the daily rate
        $this->assertEquals(2000, $cartItem->unit_amount);

        // price should be unit_amount * days (3 days)
        $this->assertEquals(6000, $cartItem->price); // 2000 * 3

        // subtotal should be price * quantity
        $this->assertEquals(6000, $cartItem->subtotal);
    }

    #[Test]
    public function cart_item_calculates_price_with_partial_days()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 4800)->create(['type' => ProductType::BOOKING]); // $48.00 per day
        $price = $product->defaultPrice()->first();

        // 12 hours = 0.5 days
        $from = Carbon::parse('2025-01-01 00:00:00');
        $until = Carbon::parse('2025-01-01 12:00:00');

        $cartItem = $cart->addToCart($price, quantity: 1, from: $from, until: $until);

        $this->assertEquals(4800, $cartItem->unit_amount);

        // Price should be approximately 2400 (4800 * 0.5 days)
        // Allow small rounding differences
        $this->assertEqualsWithDelta(2400, $cartItem->price, 1);
    }

    #[Test]
    public function cart_item_handles_multiple_quantities_correctly()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 1000)->create();
        $price = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($price, quantity: 5);

        $this->assertEquals(1000, $cartItem->unit_amount);
        $this->assertEquals(1000, $cartItem->price); // Price per unit
        $this->assertEquals(5000, $cartItem->subtotal); // 1000 * 5
    }

    #[Test]
    public function cart_item_updates_prices_when_dates_change()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 3000)->withStocks(10)->create(['type' => ProductType::BOOKING]); // $30.00 per day

        $from = Carbon::parse('2025-01-01 00:00:00');
        $until = Carbon::parse('2025-01-02 00:00:00'); // 1 day

        $cartItem = $cart->addToCart($product, quantity: 1, from: $from, until: $until);

        // Initial state: 1 day
        $this->assertEquals(3000, $cartItem->unit_amount);
        $this->assertEquals(3000, $cartItem->price);
        $this->assertEquals(3000, $cartItem->subtotal);

        // Update to 5 days
        $newUntil = Carbon::parse('2025-01-06 00:00:00');
        $cartItem->updateDates($from, $newUntil);

        // Refresh to get updated values
        $cartItem = $cartItem->fresh();

        // unit_amount should remain the same (daily rate)
        $this->assertEquals(3000, $cartItem->unit_amount);

        // price should now be for 5 days
        $this->assertEquals(15000, $cartItem->price); // 3000 * 5

        // subtotal should update accordingly
        $this->assertEquals(15000, $cartItem->subtotal);
    }

    #[Test]
    public function cart_item_handles_fractional_days_with_multiple_quantities()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 2400)->create(['type' => ProductType::BOOKING]); // $24.00 per day
        $price = $product->defaultPrice()->first();

        // 1.5 days
        $from = Carbon::parse('2025-01-01 00:00:00');
        $until = Carbon::parse('2025-01-02 12:00:00');

        $cartItem = $cart->addToCart($price, quantity: 3, from: $from, until: $until);

        $this->assertEquals(2400, $cartItem->unit_amount);

        // Price per unit should be 2400 * 1.5 = 3600
        $this->assertEqualsWithDelta(3600, $cartItem->price, 1);

        // Subtotal should be 3600 * 3 = 10800
        $this->assertEqualsWithDelta(10800, $cartItem->subtotal, 3);
    }

    #[Test]
    public function cart_item_subtotal_recalculates_on_quantity_change()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 1500)->create();
        $price = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($price, quantity: 2);

        $this->assertEquals(3000, $cartItem->subtotal); // 1500 * 2

        // Update quantity
        $cartItem->update(['quantity' => 5]);

        $this->assertEquals(7500, $cartItem->fresh()->subtotal); // 1500 * 5
    }

    #[Test]
    public function cart_item_stores_unit_amount_for_non_booking_products()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 9999)->create(['type' => ProductType::SIMPLE]);
        $price = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($price, quantity: 1);

        // Even for non-booking products, unit_amount should be set
        $this->assertEquals(9999, $cartItem->unit_amount);
        $this->assertEquals(9999, $cartItem->price);
        $this->assertEquals(9999, $cartItem->subtotal);
    }

    #[Test]
    public function cart_item_handles_sale_prices_in_cents()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(1, 50)->create([
            'sale_start' => now()->subDay(),
            'sale_end' => now()->addDay(),
        ]);

        $product->prices()->first()->update([
            'is_default' => false,
        ]);

        // Create a sale price
        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 10000, // $100.00 regular
            'sale_unit_amount' => 7500, // $75.00 sale
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $cartItem = $cart->addToCart($product, quantity: 1);

        // Should use sale price
        $this->assertEquals(7500, $cartItem->price);
        $this->assertEquals(7500, $cartItem->unit_amount);

        // Regular price should be stored too
        $this->assertEquals(10000, $cartItem->regular_price);

        $this->assertEquals(7500, $cartItem->subtotal);
    }

    #[Test]
    public function cart_item_rounds_prices_consistently()
    {
        $cart = Cart::create();

        // Create a product with a price that will result in fractional cents when multiplied
        $product = Product::factory()->withPrices(unit_amount: 3333)->create(['type' => ProductType::BOOKING]); // $33.33
        $price = $product->defaultPrice()->first();

        // 1.5 days should give 3333 * 1.5 = 4999.5 cents
        $from = Carbon::parse('2025-01-01 00:00:00');
        $until = Carbon::parse('2025-01-02 12:00:00');

        $cartItem = $cart->addToCart($price, quantity: 1, from: $from, until: $until);

        // Should round to nearest cent
        $this->assertIsInt($cartItem->price);
        $this->assertEquals(5000, $cartItem->price); // Rounded to 5000
    }

    #[Test]
    public function cart_item_unit_amount_remains_constant_across_date_updates()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 4500)->withStocks(10)->create(['type' => ProductType::BOOKING]);

        $from = Carbon::parse('2025-01-01');
        $until = Carbon::parse('2025-01-02'); // 1 day

        $cartItem = $cart->addToCart($product, quantity: 1, from: $from, until: $until);

        $originalUnitAmount = $cartItem->unit_amount;
        $this->assertEquals(4500, $originalUnitAmount);

        // Update to different date range (7 days)
        $cartItem->updateDates($from, Carbon::parse('2025-01-08'));
        $cartItem = $cartItem->fresh();

        // unit_amount should stay the same
        $this->assertEquals($originalUnitAmount, $cartItem->unit_amount);

        // But price should change
        $this->assertEquals(31500, $cartItem->price); // 4500 * 7
    }

    #[Test]
    public function cart_item_validates_database_storage_as_integer()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 2550)->create();
        $price = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($price, quantity: 2);

        // Fetch directly from database to verify storage type
        $dbItem = \DB::table('cart_items')->where('id', $cartItem->id)->first();

        // Database should store as integer, not decimal
        $this->assertIsInt($dbItem->price);
        $this->assertIsInt($dbItem->regular_price);
        $this->assertIsInt($dbItem->subtotal);
        $this->assertIsInt($dbItem->unit_amount);
    }

    #[Test]
    public function cart_item_getSubtotal_method_returns_correct_value()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 1234)->create();
        $price = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($price, quantity: 3);

        // getSubtotal() method should return the same as subtotal property
        $this->assertEquals($cartItem->subtotal, $cartItem->getSubtotal());
        $this->assertEquals(3702, $cartItem->getSubtotal()); // 1234 * 3
    }

    #[Test]
    public function cart_item_handles_zero_prices()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 0)->create(); // Free product
        $price = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($price, quantity: 5);

        $this->assertEquals(0, $cartItem->unit_amount);
        $this->assertEquals(0, $cartItem->price);
        $this->assertEquals(0, $cartItem->regular_price);
        $this->assertEquals(0, $cartItem->subtotal);
    }

    #[Test]
    public function cart_item_handles_very_long_booking_periods()
    {
        $cart = Cart::create();
        $product = Product::factory()
            ->withPrices(unit_amount: 500)
            ->create(['type' => ProductType::BOOKING]); // $5.00 per day

        $price = $product->defaultPrice()->first();

        // 365 days (1 year)
        $from = Carbon::parse('2025-01-01');
        $until = Carbon::parse('2026-01-01');

        $cartItem = $cart->addToCart($price, quantity: 1, from: $from, until: $until);

        $this->assertEquals(500, $cartItem->unit_amount);
        $this->assertEquals(182500, $cartItem->price); // 500 * 365
        $this->assertEquals(182500, $cartItem->subtotal);
    }
}
