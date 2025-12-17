<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\PriceType;
use Blax\Shop\Enums\PricingStrategy;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;

class PoolClaimingPriorityTest extends TestCase
{
    /** @test */
    public function it_claims_lowest_priced_items_first_with_lowest_strategy()
    {
        // Create pool product
        $pool = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Set pool pricing strategy to LOWEST
        $pool->setPoolPricingStrategy(PricingStrategy::LOWEST);

        // Create fallback price on pool
        ProductPrice::factory()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'unit_amount' => 500, // $5.00 fallback
            'is_default' => true,
        ]);

        // Create single parking spots with different prices
        $spot1 = Product::factory()->create([
            'name' => 'Parking Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        ProductPrice::factory()->create([
            'purchasable_id' => $spot1->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'unit_amount' => 500, // $5.00
            'is_default' => true,
        ]);

        $spot2 = Product::factory()->create([
            'name' => 'Parking Spot 2',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot2->increaseStock(1);

        ProductPrice::factory()->create([
            'purchasable_id' => $spot2->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'unit_amount' => 1000, // $10.00 (most expensive)
            'is_default' => true,
        ]);

        $spot3 = Product::factory()->create([
            'name' => 'Parking Spot 3',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot3->increaseStock(1);

        // Spot 3 has NO price - should use pool fallback (500)

        // Attach spots to pool
        $pool->attachSingleItems([$spot1->id, $spot2->id, $spot3->id]);

        // Create cart and claim 3 spots
        $cart = Cart::factory()->create();
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $claimedItems = $pool->claimPoolStock(3, $cart, $from, $until);

        // Should claim in order: spot1 (500), spot3 (500 fallback), spot2 (1000)
        $this->assertCount(3, $claimedItems);

        // Extract IDs for easier comparison
        $claimedIds = array_map(fn($item) => $item->id, $claimedItems);

        // First two should be spot1 and spot3 (both $5.00) in some order
        $this->assertContains($spot1->id, [$claimedIds[0], $claimedIds[1]]);
        $this->assertContains($spot3->id, [$claimedIds[0], $claimedIds[1]]);

        // Last should be spot2 (most expensive)
        $this->assertEquals($spot2->id, $claimedIds[2]);
    }

    /** @test */
    public function it_claims_highest_priced_items_first_with_highest_strategy()
    {
        // Create pool product
        $pool = Product::factory()->create([
            'name' => 'Premium Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Set pool pricing strategy to HIGHEST
        $pool->setPoolPricingStrategy(PricingStrategy::HIGHEST);

        // Create fallback price on pool
        ProductPrice::factory()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'unit_amount' => 500,
            'is_default' => true,
        ]);

        // Create spots with different prices
        $cheapSpot = Product::factory()->create([
            'name' => 'Cheap Spot',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $cheapSpot->increaseStock(1);

        ProductPrice::factory()->create([
            'purchasable_id' => $cheapSpot->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'unit_amount' => 300, // Cheapest
            'is_default' => true,
        ]);

        $expensiveSpot = Product::factory()->create([
            'name' => 'Expensive Spot',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $expensiveSpot->increaseStock(1);

        ProductPrice::factory()->create([
            'purchasable_id' => $expensiveSpot->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'unit_amount' => 1500, // Most expensive
            'is_default' => true,
        ]);

        // Attach to pool
        $pool->attachSingleItems([$cheapSpot->id, $expensiveSpot->id]);

        // Claim 2 spots
        $cart = Cart::factory()->create();
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $claimedItems = $pool->claimPoolStock(2, $cart, $from, $until);

        // Should claim expensive first, then cheap
        $this->assertEquals($expensiveSpot->id, $claimedItems[0]->id);
        $this->assertEquals($cheapSpot->id, $claimedItems[1]->id);
    }

    /** @test */
    public function it_uses_fallback_price_for_items_without_price()
    {
        $pool = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $pool->setPoolPricingStrategy(PricingStrategy::LOWEST);

        // Pool fallback price
        ProductPrice::factory()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'unit_amount' => 500,
            'is_default' => true,
        ]);

        // Spot with specific price (higher than fallback)
        $pricedSpot = Product::factory()->create([
            'name' => 'Priced Spot',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $pricedSpot->increaseStock(1);

        ProductPrice::factory()->create([
            'purchasable_id' => $pricedSpot->id,
            'purchasable_type' => Product::class,
            'type' => PriceType::RECURRING,
            'unit_amount' => 1000,
            'is_default' => true,
        ]);

        // Spot without price (uses fallback)
        $unpricedSpot = Product::factory()->create([
            'name' => 'Unpriced Spot',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $unpricedSpot->increaseStock(1);
        // No price created for this spot

        $pool->attachSingleItems([$pricedSpot->id, $unpricedSpot->id]);

        // Claim both
        $cart = Cart::factory()->create();
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $claimedItems = $pool->claimPoolStock(2, $cart, $from, $until);

        // Unpriced spot (using fallback 500) should be claimed first
        $this->assertEquals($unpricedSpot->id, $claimedItems[0]->id);
        $this->assertEquals($pricedSpot->id, $claimedItems[1]->id);
    }
}
