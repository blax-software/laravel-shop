<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;

class CartManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_can_create_a_cart()
    {
        $user = User::factory()->create();

        $cart = Cart::create([
            'customer_type' => get_class($user),
            'customer_id' => $user->id,
            'expires_at' => now()->addDays(7),
        ]);

        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'customer_type' => get_class($user),
            'customer_id' => $user->id,
        ]);
        $this->assertNotNull($cart->id);
    }

    /** @test */
    public function it_automatically_generates_uuid()
    {
        $cart = Cart::create();

        $this->assertNotNull($cart->id);
        $this->assertIsString($cart->id);
    }

    /** @test */
    public function it_can_add_items_to_cart()
    {
        $cart = Cart::create();
        $product = Product::factory()->create(['price' => 99.99]);

        $cartItem = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => $product->price,
            'subtotal' => $product->price * 2,
        ]);

        $this->assertCount(1, $cart->fresh()->items);
        $this->assertEquals(2, $cart->items->first()->quantity);
    }

    /** @test */
    public function it_can_update_cart_item_quantity()
    {
        $cart = Cart::create();
        $product = Product::factory()->create(['price' => 50.00]);

        $cartItem = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price,
            'subtotal' => $product->price,
        ]);

        $cartItem->update(['quantity' => 3]);

        $this->assertEquals(3, $cartItem->fresh()->quantity);
    }

    /** @test */
    public function it_can_remove_items_from_cart()
    {
        $cart = Cart::create();
        $product = Product::factory()->create(['price' => 75.00]);

        $cartItem = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price,
            'subtotal' => $product->price,
        ]);

        $this->assertCount(1, $cart->fresh()->items);

        $cartItem->delete();

        $this->assertCount(0, $cart->fresh()->items);
    }

    /** @test */
    public function it_calculates_cart_total_correctly()
    {
        $cart = Cart::create();
        $product1 = Product::factory()->create(['price' => 50.00]);
        $product2 = Product::factory()->create(['price' => 30.00]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product1->id,
            'quantity' => 2,
            'price' => $product1->price,
            'subtotal' => $product1->price * 2,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product2->id,
            'quantity' => 1,
            'price' => $product2->price,
            'subtotal' => $product2->price,
        ]);

        $total = $cart->fresh()->getTotal();

        $this->assertEquals(130.00, $total); // (50 * 2) + (30 * 1)
    }

    /** @test */
    public function it_calculates_total_items_correctly()
    {
        $cart = Cart::create();
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product1->id,
            'quantity' => 3,
            'price' => 10.00,
            'subtotal' => 30.00,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product2->id,
            'quantity' => 2,
            'price' => 20.00,
            'subtotal' => 40.00,
        ]);

        $totalItems = $cart->fresh()->getTotalItems();

        $this->assertEquals(5, $totalItems); // 3 + 2
    }

    /** @test */
    public function it_can_check_if_cart_is_expired()
    {
        $expiredCart = Cart::create([
            'expires_at' => now()->subDay(),
        ]);

        $activeCart = Cart::create([
            'expires_at' => now()->addDay(),
        ]);

        $this->assertTrue($expiredCart->isExpired());
        $this->assertFalse($activeCart->isExpired());
    }

    /** @test */
    public function it_can_check_if_cart_is_converted()
    {
        $convertedCart = Cart::create([
            'converted_at' => now(),
        ]);

        $activeCart = Cart::create([
            'converted_at' => null,
        ]);

        $this->assertTrue($convertedCart->isConverted());
        $this->assertFalse($activeCart->isConverted());
    }

    /** @test */
    public function it_can_scope_active_carts()
    {
        Cart::create([
            'expires_at' => now()->addDay(),
            'converted_at' => null,
        ]);

        Cart::create([
            'expires_at' => now()->subDay(),
            'converted_at' => null,
        ]);

        Cart::create([
            'expires_at' => now()->addDay(),
            'converted_at' => now(),
        ]);

        $activeCarts = Cart::active()->get();

        $this->assertCount(1, $activeCarts);
    }

    /** @test */
    public function it_can_scope_carts_for_user()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        Cart::create(['customer_type' => get_class($user), 'customer_id' => $user->id]);
        Cart::create(['customer_type' => get_class($user), 'customer_id' => $user->id]);
        Cart::create(['customer_type' => get_class($otherUser), 'customer_id' => $otherUser->id]);

        $userCarts = Cart::forUser($user)->get();

        $this->assertCount(2, $userCarts);
    }

    /** @test */
    public function it_belongs_to_a_user()
    {
        $user = User::factory()->create();
        $cart = Cart::create(['customer_type' => get_class($user), 'customer_id' => $user->id]);

        $this->assertEquals($user->id, $cart->user->id);
    }

    /** @test */
    public function cart_items_have_correct_relationships()
    {
        $cart = Cart::create();
        $product = Product::factory()->create(['price' => 45.00]);

        $cartItem = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price,
            'subtotal' => $product->price,
        ]);

        $this->assertEquals($cart->id, $cartItem->cart->id);
        $this->assertEquals($product->id, $cartItem->product->id);
    }

    /** @test */
    public function it_calculates_cart_item_subtotal()
    {
        $cart = Cart::create();
        $product = Product::factory()->create(['price' => 25.00]);

        $cartItem = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 4,
            'price' => $product->price,
            'subtotal' => $product->price * 4,
        ]);

        $this->assertEquals(100.00, $cartItem->getSubtotal()); // 25 * 4
    }

    /** @test */
    public function it_can_store_cart_item_attributes()
    {
        $cart = Cart::create();
        $product = Product::factory()->create();

        $cartItem = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 50.00,
            'subtotal' => 50.00,
            'attributes' => [
                'color' => 'blue',
                'size' => 'large',
            ],
        ]);

        $this->assertEquals('blue', $cartItem->attributes['color']);
        $this->assertEquals('large', $cartItem->attributes['size']);
    }

    /** @test */
    public function it_can_have_multiple_items_of_same_product_with_different_attributes()
    {
        $cart = Cart::create();
        $product = Product::factory()->create(['price' => 30.00]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => $product->price,
            'subtotal' => $product->price,
            'attributes' => ['size' => 'small'],
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'price' => $product->price,
            'subtotal' => $product->price * 2,
            'attributes' => ['size' => 'large'],
        ]);

        $this->assertCount(2, $cart->fresh()->items);
    }

    /** @test */
    public function it_deletes_cart_items_when_cart_is_deleted()
    {
        $cart = Cart::create();
        $product = Product::factory()->create();

        $cartItem = CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 50.00,
            'subtotal' => 50.00,
        ]);

        $cartItemId = $cartItem->id;

        $cart->delete();

        $this->assertDatabaseMissing('cart_items', ['id' => $cartItemId]);
    }
}
