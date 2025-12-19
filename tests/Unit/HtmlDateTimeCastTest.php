<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;

class HtmlDateTimeCastTest extends TestCase
{
    /** @test */
    public function it_accepts_carbon_instance_and_stores_as_timestamp()
    {
        $cart = Cart::factory()->create();
        $date = Carbon::parse('2025-12-25 14:30:00');

        $cart->from = $date;
        $cart->save();

        // Reload from database
        $cart->refresh();

        // Should return Carbon instance
        $this->assertInstanceOf(Carbon::class, $cart->from);
        $this->assertEquals('2025-12-25 14:30:00', $cart->from->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_accepts_datetime_interface_and_stores_as_timestamp()
    {
        $cart = Cart::factory()->create();
        $date = new \DateTime('2025-12-25 14:30:00');

        $cart->from = $date;
        $cart->save();

        $cart->refresh();

        $this->assertInstanceOf(Carbon::class, $cart->from);
        $this->assertEquals('2025-12-25 14:30:00', $cart->from->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_accepts_string_and_stores_as_timestamp()
    {
        $cart = Cart::factory()->create();

        // Standard datetime string
        $cart->from = '2025-12-25 14:30:00';
        $cart->save();

        $cart->refresh();

        $this->assertInstanceOf(Carbon::class, $cart->from);
        $this->assertEquals('2025-12-25 14:30:00', $cart->from->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_accepts_html5_datetime_local_format()
    {
        $cart = Cart::factory()->create();

        // HTML5 datetime-local format (YYYY-MM-DDTHH:MM)
        $cart->from = '2025-12-25T14:30';
        $cart->save();

        $cart->refresh();

        $this->assertInstanceOf(Carbon::class, $cart->from);
        $this->assertEquals('2025-12-25 14:30:00', $cart->from->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_can_format_for_html5_input()
    {
        $cart = Cart::factory()->create();
        $cart->from = Carbon::parse('2025-12-25 14:30:00');
        $cart->save();

        $cart->refresh();

        // Can format for HTML5 datetime-local input
        $htmlFormat = $cart->from->format('Y-m-d\TH:i');
        $this->assertEquals('2025-12-25T14:30', $htmlFormat);
    }

    /** @test */
    public function it_handles_null_values()
    {
        $cart = Cart::factory()->create();
        $cart->from = null;
        $cart->save();

        $cart->refresh();

        $this->assertNull($cart->from);
    }

    /** @test */
    public function it_works_with_cart_items()
    {
        $product = Product::factory()->create();
        $cart = Cart::factory()->create();

        $item = $cart->items()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => 1,
            'price' => 100,
            'subtotal' => 100,
            'from' => '2025-12-25T14:30',
            'until' => '2025-12-27T10:00',
        ]);

        $item->refresh();

        $this->assertInstanceOf(Carbon::class, $item->from);
        $this->assertInstanceOf(Carbon::class, $item->until);
        $this->assertEquals('2025-12-25T14:30', $item->from->format('Y-m-d\TH:i'));
        $this->assertEquals('2025-12-27T10:00', $item->until->format('Y-m-d\TH:i'));
    }

    /** @test */
    public function it_accepts_unix_timestamp()
    {
        $cart = Cart::factory()->create();
        $timestamp = Carbon::parse('2025-12-25 14:30:00')->timestamp;

        $cart->from = $timestamp;
        $cart->save();

        $cart->refresh();

        $this->assertInstanceOf(Carbon::class, $cart->from);
        $this->assertEquals('2025-12-25 14:30:00', $cart->from->format('Y-m-d H:i:s'));
    }

    /** @test */
    public function it_maintains_carbon_methods()
    {
        $cart = Cart::factory()->create();
        $cart->from = Carbon::parse('2025-12-25 14:30:00');
        $cart->save();

        $cart->refresh();

        // All Carbon methods should be available
        $this->assertTrue($cart->from->isAfter(Carbon::parse('2025-12-24')));
        $this->assertTrue($cart->from->isBefore(Carbon::parse('2025-12-26')));
        $this->assertEquals('December', $cart->from->format('F'));
        $this->assertEquals('2025-12-25', $cart->from->toDateString());
    }
}
