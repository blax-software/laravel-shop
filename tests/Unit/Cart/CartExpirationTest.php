<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Enums\CartStatus;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

class CartExpirationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function cart_sets_last_activity_at_on_creation()
    {
        $cart = Cart::factory()->create();

        $this->assertNotNull($cart->last_activity_at);
    }

    #[Test]
    public function adding_to_cart_updates_last_activity_at()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create(['manage_stock' => false]);

        // Create cart with old activity timestamp
        $cart = Cart::factory()->create([
            'customer_id' => $user->id,
            'customer_type' => get_class($user),
            'last_activity_at' => now()->subHours(2),
        ]);

        $oldActivityAt = $cart->last_activity_at;

        // Add item to cart
        $cart->addToCart($product);

        $this->assertTrue($cart->fresh()->last_activity_at->gt($oldActivityAt));
    }

    #[Test]
    public function removing_from_cart_updates_last_activity_at()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create(['manage_stock' => false]);

        $cart = Cart::factory()->create([
            'customer_id' => $user->id,
            'customer_type' => get_class($user),
        ]);

        $cart->addToCart($product);

        // Set old activity timestamp
        $cart->update(['last_activity_at' => now()->subHours(2)]);
        $oldActivityAt = $cart->fresh()->last_activity_at;

        // Remove item from cart
        $cart->removeFromCart($product);

        $this->assertTrue($cart->fresh()->last_activity_at->gt($oldActivityAt));
    }

    #[Test]
    public function touch_activity_updates_timestamp()
    {
        $cart = Cart::factory()->create([
            'last_activity_at' => now()->subHours(2),
        ]);

        $oldActivityAt = $cart->last_activity_at;

        $cart->touchActivity();

        $this->assertTrue($cart->last_activity_at->gt($oldActivityAt));
    }

    #[Test]
    public function cart_is_expired_after_configured_time()
    {
        config(['shop.cart.expiration_minutes' => 60]);

        $cart = Cart::factory()->create([
            'status' => CartStatus::ACTIVE,
            'last_activity_at' => now()->subMinutes(61),
        ]);

        $this->assertTrue($cart->isExpired());
    }

    #[Test]
    public function cart_is_not_expired_within_configured_time()
    {
        config(['shop.cart.expiration_minutes' => 60]);

        $cart = Cart::factory()->create([
            'status' => CartStatus::ACTIVE,
            'last_activity_at' => now()->subMinutes(30),
        ]);

        $this->assertFalse($cart->isExpired());
    }

    #[Test]
    public function cart_with_expired_status_is_expired()
    {
        $cart = Cart::factory()->create([
            'status' => CartStatus::EXPIRED,
            'last_activity_at' => now(), // Recent activity doesn't matter
        ]);

        $this->assertTrue($cart->isExpired());
    }

    #[Test]
    public function cart_should_be_deleted_after_configured_time()
    {
        config(['shop.cart.deletion_hours' => 24]);

        $cart = Cart::factory()->create([
            'status' => CartStatus::ABANDONED,
            'last_activity_at' => now()->subHours(25),
            'converted_at' => null,
        ]);

        $this->assertTrue($cart->shouldBeDeleted());
    }

    #[Test]
    public function cart_should_not_be_deleted_within_configured_time()
    {
        config(['shop.cart.deletion_hours' => 24]);

        $cart = Cart::factory()->create([
            'status' => CartStatus::ABANDONED,
            'last_activity_at' => now()->subHours(12),
            'converted_at' => null,
        ]);

        $this->assertFalse($cart->shouldBeDeleted());
    }

    #[Test]
    public function converted_cart_should_never_be_deleted()
    {
        config(['shop.cart.deletion_hours' => 24]);

        $cart = Cart::factory()->create([
            'status' => CartStatus::CONVERTED,
            'last_activity_at' => now()->subDays(30),
            'converted_at' => now()->subDays(30),
        ]);

        $this->assertFalse($cart->shouldBeDeleted());
    }

    #[Test]
    public function mark_as_expired_changes_status()
    {
        $cart = Cart::factory()->create([
            'status' => CartStatus::ACTIVE,
        ]);

        $cart->markAsExpired();

        $this->assertEquals(CartStatus::EXPIRED, $cart->status);
    }

    #[Test]
    public function mark_as_abandoned_changes_status()
    {
        $cart = Cart::factory()->create([
            'status' => CartStatus::ACTIVE,
        ]);

        $cart->markAsAbandoned();

        $this->assertEquals(CartStatus::ABANDONED, $cart->status);
    }

    #[Test]
    public function scope_should_expire_returns_correct_carts()
    {
        config(['shop.cart.expiration_minutes' => 60]);

        // Should expire - old activity
        $expiredCart = Cart::factory()->create([
            'status' => CartStatus::ACTIVE,
            'last_activity_at' => now()->subHours(2),
        ]);

        // Should not expire - recent activity
        $activeCart = Cart::factory()->create([
            'status' => CartStatus::ACTIVE,
            'last_activity_at' => now()->subMinutes(30),
        ]);

        // Should not expire - already expired
        $alreadyExpiredCart = Cart::factory()->create([
            'status' => CartStatus::EXPIRED,
            'last_activity_at' => now()->subHours(2),
        ]);

        $cartsToExpire = Cart::shouldExpire()->get();

        $this->assertCount(1, $cartsToExpire);
        $this->assertEquals($expiredCart->id, $cartsToExpire->first()->id);
    }

    #[Test]
    public function scope_should_delete_returns_correct_carts()
    {
        config(['shop.cart.deletion_hours' => 24]);

        // Should delete - old and not converted
        $oldCart = Cart::factory()->create([
            'status' => CartStatus::ABANDONED,
            'last_activity_at' => now()->subDays(2),
            'converted_at' => null,
        ]);

        // Should not delete - recent
        $recentCart = Cart::factory()->create([
            'status' => CartStatus::ABANDONED,
            'last_activity_at' => now()->subHours(12),
            'converted_at' => null,
        ]);

        // Should not delete - converted
        $convertedCart = Cart::factory()->create([
            'status' => CartStatus::CONVERTED,
            'last_activity_at' => now()->subDays(2),
            'converted_at' => now()->subDays(2),
        ]);

        $cartsToDelete = Cart::shouldDelete()->get();

        $this->assertCount(1, $cartsToDelete);
        $this->assertEquals($oldCart->id, $cartsToDelete->first()->id);
    }

    #[Test]
    public function carts_can_check_if_converted()
    {
        $unconvertedCart = Cart::factory()->create([
            'status' => CartStatus::ACTIVE,
            'converted_at' => null,
            'expires_at' => null,
        ]);
        $convertedCart = Cart::factory()->create([
            'status' => CartStatus::CONVERTED,
            'converted_at' => now(),
        ]);

        $this->assertFalse($unconvertedCart->isConverted());
        $this->assertTrue($convertedCart->isConverted());
    }

    #[Test]
    public function scope_expired_returns_carts_with_expired_status()
    {
        Cart::factory()->create(['status' => CartStatus::ACTIVE]);
        Cart::factory()->create(['status' => CartStatus::EXPIRED]);

        $expiredCarts = Cart::withExpiredStatus()->get();

        $this->assertCount(1, $expiredCarts);
    }

    #[Test]
    public function scope_abandoned_returns_inactive_carts()
    {
        config(['shop.cart.expiration_minutes' => 60]);

        // Should be considered abandoned - active but old
        Cart::factory()->create([
            'status' => CartStatus::ACTIVE,
            'last_activity_at' => now()->subHours(2),
        ]);

        // Should not be considered abandoned - recent activity
        Cart::factory()->create([
            'status' => CartStatus::ACTIVE,
            'last_activity_at' => now()->subMinutes(30),
        ]);

        $abandonedCarts = Cart::abandoned(60)->get();

        $this->assertCount(1, $abandonedCarts);
    }

    #[Test]
    public function is_converted_method_returns_true_for_converted_carts()
    {
        $convertedCart = Cart::factory()->create(['converted_at' => now()]);
        $unconvertedCart = Cart::factory()->create(['converted_at' => null]);

        $this->assertTrue($convertedCart->isConverted());
        $this->assertFalse($unconvertedCart->isConverted());
    }
}
