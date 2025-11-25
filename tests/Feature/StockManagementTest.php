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
    public function it_can_reserve_stock_for_a_product()
    {
        $product = Product::factory()
            ->withStocks(100)
            ->create();

        $reservation = $product->reserveStock(
            quantity: 10,
            until: now()->addHours(2)
        );

        $this->assertNotNull($reservation);
        $this->assertEquals(10, $reservation->quantity);
        $this->assertEquals(90, $product->getAvailableStock());
    }

    /** @test */
    public function it_cannot_reserve_more_stock_than_available()
    {
        $product = Product::factory()
            ->withStocks(5)
            ->create();

        $reservation = null;

        $this->assertThrows(fn() => $reservation = $product->reserveStock(15), NotEnoughStockException::class);

        $this->assertNull($reservation);
        $this->assertEquals(5, $product->getAvailableStock());
    }

    /** @test */
    public function it_can_release_reserved_stock()
    {
        $product = Product::factory()
            ->withStocks(100)
            ->create();

        $reservation = $product->reserveStock(
            quantity: 10,
            until: now()->addHours(2)
        );

        $this->assertEquals(90, $product->getAvailableStock());

        $reservation->release();

        $this->assertEquals(100, $product->refresh()->getAvailableStock());
        $this->assertNotNull($reservation->fresh()->released_at);
    }

    /** @test */
    public function it_can_check_if_stock_is_pending()
    {
        $product = Product::factory()->withStocks(10)->create();

        $reservation = $product->reserveStock(5);

        $pending = ProductStock::pending()->where('id', $reservation->id)->first();

        $this->assertNotNull($pending);
        $this->assertNull($pending->released_at);
    }

    /** @test */
    public function it_can_check_if_stock_is_released()
    {
        $product = Product::factory()->withStocks(50)->create();

        $reservation = $product->reserveStock(5);

        $reservation->release();

        $released = ProductStock::released()->where('id', $reservation->id)->first();

        $this->assertNotNull($released);
        $this->assertNotNull($released->released_at);
    }

    /** @test */
    public function it_can_distinguish_temporary_and_permanent_reservations()
    {
        $product = Product::factory()->withStocks(100)->create();

        $permanentReservation = $product->reserveStock(
            quantity: 10
        );

        $temporaryReservation = $product->reserveStock(
            quantity: 5,
            until: now()->addHours(1)
        );

        $this->assertTrue($permanentReservation->isPermanent());
        $this->assertFalse($permanentReservation->isTemporary());

        $this->assertTrue($temporaryReservation->isTemporary());
        $this->assertFalse($temporaryReservation->isPermanent());
    }

    /** @test */
    public function it_belongs_to_a_product()
    {
        $product = Product::factory()->withStocks(20)->create();

        $reservation = $product->reserveStock(5);

        $this->assertInstanceOf(Product::class, $reservation->product);
        $this->assertEquals($product->id, $reservation->product->id);
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
    public function it_can_get_active_stock_reservations()
    {
        $product = Product::factory()->withStocks(100)->create();

        $activeReservation = $product->reserveStock(
            quantity: 10,
            until: now()->addHours(2)
        );

        $expiredReservation = $product->reserveStock(
            quantity: 5,
            until: now()->subHours(1)
        );

        $activeReservations = $product->reservations()->get();

        $this->assertCount(1, $activeReservations);
        $this->assertEquals($activeReservation->id, $activeReservations->first()->id);
    }

    /** @test */
    public function it_cannot_release_stock_twice()
    {
        $product = Product::factory()->withStocks()->create();

        $reservation = $product->reserveStock(5);

        $this->assertTrue($reservation->release());
        $this->assertFalse($reservation->release());
    }

    /** @test */
    public function it_can_store_reservation_note()
    {
        $product = Product::factory()->withStocks()->create();

        $note = "Customer requested to hold this item for 2 days.";

        $reservation = $product->reserveStock(
            quantity: 5,
            note: $note
        );

        $this->assertEquals($note, $reservation->note);
    }

    /** @test */
    public function it_calculates_available_stock_correctly()
    {
        $product = Product::factory()->withStocks(100)->create();

        $reservation1 = $product->reserveStock(
            quantity: 10,
            until: now()->addHours(2)
        );

        $reservation2 = $product->reserveStock(
            quantity: 5,
            until: now()->addHours(1)
        );

        $reservation1->refresh();
        $reservation2->refresh();

        $this->assertEquals(85, $product->refresh()->getAvailableStock());
    }

    /** @test */
    public function product_tracks_low_stock_threshold()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 15,
            'low_stock_threshold' => 10,
        ]);

        $this->assertFalse($product->isLowStock());

        $product->decreaseStock(8);

        $this->assertTrue($product->fresh()->isLowStock());
    }

    /** @test */
    public function it_updates_in_stock_status_automatically()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 5,
            'in_stock' => true,
        ]);

        $product->decreaseStock(5);

        $this->assertFalse($product->fresh()->in_stock);
    }
}
