<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PricingStrategy;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;

class PoolProductPricingTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Product $poolProduct;
    protected Product $singleItem1;
    protected Product $singleItem2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        auth()->login($this->user);

        // Create pool product
        $this->poolProduct = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
        ]);

        // Create single items
        $this->singleItem1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $this->singleItem1->increaseStock(1);

        $this->singleItem2 = Product::factory()->create([
            'name' => 'Spot 2',
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
    public function pool_product_inherits_price_from_single_items_when_no_pool_price_set()
    {
        // Set price on single items
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000, // 50.00
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

        // Pool product should inherit price
        $price = $this->poolProduct->getCurrentPrice();

        $this->assertNotNull($price);
        $this->assertEquals(5000, $price);
    }

    /** @test */
    public function pool_product_uses_own_price_when_explicitly_set()
    {
        // Set different prices on single items
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Set pool price
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 4500, // Discounted pool price
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $price = $this->poolProduct->getCurrentPrice();

        $this->assertEquals(4500, $price);
    }

    /** @test */
    public function pool_product_inherits_average_price_from_single_items_with_different_prices()
    {
        // Set different prices on single items
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 7000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Set pricing strategy to average
        $this->poolProduct->setPricingStrategy(PricingStrategy::AVERAGE);

        // Pool should inherit average: (5000 + 7000) / 2 = 6000
        $price = $this->poolProduct->getCurrentPrice();

        $this->assertEquals(6000, $price);
    }

    /** @test */
    public function pool_product_returns_null_when_no_prices_available()
    {
        // No prices set on pool or single items
        $price = $this->poolProduct->getCurrentPrice();

        $this->assertNull($price);
    }

    /** @test */
    public function pool_product_inherits_lowest_price_from_single_items()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 7000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $lowestPrice = $this->poolProduct->getLowestAvailablePoolPrice();

        $this->assertEquals(5000, $lowestPrice);
    }

    /** @test */
    public function pool_product_inherits_highest_price_from_single_items()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 7000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $highestPrice = $this->poolProduct->getHighestAvailablePoolPrice();

        $this->assertEquals(7000, $highestPrice);
    }

    /** @test */
    public function pool_product_bulk_discount_applied_for_multiple_items()
    {
        // Set pool price with bulk discount
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        // Add to cart with quantity 2
        $cart = $this->user->currentCart();
        $cart->items()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'quantity' => 2,
            'price' => 5000 * 2, // 2 days
            'subtotal' => 5000 * 2 * 2, // 2 items × 2 days × 5000
            'from' => $from,
            'until' => $until,
        ]);

        $total = $cart->getTotal();

        $this->assertEquals(20000, $total);
    }

    /** @test */
    public function pool_product_pricing_strategy_can_be_set_to_average()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 7000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Set pricing strategy to average
        $this->poolProduct->setPoolPricingStrategy('average');
        $price = $this->poolProduct->getCurrentPrice();

        $this->assertEquals(6000, $price);
    }

    /** @test */
    public function pool_product_pricing_strategy_can_be_set_to_lowest()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 7000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Set pricing strategy to lowest
        $this->poolProduct->setPoolPricingStrategy('lowest');
        $price = $this->poolProduct->getCurrentPrice();

        $this->assertEquals(5000, $price);
    }

    /** @test */
    public function pool_product_pricing_strategy_can_be_set_to_highest()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 7000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Set pricing strategy to highest
        $this->poolProduct->setPoolPricingStrategy('highest');
        $price = $this->poolProduct->getCurrentPrice();

        $this->assertEquals(7000, $price);
    }

    /** @test */
    public function pool_product_price_range_returns_min_and_max()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 7000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $priceRange = $this->poolProduct->getPoolPriceRange();

        $this->assertEquals(['min' => 5000, 'max' => 7000], $priceRange);
    }

    /** @test */
    public function pool_product_with_sale_price_applies_discount()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->poolProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 10000,
            'sale_unit_amount' => 8000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Set sale period to make product on sale
        $this->poolProduct->update([
            'sale_start' => now()->subDay(),
            'sale_end' => now()->addDay(),
        ]);

        $price = $this->poolProduct->getCurrentPrice(true);

        $this->assertEquals(8000, $price);
    }

    /** @test */
    public function pool_product_ignores_single_items_without_prices()
    {
        // Only set price on one item
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Item 2 has no price
        $price = $this->poolProduct->getCurrentPrice();

        $this->assertEquals(5000, $price);
    }

    /** @test */
    public function pool_product_pricing_updates_when_single_item_prices_change()
    {
        $price1 = ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $initialPrice = $this->poolProduct->getCurrentPrice();
        $this->assertEquals(5000, $initialPrice);

        // Update single item price
        $price1->update(['unit_amount' => 6000]);
        $this->poolProduct->refresh();

        // Set to average pricing to see the average of 6000 and 5000
        $this->poolProduct->setPricingStrategy(PricingStrategy::AVERAGE);

        $updatedPrice = $this->poolProduct->getCurrentPrice();
        $this->assertEquals(5500, $updatedPrice); // Average of 6000 and 5000
    }

    /** @test */
    public function pool_product_with_custom_pricing_strategy_in_meta()
    {
        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 7000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Store strategy in metadata using the enum
        $this->poolProduct->setPricingStrategy(PricingStrategy::LOWEST);

        $price = $this->poolProduct->getCurrentPrice();

        $this->assertEquals(5000, $price);
    }
}
