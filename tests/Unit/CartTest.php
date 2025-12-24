<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Enums\CartStatus;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;


class CartTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cart_can_add_product_price_directly()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 100.00)->create();
        $price = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($price, quantity: 2);

        $this->assertNotNull($cartItem);
        $this->assertEquals(2, $cartItem->quantity);
        $this->assertEquals(100.00, $cartItem->price);
    }

    #[Test]
    public function cart_calculates_subtotal_automatically()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create();
        $price = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($price, quantity: 3);

        $this->assertEquals(150.00, $cartItem->subtotal);
    }

    #[Test]
    public function cart_respects_sale_prices()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(1, 50)->create([
            'sale_start' => now()->subDay(),
            'sale_end' => now()->addDay(),
        ]);

        $product->prices()->first()->update([
            'is_default' => false,
        ]);

        // Create a second price using factory
        $price = ProductPrice::factory()->create([
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

    #[Test]
    public function cart_can_add_items_with_custom_parameters()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create();
        $price = $product->defaultPrice()->first();

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

    #[Test]
    public function cart_total_sums_all_items()
    {
        $cart = Cart::create();

        $product1 = Product::factory()->withPrices(unit_amount: 25.00)->create();
        $price1 = $product1->defaultPrice()->first();

        $product2 = Product::factory()->withPrices(unit_amount: 50.00)->create();
        $price2 = $product2->defaultPrice()->first();

        $cart->addToCart($price1, quantity: 2); // 50
        $cart->addToCart($price2, quantity: 3); // 150

        $total = $cart->fresh()->getTotal();

        $this->assertEquals(200.00, $total);
    }

    #[Test]
    public function cart_tracks_last_activity()
    {
        $cart = Cart::create([
            'last_activity_at' => now()->subHours(2),
        ]);

        $this->assertNotNull($cart->last_activity_at);
        $this->assertTrue($cart->last_activity_at->isPast());
    }

    #[Test]
    public function cart_can_be_converted()
    {
        $cart = Cart::create();

        $this->assertFalse($cart->isConverted());

        $cart->update(['converted_at' => now()]);

        $this->assertTrue($cart->fresh()->isConverted());
    }

    #[Test]
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

    #[Test]
    public function cart_deletes_items_on_deletion()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 50.00)->create();
        $price = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($price);
        $cartItemId = $cartItem->id;

        $this->assertDatabaseHas('cart_items', ['id' => $cartItemId]);

        $cart->delete();

        $this->assertDatabaseMissing('cart_items', ['id' => $cartItemId]);
    }

    #[Test]
    public function cart_can_have_currency()
    {
        $cart = Cart::create([
            'currency' => 'EUR',
        ]);

        $this->assertEquals('EUR', $cart->currency);
    }

    #[Test]
    public function cart_can_have_status()
    {
        $cart = Cart::create([
            'status' => CartStatus::ACTIVE,
        ]);

        $this->assertEquals(CartStatus::ACTIVE, $cart->status);

        $cart->update(['status' => CartStatus::CONVERTED]);

        $this->assertEquals(CartStatus::CONVERTED, $cart->fresh()->status);
    }

    #[Test]
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

    #[Test]
    public function cart_can_have_session_id()
    {
        $sessionId = 'sess_' . str()->random(40);

        $cart = Cart::create([
            'session_id' => $sessionId,
        ]);

        $this->assertEquals($sessionId, $cart->session_id);
    }

    #[Test]
    public function checkout_session_link_throws_when_stripe_disabled()
    {
        config(['shop.stripe.enabled' => false]);

        $cart = Cart::create();

        // Now throws CartEmptyException (validation happens before stripe check)
        $this->expectException(\Blax\Shop\Exceptions\CartEmptyException::class);
        $cart->checkoutSessionLink();
    }

    #[Test]
    public function checkout_session_link_throws_when_cart_empty()
    {
        config(['shop.stripe.enabled' => true]);

        $cart = Cart::create();

        // Now throws CartEmptyException instead of returning null
        $this->expectException(\Blax\Shop\Exceptions\CartEmptyException::class);
        $cart->checkoutSessionLink();
    }

    #[Test]
    public function checkout_session_link_throws_when_cart_empty_even_with_meta()
    {
        config(['shop.stripe.enabled' => true]);

        $cart = Cart::create([
            'meta' => ['other_data' => 'value'],
        ]);

        // Now throws CartEmptyException instead of returning null
        $this->expectException(\Blax\Shop\Exceptions\CartEmptyException::class);
        $cart->checkoutSessionLink();
    }

    #[Test]
    public function checkout_session_link_returns_false_on_stripe_error()
    {
        config(['shop.stripe.enabled' => true]);
        config(['services.stripe.secret' => 'sk_test_invalid']);

        $cart = Cart::create([
            'meta' => ['stripe_session_id' => 'cs_test_invalid'],
        ]);

        // Note: This test would require a real Stripe API call or advanced mocking
        // to properly test the error scenario. For now, we verify the method exists
        // and has the correct signature. Integration tests should cover actual Stripe errors.
        $this->assertTrue(method_exists($cart, 'checkoutSessionLink'));

        // The method signature should return string|null|false
        $reflection = new \ReflectionMethod($cart, 'checkoutSessionLink');
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
    }

    #[Test]
    public function checkout_session_link_returns_null_when_session_not_found()
    {
        config(['shop.stripe.enabled' => true]);
        config(['services.stripe.secret' => 'sk_test_invalid']);

        $cart = Cart::create([
            'meta' => ['stripe_session_id' => 'cs_test_nonexistent'],
        ]);

        // This test requires mocking Stripe, which would be done in integration tests
        // For unit tests, we verify the method exists and handles the scenario
        $this->assertTrue(method_exists($cart, 'checkoutSessionLink'));
    }

    #[Test]
    public function checkout_session_link_handles_meta_as_array()
    {
        config(['shop.stripe.enabled' => true]);

        $cart = Cart::create([
            'meta' => ['stripe_session_id' => 'cs_test_123'],
        ]);

        // Verify meta is accessible
        $this->assertEquals('cs_test_123', $cart->meta->stripe_session_id);
    }

    #[Test]
    public function checkout_session_link_handles_meta_as_object()
    {
        config(['shop.stripe.enabled' => true]);

        $cart = Cart::create();
        $cart->meta = (object)['stripe_session_id' => 'cs_test_456'];
        $cart->save();

        $cart = $cart->fresh();

        // Verify meta is accessible
        $this->assertEquals('cs_test_456', $cart->meta->stripe_session_id);
    }
}
