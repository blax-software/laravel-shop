<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Facades\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\Cart as CartModel;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class GuestCartTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_create_a_guest_cart()
    {
        $guestCart = Cart::guest();

        $this->assertInstanceOf(CartModel::class, $guestCart);
        $this->assertNotNull($guestCart->session_id);
        $this->assertNull($guestCart->customer_id);
        $this->assertNull($guestCart->customer_type);
    }

    #[Test]
    public function it_can_create_a_guest_cart_with_specific_session_id()
    {
        $sessionId = 'test-session-123';

        $guestCart = Cart::guest($sessionId);

        $this->assertEquals($sessionId, $guestCart->session_id);
        $this->assertNull($guestCart->customer_id);
    }

    #[Test]
    public function it_retrieves_same_guest_cart_for_same_session()
    {
        $sessionId = 'persistent-session-456';

        $cart1 = Cart::guest($sessionId);
        $cart1->items()->create([
            'purchasable_id' => 'test-id',
            'purchasable_type' => 'Test\Model',
            'quantity' => 2,
            'price' => 100.00,
            'subtotal' => 200.00,
        ]);

        $cart2 = Cart::guest($sessionId);

        $this->assertEquals($cart1->id, $cart2->id);
        $this->assertCount(1, $cart2->items);
    }

    #[Test]
    public function it_can_add_items_to_guest_cart()
    {
        $guestCart = Cart::guest('guest-session');
        $product = Product::factory()->withStocks(50)->withPrices()->create();

        $cartItem = $guestCart->addToCart($product, quantity: 2);

        $this->assertCount(1, $guestCart->items);
        $this->assertEquals(2, $cartItem->quantity);
    }

    #[Test]
    public function it_can_get_guest_cart_total()
    {
        $guestCart = Cart::guest('guest-session-2');
        $product1 = Product::factory()->withStocks(50)->withPrices(1, 100)->create();
        $product2 = Product::factory()->withStocks(50)->withPrices(1, 50)->create();

        $guestCart->addToCart($product1, quantity: 2);  // 200
        $guestCart->addToCart($product2, quantity: 1);  // 50

        $total = Cart::total($guestCart);

        $this->assertEquals(250.00, $total);
    }

    #[Test]
    public function it_can_get_guest_cart_item_count()
    {
        $guestCart = Cart::guest('guest-session-3');
        $product = Product::factory()->withStocks(50)->withPrices()->create();

        $guestCart->addToCart($product, quantity: 5);

        $count = Cart::itemCount($guestCart);

        $this->assertEquals(5, $count);
    }

    #[Test]
    public function it_can_remove_items_from_guest_cart()
    {
        $guestCart = Cart::guest('guest-session-4');
        $product = Product::factory()->withStocks(50)->withPrices()->create();

        $guestCart->addToCart($product, quantity: 5);
        $guestCart->removeFromCart($product, quantity: 2);

        $items = Cart::items($guestCart);

        $this->assertCount(1, $items);
        $this->assertEquals(3, $items[0]->quantity);
    }

    #[Test]
    public function it_can_clear_guest_cart()
    {
        $guestCart = Cart::guest('guest-session-5');
        $product1 = Product::factory()->withStocks(50)->withPrices()->create();
        $product2 = Product::factory()->withStocks(50)->withPrices()->create();

        $guestCart->addToCart($product1, quantity: 2);
        $guestCart->addToCart($product2, quantity: 1);

        $count = Cart::clear($guestCart);

        $this->assertEquals(2, $count);
        $this->assertTrue(Cart::isEmpty($guestCart));
    }

    #[Test]
    public function it_can_check_if_guest_cart_is_empty()
    {
        $guestCart = Cart::guest('guest-session-6');

        $this->assertTrue(Cart::isEmpty($guestCart));

        $product = Product::factory()->withStocks(50)->withPrices()->create();
        $guestCart->addToCart($product, quantity: 1);
        $guestCart->refresh();

        $this->assertFalse(Cart::isEmpty($guestCart));
    }

    #[Test]
    public function it_can_find_guest_cart_by_id()
    {
        $guestCart = Cart::guest('guest-session-7');
        $cartId = $guestCart->id;

        $foundCart = Cart::find($cartId);

        $this->assertNotNull($foundCart);
        $this->assertEquals($cartId, $foundCart->id);
    }

    #[Test]
    public function guest_and_authenticated_carts_are_separate()
    {
        // Create guest cart
        $guestCart = Cart::guest('guest-session-8');
        $product = Product::factory()->withStocks(50)->withPrices(1, 100)->create();
        $guestCart->addToCart($product, quantity: 1);

        // Create authenticated user cart
        $user = User::factory()->create();
        $this->actingAs($user);
        Cart::add($product, quantity: 1);

        // Verify they're different
        $this->assertEquals(100.00, Cart::total($guestCart));
        $this->assertEquals(100.00, Cart::total());  // Current user's cart

        $guestCartItems = Cart::items($guestCart);
        $userCartItems = Cart::items();

        $this->assertCount(1, $guestCartItems);
        $this->assertCount(1, $userCartItems);
        $this->assertNotEquals($guestCart->id, Cart::current()->id);
    }

    #[Test]
    public function it_can_convert_guest_cart_to_user_cart()
    {
        // Guest adds items
        $guestCart = Cart::guest('guest-session-9');
        $product = Product::factory()->withStocks(50)->withPrices(1, 100)->create();
        $guestCart->addToCart($product, quantity: 2);

        // User logs in
        $user = User::factory()->create();
        $this->actingAs($user);

        // Create a new cart for user (simulating cart migration)
        $userCart = Cart::current();
        $userCart->addToCart($product, quantity: 2);

        // Original guest cart should still exist and be separate
        $this->assertEquals(200.00, Cart::total($guestCart));
        $this->assertEquals(200.00, Cart::total($userCart));
    }

    #[Test]
    public function it_returns_true_for_empty_guest_cart_after_clear()
    {
        $guestCart = Cart::guest('guest-session-10');
        $product = Product::factory()->withStocks(50)->withPrices()->create();
        $guestCart->addToCart($product, quantity: 3);

        Cart::clear($guestCart);

        $this->assertTrue(Cart::isEmpty($guestCart));
    }

    #[Test]
    public function multiple_guests_have_separate_carts()
    {
        $sessionId1 = 'guest-session-11';
        $sessionId2 = 'guest-session-12';

        $guestCart1 = Cart::guest($sessionId1);
        $guestCart2 = Cart::guest($sessionId2);

        $product = Product::factory()->withStocks(50)->withPrices(1, 100)->create();

        $guestCart1->addToCart($product, quantity: 1);  // 100
        $guestCart2->addToCart($product, quantity: 3);  // 300

        $this->assertNotEquals($guestCart1->id, $guestCart2->id);
        $this->assertEquals(100.00, Cart::total($guestCart1));
        $this->assertEquals(300.00, Cart::total($guestCart2));
    }

    #[Test]
    public function it_can_update_items_in_guest_cart()
    {
        $guestCart = Cart::guest('guest-session-13');
        $product = Product::factory()->withStocks(50)->withPrices(1, 50)->create();

        $cartItem = $guestCart->addToCart($product, quantity: 2);
        $this->assertEquals(100.00, $cartItem->subtotal);

        $updated = Cart::update($cartItem, quantity: 5);

        $this->assertEquals(5, $updated->quantity);
        $this->assertEquals(250.00, $updated->subtotal);
    }

    #[Test]
    public function guest_cart_expires_based_on_configuration()
    {
        $guestCart = Cart::guest('guest-session-14');

        // New carts shouldn't be expired
        $this->assertFalse(Cart::isExpired($guestCart));

        // Create an expired cart
        $expiredCart = CartModel::create([
            'session_id' => 'expired-session',
            'expires_at' => now()->subDay(),
        ]);

        $this->assertTrue(Cart::isExpired($expiredCart));
    }
}
