<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Exceptions\HasNoPriceException;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PoolProductPricingFlexibilityTest extends TestCase
{
    use RefreshDatabase;

    protected $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = \Workbench\App\Models\User::factory()->create();
    }

    /** @test */
    public function pool_without_direct_price_uses_single_item_prices()
    {
        $pool = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        ProductPrice::factory()->create([
            'purchasable_id' => $spot1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000, // $50
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $pool->attachSingleItems([$spot1->id]);

        // Pool should be able to use single item price
        $price = $pool->getCurrentPrice();
        $this->assertEquals(5000, $price);

        // Should be able to add to cart without pool having direct price
        $cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        $cartItem = $cart->addToCart($pool, 1);
        $this->assertNotNull($cartItem);
        $this->assertEquals(5000, $cartItem->price);
    }

    /** @test */
    public function pool_validation_does_not_throw_when_single_items_have_prices()
    {
        $pool = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        ProductPrice::factory()->create([
            'purchasable_id' => $spot1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $pool->attachSingleItems([$spot1->id]);

        // validatePricing should not throw when single items have prices
        $result = $pool->validatePricing(throwExceptions: false);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('inherited pricing', $result['warnings'][0]);
    }

    /** @test */
    public function pool_validation_warns_when_no_prices_available_but_does_not_throw()
    {
        $pool = Product::factory()->create([
            'name' => 'Pool Without Any Prices',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        $pool->attachSingleItems([$spot1->id]);

        // validatePricing should not throw, just return warnings
        $result = $pool->validatePricing(throwExceptions: false);

        $this->assertTrue($result['valid']); // Changed: should still be valid
        $this->assertEmpty($result['errors']); // Changed: no errors
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('Price will be needed when adding to cart', $result['warnings'][0]);
    }

    /** @test */
    public function pool_throws_exception_only_when_adding_to_cart_without_any_prices()
    {
        $pool = Product::factory()->create([
            'name' => 'Pool Without Any Prices',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        $pool->attachSingleItems([$spot1->id]);

        $cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        // Exception should only be thrown when trying to add to cart
        $this->expectException(HasNoPriceException::class);
        $this->expectExceptionMessage('Cannot add pool product');
        $this->expectExceptionMessage('No pricing available');

        $cart->addToCart($pool, 1);
    }

    /** @test */
    public function pool_with_direct_price_used_as_fallback_when_single_items_have_no_prices()
    {
        $pool = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        // Single item has NO price
        // Pool has direct price as fallback
        ProductPrice::factory()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 4000, // $40 - fallback pool price
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $pool->attachSingleItems([$spot1->id]);

        // Pool should use its own direct price as fallback when single items have no prices
        $price = $pool->getCurrentPrice();
        $this->assertEquals(4000, $price);
    }

    /** @test */
    public function pool_prefers_single_item_prices_over_direct_price()
    {
        $pool = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        // Single item has price
        ProductPrice::factory()->create([
            'purchasable_id' => $spot1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000, // $50
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Pool also has direct price, but single item price should be preferred
        ProductPrice::factory()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 4000, // $40 - pool price (should be ignored)
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $pool->attachSingleItems([$spot1->id]);

        // Pool should prefer single item price over its own direct price
        $price = $pool->getCurrentPrice();
        $this->assertEquals(5000, $price);
    }

    /** @test */
    public function pool_can_be_created_without_price_if_single_items_will_have_prices()
    {
        // This test verifies that pools can exist in a "not fully configured" state
        // as long as they get prices before being added to cart

        $pool = Product::factory()->create([
            'name' => 'Future Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        $pool->attachSingleItems([$spot1->id]);

        // At this point, neither pool nor single items have prices
        // This should be allowed - pool can exist without prices

        $this->assertNotNull($pool);
        $this->assertCount(1, $pool->singleProducts);

        // Now add price to single item
        ProductPrice::factory()->create([
            'purchasable_id' => $spot1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Now pool should be ready to use
        $cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        $cartItem = $cart->addToCart($pool, 1);
        $this->assertNotNull($cartItem);
        $this->assertEquals(5000, $cartItem->price);
    }

    /** @test */
    public function pool_uses_pricing_strategy_with_multiple_single_item_prices()
    {
        $pool = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        $spot2 = Product::factory()->create([
            'name' => 'Spot 2',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot2->increaseStock(1);

        ProductPrice::factory()->create([
            'purchasable_id' => $spot1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 3000, // $30
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $spot2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 7000, // $70
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $pool->attachSingleItems([$spot1->id, $spot2->id]);

        // By default, should use LOWEST pricing strategy
        $price = $pool->getCurrentPrice();
        $this->assertEquals(3000, $price);

        // Change to HIGHEST
        $pool->setPoolPricingStrategy('highest');
        $price = $pool->getCurrentPrice();
        $this->assertEquals(7000, $price);

        // Change to AVERAGE
        $pool->setPoolPricingStrategy('average');
        $price = $pool->getCurrentPrice();
        $this->assertEquals(5000, $price); // (3000 + 7000) / 2
    }
}
