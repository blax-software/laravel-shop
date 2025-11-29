<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
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
        $product = Product::factory()->create();
        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
        ]);

        $cart = Cart::create();

        $cartItem = CartItem::create([
            'cart_id' => $cart->id,
            'purchasable_id' => $price->id,
            'purchasable_type' => get_class($price),
            'quantity' => 2,
            'price' => $price->unit_amount,
            'subtotal' => $price->unit_amount * 2,
        ]);

        $this->assertCount(1, $cart->fresh()->items);
        $this->assertEquals(2, $cart->items->first()->quantity);
    }

    /** @test */
    public function it_can_update_cart_item_quantity()
    {
        $cart = Cart::create();
        $product = Product::factory()->create();
        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 50.00,
        ]);

        $cartItem = $cart->addToCart($price, quantity: 1);
        $cartItem->update(['quantity' => 3]);

        $this->assertEquals(3, $cartItem->fresh()->quantity);
    }

    /** @test */
    public function it_can_remove_items_from_cart()
    {
        $cart = Cart::create();
        $product = Product::factory()->create();
        $price = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 100.00,
        ]);

        $cartItem = $cart->addToCart($price, quantity: 1);

        $this->assertCount(1, $cart->items);

        $cartItem->delete();

        $this->assertCount(0, $cart->refresh()->items);
    }

    /** @test */
    public function it_calculates_cart_total_correctly()
    {
        $cart = Cart::create();
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();

        $productPrice1 = ProductPrice::factory()->create([
            'purchasable_id' => $product1->id,
            'purchasable_type' => get_class($product1),
            'unit_amount' => 50.00,
        ]);

        $productPrice2 = ProductPrice::factory()->create([
            'purchasable_id' => $product2->id,
            'purchasable_type' => get_class($product2),
            'unit_amount' => 30.00,
        ]);

        $cart->addToCart($productPrice1, quantity: 2);
        $cart->addToCart($productPrice2, quantity: 1);

        $total = $cart->fresh()->getTotal();

        $this->assertEquals(130.00, $total); // (50 * 2) + (30 * 1)
    }

    /** @test */
    public function it_calculates_total_items_correctly()
    {
        $cart = Cart::create();
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();

        $product1Price = ProductPrice::factory()->create([
            'purchasable_id' => $product1->id,
            'purchasable_type' => get_class($product1),
            'unit_amount' => 10.00,
        ]);

        $product2Price = ProductPrice::factory()->create([
            'purchasable_id' => $product2->id,
            'purchasable_type' => get_class($product2),
            'unit_amount' => 20.00,
        ]);

        $cart->addToCart($product1Price, quantity: 3);
        $cart->addToCart($product2Price, quantity: 2);

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
        $product = Product::factory()->create();

        $productPrice = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 45.00,
        ]);

        $cartItem = $cart->addToCart($productPrice, quantity: 1);

        $this->assertEquals($cart->id, $cartItem->cart->id);
        $this->assertEquals($productPrice->id, $cartItem->purchasable_id);
    }

    /** @test */
    public function it_calculates_cart_item_subtotal()
    {
        $cart = Cart::create();
        $product = Product::factory()->create();

        $productPrice = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 25.00,
        ]);

        $cartItem = $cart->addToCart($productPrice, quantity: 4);

        $this->assertEquals(100.00, $cartItem->getSubtotal()); // 25 * 4
    }

    /** @test */
    public function it_can_store_cart_item_attributes()
    {
        $cart = Cart::create();
        $product = Product::factory()->create();

        $productPrice = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 50.00,
        ]);

        $cartItem = $cart->addToCart(
            $productPrice,
            quantity: 1,
            parameters: [
                'color' => 'blue',
                'size' => 'large',
            ]
        );

        $this->assertEquals('blue', $cartItem->parameters['color']);
        $this->assertEquals('large', $cartItem->parameters['size']);
    }

    /** @test */
    public function it_can_have_multiple_items_of_same_product_with_different_attributes()
    {
        $cart = Cart::create();
        $product = Product::factory()->create();

        $productPrice = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 30.00,
        ]);

        $cart->addToCart(
            $productPrice,
            quantity: 1,
            parameters: ['size' => 'small']
        );

        $cart->addToCart(
            $productPrice,
            quantity: 2,
            parameters: ['size' => 'large']
        );

        $this->assertCount(2, $cart->fresh()->items);
    }

    /** @test */
    public function it_deletes_cart_items_when_cart_is_deleted()
    {
        $cart = Cart::create();
        $product = Product::factory()->create();

        $productPrice = ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 75.00,
        ]);

        $cartItem = $cart->addToCart(
            $productPrice,
            quantity: 1,
        );

        $cartItemId = $cartItem->id;

        $cart->forceDelete();

        $this->assertDatabaseMissing('cart_items', ['id' => $cartItemId]);
    }

    /** @test */
    public function it_calculats_unpaid_and_paid_and_can_scope()
    {
        $user = User::factory()->create();
        $cart = $user->currentCart();
        $product1 = Product::factory()->withStocks()->withPrices(1, 782)->create();
        $product2 = Product::factory()->withStocks()->withPrices(1, 402)->create();
        $product3 = Product::factory()->withStocks()->withPrices(1, 855)->create();

        $cart->addToCart($product1);
        $cart->addToCart($product2);
        $cart->addToCart($product3);

        $this->assertEquals(2039, $cart->getUnpaidAmount(), 'Unpaid amount should equal total cart amount initially.');
        $this->assertEquals(0, $cart->getPaidAmount(), 'Paid amount should be zero initially.');
        $this->assertEquals(2039, $cart->getTotal(), 'Total amount should equal sum of paid and unpaid amounts.');
        $this->assertEquals($user->currentCart()->id, Cart::unpaid()->first()->id, 'Unpaid cart scope should return the current cart.');
    }

    /** @test */
    public function it_can_create_cart_with_factory()
    {
        $cart = Cart::factory()->create();

        $this->assertNotNull($cart);
        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
        ]);

        $cartWithProduct = Cart::factory()
            ->withNewProductInCart(
                quantity: 2,
                unit_amount: 150.00,
                sale_unit_amount: 120.00,
                stocks: 10
            )
            ->withNewProductInCart(
                quantity: 2,
                unit_amount: 150.00,
                sale_unit_amount: 120.00,
                stocks: 10,
                sale_start: now()->subDay(),
                sale_end: now()->addDay()
            )
            ->create();

        $this->assertCount(2, $cartWithProduct->items);
        $this->assertEquals(4, $cartWithProduct->getTotalItems());
        $this->assertEquals((150.00 * 2) + (120 * 2), $cartWithProduct->getTotal());
    }
}
