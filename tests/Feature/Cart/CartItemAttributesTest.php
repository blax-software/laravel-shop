<?php

namespace Blax\Shop\Tests\Feature\Cart;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class CartItemAttributesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cart_item_has_is_booking_attribute_for_booking_products()
    {
        $bookingProduct = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->create(['type' => ProductType::BOOKING]);

        $cart = Cart::create();
        $cartItem = $cart->addToCart($bookingProduct, quantity: 1);

        $this->assertTrue($cartItem->is_booking);
    }

    #[Test]
    public function cart_item_has_is_booking_false_for_regular_products()
    {
        $regularProduct = Product::factory()
            ->withPrices(unit_amount: 50.00)
            ->create(['type' => ProductType::SIMPLE]);

        $cart = Cart::create();
        $cartItem = $cart->addToCart($regularProduct, quantity: 1);

        $this->assertFalse($cartItem->is_booking);
    }

    #[Test]
    public function cart_item_is_booking_works_via_price_id()
    {
        $bookingProduct = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->create(['type' => ProductType::BOOKING]);

        $cart = Cart::create();
        $cartItem = $cart->addToCart($bookingProduct, quantity: 1);

        // Verify price_id was set
        $this->assertNotNull($cartItem->price_id);

        // Reload and check is_booking still works
        $reloadedItem = CartItem::find($cartItem->id);
        $this->assertTrue($reloadedItem->is_booking);
    }

    #[Test]
    public function cart_is_full_booking_is_true_when_all_items_are_bookings()
    {
        $booking1 = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->create(['type' => ProductType::BOOKING]);

        $booking2 = Product::factory()
            ->withPrices(unit_amount: 150.00)
            ->create(['type' => ProductType::BOOKING]);

        $cart = Cart::create();
        $cart->addToCart($booking1, quantity: 1);
        $cart->addToCart($booking2, quantity: 1);

        $this->assertTrue($cart->is_full_booking);
    }

    #[Test]
    public function cart_is_full_booking_is_false_when_mixed_products()
    {
        $booking = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->create(['type' => ProductType::BOOKING]);

        $regular = Product::factory()
            ->withPrices(unit_amount: 50.00)
            ->create(['type' => ProductType::SIMPLE]);

        $cart = Cart::create();
        $cart->addToCart($booking, quantity: 1);
        $cart->addToCart($regular, quantity: 1);

        $this->assertFalse($cart->is_full_booking);
    }

    #[Test]
    public function cart_is_full_booking_is_false_when_empty()
    {
        $cart = Cart::create();

        $this->assertFalse($cart->is_full_booking);
    }

    #[Test]
    public function cart_booking_items_returns_correct_count()
    {
        $booking1 = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->create(['type' => ProductType::BOOKING]);

        $booking2 = Product::factory()
            ->withPrices(unit_amount: 150.00)
            ->create(['type' => ProductType::BOOKING]);

        $regular = Product::factory()
            ->withPrices(unit_amount: 50.00)
            ->create(['type' => ProductType::SIMPLE]);

        $cart = Cart::create();
        $cart->addToCart($booking1, quantity: 1);
        $cart->addToCart($booking2, quantity: 1);
        $cart->addToCart($regular, quantity: 1);

        $this->assertEquals(2, $cart->bookingItems());
    }

    #[Test]
    public function cart_booking_items_returns_zero_when_no_bookings()
    {
        $regular = Product::factory()
            ->withPrices(unit_amount: 50.00)
            ->create(['type' => ProductType::SIMPLE]);

        $cart = Cart::create();
        $cart->addToCart($regular, quantity: 1);

        $this->assertEquals(0, $cart->bookingItems());
    }

    #[Test]
    public function price_id_is_automatically_assigned_when_adding_product_to_cart()
    {
        $product = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->create();

        $cart = Cart::create();
        $cartItem = $cart->addToCart($product, quantity: 1);

        $this->assertNotNull($cartItem->price_id);

        // Access the relationship using the method, not property
        $this->assertInstanceOf(ProductPrice::class, $cartItem->price()->first());
    }

    #[Test]
    public function price_id_is_assigned_when_adding_product_price_to_cart()
    {
        $product = Product::factory()->create();
        $price = ProductPrice::factory()->create([
            'purchasable_type' => get_class($product),
            'purchasable_id' => $product->id,
            'unit_amount' => 100.00,
            'is_default' => true,
        ]);

        $cart = Cart::create();
        $cartItem = $cart->addToCart($price, quantity: 1);

        $this->assertEquals($price->id, $cartItem->price_id);

        // Access the relationship using the method, not property
        $this->assertInstanceOf(ProductPrice::class, $cartItem->price()->first());
    }

    #[Test]
    public function cart_stripe_price_ids_returns_array_of_stripe_price_ids()
    {
        $product1 = Product::factory()->create();
        $price1 = ProductPrice::factory()->create([
            'purchasable_type' => get_class($product1),
            'purchasable_id' => $product1->id,
            'stripe_price_id' => 'price_123',
            'unit_amount' => 100.00,
            'is_default' => true,
        ]);

        $product2 = Product::factory()->create();
        $price2 = ProductPrice::factory()->create([
            'purchasable_type' => get_class($product2),
            'purchasable_id' => $product2->id,
            'stripe_price_id' => 'price_456',
            'unit_amount' => 200.00,
            'is_default' => true,
        ]);

        $cart = Cart::create();
        $cart->addToCart($product1, quantity: 1);
        $cart->addToCart($product2, quantity: 1);

        $stripePriceIds = $cart->stripePriceIds();

        $this->assertCount(2, $stripePriceIds);
        $this->assertContains('price_123', $stripePriceIds);
        $this->assertContains('price_456', $stripePriceIds);
    }

    #[Test]
    public function cart_stripe_price_ids_returns_nulls_for_items_without_stripe_price_id()
    {
        $product1 = Product::factory()->create();
        $price1 = ProductPrice::factory()->create([
            'purchasable_type' => get_class($product1),
            'purchasable_id' => $product1->id,
            'stripe_price_id' => 'price_123',
            'unit_amount' => 100.00,
            'is_default' => true,
        ]);

        $product2 = Product::factory()->create();
        $price2 = ProductPrice::factory()->create([
            'purchasable_type' => get_class($product2),
            'purchasable_id' => $product2->id,
            'stripe_price_id' => null,
            'unit_amount' => 200.00,
            'is_default' => true,
        ]);

        $cart = Cart::create();
        $cart->addToCart($product1, quantity: 1);
        $cart->addToCart($product2, quantity: 1);

        $stripePriceIds = $cart->stripePriceIds();

        $this->assertCount(2, $stripePriceIds);
        $this->assertEquals('price_123', $stripePriceIds[0]);
        $this->assertNull($stripePriceIds[1]);
    }

    #[Test]
    public function cart_item_is_ready_to_checkout_is_true_for_regular_products()
    {
        $product = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->create(['type' => ProductType::SIMPLE]);

        $cart = Cart::create();
        $cartItem = $cart->addToCart($product, quantity: 1);

        $this->assertTrue($cartItem->is_ready_to_checkout);
    }

    #[Test]
    public function cart_item_is_ready_to_checkout_is_false_for_booking_without_dates()
    {
        $bookingProduct = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->create(['type' => ProductType::BOOKING]);

        $cart = Cart::create();
        $cartItem = $cart->addToCart($bookingProduct, quantity: 1);

        $this->assertFalse($cartItem->is_ready_to_checkout);
    }

    #[Test]
    public function cart_item_is_ready_to_checkout_is_true_for_booking_with_valid_dates()
    {
        $bookingProduct = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->withStocks(quantity: 10)
            ->create(['type' => ProductType::BOOKING]);

        $cart = Cart::create();
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cartItem = $cart->addToCart($bookingProduct, quantity: 1, from: $from, until: $until);

        $this->assertTrue($cartItem->is_ready_to_checkout);
    }

    #[Test]
    public function cart_item_is_ready_to_checkout_is_false_for_booking_with_invalid_date_range()
    {
        $bookingProduct = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->withStocks(quantity: 10)
            ->create(['type' => ProductType::BOOKING]);

        $cart = Cart::create();
        $cartItem = $cart->addToCart($bookingProduct, quantity: 1);

        // Manually set invalid dates (from >= until)
        $cartItem->update([
            'from' => Carbon::now()->addDays(3),
            'until' => Carbon::now()->addDays(1), // until before from
        ]);

        $this->assertFalse($cartItem->fresh()->is_ready_to_checkout);
    }

    #[Test]
    public function cart_is_ready_to_checkout_is_true_when_all_items_are_ready()
    {
        $product1 = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->create(['type' => ProductType::SIMPLE]);

        $product2 = Product::factory()
            ->withPrices(unit_amount: 150.00)
            ->create(['type' => ProductType::SIMPLE]);

        $cart = Cart::create();
        $cart->addToCart($product1, quantity: 1);
        $cart->addToCart($product2, quantity: 1);

        $this->assertTrue($cart->is_ready_to_checkout);
    }

    #[Test]
    public function cart_is_ready_to_checkout_is_false_when_at_least_one_item_not_ready()
    {
        $regularProduct = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->create(['type' => ProductType::SIMPLE]);

        $bookingProduct = Product::factory()
            ->withPrices(unit_amount: 150.00)
            ->create(['type' => ProductType::BOOKING]);

        $cart = Cart::create();
        $cart->addToCart($regularProduct, quantity: 1);
        $cart->addToCart($bookingProduct, quantity: 1); // No dates

        $this->assertFalse($cart->is_ready_to_checkout);
    }

    #[Test]
    public function cart_allows_adding_items_without_dates_that_require_them()
    {
        $bookingProduct = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->withStocks(quantity: 10) // Has stock
            ->create(['type' => ProductType::BOOKING]);

        $cart = Cart::create();

        // Add without dates - should be allowed
        $cartItem = $cart->addToCart($bookingProduct, quantity: 1);

        $this->assertInstanceOf(CartItem::class, $cartItem);

        // But is_ready_to_checkout should be false (missing dates)
        $this->assertFalse($cartItem->is_ready_to_checkout);
    }

    #[Test]
    public function update_dates_allows_setting_any_dates()
    {
        $bookingProduct = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->withStocks(quantity: 10) // Has stock
            ->create(['type' => ProductType::BOOKING]);

        $cart = Cart::create();
        $cartItem = $cart->addToCart($bookingProduct, quantity: 1);

        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        // Can set dates anytime
        $cartItem->updateDates($from, $until);

        $this->assertNotNull($cartItem->from);
        $this->assertNotNull($cartItem->until);

        // Should be ready to checkout now (has dates and stock)
        $this->assertTrue($cartItem->fresh()->is_ready_to_checkout);
    }

    #[Test]
    public function cart_calculates_correctly_when_dates_are_adjusted()
    {
        $bookingProduct = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->withStocks(quantity: 10)
            ->create(['type' => ProductType::BOOKING]);

        $cart = Cart::create();
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3); // 2 days

        $cartItem = $cart->addToCart($bookingProduct, quantity: 1, from: $from, until: $until);

        // Initial price for 2 days
        $this->assertEquals(200.00, $cartItem->price);
        $this->assertEquals(200.00, $cartItem->subtotal);

        // Adjust dates to 5 days
        $newUntil = Carbon::now()->addDays(6);
        $cartItem->updateDates($from, $newUntil);

        // Price should be recalculated for 5 days
        $this->assertEquals(500.00, $cartItem->fresh()->price);
        $this->assertEquals(500.00, $cartItem->fresh()->subtotal);
    }

    #[Test]
    public function set_from_date_recalculates_pricing_when_both_dates_set()
    {
        $bookingProduct = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->withStocks(quantity: 10)
            ->create(['type' => ProductType::BOOKING]);

        $cart = Cart::create();
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(4); // 3 days

        $cartItem = $cart->addToCart($bookingProduct, quantity: 1, from: $from, until: $until);

        // Initial price for 3 days
        $this->assertEquals(300.00, $cartItem->price);

        // Adjust from date to make it span more days (move 1 day earlier)
        $newFrom = $from->copy()->subDays(1);
        $cartItem->setFromDate($newFrom);

        // Price should be recalculated for 4 days
        $this->assertEquals(400.00, $cartItem->fresh()->price);
    }

    #[Test]
    public function set_until_date_recalculates_pricing_when_both_dates_set()
    {
        $bookingProduct = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->withStocks(quantity: 10)
            ->create(['type' => ProductType::BOOKING]);

        $cart = Cart::create();
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3); // 2 days

        $cartItem = $cart->addToCart($bookingProduct, quantity: 1, from: $from, until: $until);

        // Initial price for 2 days
        $this->assertEquals(200.00, $cartItem->price);

        // Adjust until date to make it 4 days
        $newUntil = Carbon::now()->addDays(5);
        $cartItem->setUntilDate($newUntil);

        // Price should be recalculated for 4 days
        $this->assertEquals(400.00, $cartItem->fresh()->price);
    }

    #[Test]
    public function is_ready_to_checkout_checks_stock_for_regular_products_with_stock_management()
    {
        $product = Product::factory()
            ->withPrices(unit_amount: 100.00)
            ->withStocks(quantity: 5)
            ->create([
                'type' => ProductType::SIMPLE,
                'manage_stock' => true,
            ]);

        $cart = Cart::create();

        // Add 3 items - should be ready
        $cartItem1 = $cart->addToCart($product, quantity: 3);
        $this->assertTrue($cartItem1->is_ready_to_checkout);

        // Add 5 more items - now exceeds stock
        $cartItem2 = $cart->addToCart($product, quantity: 5);

        // Both items should now show as not ready (total exceeds stock)
        $this->assertFalse($cartItem1->fresh()->is_ready_to_checkout);
        $this->assertFalse($cartItem2->fresh()->is_ready_to_checkout);
    }
}
