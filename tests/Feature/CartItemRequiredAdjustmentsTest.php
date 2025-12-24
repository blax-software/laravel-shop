<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class CartItemRequiredAdjustmentsTest extends TestCase
{
    protected User $user;
    protected Cart $cart;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->cart = Cart::factory()->create([
            'customer_id' => $this->user->id,
            'customer_type' => get_class($this->user),
        ]);
    }

    #[Test]
    public function it_returns_empty_array_for_simple_product()
    {
        $product = Product::factory()->create([
            'type' => ProductType::SIMPLE,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000, // 50.00
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $cartItem = $this->cart->addToCart($product, 1);

        $adjustments = $cartItem->requiredAdjustments();

        $this->assertIsArray($adjustments);
        $this->assertEmpty($adjustments);
    }

    #[Test]
    public function it_returns_from_and_until_for_booking_product_without_dates()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $cartItem = $this->cart->addToCart($product, 1);

        $adjustments = $cartItem->requiredAdjustments();

        $this->assertEquals([
            'from' => 'datetime',
            'until' => 'datetime',
        ], $adjustments);
    }

    #[Test]
    public function it_returns_only_until_for_booking_product_with_from_date()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1);

        $cartItem = $this->cart->addToCart($product, 1);
        $cartItem->update(['from' => $from]);

        $adjustments = $cartItem->requiredAdjustments();

        $this->assertEquals([
            'until' => 'datetime',
        ], $adjustments);
    }

    #[Test]
    public function it_returns_only_from_for_booking_product_with_until_date()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $until = Carbon::now()->addDays(5);

        $cartItem = $this->cart->addToCart($product, 1);
        $cartItem->update(['until' => $until]);

        $adjustments = $cartItem->requiredAdjustments();

        $this->assertEquals([
            'from' => 'datetime',
        ], $adjustments);
    }

    #[Test]
    public function it_returns_empty_array_for_booking_product_with_both_dates()
    {
        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);

        $product->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(5);

        $cartItem = $this->cart->addToCart($product, 1, [], $from, $until);

        $adjustments = $cartItem->requiredAdjustments();

        $this->assertEmpty($adjustments);
    }

    #[Test]
    public function it_returns_dates_for_pool_with_booking_single_items_without_dates()
    {
        // Create pool product
        $poolProduct = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
        ]);

        // Create booking single items
        $singleItem1 = Product::factory()->create([
            'name' => 'Parking Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $singleItem1->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $singleItem2 = Product::factory()->create([
            'name' => 'Parking Spot 2',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $singleItem2->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Attach single items to pool
        $poolProduct->productRelations()->attach($singleItem1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
        $poolProduct->productRelations()->attach($singleItem2->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        $cartItem = $this->cart->addToCart($poolProduct, 1);

        $adjustments = $cartItem->requiredAdjustments();

        $this->assertEquals([
            'from' => 'datetime',
            'until' => 'datetime',
        ], $adjustments);
    }

    #[Test]
    public function it_returns_empty_array_for_pool_with_booking_items_with_dates()
    {
        // Create pool product
        $poolProduct = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
        ]);

        // Create booking single items
        $singleItem = Product::factory()->create([
            'name' => 'Parking Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);

        $singleItem->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $singleItem->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Attach single item to pool
        $poolProduct->productRelations()->attach($singleItem->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(5);

        $cartItem = $this->cart->addToCart($poolProduct, 1, [], $from, $until);

        $adjustments = $cartItem->requiredAdjustments();

        $this->assertEmpty($adjustments);
    }

    #[Test]
    public function it_returns_empty_array_for_pool_with_simple_single_items()
    {
        // Create pool product
        $poolProduct = Product::factory()->create([
            'name' => 'Product Bundle',
            'type' => ProductType::POOL,
        ]);

        // Create simple single items
        $singleItem1 = Product::factory()->create([
            'name' => 'Item 1',
            'type' => ProductType::SIMPLE,
            'manage_stock' => true,
        ]);
        $singleItem1->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $singleItem1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $singleItem2 = Product::factory()->create([
            'name' => 'Item 2',
            'type' => ProductType::SIMPLE,
            'manage_stock' => true,
        ]);
        $singleItem2->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $singleItem2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Attach single items to pool
        $poolProduct->productRelations()->attach($singleItem1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
        $poolProduct->productRelations()->attach($singleItem2->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        $cartItem = $this->cart->addToCart($poolProduct, 1);

        $adjustments = $cartItem->requiredAdjustments();

        $this->assertEmpty($adjustments);
    }

    #[Test]
    public function it_returns_dates_for_pool_with_mixed_single_items_containing_bookings()
    {
        // Create pool product
        $poolProduct = Product::factory()->create([
            'name' => 'Mixed Pool',
            'type' => ProductType::POOL,
        ]);

        // Create simple single item
        $simpleItem = Product::factory()->create([
            'name' => 'Simple Item',
            'type' => ProductType::SIMPLE,
            'manage_stock' => true,
        ]);
        $simpleItem->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $simpleItem->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Create booking single item
        $bookingItem = Product::factory()->create([
            'name' => 'Booking Item',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $bookingItem->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $bookingItem->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Attach single items to pool
        $poolProduct->productRelations()->attach($simpleItem->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
        $poolProduct->productRelations()->attach($bookingItem->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        $cartItem = $this->cart->addToCart($poolProduct, 1);

        $adjustments = $cartItem->requiredAdjustments();

        // Even though it has simple items, the booking item requires dates
        $this->assertEquals([
            'from' => 'datetime',
            'until' => 'datetime',
        ], $adjustments);
    }

    #[Test]
    public function it_returns_empty_array_for_non_product_purchasable()
    {
        // Create a cart item with a non-product purchasable
        $cartItem = new CartItem([
            'cart_id' => $this->cart->id,
            'purchasable_id' => 1,
            'purchasable_type' => 'App\\Models\\Subscription', // Not a product
            'quantity' => 1,
            'price' => 1000,
        ]);
        $cartItem->save();

        $adjustments = $cartItem->requiredAdjustments();

        $this->assertEmpty($adjustments);
    }

    #[Test]
    public function it_handles_null_purchasable_gracefully()
    {
        // Create a cart item with invalid purchasable_id
        $cartItem = new CartItem([
            'cart_id' => $this->cart->id,
            'purchasable_id' => 99999,
            'purchasable_type' => config('shop.models.product', Product::class),
            'quantity' => 1,
            'price' => 1000,
        ]);
        $cartItem->save();

        $adjustments = $cartItem->requiredAdjustments();

        $this->assertEmpty($adjustments);
    }

    #[Test]
    public function it_can_validate_entire_cart_before_checkout()
    {
        // Create mixed cart with booking and simple products
        $simpleProduct = Product::factory()->create([
            'type' => ProductType::SIMPLE,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $simpleProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $bookingProduct = Product::factory()->create([
            'type' => ProductType::BOOKING,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $bookingProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        // Add both to cart
        $this->cart->addToCart($simpleProduct, 1);
        $this->cart->addToCart($bookingProduct, 1);

        // Check which items need adjustments
        $itemsNeedingAdjustments = $this->cart->items->filter(function ($item) {
            return !empty($item->requiredAdjustments());
        });

        // Only the booking product should need adjustments
        $this->assertCount(1, $itemsNeedingAdjustments);
        $this->assertEquals($bookingProduct->id, $itemsNeedingAdjustments->first()->purchasable_id);
    }

    #[Test]
    public function cart_can_get_items_requiring_adjustments()
    {
        $simpleProduct = Product::factory()->create([
            'type' => ProductType::SIMPLE,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $simpleProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $bookingProduct = Product::factory()->create([
            'type' => ProductType::BOOKING,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $bookingProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $this->cart->addToCart($simpleProduct, 1);
        $this->cart->addToCart($bookingProduct, 1);

        $incompleteItems = $this->cart->getItemsRequiringAdjustments();

        $this->assertCount(1, $incompleteItems);
        $this->assertEquals($bookingProduct->id, $incompleteItems->first()->purchasable_id);
    }

    #[Test]
    public function cart_is_not_ready_for_checkout_when_items_need_adjustments()
    {
        $bookingProduct = Product::factory()->create([
            'type' => ProductType::BOOKING,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $bookingProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $this->cart->addToCart($bookingProduct, 1);

        $this->assertFalse($this->cart->isReadyForCheckout());
    }

    #[Test]
    public function cart_is_ready_for_checkout_when_all_items_complete()
    {
        $simpleProduct = Product::factory()->create([
            'type' => ProductType::SIMPLE,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $simpleProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $bookingProduct = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);

        $bookingProduct->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $bookingProduct->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(5);

        $this->cart->addToCart($simpleProduct, 1);
        $this->cart->addToCart($bookingProduct, 1, [], $from, $until);

        $this->assertTrue($this->cart->isReadyForCheckout());
    }
}
