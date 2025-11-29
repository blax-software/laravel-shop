<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function cart_can_add_product_price_directly()
    {
        $cart = Cart::create();
        $product = Product::factory()->create();
        $price = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 100.00,
            'currency' => 'USD',
        ]);

        $cartItem = $cart->addToCart($price, quantity: 2);

        $this->assertNotNull($cartItem);
        $this->assertEquals(2, $cartItem->quantity);
        $this->assertEquals(100.00, $cartItem->price);
    }

    /** @test */
    public function cart_calculates_subtotal_automatically()
    {
        $cart = Cart::create();
        $product = Product::factory()->create();
        $price = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 50.00,
            'currency' => 'USD',
        ]);

        $cartItem = $cart->addToCart($price, quantity: 3);

        $this->assertEquals(150.00, $cartItem->subtotal);
    }

    /** @test */
    public function cart_respects_sale_prices()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(1,50)->create([
            'sale_start' => now()->subDay(), 
            'sale_end' => now()->addDay(),
        ]);

        $product->prices()->first()->update([
            'is_default' => false,
        ]);

        $price = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 100.00,
            'sale_unit_amount' => 80.00,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Assert product has price
        $this->assertTrue($product->hasPrice());
        $this->assertEquals(2, $product->prices()->count());

        $cartItem = $cart->addToCart($product, quantity: 1);

        $this->assertEquals(80.00, $cartItem->getSubtotal());
        $this->assertEquals(100.00, $cartItem->regular_price);
    }

    /** @test */
    public function cart_can_add_items_with_custom_parameters()
    {
        $cart = Cart::create();
        $product = Product::factory()->create();
        $price = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 50.00,
            'currency' => 'USD',
        ]);

        $parameters = [
            'color' => 'red',
            'size' => 'medium',
            'engraving' => 'Custom text',
        ];

        $cartItem = $cart->addToCart($price, quantity: 1, parameters: $parameters);

        $this->assertEquals('red', $cartItem->parameters['color']);
        $this->assertEquals('medium', $cartItem->parameters['size']);
        $this->assertEquals('Custom text', $cartItem->parameters['engraving']);
    }

    /** @test */
    public function cart_total_sums_all_items()
    {
        $cart = Cart::create();
        
        $product1 = Product::factory()->create();
        $price1 = ProductPrice::create([
            'purchasable_id' => $product1->id,
            'purchasable_type' => get_class($product1),
            'unit_amount' => 25.00,
            'currency' => 'USD',
        ]);

        $product2 = Product::factory()->create();
        $price2 = ProductPrice::create([
            'purchasable_id' => $product2->id,
            'purchasable_type' => get_class($product2),
            'unit_amount' => 50.00,
            'currency' => 'USD',
        ]);

        $cart->addToCart($price1, quantity: 2); // 50
        $cart->addToCart($price2, quantity: 3); // 150

        $total = $cart->fresh()->getTotal();

        $this->assertEquals(200.00, $total);
    }

    /** @test */
    public function cart_tracks_last_activity()
    {
        $cart = Cart::create([
            'last_activity_at' => now()->subHours(2),
        ]);

        $this->assertNotNull($cart->last_activity_at);
        $this->assertTrue($cart->last_activity_at->isPast());
    }

    /** @test */
    public function cart_can_be_converted()
    {
        $cart = Cart::create();

        $this->assertFalse($cart->isConverted());

        $cart->update(['converted_at' => now()]);

        $this->assertTrue($cart->fresh()->isConverted());
    }

    /** @test */
    public function active_scope_filters_correctly()
    {
        // Active cart (not expired, not converted)
        Cart::create([
            'expires_at' => now()->addDay(),
            'converted_at' => null,
        ]);

        // Expired cart
        Cart::create([
            'expires_at' => now()->subDay(),
            'converted_at' => null,
        ]);

        // Converted cart
        Cart::create([
            'expires_at' => now()->addDay(),
            'converted_at' => now(),
        ]);

        // Permanent cart (no expiry)
        Cart::create([
            'expires_at' => null,
            'converted_at' => null,
        ]);

        $active = Cart::active()->get();

        $this->assertCount(2, $active);
    }

    /** @test */
    public function cart_deletes_items_on_deletion()
    {
        $cart = Cart::create();
        $product = Product::factory()->create();
        $price = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 50.00,
            'currency' => 'USD',
        ]);

        $cartItem = $cart->addToCart($price);
        $cartItemId = $cartItem->id;

        $this->assertDatabaseHas('cart_items', ['id' => $cartItemId]);

        $cart->delete();

        $this->assertDatabaseMissing('cart_items', ['id' => $cartItemId]);
    }

    /** @test */
    public function cart_can_have_currency()
    {
        $cart = Cart::create([
            'currency' => 'EUR',
        ]);

        $this->assertEquals('EUR', $cart->currency);
    }

    /** @test */
    public function cart_can_have_status()
    {
        $cart = Cart::create([
            'status' => 'pending',
        ]);

        $this->assertEquals('pending', $cart->status);

        $cart->update(['status' => 'completed']);

        $this->assertEquals('completed', $cart->fresh()->status);
    }

    /** @test */
    public function cart_can_store_metadata()
    {
        $cart = Cart::create([
            'meta' => [
                'coupon_code' => 'SAVE10',
                'notes' => 'Gift wrapped',
            ],
        ]);

        $this->assertEquals('SAVE10', $cart->meta->coupon_code);
        $this->assertEquals('Gift wrapped', $cart->meta->notes);
    }

    /** @test */
    public function cart_can_have_session_id()
    {
        $sessionId = 'sess_' . str()->random(40);

        $cart = Cart::create([
            'session_id' => $sessionId,
        ]);

        $this->assertEquals($sessionId, $cart->session_id);
    }
}
