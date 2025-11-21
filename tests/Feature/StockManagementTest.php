<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductStock;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_reserve_stock_for_a_product()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 100,
        ]);

        $reservation = ProductStock::reserve(
            product: $product,
            quantity: 10,
            type: 'reservation',
            until: now()->addHours(2)
        );

        $this->assertNotNull($reservation);
        $this->assertEquals(10, $reservation->quantity);
        $this->assertEquals(90, $product->fresh()->stock_quantity);
    }

    /** @test */
    public function it_cannot_reserve_more_stock_than_available()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 5,
        ]);

        $reservation = ProductStock::reserve(
            product: $product,
            quantity: 10,
            type: 'reservation'
        );

        $this->assertNull($reservation);
        $this->assertEquals(5, $product->fresh()->stock_quantity);
    }

    /** @test */
    public function it_can_release_reserved_stock()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 100,
        ]);

        $reservation = ProductStock::reserve(
            product: $product,
            quantity: 10,
            type: 'reservation'
        );

        $this->assertEquals(90, $product->fresh()->stock_quantity);

        $reservation->release();

        $this->assertEquals(100, $product->fresh()->stock_quantity);
        $this->assertNotNull($reservation->fresh()->released_at);
    }

    /** @test */
    public function it_can_check_if_stock_is_pending()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 50,
        ]);

        $reservation = ProductStock::reserve(
            product: $product,
            quantity: 5,
            type: 'reservation'
        );

        $pending = ProductStock::pending()->where('id', $reservation->id)->first();

        $this->assertNotNull($pending);
        $this->assertNull($pending->released_at);
    }

    /** @test */
    public function it_can_check_if_stock_is_released()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 50,
        ]);

        $reservation = ProductStock::reserve(
            product: $product,
            quantity: 5,
            type: 'reservation'
        );

        $reservation->release();

        $released = ProductStock::released()->where('id', $reservation->id)->first();

        $this->assertNotNull($released);
        $this->assertNotNull($released->released_at);
    }

    /** @test */
    public function it_can_find_expired_reservations()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 100,
        ]);

        $expiredReservation = ProductStock::reserve(
            product: $product,
            quantity: 10,
            type: 'reservation',
            until: now()->subHour()
        );

        $activeReservation = ProductStock::reserve(
            product: $product,
            quantity: 5,
            type: 'reservation',
            until: now()->addHour()
        );

        $expired = ProductStock::expired()->get();

        $this->assertTrue($expired->contains($expiredReservation));
        $this->assertFalse($expired->contains($activeReservation));
    }

    /** @test */
    public function it_can_distinguish_temporary_and_permanent_reservations()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 100,
        ]);

        $temporary = ProductStock::reserve(
            product: $product,
            quantity: 10,
            type: 'reservation',
            until: now()->addHours(2)
        );

        $permanent = ProductStock::reserve(
            product: $product,
            quantity: 5,
            type: 'sold'
        );

        $temporaryReservations = ProductStock::temporary()->get();
        $permanentReservations = ProductStock::permanent()->get();

        $this->assertTrue($temporaryReservations->contains($temporary));
        $this->assertFalse($temporaryReservations->contains($permanent));
        $this->assertTrue($permanentReservations->contains($permanent));
        $this->assertFalse($permanentReservations->contains($temporary));
    }

    /** @test */
    public function it_belongs_to_a_product()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 50,
        ]);

        $stock = ProductStock::reserve(
            product: $product,
            quantity: 5,
            type: 'reservation'
        );

        $this->assertEquals($product->id, $stock->product->id);
    }

    /** @test */
    public function product_has_many_stock_records()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 100,
        ]);

        ProductStock::reserve($product, quantity: 10, type: 'reservation');
        ProductStock::reserve($product, quantity: 5, type: 'reservation');
        ProductStock::reserve($product, quantity: 3, type: 'sold');

        $this->assertCount(3, $product->fresh()->stocks);
    }

    /** @test */
    public function it_can_get_active_stock_reservations()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 100,
        ]);

        $active1 = ProductStock::reserve($product, quantity: 10, type: 'reservation');
        $active2 = ProductStock::reserve($product, quantity: 5, type: 'reservation');
        $released = ProductStock::reserve($product, quantity: 3, type: 'sold');
        $released->release();

        $activeStocks = $product->fresh()->activeStocks;

        $this->assertCount(2, $activeStocks);
        $this->assertTrue($activeStocks->contains($active1));
        $this->assertTrue($activeStocks->contains($active2));
        $this->assertFalse($activeStocks->contains($released));
    }

    /** @test */
    public function it_cannot_release_stock_twice()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 50,
        ]);

        $reservation = ProductStock::reserve($product, quantity: 10, type: 'reservation');

        $this->assertTrue($reservation->release());
        $this->assertFalse($reservation->release());
    }

    /** @test */
    public function it_can_store_reservation_note()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 50,
        ]);

        $reservation = ProductStock::reserve(
            product: $product,
            quantity: 5,
            type: 'reservation',
            note: 'Reserved for order #12345'
        );

        $this->assertEquals('Reserved for order #12345', $reservation->note);
    }

    /** @test */
    public function it_handles_stock_transactions_atomically()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 10,
        ]);

        // Try to reserve more than available
        $reservation = ProductStock::reserve($product, quantity: 15, type: 'reservation');

        // Should fail and not change stock
        $this->assertNull($reservation);
        $this->assertEquals(10, $product->fresh()->stock_quantity);
    }

    /** @test */
    public function it_calculates_available_stock_correctly()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 100,
        ]);

        // Reserve some stock
        ProductStock::reserve($product, quantity: 20, type: 'reservation');
        ProductStock::reserve($product, quantity: 10, type: 'reservation');

        $available = $product->fresh()->stock_quantity;

        $this->assertEquals(70, $available);
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
