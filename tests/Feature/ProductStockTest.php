<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductStock;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductStockTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_creates_stock_record_on_increase()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(10);

        $this->assertDatabaseHas('product_stocks', [
            'product_id' => $product->id,
            'quantity' => 10,
            'type' => 'increase',
        ]);
    }

    /** @test */
    public function it_creates_stock_record_on_decrease()
    {
        $product = Product::factory()->create(['manage_stock' => true]);
        $product->increaseStock(20);

        $product->decreaseStock(5);

        $this->assertDatabaseHas('product_stocks', [
            'product_id' => $product->id,
            'quantity' => -5,
            'type' => 'decrease',
        ]);
    }

    /** @test */
    public function stock_belongs_to_product()
    {
        $product = Product::factory()->create(['manage_stock' => true]);
        $product->increaseStock(10);

        $stock = $product->stocks()->first();

        $this->assertInstanceOf(Product::class, $stock->product);
        $this->assertEquals($product->id, $stock->product->id);
    }

    /** @test */
    public function product_has_many_stock_records()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(10);
        $product->increaseStock(5);
        $product->decreaseStock(3);

        $this->assertCount(3, $product->stocks);
    }

    /** @test */
    public function available_stock_considers_all_records()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(50);
        $product->increaseStock(30);
        $product->decreaseStock(20);

        $this->assertEquals(60, $product->getAvailableStock());
    }

    /** @test */
    public function reservation_reduces_available_stock()
    {
        $product = Product::factory()->withStocks(100)->create();

        $reservation = $product->reserveStock(25);

        $this->assertEquals(75, $product->getAvailableStock());
        $this->assertNotNull($reservation);
    }

    /** @test */
    public function releasing_reservation_increases_available_stock()
    {
        $product = Product::factory()->withStocks(100)->create();

        $reservation = $product->reserveStock(25);
        $this->assertEquals(75, $product->getAvailableStock());

        $reservation->release();

        $this->assertEquals(100, $product->refresh()->getAvailableStock());
    }

    /** @test */
    public function permanent_reservation_has_no_expiry()
    {
        $product = Product::factory()->withStocks(50)->create();

        $reservation = $product->reserveStock(10);

        $this->assertNull($reservation->expires_at);
        $this->assertTrue($reservation->isPermanent());
    }

    /** @test */
    public function temporary_reservation_has_expiry()
    {
        $product = Product::factory()->withStocks(50)->create();

        $reservation = $product->reserveStock(
            quantity: 10,
            until: now()->addHours(2)
        );

        $this->assertNotNull($reservation->expires_at);
        $this->assertTrue($reservation->isTemporary());
    }

    /** @test */
    public function reservation_can_have_note()
    {
        $product = Product::factory()->withStocks(50)->create();

        $note = 'Reserved for VIP customer';
        $reservation = $product->reserveStock(
            quantity: 10,
            note: $note
        );

        $this->assertEquals($note, $reservation->note);
    }

    /** @test */
    public function cannot_reserve_more_than_available()
    {
        $product = Product::factory()->withStocks(10)->create();

        $this->expectException(NotEnoughStockException::class);

        $product->reserveStock(15);
    }

    /** @test */
    public function pending_scope_returns_unreleased_reservations()
    {
        $product = Product::factory()->withStocks(100)->create();

        $pending = $product->reserveStock(10);
        $released = $product->reserveStock(5);
        $released->release();

        $pendingReservations = ProductStock::pending()->get();

        $this->assertTrue($pendingReservations->contains($pending));
        $this->assertFalse($pendingReservations->contains($released));
    }

    /** @test */
    public function released_scope_returns_released_reservations()
    {
        $product = Product::factory()->withStocks(100)->create();

        $pending = $product->reserveStock(10);
        $released = $product->reserveStock(5);
        $released->release();

        $releasedReservations = ProductStock::released()->get();

        $this->assertFalse($releasedReservations->contains($pending));
        $this->assertTrue($releasedReservations->contains($released));
    }

    /** @test */
    public function expired_reservations_dont_affect_available_stock()
    {
        $product = Product::factory()->withStocks(100)->create();

        $product->reserveStock(
            quantity: 20,
            until: now()->subHour()
        );

        // Expired reservations should be counted in available stock
        $available = $product->reservations()->get();
        
        $this->assertEquals(0, $available->count());
    }

    /** @test */
    public function cannot_release_stock_twice()
    {
        $product = Product::factory()->withStocks(50)->create();

        $reservation = $product->reserveStock(10);

        $this->assertTrue($reservation->release());
        $this->assertFalse($reservation->release());
    }

    /** @test */
    public function stock_status_is_tracked()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(10);

        $stock = $product->stocks()->first();

        $this->assertEquals(StockStatus::COMPLETED, $stock->status);
    }

    /** @test */
    public function product_without_stock_management_returns_max_stock()
    {
        $product = Product::factory()->create(['manage_stock' => false]);

        $available = $product->getAvailableStock();

        $this->assertEquals(PHP_INT_MAX, $available);
    }

    /** @test */
    public function product_without_stock_management_doesnt_create_records()
    {
        $product = Product::factory()->create(['manage_stock' => false]);

        $result = $product->increaseStock(10);

        $this->assertFalse($result);
        $this->assertCount(0, $product->stocks);
    }

    /** @test */
    public function reservation_without_stock_management_returns_null()
    {
        $product = Product::factory()->create(['manage_stock' => false]);

        $reservation = $product->reserveStock(10);

        $this->assertNull($reservation);
    }

    /** @test */
    public function available_stocks_attribute_accessor_works()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(25);
        $product->increaseStock(15);

        $this->assertEquals(40, $product->AvailableStocks);
    }

    /** @test */
    public function reservations_method_filters_active_only()
    {
        $product = Product::factory()->withStocks(100)->create();

        $active = $product->reserveStock(10, until: now()->addDay());
        $expired = $product->reserveStock(5, until: now()->subDay());

        $reservations = $product->reservations()->get();

        $this->assertCount(1, $reservations);
        $this->assertEquals($active->id, $reservations->first()->id);
    }
}
