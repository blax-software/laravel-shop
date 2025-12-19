<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PricingStrategy;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Workbench\App\Models\User;

class PoolSeparateCartItemsTest extends TestCase
{
    protected User $user;
    protected Cart $cart;
    protected Product $pool;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        // Create pool with single items
        $this->pool = Product::factory()->create([
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $spot1 = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(10);

        $spot2 = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot2->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $spot1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $spot2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $this->pool->attachSingleItems([$spot1->id, $spot2->id]);
    }

    /** @test */
    public function it_creates_separate_cart_items_for_different_dates()
    {
        $from1 = Carbon::now()->addDays(1)->startOfDay();
        $until1 = Carbon::now()->addDays(3)->startOfDay();

        $from2 = Carbon::now()->addDays(5)->startOfDay();
        $until2 = Carbon::now()->addDays(7)->startOfDay();

        $item1 = $this->cart->addToCart($this->pool, 1, [], $from1, $until1);
        $item2 = $this->cart->addToCart($this->pool, 1, [], $from2, $until2);

        // Should create two separate cart items
        $this->assertNotEquals($item1->id, $item2->id);
        $this->assertEquals(2, $this->cart->items()->count());
    }

    /** @test */
    public function it_merges_cart_items_with_same_dates_and_price()
    {
        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay();

        $item1 = $this->cart->addToCart($this->pool, 1, [], $from, $until);
        $item2 = $this->cart->addToCart($this->pool, 1, [], $from, $until);

        // Should merge into one cart item
        $this->assertEquals($item1->id, $item2->id);
        $this->assertEquals(1, $this->cart->items()->count());
        $this->assertEquals(2, $item2->quantity);
    }

    /** @test */
    public function it_creates_separate_cart_items_when_price_changes()
    {
        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(3)->startOfDay();

        // Use AVERAGE strategy to ensure price change when single items change
        $this->pool->setPricingStrategy(PricingStrategy::AVERAGE);

        // Add first item
        $item1 = $this->cart->addToCart($this->pool, 1, [], $from, $until);
        $price1 = $item1->price;

        // Change the price on one of the single items
        $singleItem = $this->pool->singleProducts->first();
        $priceRecord = ProductPrice::where('purchasable_id', $singleItem->id)->first();
        $priceRecord->update(['unit_amount' => 8000]); // Changed from 5000 to 8000

        // Clear cache if any
        $this->pool->refresh();
        $singleItem->refresh();

        // Add second item with same dates but different price
        $item2 = $this->cart->addToCart($this->pool, 1, [], $from, $until);

        // Should create separate cart items because price is different
        // (Note: With AVERAGE strategy, price_id may be the same but price differs)
        $this->assertNotEquals($item1->id, $item2->id);
        $this->assertEquals(2, $this->cart->items()->count());
        $this->assertNotEquals($price1, $item2->price);
    }

    /** @test */
    public function it_creates_separate_cart_items_for_different_date_lengths()
    {
        $from = Carbon::now()->addDays(1)->startOfDay();
        $until1 = Carbon::now()->addDays(3)->startOfDay(); // 2 days
        $until2 = Carbon::now()->addDays(5)->startOfDay(); // 4 days

        $item1 = $this->cart->addToCart($this->pool, 1, [], $from, $until1);
        $item2 = $this->cart->addToCart($this->pool, 1, [], $from, $until2);

        // Different date ranges mean different prices, so separate items
        $this->assertNotEquals($item1->id, $item2->id);
        $this->assertEquals(2, $this->cart->items()->count());
        $this->assertNotEquals($item1->price, $item2->price);
    }
}
