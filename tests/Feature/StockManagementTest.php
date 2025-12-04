<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductStock;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_claim_stock_for_a_product()
    {
        $product = Product::factory()
            ->withStocks(100)
            ->create();

        $claim = $product->claimStock(
            quantity: 10,
            until: now()->addHours(2)
        );

        $this->assertNotNull($claim);
        $this->assertEquals(10, $claim->quantity);
        $this->assertEquals(90, $product->getAvailableStock());
    }

    /** @test */
    public function it_cannot_claim_more_stock_than_available()
    {
        $product = Product::factory()
            ->withStocks(5)
            ->create();

        $claim = null;

        $this->assertThrows(fn() => $claim = $product->claimStock(15), NotEnoughStockException::class);

        $this->assertNull($claim);
        $this->assertEquals(5, $product->getAvailableStock());
    }

    /** @test */
    public function it_can_release_claimed_stock()
    {
        $product = Product::factory()
            ->withStocks(100)
            ->create();

        $claim = $product->claimStock(
            quantity: 10,
            until: now()->addHours(2)
        );

        $this->assertEquals(90, $product->getAvailableStock());

        $claim->release();

        $this->assertEquals(100, $product->refresh()->getAvailableStock());
        $this->assertNotNull($claim->fresh()->released_at);
    }

    /** @test */
    public function it_can_check_if_stock_is_pending()
    {
        $product = Product::factory()->withStocks(10)->create();

        $claim = $product->claimStock(5);

        $pending = ProductStock::pending()->where('id', $claim->id)->first();

        $this->assertNotNull($pending);
        $this->assertNull($pending->released_at);
    }

    /** @test */
    public function it_can_check_if_stock_is_released()
    {
        $product = Product::factory()->withStocks(50)->create();

        $claim = $product->claimStock(5);

        $claim->release();

        $released = ProductStock::released()->where('id', $claim->id)->first();

        $this->assertNotNull($released);
        $this->assertNotNull($released->released_at);
    }

    /** @test */
    public function it_can_distinguish_temporary_and_permanent_claims()
    {
        $product = Product::factory()->withStocks(100)->create();

        $permanentClaim = $product->claimStock(
            quantity: 10
        );

        $temporaryClaim = $product->claimStock(
            quantity: 5,
            until: now()->addHours(1)
        );

        $this->assertTrue($permanentClaim->isPermanent());
        $this->assertFalse($permanentClaim->isTemporary());

        $this->assertTrue($temporaryClaim->isTemporary());
        $this->assertFalse($temporaryClaim->isPermanent());
    }

    /** @test */
    public function it_belongs_to_a_product()
    {
        $product = Product::factory()->withStocks(20)->create();

        $claim = $product->claimStock(5);

        $this->assertInstanceOf(Product::class, $claim->product);
        $this->assertEquals($product->id, $claim->product->id);
    }

    /** @test */
    public function product_has_many_stock_records()
    {
        $product = Product::factory()->withStocks(30)->create();

        $product->increaseStock(10);
        $product->increaseStock(10);
        $product->increaseStock(50);

        $this->assertCount(4, $product->stocks);
        $this->assertInstanceOf(ProductStock::class, $product->stocks->first());
        $this->assertEquals(30 + 10 + 10 + 50, $product->getAvailableStock());
    }

    /** @test */
    public function it_can_get_active_stock_claims()
    {
        $product = Product::factory()->withStocks(100)->create();

        $activeClaim = $product->claimStock(
            quantity: 10,
            until: now()->addHours(2)
        );

        $expiredClaim = $product->claimStock(
            quantity: 5,
            until: now()->subHours(1)
        );

        $activeClaims = $product->claims()->get();

        $this->assertCount(1, $activeClaims);
        $this->assertEquals($activeClaim->id, $activeClaims->first()->id);
    }

    /** @test */
    public function it_cannot_release_stock_twice()
    {
        $product = Product::factory()->withStocks()->create();

        $claim = $product->claimStock(5);

        $this->assertTrue($claim->release());
        $this->assertFalse($claim->release());
    }

    /** @test */
    public function it_can_store_claim_note()
    {
        $product = Product::factory()->withStocks()->create();

        $note = "Customer requested to hold this item for 2 days.";

        $claim = $product->claimStock(
            quantity: 5,
            note: $note
        );

        $this->assertEquals($note, $claim->note);
    }

    /** @test */
    public function it_calculates_available_stock_correctly()
    {
        $product = Product::factory()->withStocks(100)->create();

        $claim1 = $product->claimStock(
            quantity: 10,
            until: now()->addHours(2)
        );

        $claim2 = $product->claimStock(
            quantity: 5,
            until: now()->addHours(1)
        );

        $claim1->refresh();
        $claim2->refresh();

        $this->assertEquals(85, $product->refresh()->getAvailableStock());
    }

    /** @test */
    public function product_tracks_low_stock_threshold()
    {
        $product = Product::factory()
            ->withStocks(12)
            ->create([
                'low_stock_threshold' => 10,
            ]);

        $this->assertFalse($product->isLowStock());

        $product->decreaseStock(8);

        $this->assertTrue($product->fresh()->isLowStock());
    }

    /** @test */
    public function it_updates_in_stock_status_automatically()
    {
        $product = Product::factory()
            ->withStocks(10)
            ->create();

        $product->decreaseStock(10);

        $this->assertFalse($product->fresh()->isInStock());
    }
}
