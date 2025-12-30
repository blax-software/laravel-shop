<?php

namespace Blax\Shop\Tests\Feature\Pool;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class PoolBookingDetectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    #[Test]
    public function pool_product_with_booking_items_is_detected_as_booking()
    {
        // Create pool
        $pool = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create booking single items
        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        $spot2 = Product::factory()->create([
            'name' => 'Spot 2',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot2->increaseStock(1);

        // Link booking items to pool
        $pool->productRelations()->attach($spot1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
        $pool->productRelations()->attach($spot2->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        // Test: Pool should be detected as booking
        $this->assertTrue($pool->isBooking(), 'Pool with booking items should be detected as booking');
        $this->assertTrue($pool->hasBookingSingleItems(), 'Pool should have booking single items');
    }

    #[Test]
    public function pool_product_without_booking_items_is_not_booking()
    {
        // Create pool
        $pool = Product::factory()->create([
            'name' => 'Simple Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create simple (non-booking) single items
        $item1 = Product::factory()->create([
            'name' => 'Item 1',
            'type' => ProductType::SIMPLE,
            'manage_stock' => true,
        ]);
        $item1->increaseStock(10);

        $item2 = Product::factory()->create([
            'name' => 'Item 2',
            'type' => ProductType::SIMPLE,
            'manage_stock' => true,
        ]);
        $item2->increaseStock(10);

        // Link simple items to pool
        $pool->productRelations()->attach($item1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
        $pool->productRelations()->attach($item2->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        // Test: Pool should NOT be detected as booking
        $this->assertFalse($pool->isBooking(), 'Pool without booking items should not be detected as booking');
        $this->assertFalse($pool->hasBookingSingleItems(), 'Pool should not have booking single items');
    }

    #[Test]
    public function pool_cart_item_with_booking_items_is_detected_as_booking()
    {
        // Create pool
        $pool = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create price for pool
        $poolPrice = ProductPrice::factory()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Create booking single items
        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        // Link booking items to pool
        $pool->productRelations()->attach($spot1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        // Create cart and add pool product
        $cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        $cartItem = $cart->items()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'price_id' => $poolPrice->id,
            'quantity' => 1,
            'price' => 2000,
            'regular_price' => 2000,
            'unit_amount' => 2000,
        ]);

        // Test: Cart item should be detected as booking
        $this->assertTrue($cartItem->is_booking, 'Cart item for pool with booking items should be detected as booking');
    }

    #[Test]
    public function pool_cart_item_without_booking_items_is_not_booking()
    {
        // Create pool
        $pool = Product::factory()->create([
            'name' => 'Simple Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create price for pool
        $poolPrice = ProductPrice::factory()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Create simple (non-booking) single items
        $item1 = Product::factory()->create([
            'name' => 'Item 1',
            'type' => ProductType::SIMPLE,
            'manage_stock' => true,
        ]);
        $item1->increaseStock(10);

        // Link simple items to pool
        $pool->productRelations()->attach($item1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        // Create cart and add pool product
        $cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        $cartItem = $cart->items()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'price_id' => $poolPrice->id,
            'quantity' => 1,
            'price' => 1000,
            'regular_price' => 1000,
            'unit_amount' => 1000,
        ]);

        // Test: Cart item should NOT be detected as booking
        $this->assertFalse($cartItem->is_booking, 'Cart item for pool without booking items should not be detected as booking');
    }

    #[Test]
    public function cart_with_pool_booking_items_detects_booking_correctly()
    {
        // Create pool with booking items
        $pool = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $poolPrice = ProductPrice::factory()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        $pool->productRelations()->attach($spot1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        // Create cart and add pool product
        $cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        $cart->items()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'price_id' => $poolPrice->id,
            'quantity' => 1,
            'price' => 2000,
            'regular_price' => 2000,
            'unit_amount' => 2000,
        ]);

        // Test: Cart should be detected as full booking
        $this->assertTrue($cart->is_full_booking, 'Cart with pool booking items should be detected as full booking');
        $this->assertEquals(1, $cart->bookingItems(), 'Cart should have 1 booking item');
    }

    #[Test]
    public function mixed_cart_with_pool_and_regular_items_not_full_booking()
    {
        // Create pool with booking items
        $pool = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $poolPrice = ProductPrice::factory()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        $pool->productRelations()->attach($spot1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        // Create regular product
        $regularProduct = Product::factory()->create([
            'name' => 'Regular Product',
            'type' => ProductType::SIMPLE,
        ]);

        $regularPrice = ProductPrice::factory()->create([
            'purchasable_id' => $regularProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Create cart and add both products
        $cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        $cart->items()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'price_id' => $poolPrice->id,
            'quantity' => 1,
            'price' => 2000,
            'regular_price' => 2000,
            'unit_amount' => 2000,
        ]);

        $cart->items()->create([
            'purchasable_id' => $regularProduct->id,
            'purchasable_type' => Product::class,
            'price_id' => $regularPrice->id,
            'quantity' => 1,
            'price' => 1000,
            'regular_price' => 1000,
            'unit_amount' => 1000,
        ]);

        // Test: Cart should NOT be full booking
        $this->assertFalse($cart->is_full_booking, 'Cart with mixed products should not be full booking');
        $this->assertEquals(1, $cart->bookingItems(), 'Cart should have 1 booking item');
    }

    #[Test]
    public function cart_item_isBooking_method_works()
    {
        // Create pool with booking items
        $pool = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $poolPrice = ProductPrice::factory()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        $pool->productRelations()->attach($spot1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        // Create cart and add pool product
        $cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);

        $cartItem = $cart->items()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'price_id' => $poolPrice->id,
            'quantity' => 1,
            'price' => 2000,
            'regular_price' => 2000,
            'unit_amount' => 2000,
        ]);

        // Test: isBooking() method should work
        $this->assertTrue($cartItem->isBooking(), 'CartItem isBooking() method should return true for booking items');
        $this->assertEquals($cartItem->is_booking, $cartItem->isBooking(), 'isBooking() should match is_booking attribute');
    }

    #[Test]
    public function cart_isBooking_method_works()
    {
        // Create pool with booking items
        $pool = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $poolPrice = ProductPrice::factory()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        $pool->productRelations()->attach($spot1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        // Create regular product
        $regularProduct = Product::factory()->create([
            'name' => 'Regular Product',
            'type' => ProductType::SIMPLE,
        ]);

        $regularPrice = ProductPrice::factory()->create([
            'purchasable_id' => $regularProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Test with empty cart
        $emptyCart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);
        $this->assertFalse($emptyCart->isBooking(), 'Empty cart should return false for isBooking()');

        // Test with booking item
        $bookingCart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);
        $bookingCart->items()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'price_id' => $poolPrice->id,
            'quantity' => 1,
            'price' => 2000,
            'regular_price' => 2000,
            'unit_amount' => 2000,
        ]);
        $this->assertTrue($bookingCart->isBooking(), 'Cart with booking items should return true for isBooking()');

        // Test with mixed cart
        $mixedCart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);
        $mixedCart->items()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'price_id' => $poolPrice->id,
            'quantity' => 1,
            'price' => 2000,
            'regular_price' => 2000,
            'unit_amount' => 2000,
        ]);
        $mixedCart->items()->create([
            'purchasable_id' => $regularProduct->id,
            'purchasable_type' => Product::class,
            'price_id' => $regularPrice->id,
            'quantity' => 1,
            'price' => 1000,
            'regular_price' => 1000,
            'unit_amount' => 1000,
        ]);
        $this->assertTrue($mixedCart->isBooking(), 'Cart with mixed items should return true for isBooking() if it contains at least one booking');

        // Test with only regular product
        $regularCart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);
        $regularCart->items()->create([
            'purchasable_id' => $regularProduct->id,
            'purchasable_type' => Product::class,
            'price_id' => $regularPrice->id,
            'quantity' => 1,
            'price' => 1000,
            'regular_price' => 1000,
            'unit_amount' => 1000,
        ]);
        $this->assertFalse($regularCart->isBooking(), 'Cart with only regular items should return false for isBooking()');
    }
}
