<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PricingStrategy;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Workbench\App\Models\User;

class PoolProductPriceIdTest extends TestCase
{
    protected User $user;
    protected Cart $cart;
    protected Product $poolProduct;
    protected Product $singleItem1;
    protected Product $singleItem2;
    protected ProductPrice $price1;
    protected ProductPrice $price2;

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

        // Create single items with different prices
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

        // Set prices on single items
        $this->price1 = ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000, // $20/day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $this->price2 = ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000, // $50/day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Link single items to pool
        $this->poolProduct->productRelations()->attach($this->singleItem1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
        $this->poolProduct->productRelations()->attach($this->singleItem2->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
    }

    /** @test */
    public function it_stores_single_item_price_id_when_adding_pool_to_cart_with_lowest_strategy()
    {
        // Set pricing strategy to lowest (default)
        $this->poolProduct->setPricingStrategy(PricingStrategy::LOWEST);

        // Add pool to cart - should use the lowest price (singleItem1's price)
        $cartItem = $this->cart->addToCart($this->poolProduct, 1);

        // Assert the cart item has the price_id from the single item, not the pool
        $this->assertNotNull($cartItem->price_id);
        $this->assertEquals($this->price1->id, $cartItem->price_id);
        $this->assertEquals(2000, $cartItem->price); // $20
    }

    /** @test */
    public function it_stores_correct_price_id_for_second_pool_item_with_progressive_pricing()
    {
        // Set pricing strategy to lowest
        $this->poolProduct->setPricingStrategy(PricingStrategy::LOWEST);

        // Add first pool item - should use lowest price (singleItem1)
        $cartItem1 = $this->cart->addToCart($this->poolProduct, 1);
        $this->assertEquals($this->price1->id, $cartItem1->price_id);
        $this->assertEquals(2000, $cartItem1->price);

        // Add second pool item - should use next lowest price (singleItem2)
        $cartItem2 = $this->cart->addToCart($this->poolProduct, 1);
        $this->assertEquals($this->price2->id, $cartItem2->price_id);
        $this->assertEquals(5000, $cartItem2->price);
    }

    /** @test */
    public function it_stores_single_item_price_id_with_highest_strategy()
    {
        // Set pricing strategy to highest
        $this->poolProduct->setPoolPricingStrategy('highest');

        // Add pool to cart - should use the highest price (singleItem2's price)
        $cartItem = $this->cart->addToCart($this->poolProduct, 1);

        // Assert the cart item has the price_id from the single item with highest price
        $this->assertNotNull($cartItem->price_id);
        $this->assertEquals($this->price2->id, $cartItem->price_id);
        $this->assertEquals(5000, $cartItem->price); // $50
    }

    /** @test */
    public function it_stores_allocated_single_item_in_meta()
    {
        // Set pricing strategy to lowest
        $this->poolProduct->setPricingStrategy(PricingStrategy::LOWEST);

        // Add pool to cart
        $cartItem = $this->cart->addToCart($this->poolProduct, 1);

        // Check meta contains allocated single item info
        $meta = $cartItem->getMeta();
        $this->assertNotNull($meta->allocated_single_item_id ?? null);
        $this->assertEquals($this->singleItem1->id, $meta->allocated_single_item_id);
        $this->assertEquals($this->singleItem1->name, $meta->allocated_single_item_name);
    }

    /** @test */
    public function it_stores_different_single_items_in_meta_for_progressive_pricing()
    {
        // Set pricing strategy to lowest
        $this->poolProduct->setPricingStrategy(PricingStrategy::LOWEST);

        // Add first pool item
        $cartItem1 = $this->cart->addToCart($this->poolProduct, 1);
        $meta1 = $cartItem1->getMeta();
        $this->assertEquals($this->singleItem1->id, $meta1->allocated_single_item_id);

        // Add second pool item
        $cartItem2 = $this->cart->addToCart($this->poolProduct, 1);
        $meta2 = $cartItem2->getMeta();
        $this->assertEquals($this->singleItem2->id, $meta2->allocated_single_item_id);
    }

    /** @test */
    public function it_uses_pool_price_id_when_pool_has_direct_price_and_no_single_item_prices()
    {
        // Remove prices from single items
        $this->price1->delete();
        $this->price2->delete();

        // Set a direct price on the pool itself
        $poolPrice = ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000, // $30
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Add pool to cart - should use pool's direct price as fallback
        $cartItem = $this->cart->addToCart($this->poolProduct, 1);

        // Assert the cart item has the pool's price_id
        $this->assertEquals($poolPrice->id, $cartItem->price_id);
        $this->assertEquals(3000, $cartItem->price);

        // Meta should indicate which single item was allocated
        // Even though the pool's price is used as fallback, one of the single items is still allocated
        $meta = $cartItem->getMeta();
        $this->assertNotNull($meta->allocated_single_item_id ?? null);
        $this->assertTrue(
            $meta->allocated_single_item_id === $this->singleItem1->id ||
                $meta->allocated_single_item_id === $this->singleItem2->id,
            'Allocated single item should be one of the pool\'s single items'
        );
    }

    /** @test */
    public function it_stores_price_id_with_average_pricing_strategy()
    {
        // Set pricing strategy to average
        $this->poolProduct->setPricingStrategy(PricingStrategy::AVERAGE);

        // Add pool to cart - should use average price but store first item's price_id
        $cartItem = $this->cart->addToCart($this->poolProduct, 1);

        // Average of 2000 and 5000 = 3500
        $this->assertEquals(3500, $cartItem->price);

        // Should store a price_id (from one of the single items)
        $this->assertNotNull($cartItem->price_id);
        $this->assertTrue(
            $cartItem->price_id === $this->price1->id || $cartItem->price_id === $this->price2->id,
            'Price ID should be from one of the single items'
        );
    }

    /** @test */
    public function it_stores_correct_price_id_with_booking_dates()
    {
        // Set pricing strategy to lowest
        $this->poolProduct->setPricingStrategy(PricingStrategy::LOWEST);

        $from = now()->addDays(1)->startOfDay();
        $until = now()->addDays(3)->startOfDay(); // 2 days

        // Add pool to cart with dates
        $cartItem = $this->cart->addToCart($this->poolProduct, 1, [], $from, $until);

        // Should use lowest price and store its price_id
        $this->assertEquals($this->price1->id, $cartItem->price_id);
        $this->assertEquals(4000, $cartItem->price); // $20 × 2 days
    }
}
