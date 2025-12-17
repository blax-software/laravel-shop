<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CartDateStringParsingTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $cart;
    protected $bookingProduct;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = \Workbench\App\Models\User::factory()->create();

        $this->cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        $this->bookingProduct = Product::factory()->create([
            'name' => 'Hotel Room',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $this->bookingProduct->increaseStock(5);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->bookingProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 10000, // $100/day
            'currency' => 'USD',
            'is_default' => true,
        ]);
    }

    /** @test */
    public function cart_set_dates_accepts_string_dates()
    {
        $cart = $this->cart->setDates('2025-12-20', '2025-12-25', false);

        $this->assertNotNull($cart->from_date);
        $this->assertNotNull($cart->until_date);
        $this->assertEquals('2025-12-20', $cart->from_date->format('Y-m-d'));
        $this->assertEquals('2025-12-25', $cart->until_date->format('Y-m-d'));
    }

    /** @test */
    public function cart_set_dates_accepts_datetime_objects()
    {
        $from = Carbon::parse('2025-12-20');
        $until = Carbon::parse('2025-12-25');

        $cart = $this->cart->setDates($from, $until, false);

        $this->assertEquals('2025-12-20', $cart->from_date->format('Y-m-d'));
        $this->assertEquals('2025-12-25', $cart->until_date->format('Y-m-d'));
    }

    /** @test */
    public function cart_set_from_date_accepts_string()
    {
        $cart = $this->cart->setFromDate('2025-12-20', false);

        $this->assertNotNull($cart->from_date);
        $this->assertEquals('2025-12-20', $cart->from_date->format('Y-m-d'));
    }

    /** @test */
    public function cart_set_until_date_accepts_string()
    {
        $this->cart->update(['from_date' => Carbon::parse('2025-12-20')]);
        $cart = $this->cart->setUntilDate('2025-12-25', false);

        $this->assertNotNull($cart->until_date);
        $this->assertEquals('2025-12-25', $cart->until_date->format('Y-m-d'));
    }

    /** @test */
    public function cart_set_dates_parses_various_string_formats()
    {
        // Test different date string formats that Carbon can parse
        $testCases = [
            ['2025-12-20', '2025-12-25'],
            ['2025/12/20', '2025/12/25'],
            ['20-12-2025', '25-12-2025'],
            ['December 20, 2025', 'December 25, 2025'],
        ];

        foreach ($testCases as [$from, $until]) {
            $cart = Cart::factory()->create([
                'customer_id' => $this->user->id,
                'customer_type' => get_class($this->user),
            ]);

            $cart = $cart->setDates($from, $until, false);

            $this->assertNotNull($cart->from_date, "Failed to parse: $from");
            $this->assertNotNull($cart->until_date, "Failed to parse: $until");
        }
    }

    /** @test */
    public function cart_item_set_from_date_accepts_string()
    {
        $cartItem = $this->cart->addToCart(
            $this->bookingProduct,
            1,
            [],
            Carbon::parse('2025-12-20'),
            Carbon::parse('2025-12-25')
        );

        $cartItem = $cartItem->setFromDate('2025-12-21');

        $this->assertEquals('2025-12-21', $cartItem->from->format('Y-m-d'));
    }

    /** @test */
    public function cart_item_set_until_date_accepts_string()
    {
        $cartItem = $this->cart->addToCart(
            $this->bookingProduct,
            1,
            [],
            Carbon::parse('2025-12-20'),
            Carbon::parse('2025-12-25')
        );

        $cartItem = $cartItem->setUntilDate('2025-12-26');

        $this->assertEquals('2025-12-26', $cartItem->until->format('Y-m-d'));
    }

    /** @test */
    public function cart_item_update_dates_accepts_string_dates()
    {
        $cartItem = $this->cart->addToCart(
            $this->bookingProduct,
            1,
            [],
            Carbon::parse('2025-12-20'),
            Carbon::parse('2025-12-25')
        );

        $cartItem = $cartItem->updateDates('2025-12-21', '2025-12-27');

        $this->assertEquals('2025-12-21', $cartItem->from->format('Y-m-d'));
        $this->assertEquals('2025-12-27', $cartItem->until->format('Y-m-d'));

        // Verify price was recalculated for new date range (6 days instead of 5)
        $expectedPrice = 10000 * 6; // $100/day * 6 days
        $this->assertEquals($expectedPrice, $cartItem->price);
    }

    /** @test */
    public function cart_item_update_dates_accepts_mixed_string_and_datetime()
    {
        $cartItem = $this->cart->addToCart(
            $this->bookingProduct,
            1,
            [],
            Carbon::parse('2025-12-20'),
            Carbon::parse('2025-12-25')
        );

        // String from, DateTime until
        $cartItem = $cartItem->updateDates('2025-12-21', Carbon::parse('2025-12-27'));

        $this->assertEquals('2025-12-21', $cartItem->from->format('Y-m-d'));
        $this->assertEquals('2025-12-27', $cartItem->until->format('Y-m-d'));
    }

    /** @test */
    public function cart_item_date_parsing_works_with_now_relative_strings()
    {
        $cartItem = $this->cart->addToCart(
            $this->bookingProduct,
            1,
            [],
            Carbon::parse('2025-12-20'),
            Carbon::parse('2025-12-25')
        );

        // Test relative date strings
        $cartItem = $cartItem->updateDates('now', '+5 days');

        $this->assertNotNull($cartItem->from);
        $this->assertNotNull($cartItem->until);
        $this->assertTrue($cartItem->from < $cartItem->until);
    }
}
