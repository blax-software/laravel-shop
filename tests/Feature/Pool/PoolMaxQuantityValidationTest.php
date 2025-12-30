<?php

namespace Blax\Shop\Tests\Feature\Pool;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests to ensure pool products cannot exceed available single items
 * even when adding without dates (flexible cart behavior)
 */
class PoolMaxQuantityValidationTest extends TestCase
{
    protected User $user;
    protected Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        auth()->login($this->user);
        $this->cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);
    }

    /**
     * Create a pool with 7 single items (production scenario)
     */
    protected function createPoolWith7Singles(): Product
    {
        $pool = Product::factory()->create([
            'name' => 'Production Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $pool->setPoolPricingStrategy('lowest');

        // Create 7 single items, each with 1 stock
        for ($i = 1; $i <= 7; $i++) {
            $single = Product::factory()->create([
                'name' => "Single {$i}",
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);
            $single->increaseStock(1);

            ProductPrice::factory()->create([
                'purchasable_id' => $single->id,
                'purchasable_type' => Product::class,
                'unit_amount' => 5000,
                'currency' => 'USD',
                'is_default' => true,
            ]);

            $pool->attachSingleItems([$single->id]);
        }

        return $pool;
    }

    #[Test]
    public function cannot_add_more_items_than_available_singles_without_dates()
    {
        $pool = $this->createPoolWith7Singles();

        // Pool has 7 single items, each with 1 stock
        $this->assertEquals(7, $pool->getPoolMaxQuantity());

        // Should be able to add 7 items
        $this->cart->addToCart($pool, 7);
        $this->assertEquals(7, $this->cart->fresh()->items->sum('quantity'));

        // Should NOT be able to add 8th item - should throw exception
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $this->expectExceptionMessage('has only 0 items available');

        $this->cart->addToCart($pool, 1);
    }

    #[Test]
    public function cannot_add_more_items_than_available_singles_with_dates()
    {
        $pool = $this->createPoolWith7Singles();

        $from = now()->addDays(1);
        $until = now()->addDays(2);

        // Pool has 7 single items, each with 1 stock
        $this->assertEquals(7, $pool->getPoolMaxQuantity($from, $until));

        // Should be able to add 7 items with dates
        $this->cart->addToCart($pool, 7, [], $from, $until);
        $this->assertEquals(7, $this->cart->fresh()->items->sum('quantity'));

        // Should NOT be able to add 8th item - should throw exception
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $this->expectExceptionMessage('has only 0 items available');

        $this->cart->addToCart($pool, 1, [], $from, $until);
    }

    #[Test]
    public function cannot_add_batch_exceeding_available_singles_without_dates()
    {
        $pool = $this->createPoolWith7Singles();

        // Trying to add 8 items at once should fail
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $this->expectExceptionMessage('has only 7 items available');

        $this->cart->addToCart($pool, 8);
    }

    #[Test]
    public function cannot_add_batch_exceeding_available_singles_with_dates()
    {
        $pool = $this->createPoolWith7Singles();

        $from = now()->addDays(1);
        $until = now()->addDays(2);

        // Trying to add 8 items at once should fail
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $this->expectExceptionMessage('has only 7 items available');

        $this->cart->addToCart($pool, 8, [], $from, $until);
    }

    #[Test]
    public function adding_items_without_dates_then_adding_more_validates_correctly()
    {
        $pool = $this->createPoolWith7Singles();

        // Add 5 items without dates
        $this->cart->addToCart($pool, 5);
        $this->assertEquals(5, $this->cart->fresh()->items->sum('quantity'));

        // Should be able to add 2 more (total 7)
        $this->cart->addToCart($pool, 2);
        $this->assertEquals(7, $this->cart->fresh()->items->sum('quantity'));

        // Should NOT be able to add 1 more
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $this->cart->addToCart($pool, 1);
    }

    #[Test]
    public function checkoutSessionLink_throws_exception_when_cart_invalid()
    {
        $pool = $this->createPoolWith7Singles();

        // Add items without dates - cart is not ready for checkout
        $this->cart->addToCart($pool, 3);

        // checkoutSessionLink should throw exception, not return null
        // When items don't have dates, validation throws CartItemMissingInformationException
        $this->expectException(\Blax\Shop\Exceptions\CartItemMissingInformationException::class);
        $this->expectExceptionMessage('is missing required information: from, until');

        $this->cart->checkoutSessionLink();
    }

    #[Test]
    public function checkoutSessionLink_throws_exception_when_not_enough_stock()
    {
        $pool = $this->createPoolWith7Singles();

        $from = now()->addDays(1);
        $until = now()->addDays(2);

        // Add 7 items with dates
        $this->cart->addToCart($pool, 7, [], $from, $until);

        // Simulate another cart claiming all stock for the same period
        $otherCart = Cart::factory()->create([
            'customer_id' => User::factory()->create()->id,
            'customer_type' => User::class,
        ]);
        $otherCart->addToCart($pool, 7, [], $from, $until);
        $otherCart->checkout(); // This claims the stock

        // Our cart should now fail validation when trying to create checkout session
        // The validation throws NotEnoughStockException when checking availability
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $this->expectExceptionMessage('has only 0 items available');

        $this->cart->fresh()->checkoutSessionLink();
    }

    #[Test]
    public function cart_aware_validation_accounts_for_items_already_in_cart()
    {
        $pool = $this->createPoolWith7Singles();

        $from = now()->addDays(1);
        $until = now()->addDays(2);

        // Add 5 items to cart
        $this->cart->addToCart($pool, 5, [], $from, $until);

        // Pool has 7 total, 5 in cart, so 2 available for this request
        $this->assertEquals(7, $pool->getPoolMaxQuantity($from, $until));

        // Should be able to add 2 more
        $this->cart->addToCart($pool, 2, [], $from, $until);
        $this->assertEquals(7, $this->cart->fresh()->items->sum('quantity'));

        // Should NOT be able to add 1 more
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $this->expectExceptionMessage('has only 0 items available');

        $this->cart->addToCart($pool, 1, [], $from, $until);
    }

    #[Test]
    public function validation_message_shows_correct_remaining_availability()
    {
        $pool = $this->createPoolWith7Singles();

        // Add 5 items without dates
        $this->cart->addToCart($pool, 5);

        try {
            // Try to add 5 more (total would be 10, but max is 7)
            // Should fail saying only 2 available
            $this->cart->addToCart($pool, 5);
            $this->fail('Should have thrown NotEnoughStockException');
        } catch (\Blax\Shop\Exceptions\NotEnoughStockException $e) {
            $this->assertStringContainsString('has only 2 items available', $e->getMessage());
            $this->assertStringContainsString('Requested: 5', $e->getMessage());
        }
    }
}
