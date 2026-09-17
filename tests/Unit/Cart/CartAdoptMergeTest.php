<?php

namespace Blax\Shop\Tests\Unit\Cart;

use Blax\Shop\Facades\Cart as CartFacade;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Covers the storefront primitives added for WS-first shops:
 * CartService::adopt() (connection cart binding) and
 * CartService::mergeGuestIntoUser() (the previously-dead merge_on_login gap).
 */
class CartAdoptMergeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function adopt_without_id_returns_a_guest_cart_bound_to_the_session()
    {
        $cart = CartFacade::adopt(null, 'socket-A');

        $this->assertInstanceOf(Cart::class, $cart);
        $this->assertSame('socket-A', $cart->session_id);
        $this->assertNull($cart->customer_id);
    }

    #[Test]
    public function adopt_with_unknown_id_falls_back_to_a_fresh_guest_cart()
    {
        $cart = CartFacade::adopt('00000000-0000-0000-0000-000000000000', 'socket-A');

        $this->assertSame('socket-A', $cart->session_id);
    }

    #[Test]
    public function adopt_rebinds_a_guest_cart_to_the_new_session_and_clears_stale_ones()
    {
        $held = Cart::factory()->create(['session_id' => 'socket-old', 'customer_id' => null]);
        $stale = Cart::factory()->create(['session_id' => 'socket-B', 'customer_id' => null]);

        $adopted = CartFacade::adopt($held->id, 'socket-B');

        $this->assertTrue($held->is($adopted));
        $this->assertSame('socket-B', $adopted->fresh()->session_id);
        // The stale guest cart already sitting on socket-B is removed.
        $this->assertNull(Cart::find($stale->id));
    }

    #[Test]
    public function adopt_never_steals_a_customer_owned_cart()
    {
        $user = User::factory()->create();
        $owned = Cart::factory()->create([
            'session_id' => 'socket-old',
            'customer_id' => $user->id,
            'customer_type' => get_class($user),
        ]);

        $adopted = CartFacade::adopt($owned->id, 'socket-Z');

        $this->assertTrue($owned->is($adopted));
        // Untouched: still owned, session not rebound.
        $this->assertSame('socket-old', $adopted->fresh()->session_id);
    }

    #[Test]
    public function merge_moves_guest_items_onto_the_user_cart_and_retires_the_guest()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create(['manage_stock' => false]);

        $guest = Cart::factory()->create(['session_id' => 'socket-A', 'customer_id' => null]);
        $guest->addToCart($product, 2);

        $userCart = CartFacade::mergeGuestIntoUser($guest, $user);

        $this->assertSame(2, $userCart->items()->sum('quantity'));
        $this->assertSame(0, $guest->fresh()->items()->count());
        $this->assertStringStartsWith('merged_', $guest->fresh()->session_id);
    }

    #[Test]
    public function merge_is_a_noop_for_a_converted_or_owned_source_cart()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create(['manage_stock' => false]);

        // A cart already owned by (another) customer must not be drained.
        $owned = Cart::factory()->create([
            'session_id' => 'socket-A',
            'customer_id' => $user->id,
            'customer_type' => get_class($user),
        ]);
        $owned->addToCart($product, 1);

        $userCart = CartFacade::mergeGuestIntoUser($owned, $user);

        // Items stayed on the owned cart; nothing moved.
        $this->assertSame(1, $owned->fresh()->items()->count());
        $this->assertInstanceOf(Cart::class, $userCart);
    }

    #[Test]
    public function checkout_session_link_delegates_to_the_cart_model()
    {
        config()->set('shop.stripe.enabled', false);

        $product = Product::factory()->withPrices()->create(['manage_stock' => false]);
        $guest = Cart::factory()->create(['session_id' => 'socket-A', 'customer_id' => null]);
        $guest->addToCart($product, 1);

        // The facade passthrough reaches Cart::checkoutSessionLink(), which hits
        // the Stripe gate — proving delegation is wired (a no-op wrapper would
        // never surface this error).
        $this->expectExceptionMessage('Stripe is not enabled');
        CartFacade::checkoutSessionLink($guest);
    }
}
