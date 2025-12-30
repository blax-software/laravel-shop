<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PricingStrategy;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;

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

    #[Test]
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

    #[Test]
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

        // Set pricing strategy to average: (2000 + 5000) / 2 = 3500
        $this->poolProduct->setPricingStrategy(PricingStrategy::AVERAGE);

        // Pool should inherit average: (2000 + 5000) / 2 = 3500
        $cartItem = $this->cart->addToCart($this->poolProduct, 1);

        $this->assertNotNull($cartItem);
        $this->assertEquals($this->poolProduct->id, $cartItem->purchasable_id);
        $this->assertEquals(1, $cartItem->quantity);
        $this->assertEquals(3500, $cartItem->price); // Average: 35.00
        $this->assertEquals(3500, $cartItem->subtotal);
    }

    #[Test]
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

    #[Test]
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

        // Set pricing strategy to average: (2000 + 5000) / 2 = 3500 per day
        $this->poolProduct->setPricingStrategy(PricingStrategy::AVERAGE);

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

    #[Test]
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

        // Adding 2 pool items creates separate cart items (one per single item)
        // because each single item has its own stock limit
        $cartItem = $this->cart->addToCart($this->poolProduct, 2, [], $from, $until);

        // Returns the last cart item created (quantity 1)
        $this->assertEquals(1, $cartItem->quantity);
        $this->assertEquals(12500, $cartItem->price); // 25.00 × 5 days per unit
        $this->assertEquals(12500, $cartItem->subtotal); // 125.00 × 1 quantity

        // But total cart should have 2 items with combined subtotal
        $this->assertEquals(2, $this->cart->fresh()->items->count());
        $this->assertEquals(25000, $this->cart->fresh()->getTotal()); // 125.00 × 2 units = 250.00
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function it_creates_separate_items_when_adding_same_pool_product_with_same_dates()
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

        // Items from different single items don't merge, even with same dates
        $this->assertNotEquals($cartItem1->id, $cartItem2->id);
        $this->assertEquals(1, $cartItem1->quantity);
        $this->assertEquals(1, $cartItem2->quantity);

        // Both items have the same price since they use pool fallback
        $this->assertEquals(6000, $cartItem1->subtotal); // 3000 × 2 days × 1 unit
        $this->assertEquals(6000, $cartItem2->subtotal); // 3000 × 2 days × 1 unit

        // Total cart subtotal
        $this->assertEquals(12000, $this->cart->fresh()->getTotal()); // 6000 × 2 = 12000
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

        // Set pricing strategy to average
        $this->poolProduct->setPricingStrategy(PricingStrategy::AVERAGE);

        $cartItem = $this->cart->addToCart($this->poolProduct, 1, [], $from, $until);

        // Average sale price: (3000 + 5000) / 2 = 4000 per day
        $this->assertEquals(4000, $cartItem->price);
        // Average regular price: (5000 + 7000) / 2 = 6000 per day
        $this->assertEquals(6000, $cartItem->regular_price);
    }

    #[Test]
    public function it_handles_zero_days_as_one_day_minimum()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 30,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Same day booking (4 hours)
        $from = Carbon::now()->addDays(1)->setTime(10, 0);
        $until = Carbon::now()->addDays(1)->setTime(14, 0);

        $cartItem = $this->cart->addToCart($this->poolProduct, 1, [], $from, $until);

        // 4 hours = 0.1667 days, 30 * 0.1667 = 5.00 (rounded to 2 decimals)
        $this->assertEquals('5.00', $cartItem->price);
    }

    #[Test]
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

    #[Test]
    public function it_allows_adding_pool_to_cart_when_claimed_but_validates_at_checkout()
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

        // Adding to cart should succeed (lenient - uses total capacity)
        $this->cart->addToCart($this->poolProduct, 1, [], $from, $until);

        // But checkout validation should fail
        $this->assertFalse($this->cart->validateForCheckout(false));
    }

    #[Test]
    public function it_allows_adding_booking_to_cart_when_claimed_but_validates_at_checkout()
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

        // Adding to cart should succeed (lenient - uses total capacity)
        $this->cart->addToCart($bookingProduct, 1, [], $from, $until);

        // But checkout validation should fail
        $this->assertFalse($this->cart->validateForCheckout(false));
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function it_limits_pool_in_cart_quantity_by_single_products()
    {
        // Set price on pool
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Assert cart is empty
        $this->assertEquals(0, $this->cart->items()->count());

        // Assert poolProduct has quantity availability of 2 (based on 2 single items)
        $availableQuantity = $this->poolProduct->getAvailableQuantity();
        $this->assertEquals(2, $availableQuantity);

        // Set booking dates for the test
        $from = now()->addDays(1);
        $until = now()->addDays(3);

        // Adding 2 pool items creates 2 cart items (one per single item)
        $cartItem = $this->cart->addToCart($this->poolProduct, 2, [], $from, $until);
        $this->assertNotNull($cartItem);
        // Returns the last cart item (quantity 1)
        $this->assertEquals(1, $cartItem->quantity);
        // But total items should be 2
        $this->assertEquals(2, $this->cart->fresh()->items->sum('quantity'));

        // Try to add 1 more with dates (total would be 3, but only 2 available)
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $this->expectExceptionMessage('has only 0 items available'); // 2 total - 2 in cart = 0 remaining
        $this->cart->addToCart($this->poolProduct, 1, [], $from, $until);
    }

    #[Test]
    public function it_counts_single_item_stock_quantities_in_pool_availability()
    {
        // Create a pool with multiple single items having different stock quantities
        $pool = Product::factory()->create([
            'name' => 'Large Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create single items with different stock quantities
        $spot1 = Product::factory()->create([
            'name' => 'Standard Spot',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(5); // 5 units available

        $spot2 = Product::factory()->create([
            'name' => 'Premium Spot',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot2->increaseStock(3); // 3 units available

        $spot3 = Product::factory()->create([
            'name' => 'VIP Spot',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot3->increaseStock(2); // 2 units available

        // Attach single items to pool
        $pool->attachSingleItems([$spot1->id, $spot2->id, $spot3->id]);

        // Pool should have availability = 5 + 3 + 2 = 10
        $availableQuantity = $pool->getAvailableQuantity();
        $this->assertEquals(10, $availableQuantity);

        // Set price on pool
        ProductPrice::factory()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        // Set dates for validation
        $from = now()->addDays(1);
        $until = now()->addDays(3);

        // Adding 10 pool items creates multiple cart items (grouped by single item)
        // Since each single item stock is counted as 5+3+2=10
        $cartItem = $cart->addToCart($pool, 10, [], $from, $until);
        $this->assertNotNull($cartItem);
        // Returns the last cart item (from VIP Spot with 2 stock)
        $this->assertEquals(2, $cartItem->quantity);
        // But total items in cart should sum to 10
        $this->assertEquals(10, $cart->fresh()->items->sum('quantity'));

        // But not 11 - with dates for validation
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $this->expectExceptionMessage('has only 0 items available'); // 10 total - 10 in cart = 0 remaining
        $cart->addToCart($pool, 1, [], $from, $until);
    }

    #[Test]
    public function it_counts_available_stock_with_booking_dates()
    {
        // Create pool with single items having stock
        $pool = Product::factory()->create([
            'name' => 'Conference Room Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $room1 = Product::factory()->create([
            'name' => 'Room A',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $room1->increaseStock(3);

        $room2 = Product::factory()->create([
            'name' => 'Room B',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $room2->increaseStock(2);

        $pool->attachSingleItems([$room1->id, $room2->id]);

        ProductPrice::factory()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(2)->startOfDay();

        // Total availability should be 3 + 2 = 5
        $this->assertEquals(5, $pool->getAvailableQuantity($from, $until));

        $cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        // Adding 5 pool items creates multiple cart items (grouped by single item)
        $cartItem = $cart->addToCart($pool, 5, [], $from, $until);
        $this->assertNotNull($cartItem);
        // Returns the last cart item (from Room B with 2 stock)
        $this->assertEquals(2, $cartItem->quantity);
        // But total items in cart should sum to 5
        $this->assertEquals(5, $cart->fresh()->items->sum('quantity'));
    }

    #[Test]
    public function it_allows_unlimited_pool_when_single_items_dont_manage_stock()
    {
        // Create pool with single items that don't manage stock
        $pool = Product::factory()->create([
            'name' => 'Unlimited Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'Unlimited Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => false, // No stock management
        ]);

        $spot2 = Product::factory()->create([
            'name' => 'Unlimited Spot 2',
            'type' => ProductType::BOOKING,
            'manage_stock' => false, // No stock management
        ]);

        $pool->attachSingleItems([$spot1->id, $spot2->id]);

        ProductPrice::factory()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Pool should have unlimited availability
        $this->assertEquals(PHP_INT_MAX, $pool->getAvailableQuantity());

        $cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        // Should be able to add any quantity without dates
        $cartItem = $cart->addToCart($pool, 1000);
        $this->assertNotNull($cartItem);
        $this->assertEquals(1000, $cartItem->quantity);

        // And with dates
        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(2)->startOfDay();

        $cartItem2 = $cart->addToCart($pool, 500, [], $from, $until);
        $this->assertNotNull($cartItem2);
        $this->assertEquals(500, $cartItem2->quantity);
    }

    #[Test]
    public function it_picks_correct_price_for_pool_and_items_and_respects_stocks()
    {
        $this->actingAs($this->user);

        $pool = Product::factory()
            ->withPrices(1, 5000) // 50€
            ->create([
                'name' => 'Parking Pool',
                'type' => ProductType::POOL
            ]);

        $spot1 = Product::factory()
            ->withStocks(2)
            ->withPrices(1, 2000) // 20€
            ->create([
                'name' => 'Spot 1',
                'type' => ProductType::BOOKING,
            ]);

        $spot2 = Product::factory()
            ->withStocks(2)
            ->create([
                'name' => 'Spot 2',
                'type' => ProductType::BOOKING,
            ]);

        $spot3 = Product::factory()
            ->withStocks(2)
            ->withPrices(1, 8000) // 80€
            ->create([
                'name' => 'Spot 3',
                'type' => ProductType::BOOKING,
            ]);

        $pool->attachSingleItems([
            $spot1->id,
            $spot2->id,
            $spot3->id
        ]);

        // Pool should have availability of 6
        $this->assertEquals(6, $pool->getAvailableQuantity());

        $pool->setPoolPricingStrategy('lowest');

        $cart = $this->user->currentCart();

        $this->assertEquals(0, $cart->items()->count());

        // Set dates for booking to test progressive pricing with date-aware allocation
        $from = now()->addDays(1);
        $until = now()->addDays(3);

        // With flexible cart: adding with dates validates stock
        $this->assertThrows(
            fn() => $cartItem = $cart->addToCart($pool, 1000, [], $from, $until),
            \Blax\Shop\Exceptions\NotEnoughStockException::class
        );

        // 1. Addition - with dates for proper allocation
        // Price per day: 20.00, Booking: 2 days, Total: 40.00
        $this->assertEquals(2000, $pool->getCurrentPrice(cart: $cart, from: $from, until: $until)); // 20.00/day
        $this->assertEquals(2000, $pool->getLowestAvailablePoolPrice($from, $until)); // 20.00/day
        $this->assertEquals(8000, $pool->getHighestAvailablePoolPrice($from, $until)); // 80.00/day
        $cartItem = $cart->addToCart($pool, 1, [], $from, $until);

        $this->assertNotNull($cartItem);
        $this->assertEquals(4000, $cartItem->price); // 20.00/day × 2 days = 40.00
        $this->assertEquals(4000, $cartItem->subtotal); // 40.00 × 1

        // 2. Addition - should merge with 1st item (same single, same price, same dates)
        // Price per day: 20.00, Booking: 2 days, Total: 40.00
        $this->assertEquals(2000, $pool->getCurrentPrice(from: $from, until: $until)); // 20.00/day
        $this->assertEquals(2000, $pool->getLowestAvailablePoolPrice($from, $until)); // 20.00/day
        $this->assertEquals(8000, $pool->getHighestAvailablePoolPrice($from, $until)); // 80.00/day
        $cartItem = $cart->addToCart($pool, 1, [], $from, $until);

        $this->assertNotNull($cartItem);
        // Merges with 1st item: quantity becomes 2, subtotal becomes 80.00
        $this->assertEquals(2, $cartItem->quantity);
        $this->assertEquals(8000, $cartItem->subtotal); // 40.00 × 2

        // 3. Addition - first Spot1 unit exhausted, moves to second Spot1 unit
        // Both units of Spot1 now have 1 item each, so next item goes to Spot2
        // Price per day: 50.00 (Spot2 inherits from pool), Booking: 2 days, Total: 100.00
        $this->assertEquals(5000, $pool->getCurrentPrice(cart: $cart, from: $from, until: $until)); // 50.00/day
        $this->assertEquals(5000, $pool->getLowestAvailablePoolPrice($from, $until)); // 50.00/day
        $this->assertEquals(8000, $pool->getHighestAvailablePoolPrice($from, $until)); // 80.00/day
        $cartItem = $cart->addToCart($pool, 1, [], $from, $until);

        $this->assertNotNull($cartItem);
        $this->assertEquals(10000, $cartItem->price); // 50.00/day × 2 days = 100.00
        $this->assertEquals(10000, $cartItem->subtotal); // 100.00 × 1

        // 4. Addition - merges with 3rd item (same Spot2, same price, same dates)
        // Price per day: 50.00, Booking: 2 days, Total: 100.00
        $this->assertEquals(5000, $pool->getCurrentPrice(from: $from, until: $until)); // 50.00/day
        $this->assertEquals(5000, $pool->getLowestAvailablePoolPrice($from, $until)); // 50.00/day
        $this->assertEquals(8000, $pool->getHighestAvailablePoolPrice($from, $until)); // 80.00/day
        $cartItem = $cart->addToCart($pool, 1, [], $from, $until);

        $this->assertNotNull($cartItem);
        $this->assertEquals(2, $cartItem->quantity);
        $this->assertEquals(20000, $cartItem->subtotal); // 100.00 × 2

        // 5. Addition - Spot1 and Spot2 both exhausted, moves to Spot3
        // Price per day: 80.00, Booking: 2 days, Total: 160.00
        $this->assertEquals(8000, $pool->getCurrentPrice(cart: $cart, from: $from, until: $until)); // 80.00/day
        $this->assertEquals(8000, $pool->getLowestAvailablePoolPrice($from, $until)); // 80.00/day
        $this->assertEquals(8000, $pool->getHighestAvailablePoolPrice($from, $until)); // 80.00/day
        $cartItem = $cart->addToCart($pool, 1, [], $from, $until);

        $this->assertNotNull($cartItem);
        $this->assertEquals(16000, $cartItem->price); // 80.00/day × 2 days = 160.00
        $this->assertEquals(16000, $cartItem->subtotal); // 160.00 × 1

        // 6. Addition - merges with 5th item (same Spot3, same price, same dates)
        // Price per day: 80.00, Booking: 2 days, Total: 160.00
        $this->assertEquals(8000, $pool->getCurrentPrice(from: $from, until: $until)); // 80.00/day
        $this->assertEquals(8000, $pool->getLowestAvailablePoolPrice($from, $until)); // 80.00/day
        $this->assertEquals(8000, $pool->getHighestAvailablePoolPrice($from, $until)); // 80.00/day
        $cartItem = $cart->addToCart($pool, 1, [], $from, $until);

        $this->assertNotNull($cartItem);
        $this->assertEquals(2, $cartItem->quantity);
        $this->assertEquals(32000, $cartItem->subtotal); // 160.00 × 2

        $this->assertEquals(3, $cart->items()->count());

        $this->assertNull($pool->getCurrentPrice(from: $from, until: $until));
        $this->assertNull($pool->getLowestAvailablePoolPrice($from, $until));
        $this->assertNull($pool->getHighestAvailablePoolPrice($from, $until));
        $this->assertNull($pool->getCurrentPrice(cart: $cart, from: $from, until: $until));
        $this->assertNull($pool->getLowestAvailablePoolPrice($from, $until));
        $this->assertNull($pool->getHighestAvailablePoolPrice($from, $until));


        // 7. Addition - should fail because all 6 items are allocated for this period
        $this->assertThrows(
            fn() => $cart->addToCart($pool, 1, [], $from, $until),
            \Blax\Shop\Exceptions\NotEnoughStockException::class
        );
    }

    #[Test]
    public function it_picks_correct_price_respects_stocks_respects_timespan_for_price()
    {
        $this->actingAs($this->user);

        $pool = Product::factory()
            ->withPrices(1, 5000) // 50€
            ->create([
                'name' => 'Parking Pool',
                'type' => ProductType::POOL
            ]);

        $spot1 = Product::factory()
            ->withStocks(2)
            ->withPrices(1, 2000) // 20€
            ->create([
                'name' => 'Spot 1',
                'type' => ProductType::BOOKING,
            ]);

        $spot2 = Product::factory()
            ->withStocks(2)
            ->create([
                'name' => 'Spot 2',
                'type' => ProductType::BOOKING,
            ]);

        $spot3 = Product::factory()
            ->withStocks(2)
            ->withPrices(1, 8000) // 80€
            ->create([
                'name' => 'Spot 3',
                'type' => ProductType::BOOKING,
            ]);

        $pool->attachSingleItems([
            $spot1->id,
            $spot2->id,
            $spot3->id
        ]);

        $from = now()->addWeek();
        $until = now()->addWeek()->addDays(5); // 5 days

        // Pool should have availability of 6
        $this->assertEquals(6, $pool->getAvailableQuantity());

        $pool->setPoolPricingStrategy('lowest');

        $cart = $this->user->currentCart();

        $this->assertEquals(0, $cart->items()->count());

        // With flexible cart: adding without dates is allowed, but with dates stock is validated
        $this->assertThrows(
            fn() => $cartItem = $cart->addToCart($pool, 1000, [], $from, $until),
            \Blax\Shop\Exceptions\NotEnoughStockException::class
        );

        $cart->addToCart(
            $pool,
            3,
            [],
            $from,
            $until
        );

        $this->assertEqualsWithDelta(
            (2000 * 2 * 5) + (5000 * 1 * 5),
            $cart->getTotal(),
            0.01 // Allow 1 cent tolerance for floating point errors
        );
        $this->assertEquals(
            5000,
            $pool->getCurrentPrice()
        );

        $cart->addToCart(
            $pool,
            3,
            [],
            $from,
            $until
        );

        $this->assertEqualsWithDelta(
            (2000 * 2 * 5) + (5000 * 2 * 5) + (8000 * 2 * 5),
            $cart->getTotal(),
            0.01
        );
        $this->assertNull($pool->getCurrentPrice());

        $this->assertEquals(3, $cart->items()->count());

        // Clear cart
        $cart->items()->delete();

        $this->assertEquals(0, $cart->items()->count());
        $this->assertEquals(0, $cart->getTotal());

        // Make one spot unavailable for part of the period
        $spot2->adjustStock(
            StockType::CLAIMED,
            1,
            from: now()->addWeek()->addDays(2),
            until: now()->addWeek()->addDays(3)
        );

        $this->assertThrows(
            fn() => $cart->addToCart(
                $pool,
                6,
                [],
                $from,
                $until
            ),
            \Blax\Shop\Exceptions\NotEnoughStockException::class
        );

        $cart->addToCart(
            $pool,
            5,
            [],
            $from,
            $until
        );

        $this->assertEqualsWithDelta(
            (2000 * 2 * 5) + (5000 * 1 * 5) + (8000 * 2 * 5),
            $cart->getTotal(),
            0.01
        );

        $cart->removeFromCart($pool, 1);

        $this->assertEqualsWithDelta(
            (2000 * 2 * 5) + (5000 * 1 * 5) + (8000 * 1 * 5),
            $cart->getTotal(),
            0.01
        );
        $this->assertEquals(8000, $pool->getCurrentPrice());

        $cart->removeFromCart($pool, 1);

        $this->assertEqualsWithDelta(
            (2000 * 2 * 5) + (5000 * 1 * 5),
            $cart->getTotal(),
            0.01
        );

        // Get cart item with price 2000
        $cartItem = $cart->items()
            ->orderBy('price', 'asc')
            ->first();

        $cart->removeFromCart($cartItem, 1);

        $this->assertEqualsWithDelta(
            (2000 * 1 * 5) + (5000 * 1 * 5),
            $cart->getTotal(),
            0.01
        );
        $this->assertEquals(
            2000,
            $pool->getCurrentPrice()
        );

        $cart->addToCart(
            $pool,
            1,
            [],
            $from,
            $until
        );

        $this->assertEqualsWithDelta(
            (2000 * 2 * 5) + (5000 * 1 * 5),
            $cart->getTotal(),
            0.01
        );

        // Get cart item with price 2000
        $cartItem = $cart->items()
            ->orderBy('price', 'asc')
            ->first();

        $cart->removeFromCart($cartItem, 2);

        $this->assertEqualsWithDelta(
            (5000 * 1 * 5),
            $cart->getTotal(),
            0.01
        );

        $this->assertEquals(2000, $pool->getCurrentPrice());

        $cart->addToCart(
            $pool,
            4,
            [],
            $from,
            $until
        );

        $this->assertEqualsWithDelta(
            (2000 * 2 * 5) + (5000 * 1 * 5) + (8000 * 2 * 5),
            $cart->getTotal(),
            0.01
        );
    }
}
