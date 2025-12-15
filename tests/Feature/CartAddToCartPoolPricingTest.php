<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Workbench\App\Models\User;

class CartAddToCartPoolPricingTest extends TestCase
{
    protected User $user;
    protected Cart $cart;
    protected Product $poolProduct;
    protected Product $singleItem1;
    protected Product $singleItem2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        // Create pool product
        $this->poolProduct = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create single items
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

        // Link single items to pool
        $this->poolProduct->productRelations()->attach($this->singleItem1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
        $this->poolProduct->productRelations()->attach($this->singleItem2->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
    }

    /** @test */
    public function it_adds_pool_with_direct_price_to_cart_without_dates()
    {
        // Set direct price on pool
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000, // 30.00 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $cartItem = $this->cart->addToCart($this->poolProduct, 1);

        $this->assertNotNull($cartItem);
        $this->assertEquals($this->poolProduct->id, $cartItem->purchasable_id);
        $this->assertEquals(1, $cartItem->quantity);
        $this->assertEquals(3000, $cartItem->price); // 30.00 per day × 1 day
        $this->assertEquals(3000, $cartItem->subtotal); // 30.00 × 1 quantity
        $this->assertNull($cartItem->from);
        $this->assertNull($cartItem->until);
    }

    /** @test */
    public function it_adds_pool_with_inherited_price_to_cart_without_dates()
    {
        // Set prices on single items (20€ and 50€)
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000, // 20.00
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000, // 50.00
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Pool should inherit average: (2000 + 5000) / 2 = 3500
        $cartItem = $this->cart->addToCart($this->poolProduct, 1);

        $this->assertNotNull($cartItem);
        $this->assertEquals($this->poolProduct->id, $cartItem->purchasable_id);
        $this->assertEquals(1, $cartItem->quantity);
        $this->assertEquals(3500, $cartItem->price); // Average: 35.00
        $this->assertEquals(3500, $cartItem->subtotal);
    }

    /** @test */
    public function it_adds_pool_with_direct_price_to_cart_with_booking_dates()
    {
        // Set direct price on pool
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000, // 30.00 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(4)->startOfDay(); // 3 days
        $days = $from->diffInDays($until);

        $cartItem = $this->cart->addToCart($this->poolProduct, 1, [], $from, $until);

        $this->assertNotNull($cartItem);
        $this->assertEquals($this->poolProduct->id, $cartItem->purchasable_id);
        $this->assertEquals(1, $cartItem->quantity);
        $this->assertEquals(9000, $cartItem->price); // 30.00 × 3 days
        $this->assertEquals(9000, $cartItem->subtotal); // 90.00
        $this->assertEquals($from->format('Y-m-d H:i:s'), $cartItem->from->format('Y-m-d H:i:s'));
        $this->assertEquals($until->format('Y-m-d H:i:s'), $cartItem->until->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_adds_pool_with_inherited_price_to_cart_with_booking_dates()
    {
        // Set prices on single items (20€ and 50€)
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000, // 20.00 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000, // 50.00 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay(); // 2 days
        $days = $from->diffInDays($until);

        // Pool inherits average: (2000 + 5000) / 2 = 3500 per day
        $cartItem = $this->cart->addToCart($this->poolProduct, 1, [], $from, $until);

        $this->assertNotNull($cartItem);
        $this->assertEquals($this->poolProduct->id, $cartItem->purchasable_id);
        $this->assertEquals(1, $cartItem->quantity);
        $this->assertEquals(7000, $cartItem->price); // 35.00 × 2 days
        $this->assertEquals(7000, $cartItem->subtotal);
        $this->assertEquals($from->format('Y-m-d H:i:s'), $cartItem->from->format('Y-m-d H:i:s'));
        $this->assertEquals($until->format('Y-m-d H:i:s'), $cartItem->until->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_calculates_price_for_multiple_pool_items_with_booking_dates()
    {
        // Set direct price on pool
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2500, // 25.00 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(6)->startOfDay(); // 5 days

        $cartItem = $this->cart->addToCart($this->poolProduct, 2, [], $from, $until);

        $this->assertEquals(2, $cartItem->quantity);
        $this->assertEquals(12500, $cartItem->price); // 25.00 × 5 days per unit
        $this->assertEquals(25000, $cartItem->subtotal); // 125.00 × 2 units = 250.00
    }

    /** @test */
    public function it_uses_lowest_pricing_strategy_with_mixed_single_item_prices()
    {
        // Set prices on single items (20€ and 50€)
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000, // 20.00
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000, // 50.00
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Set pricing strategy to lowest
        $this->poolProduct->setPoolPricingStrategy('lowest');

        $cartItem = $this->cart->addToCart($this->poolProduct, 1);

        $this->assertEquals(2000, $cartItem->price); // Lowest: 20.00
        $this->assertEquals(2000, $cartItem->subtotal);
    }

    /** @test */
    public function it_uses_highest_pricing_strategy_with_mixed_single_item_prices()
    {
        // Set prices on single items (20€ and 50€)
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000, // 20.00
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000, // 50.00
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Set pricing strategy to highest
        $this->poolProduct->setPoolPricingStrategy('highest');

        $cartItem = $this->cart->addToCart($this->poolProduct, 1);

        $this->assertEquals(5000, $cartItem->price); // Highest: 50.00
        $this->assertEquals(5000, $cartItem->subtotal);
    }

    /** @test */
    public function it_uses_lowest_pricing_strategy_with_booking_dates()
    {
        // Set prices on single items (20€ and 50€)
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000, // 20.00 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000, // 50.00 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Set pricing strategy to lowest
        $this->poolProduct->setPoolPricingStrategy('lowest');

        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(4)->startOfDay(); // 3 days

        $cartItem = $this->cart->addToCart($this->poolProduct, 1, [], $from, $until);

        $this->assertEquals(6000, $cartItem->price); // 20.00 × 3 days
        $this->assertEquals(6000, $cartItem->subtotal);
    }

    /** @test */
    public function it_adds_regular_product_to_cart_without_dates()
    {
        $regularProduct = Product::factory()->create([
            'name' => 'Regular Product',
            'type' => ProductType::SIMPLE,
            'manage_stock' => true,
        ]);
        $regularProduct->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $regularProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1500, // 15.00
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $cartItem = $this->cart->addToCart($regularProduct, 2);

        $this->assertEquals(2, $cartItem->quantity);
        $this->assertEquals(1500, $cartItem->price);
        $this->assertEquals(3000, $cartItem->subtotal);
        $this->assertNull($cartItem->from);
        $this->assertNull($cartItem->until);
    }

    /** @test */
    public function it_increases_quantity_when_adding_same_pool_product_with_same_dates()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay();

        $cartItem1 = $this->cart->addToCart($this->poolProduct, 1, [], $from, $until);
        $cartItem2 = $this->cart->addToCart($this->poolProduct, 1, [], $from, $until);

        $this->assertEquals($cartItem1->id, $cartItem2->id);
        $this->assertEquals(2, $cartItem2->quantity);
        $this->assertEquals(12000, $cartItem2->subtotal); // 3000 × 2 days × 2 units
    }

    /** @test */
    public function it_creates_separate_cart_items_for_same_pool_with_different_dates()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from1 = Carbon::now()->addDays(1)->startOfDay();
        $until1 = Carbon::now()->addDays(3)->startOfDay();

        $from2 = Carbon::now()->addDays(5)->startOfDay();
        $until2 = Carbon::now()->addDays(7)->startOfDay();

        $cartItem1 = $this->cart->addToCart($this->poolProduct, 1, [], $from1, $until1);
        $cartItem2 = $this->cart->addToCart($this->poolProduct, 1, [], $from2, $until2);

        $this->assertNotEquals($cartItem1->id, $cartItem2->id);
        $this->assertEquals(1, $cartItem1->quantity);
        $this->assertEquals(1, $cartItem2->quantity);
        $this->assertEquals(2, $this->cart->items()->count());
    }

    /** @test */
    public function it_calculates_correct_total_for_cart_with_multiple_pool_items()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2500, // 25.00 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(4)->startOfDay(); // 3 days

        // Add 2 units
        $this->cart->addToCart($this->poolProduct, 2, [], $from, $until);

        $total = $this->cart->getTotal();

        // 25.00 × 3 days × 2 units = 150.00
        $this->assertEquals(15000, $total);
    }

    /** @test */
    public function it_handles_pool_with_sale_price()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000, // 50.00
            'sale_unit_amount' => 3000, // 30.00 (sale)
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Set sale period
        $this->poolProduct->update([
            'sale_start' => now()->subDay(),
            'sale_end' => now()->addDay(),
        ]);

        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay(); // 2 days

        $cartItem = $this->cart->addToCart($this->poolProduct, 1, [], $from, $until);

        $this->assertEquals(6000, $cartItem->price); // 30.00 × 2 days (sale price)
        $this->assertEquals(10000, $cartItem->regular_price); // 50.00 × 2 days (regular price)
    }

    /** @test */
    public function it_handles_pool_with_inherited_sale_price()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'sale_unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 7000,
            'sale_unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Set sale period on single items
        $this->singleItem1->update([
            'sale_start' => now()->subDay(),
            'sale_end' => now()->addDay(),
        ]);

        $this->singleItem2->update([
            'sale_start' => now()->subDay(),
            'sale_end' => now()->addDay(),
        ]);

        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(2)->startOfDay(); // 1 day

        $cartItem = $this->cart->addToCart($this->poolProduct, 1, [], $from, $until);

        // Average sale price: (3000 + 5000) / 2 = 4000 per day
        $this->assertEquals(4000, $cartItem->price);
        // Average regular price: (5000 + 7000) / 2 = 6000 per day
        $this->assertEquals(6000, $cartItem->regular_price);
    }

    /** @test */
    public function it_handles_zero_days_as_one_day_minimum()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Same day booking (0 days diff)
        $from = Carbon::now()->addDays(1)->setTime(10, 0);
        $until = Carbon::now()->addDays(1)->setTime(14, 0);

        $cartItem = $this->cart->addToCart($this->poolProduct, 1, [], $from, $until);

        // Should treat as minimum 1 day
        $this->assertEquals(3000, $cartItem->price); // 30.00 × 1 day
    }

    /** @test */
    public function it_throws_exception_when_adding_pool_without_any_pricing()
    {
        // Pool with no direct price and single items with no prices
        $pool = Product::factory()->create([
            'name' => 'Pool Without Pricing',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $spot = Product::factory()->create([
            'name' => 'Spot Without Price',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot->increaseStock(1);

        $pool->productRelations()->attach($spot->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        $this->expectException(\Blax\Shop\Exceptions\HasNoPriceException::class);
        $this->cart->addToCart($pool, 1);
    }

    /** @test */
    public function it_throws_exception_when_pool_not_available_for_booking_period()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay();

        // Claim all single items for the period
        $this->singleItem1->claimStock(1, null, $from, $until);
        $this->singleItem2->claimStock(1, null, $from, $until);

        // Try to add pool for same period
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $this->expectExceptionMessage('has only 0 items available');
        $this->cart->addToCart($this->poolProduct, 1, [], $from, $until);
    }

    /** @test */
    public function it_throws_exception_when_booking_product_not_available_for_period()
    {
        $bookingProduct = Product::factory()->create([
            'name' => 'Meeting Room',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $bookingProduct->increaseStock(1);

        ProductPrice::factory()->create([
            'purchasable_id' => $bookingProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay();

        // Claim the booking product for the period
        $bookingProduct->claimStock(1, null, $from, $until);

        // Try to add for overlapping period
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $this->expectExceptionMessage('not available for the requested period');
        $this->cart->addToCart($bookingProduct, 1, [], $from, $until);
    }

    /** @test */
    public function it_throws_exception_when_only_from_date_provided()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Both 'from' and 'until' dates must be provided together");
        $this->cart->addToCart($this->poolProduct, 1, [], $from, null);
    }

    /** @test */
    public function it_throws_exception_when_only_until_date_provided()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $until = Carbon::now()->addDays(3);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Both 'from' and 'until' dates must be provided together");
        $this->cart->addToCart($this->poolProduct, 1, [], null, $until);
    }

    /** @test */
    public function it_throws_exception_when_from_is_after_until()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(5);
        $until = Carbon::now()->addDays(2); // Before from

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("'from' date must be before the 'until' date");
        $this->cart->addToCart($this->poolProduct, 1, [], $from, $until);
    }

    /** @test */
    public function it_throws_exception_when_from_equals_until()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $date = Carbon::now()->addDays(1);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("'from' date must be before the 'until' date");
        $this->cart->addToCart($this->poolProduct, 1, [], $date, $date);
    }

    /** @test */
    public function it_creates_separate_items_for_same_product_same_dates_different_parameters()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cartItem1 = $this->cart->addToCart($this->poolProduct, 1, ['zone' => 'A'], $from, $until);
        $cartItem2 = $this->cart->addToCart($this->poolProduct, 1, ['zone' => 'B'], $from, $until);

        $this->assertNotEquals($cartItem1->id, $cartItem2->id);
        $this->assertEquals(2, $this->cart->items()->count());
        $this->assertEquals(['zone' => 'A'], $cartItem1->parameters);
        $this->assertEquals(['zone' => 'B'], $cartItem2->parameters);
    }

    /** @test */
    public function it_throws_exception_when_pool_quantity_exceeds_available_items()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        // Pool has 2 single items, try to add 3 (with dates to check availability)
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $this->expectExceptionMessage('has only 2 items available');
        $this->cart->addToCart($this->poolProduct, 3, [], $from, $until);
    }

    /** @test */
    public function it_handles_partial_pool_availability()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        // Claim one of the two single items
        $this->singleItem1->claimStock(1, null, $from, $until);

        // Should be able to add 1 (one spot still available)
        $cartItem = $this->cart->addToCart($this->poolProduct, 1, [], $from, $until);
        $this->assertNotNull($cartItem);

        // But not 2
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $this->cart->addToCart($this->poolProduct, 2, [], $from, $until);
    }

    /** @test */
    public function it_throws_exception_for_regular_product_without_price()
    {
        $regularProduct = Product::factory()->create([
            'name' => 'Product Without Price',
            'type' => ProductType::SIMPLE,
            'manage_stock' => true,
        ]);
        $regularProduct->increaseStock(10);

        $this->expectException(\Blax\Shop\Exceptions\HasNoPriceException::class);
        $this->cart->addToCart($regularProduct, 1);
    }

    /** @test */
    public function it_allows_adding_booking_product_without_dates()
    {
        $bookingProduct = Product::factory()->create([
            'name' => 'Meeting Room',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $bookingProduct->increaseStock(5);

        ProductPrice::factory()->create([
            'purchasable_id' => $bookingProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Should be able to add without dates
        $cartItem = $this->cart->addToCart($bookingProduct, 1);

        $this->assertNotNull($cartItem);
        $this->assertEquals($bookingProduct->id, $cartItem->purchasable_id);
        $this->assertNull($cartItem->from);
        $this->assertNull($cartItem->until);
        $this->assertEquals(5000, $cartItem->price); // 1 day default
    }

    /** @test */
    public function it_allows_adding_pool_product_without_dates()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Should be able to add without dates
        $cartItem = $this->cart->addToCart($this->poolProduct, 1);

        $this->assertNotNull($cartItem);
        $this->assertEquals($this->poolProduct->id, $cartItem->purchasable_id);
        $this->assertNull($cartItem->from);
        $this->assertNull($cartItem->until);
        $this->assertEquals(3000, $cartItem->price); // 1 day default
    }

    /** @test */
    public function it_allows_updating_cart_item_dates_later()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Add without dates
        $cartItem = $this->cart->addToCart($this->poolProduct, 1);
        $this->assertNull($cartItem->from);
        $this->assertNull($cartItem->until);

        // Update with dates
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cartItem->update([
            'from' => $from,
            'until' => $until,
            'price' => 3000 * 2, // 2 days
            'subtotal' => 3000 * 2 * 1, // 2 days × 1 quantity
        ]);

        $cartItem->refresh();
        $this->assertEquals($from->format('Y-m-d H:i:s'), $cartItem->from->format('Y-m-d H:i:s'));
        $this->assertEquals($until->format('Y-m-d H:i:s'), $cartItem->until->format('Y-m-d H:i:s'));
        $this->assertEquals(6000, $cartItem->price);
    }
}
