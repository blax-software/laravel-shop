<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class CartItemDateManagementTest extends TestCase
{
    protected User $user;
    protected Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);
    }

    #[Test]
    public function it_can_update_dates_on_cart_item()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000, // 50.00 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Add without dates
        $cartItem = $this->cart->addToCart($product, 1);
        $this->assertNull($cartItem->from);
        $this->assertNull($cartItem->until);

        // Update dates
        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(4)->startOfDay(); // 3 days

        $updated = $cartItem->updateDates($from, $until);

        $this->assertEquals($from->format('Y-m-d H:i:s'), $updated->from->format('Y-m-d H:i:s'));
        $this->assertEquals($until->format('Y-m-d H:i:s'), $updated->until->format('Y-m-d H:i:s'));
        $this->assertEquals(15000, $updated->price); // 50.00 × 3 days
        $this->assertEquals(15000, $updated->subtotal); // 150.00 × 1 quantity
    }

    #[Test]
    public function it_recalculates_price_when_updating_dates()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 10000, // 100.00 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from1 = Carbon::now()->addDays(1)->startOfDay();
        $until1 = Carbon::now()->addDays(3)->startOfDay(); // 2 days

        $cartItem = $this->cart->addToCart($product, 2, [], $from1, $until1);
        $this->assertEquals(20000, $cartItem->price); // 100 × 2 days
        $this->assertEquals(40000, $cartItem->subtotal); // 200 × 2 quantity

        // Update to longer period
        $from2 = Carbon::now()->addDays(5)->startOfDay();
        $until2 = Carbon::now()->addDays(10)->startOfDay(); // 5 days

        $updated = $cartItem->updateDates($from2, $until2);

        $this->assertEquals(50000, $updated->price); // 100 × 5 days
        $this->assertEquals(100000, $updated->subtotal); // 500 × 2 quantity
    }

    #[Test]
    public function it_can_set_from_date_individually()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $cartItem = $this->cart->addToCart($product, 1);

        $from = Carbon::now()->addDays(1);
        $updated = $cartItem->setFromDate($from);

        $this->assertEquals($from->format('Y-m-d H:i:s'), $updated->from->format('Y-m-d H:i:s'));
        $this->assertNull($updated->until);
    }

    #[Test]
    public function it_can_set_until_date_individually()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $cartItem = $this->cart->addToCart($product, 1);

        $until = Carbon::now()->addDays(3);
        $updated = $cartItem->setUntilDate($until);

        $this->assertNull($updated->from);
        $this->assertEquals($until->format('Y-m-d H:i:s'), $updated->until->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_recalculates_when_both_dates_are_set()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 8000, // 80.00 per day
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $cartItem = $this->cart->addToCart($product, 1);

        $from = Carbon::now()->addDays(1)->startOfDay();
        $until = Carbon::now()->addDays(5)->startOfDay(); // 4 days

        // Set from first
        $cartItem->setFromDate($from);
        $this->assertNull($cartItem->fresh()->until);
        $this->assertEquals(8000, $cartItem->fresh()->price); // Still default 1 day

        // Set until - should trigger recalculation
        $updated = $cartItem->setUntilDate($until);

        $this->assertEquals(32000, $updated->price); // 80 × 4 days
        $this->assertEquals(32000, $updated->subtotal);
    }

    #[Test]
    public function it_throws_exception_when_from_is_after_until()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $cartItem = $this->cart->addToCart($product, 1);

        $from = Carbon::now()->addDays(5);
        $until = Carbon::now()->addDays(2);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("'from' date must be before the 'until' date");
        $cartItem->updateDates($from, $until);
    }

    #[Test]
    public function it_validates_dates_at_checkout_for_booking_products()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Add booking product without dates
        $this->cart->addToCart($product, 1);

        // Should throw exception at checkout
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('missing required information: from, until');
        $this->cart->checkout();
    }

    #[Test]
    public function it_allows_checkout_when_dates_are_set()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $this->cart->addToCart($product, 1, [], $from, $until);

        // Should not throw exception
        $cart = $this->cart->checkout();
        $this->assertNotNull($cart);
    }
}
