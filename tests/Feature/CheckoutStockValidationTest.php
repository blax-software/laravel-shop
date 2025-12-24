<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;

/**
 * Test stock validation during checkout process.
 * 
 * This test ensures:
 * 1. Stock is validated before checkout session creation
 * 2. Converted carts cannot create new checkout sessions
 * 3. Stock claimed by pending purchases blocks new checkouts
 */
class CheckoutStockValidationTest extends TestCase
{
    protected User $user;
    protected Cart $cart;
    protected Product $pool;
    protected array $singles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        auth()->login($this->user);
    }

    /**
     * Create a pool with managed stock single items
     */
    protected function createPoolWithManagedStock(): void
    {
        // Create pool
        $this->pool = Product::factory()->create([
            'name' => 'Test Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Pool default price
        ProductPrice::factory()->create([
            'purchasable_id' => $this->pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $this->pool->setPoolPricingStrategy('lowest');

        // Create 2 single items with 1 stock each
        $this->singles = [];

        $single1 = Product::factory()->create([
            'name' => 'Single 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single1->increaseStock(1);
        $this->singles[] = $single1;

        $single2 = Product::factory()->create([
            'name' => 'Single 2',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single2->increaseStock(1);
        $this->singles[] = $single2;

        $this->pool->attachSingleItems(array_map(fn($s) => $s->id, $this->singles));
    }

    protected function createCart(): Cart
    {
        return Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);
    }

    #[Test]
    public function validate_for_checkout_checks_stock_availability()
    {
        $this->createPoolWithManagedStock();
        $this->cart = $this->createCart();

        $from = Carbon::tomorrow()->startOfDay();
        $until = Carbon::tomorrow()->addDay()->startOfDay();

        // Add 2 items (all available stock)
        $this->cart->addToCart($this->pool, 2, [], $from, $until);

        // Should pass validation
        $this->assertTrue($this->cart->validateForCheckout(false));

        // Now claim stock for the single items (simulating another completed purchase)
        foreach ($this->singles as $single) {
            $single->claimStock(1, null, $from, $until, 'Test claim');
        }

        // Now validation should fail - stock is claimed by another purchase
        $this->assertFalse($this->cart->fresh()->validateForCheckout(false));
    }

    #[Test]
    public function validate_for_checkout_fails_for_out_of_stock_items()
    {
        $this->createPoolWithManagedStock();
        $this->cart = $this->createCart();

        $from = Carbon::tomorrow()->startOfDay();
        $until = Carbon::tomorrow()->addDay()->startOfDay();

        // Add 2 items
        $this->cart->addToCart($this->pool, 2, [], $from, $until);

        // Manually deplete stock (simulating another purchase)
        foreach ($this->singles as $single) {
            $single->decreaseStock(1);
        }

        // Validation should fail because stock is no longer available
        $this->assertFalse($this->cart->validateForCheckout(false));
    }

    #[Test]
    public function validate_for_checkout_fails_for_converted_cart()
    {
        $this->createPoolWithManagedStock();
        $this->cart = $this->createCart();

        $from = Carbon::tomorrow()->startOfDay();
        $until = Carbon::tomorrow()->addDay()->startOfDay();

        $this->cart->addToCart($this->pool, 1, [], $from, $until);

        // Mark cart as converted
        $this->cart->update(['converted_at' => now()]);

        // Validation should fail for converted cart
        $this->assertFalse($this->cart->fresh()->validateForCheckout(false));
    }

    #[Test]
    public function checkout_session_link_returns_null_for_converted_cart()
    {
        $this->createPoolWithManagedStock();
        $this->cart = $this->createCart();

        $from = Carbon::tomorrow()->startOfDay();
        $until = Carbon::tomorrow()->addDay()->startOfDay();

        $this->cart->addToCart($this->pool, 1, [], $from, $until);

        // Mark cart as converted
        $this->cart->update(['converted_at' => now()]);

        // validateForCheckout should fail for converted cart
        $this->assertFalse($this->cart->fresh()->validateForCheckout(false));
    }

    #[Test]
    public function checkout_session_link_returns_null_for_out_of_stock()
    {
        $this->createPoolWithManagedStock();
        $this->cart = $this->createCart();

        $from = Carbon::tomorrow()->startOfDay();
        $until = Carbon::tomorrow()->addDay()->startOfDay();

        $this->cart->addToCart($this->pool, 2, [], $from, $until);

        // Deplete stock
        foreach ($this->singles as $single) {
            $single->decreaseStock(1);
        }

        // validateForCheckout should fail (stock not available)
        $this->assertFalse($this->cart->fresh()->validateForCheckout(false));
    }

    #[Test]
    public function different_date_ranges_allow_booking_same_items()
    {
        $this->createPoolWithManagedStock();
        $this->cart = $this->createCart();

        // Book for tomorrow
        $from1 = Carbon::tomorrow()->startOfDay();
        $until1 = Carbon::tomorrow()->addDay()->startOfDay();

        $this->cart->addToCart($this->pool, 2, [], $from1, $until1);
        $this->assertTrue($this->cart->validateForCheckout(false));

        // Create another cart for different dates
        $cart2 = $this->createCart();

        // Book for day after tomorrow (non-overlapping)
        $from2 = Carbon::tomorrow()->addDays(2)->startOfDay();
        $until2 = Carbon::tomorrow()->addDays(3)->startOfDay();

        // This should succeed because dates don't overlap
        $cart2->addToCart($this->pool, 2, [], $from2, $until2);
        $this->assertTrue($cart2->validateForCheckout(false));
    }

    #[Test]
    public function overlapping_dates_block_double_booking()
    {
        $this->createPoolWithManagedStock();
        $this->cart = $this->createCart();

        $from = Carbon::tomorrow()->startOfDay();
        $until = Carbon::tomorrow()->addDays(3)->startOfDay(); // 3 days

        // Book all stock for 3 days
        $this->cart->addToCart($this->pool, 2, [], $from, $until);
        $this->assertTrue($this->cart->validateForCheckout(false));

        // Claim stock (simulating completed purchase)
        foreach ($this->singles as $single) {
            $single->claimStock(1, null, $from, $until, 'Test claim');
        }

        // Create another cart
        $cart2 = $this->createCart();

        // Try to book overlapping date range (day 2)
        $from2 = Carbon::tomorrow()->addDay()->startOfDay();
        $until2 = Carbon::tomorrow()->addDays(2)->startOfDay();

        // This should fail because dates overlap and all stock is claimed
        $this->expectException(NotEnoughStockException::class);
        $cart2->addToCart($this->pool, 1, [], $from2, $until2);
    }

    #[Test]
    public function partial_stock_allows_partial_booking()
    {
        $this->createPoolWithManagedStock();
        $this->cart = $this->createCart();

        $from = Carbon::tomorrow()->startOfDay();
        $until = Carbon::tomorrow()->addDay()->startOfDay();

        // Book 1 of 2 available
        $this->cart->addToCart($this->pool, 1, [], $from, $until);
        $this->assertTrue($this->cart->validateForCheckout(false));

        // Claim stock for just 1 single item
        $this->singles[0]->claimStock(1, null, $from, $until, 'Test claim');

        // Create another cart - should be able to book 1 more for same dates
        $cart2 = $this->createCart();
        $cart2->addToCart($this->pool, 1, [], $from, $until);
        $this->assertTrue($cart2->validateForCheckout(false));

        // But not 2 - only 1 remaining
        $cart3 = $this->createCart();
        $this->expectException(NotEnoughStockException::class);
        $cart3->addToCart($this->pool, 2, [], $from, $until);
    }
}
