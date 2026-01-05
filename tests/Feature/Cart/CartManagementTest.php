<?php

namespace Blax\Shop\Tests\Feature\Cart;

use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class CartManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
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

    #[Test]
    public function it_automatically_generates_uuid()
    {
        $cart = Cart::create();

        $this->assertNotNull($cart->id);
        $this->assertIsString($cart->id);
    }

    #[Test]
    public function it_can_add_items_to_cart()
    {
        $product = Product::factory()->withPrices()->create();
        $price = $product->defaultPrice()->first();

        $cart = Cart::create();

        $cartItem = $cart->addToCart($price, quantity: 2);

        $this->assertCount(1, $cart->fresh()->items);
        $this->assertEquals(2, $cart->items->first()->quantity);
    }

    #[Test]
    public function it_can_update_cart_item_quantity()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 5000)->create();
        $price = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($price, quantity: 1);
        $cartItem->update(['quantity' => 3]);

        $this->assertEquals(3, $cartItem->fresh()->quantity);
    }

    #[Test]
    public function it_can_remove_items_from_cart()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 10000)->create();
        $price = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($price, quantity: 1);

        $this->assertCount(1, $cart->items);

        $cartItem->delete();

        $this->assertCount(0, $cart->refresh()->items);
    }

    #[Test]
    public function it_calculates_cart_total_correctly()
    {
        $cart = Cart::create();
        $product1 = Product::factory()->withPrices(unit_amount: 5000)->create();
        $product2 = Product::factory()->withPrices(unit_amount: 3000)->create();

        $productPrice1 = $product1->defaultPrice()->first();
        $productPrice2 = $product2->defaultPrice()->first();

        $cart->addToCart($productPrice1, quantity: 2);
        $cart->addToCart($productPrice2, quantity: 1);

        $total = $cart->fresh()->getTotal();

        $this->assertEquals(13000, $total); // (5000 * 2) + (3000 * 1)
    }

    #[Test]
    public function it_calculates_total_items_correctly()
    {
        $cart = Cart::create();
        $product1 = Product::factory()->withPrices(unit_amount: 1000)->create();
        $product2 = Product::factory()->withPrices(unit_amount: 2000)->create();

        $product1Price = $product1->defaultPrice()->first();
        $product2Price = $product2->defaultPrice()->first();

        $cart->addToCart($product1Price, quantity: 3);
        $cart->addToCart($product2Price, quantity: 2);

        $totalItems = $cart->fresh()->getTotalItems();

        $this->assertEquals(5, $totalItems); // 3 + 2
    }

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
    public function it_belongs_to_a_user()
    {
        $user = User::factory()->create();
        $cart = Cart::create(['customer_type' => get_class($user), 'customer_id' => $user->id]);

        $this->assertEquals($user->id, $cart->user->id);
    }

    #[Test]
    public function cart_items_have_correct_relationships()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 4500)->create();
        $productPrice = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($productPrice, quantity: 1);

        $this->assertEquals($cart->id, $cartItem->cart->id);
        $this->assertEquals($productPrice->id, $cartItem->purchasable_id);
    }

    #[Test]
    public function it_calculates_cart_item_subtotal()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 2500)->create();
        $productPrice = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($productPrice, quantity: 4);

        $this->assertEquals(10000, $cartItem->getSubtotal()); // 2500 * 4
    }

    #[Test]
    public function it_can_store_cart_item_attributes()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 5000)->create();
        $productPrice = $product->defaultPrice()->first();

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

    #[Test]
    public function it_can_have_multiple_items_of_same_product_with_different_attributes()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 3000)->create();
        $productPrice = $product->defaultPrice()->first();

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
        $this->assertEquals($productPrice->unit_amount * 3, $cart->getTotal());
    }

    #[Test]
    public function it_deletes_cart_items_when_cart_is_deleted()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 7500)->create();
        $productPrice = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart(
            $productPrice,
            quantity: 1,
        );

        $cartItemId = $cartItem->id;

        $cart->forceDelete();

        $this->assertDatabaseMissing('cart_items', ['id' => $cartItemId]);
    }

    #[Test]
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

    #[Test]
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
                unit_amount: 15000,
                sale_unit_amount: 12000,
                stocks: 10
            )
            ->withNewProductInCart(
                quantity: 2,
                unit_amount: 15000,
                sale_unit_amount: 12000,
                stocks: 10,
                sale_start: now()->subDay(),
                sale_end: now()->addDay()
            )
            ->create();

        $this->assertCount(2, $cartWithProduct->items);
        $this->assertEquals(4, $cartWithProduct->getTotalItems());
        $this->assertEquals((15000 * 2) + (12000 * 2), $cartWithProduct->getTotal());
    }

    #[Test]
    public function it_can_remove_entire_cart_item()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 5000)->create();
        $price = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($price, quantity: 2);
        $this->assertCount(1, $cart->items);

        $result = $cart->removeFromCart($price, quantity: 2);

        $this->assertCount(0, $cart->refresh()->items);
        $this->assertTrue(true); // Item was deleted
    }

    #[Test]
    public function it_can_decrease_cart_item_quantity()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 7500)->create();
        $price = $product->defaultPrice()->first();

        $cartItem = $cart->addToCart($price, quantity: 5);
        $this->assertEquals(5, $cartItem->quantity);

        $result = $cart->removeFromCart($price, quantity: 2);

        $updatedItem = $cart->items->first();
        $this->assertEquals(3, $updatedItem->quantity);
        $this->assertEquals(7500 * 3, $updatedItem->subtotal);
    }

    #[Test]
    public function it_updates_subtotal_correctly_when_decreasing_quantity()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 10000)->create();
        $price = $product->defaultPrice()->first();

        $cart->addToCart($price, quantity: 4);

        $cart->removeFromCart($price, quantity: 1);

        $cartItem = $cart->items->first();
        $this->assertEquals(3, $cartItem->quantity);
        $this->assertEquals(30000, $cartItem->subtotal);
    }

    #[Test]
    public function it_respects_parameters_when_removing_from_cart()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 5000)->create();
        $price = $product->defaultPrice()->first();

        // Add same product with different parameters
        $cartItem1 = $cart->addToCart(
            $price,
            quantity: 2,
            parameters: ['color' => 'blue']
        );

        $cartItem2 = $cart->addToCart(
            $price,
            quantity: 3,
            parameters: ['color' => 'red']
        );

        $this->assertCount(2, $cart->items);

        // Remove only the blue item
        $cart->removeFromCart($price, quantity: 2, parameters: ['color' => 'blue']);

        $this->assertCount(1, $cart->refresh()->items);
        $this->assertEquals('red', $cart->items->first()->parameters['color']);
        $this->assertEquals(3, $cart->items->first()->quantity);
    }

    #[Test]
    public function it_decreases_only_matching_parameters_when_removing()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 5000)->create();
        $price = $product->defaultPrice()->first();

        $cart->addToCart(
            $price,
            quantity: 5,
            parameters: ['size' => 'large']
        );

        $cart->removeFromCart($price, quantity: 2, parameters: ['size' => 'large']);

        $cartItem = $cart->items->first();
        $this->assertEquals(3, $cartItem->quantity);
        $this->assertEquals('large', $cartItem->parameters['size']);
    }

    #[Test]
    public function it_returns_cart_item_when_quantity_is_decreased()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 5000)->create();
        $price = $product->defaultPrice()->first();

        $cart->addToCart($price, quantity: 5);

        $result = $cart->removeFromCart($price, quantity: 2);

        $this->assertInstanceOf(CartItem::class, $result);
        $this->assertEquals(3, $result->quantity);
    }

    #[Test]
    public function it_handles_removing_nonexistent_item_gracefully()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 5000)->create();
        $price = $product->defaultPrice()->first();

        $result = $cart->removeFromCart($price, quantity: 1);

        // Should return true when item doesn't exist
        $this->assertTrue($result);
        $this->assertCount(0, $cart->items);
    }

    #[Test]
    public function it_updates_cart_total_after_removing_items()
    {
        $cart = Cart::create();
        $product = Product::factory()->withPrices(unit_amount: 5000)->create();
        $price = $product->defaultPrice()->first();

        $cart->addToCart($price, quantity: 5);
        $this->assertEquals(25000, $cart->getTotal());

        $cart->removeFromCart($price, quantity: 2);

        $this->assertEquals(15000, $cart->refresh()->getTotal());
    }

    #[Test]
    public function it_can_remove_from_cart_with_multiple_items()
    {
        $cart = Cart::create();
        $product1 = Product::factory()->withPrices(unit_amount: 5000)->create();
        $product2 = Product::factory()->withPrices(unit_amount: 7500)->create();

        $price1 = $product1->defaultPrice()->first();
        $price2 = $product2->defaultPrice()->first();

        $cart->addToCart($price1, quantity: 2);
        $cart->addToCart($price2, quantity: 3);
        $this->assertCount(2, $cart->items);

        $cart->removeFromCart($price1, quantity: 2);

        $this->assertCount(1, $cart->refresh()->items);
        $this->assertEquals($price2->id, $cart->items->first()->purchasable_id);
        $this->assertEquals(22500, $cart->getTotal());
    }
}
