<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class PoolProductCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Product $hotelRoom;
    protected Product $parkingPool;
    protected Product $parkingSpot1;
    protected Product $parkingSpot2;
    protected Product $parkingSpot3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Create hotel room
        $this->hotelRoom = Product::factory()->create([
            'name' => 'Hotel Room',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $this->hotelRoom->increaseStock(5);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->hotelRoom->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 10000,
            'is_default' => true,
        ]);

        // Create parking pool
        $this->parkingPool = Product::factory()->create([
            'name' => 'Parking Spaces',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $this->parkingPool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 2000,
            'is_default' => true,
        ]);

        // Create parking spots
        $this->parkingSpot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $this->parkingSpot1->increaseStock(1);

        $this->parkingSpot2 = Product::factory()->create([
            'name' => 'Spot 2',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $this->parkingSpot2->increaseStock(1);

        $this->parkingSpot3 = Product::factory()->create([
            'name' => 'Spot 3',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $this->parkingSpot3->increaseStock(1);

        // Link spots to pool
        foreach ([$this->parkingSpot1, $this->parkingSpot2, $this->parkingSpot3] as $spot) {
            $this->parkingPool->productRelations()->attach($spot->id, [
                'type' => ProductRelationType::SINGLE->value,
            ]);
        }
    }

    #[Test]
    public function checkout_cart_with_pool_product_claims_correct_single_items()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cart = $this->user->currentCart();
        $cart->items()->create([
            'purchasable_id' => $this->parkingPool->id,
            'purchasable_type' => Product::class,
            'quantity' => 2,
            'price' => 20.00,
            'from' => $from,
            'until' => $until,
        ]);

        $cart->checkout();

        // Count claimed spots
        $claimedCount = 0;
        foreach ([$this->parkingSpot1, $this->parkingSpot2, $this->parkingSpot3] as $spot) {
            if (!$spot->isAvailableForBooking($from, $until, 1)) {
                $claimedCount++;
            }
        }

        $this->assertEquals(2, $claimedCount);
    }

    #[Test]
    public function checkout_cart_with_pool_product_without_timespan_throws_exception_when_single_items_are_bookings()
    {
        $cart = $this->user->currentCart();

        // Add pool product without timespan
        $cart->items()->create([
            'purchasable_id' => $this->parkingPool->id,
            'purchasable_type' => Product::class,
            'quantity' => 1,
            'price' => 20.00,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('is missing required information: from, until');

        $cart->checkout();
    }

    #[Test]
    public function checkout_cart_with_pool_product_and_timespan_succeeds()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cart = $this->user->currentCart();
        $cart->items()->create([
            'purchasable_id' => $this->parkingPool->id,
            'purchasable_type' => Product::class,
            'quantity' => 2,
            'price' => 20.00,
            'from' => $from,
            'until' => $until,
        ]);

        $cart->checkout();

        $this->assertTrue($cart->isConverted());
        $this->assertCount(1, $cart->purchases);
    }

    #[Test]
    public function checkout_cart_with_pool_product_stores_claimed_items_in_cart_item_meta()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cart = $this->user->currentCart();
        $cartItem = $cart->items()->create([
            'purchasable_id' => $this->parkingPool->id,
            'purchasable_type' => Product::class,
            'quantity' => 2,
            'price' => 20.00,
            'from' => $from,
            'until' => $until,
        ]);

        $cart->checkout();

        $cartItem->refresh();
        $meta = $cartItem->getMeta();
        $claimedItems = $meta->claimed_single_items ?? null;

        $this->assertNotNull($claimedItems);
        $this->assertIsArray($claimedItems);
        $this->assertCount(2, $claimedItems);

        // Verify claimed items are valid product IDs
        foreach ($claimedItems as $itemId) {
            $this->assertNotNull(Product::find($itemId));
        }
    }

    #[Test]
    public function checkout_cart_with_multiple_pool_products_claims_from_each_independently()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        // Create second pool
        $bikePool = Product::factory()->create([
            'name' => 'Bike Rentals',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $bikePool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1500,
            'is_default' => true,
        ]);

        $bike1 = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $bike1->increaseStock(1);

        $bike2 = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $bike2->increaseStock(1);

        $bikePool->productRelations()->attach($bike1->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);
        $bikePool->productRelations()->attach($bike2->id, [
            'type' => ProductRelationType::SINGLE->value,
        ]);

        $cart = $this->user->currentCart();

        // Add parking
        $cart->items()->create([
            'purchasable_id' => $this->parkingPool->id,
            'purchasable_type' => Product::class,
            'quantity' => 2,
            'price' => 20.00,
            'from' => $from,
            'until' => $until,
        ]);

        // Add bikes
        $cart->items()->create([
            'purchasable_id' => $bikePool->id,
            'purchasable_type' => Product::class,
            'quantity' => 1,
            'price' => 15.00,
            'from' => $from,
            'until' => $until,
        ]);

        $cart->checkout();

        // Verify parking claims
        $this->assertEquals(1, $this->parkingPool->getPoolMaxQuantity($from, $until));

        // Verify bike claims
        $this->assertEquals(1, $bikePool->getPoolMaxQuantity($from, $until));
    }

    #[Test]
    public function checkout_cart_with_pool_product_and_regular_booking_product_succeeds()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cart = $this->user->currentCart();

        // Add hotel room
        $cart->items()->create([
            'purchasable_id' => $this->hotelRoom->id,
            'purchasable_type' => Product::class,
            'quantity' => 1,
            'price' => 100.00,
            'from' => $from,
            'until' => $until,
        ]);

        // Add parking
        $cart->items()->create([
            'purchasable_id' => $this->parkingPool->id,
            'purchasable_type' => Product::class,
            'quantity' => 1,
            'price' => 20.00,
            'from' => $from,
            'until' => $until,
        ]);

        $cart->checkout();

        $this->assertTrue($cart->isConverted());
        $this->assertCount(2, $cart->purchases);
    }

    #[Test]
    public function checkout_cart_with_pool_product_fails_when_single_item_becomes_unavailable_during_checkout()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cart = $this->user->currentCart();

        // Add 3 parking spots (all available)
        $cart->items()->create([
            'purchasable_id' => $this->parkingPool->id,
            'purchasable_type' => Product::class,
            'quantity' => 3,
            'price' => 20.00,
            'from' => $from,
            'until' => $until,
        ]);

        // Simulate another user booking spots before checkout
        $this->parkingSpot1->claimStock(1, null, $from, $until);
        $this->parkingSpot2->claimStock(1, null, $from, $until);

        // validateForCheckout will now catch this before checkout even starts
        $this->expectException(\Blax\Shop\Exceptions\NotEnoughStockException::class);
        $this->expectExceptionMessage('has only 1 items available');

        $cart->checkout();
    }

    #[Test]
    public function checkout_cart_validates_timespan_before_claiming_stock()
    {
        $cart = $this->user->currentCart();

        // Add pool product without timespan
        $cart->items()->create([
            'purchasable_id' => $this->parkingPool->id,
            'purchasable_type' => Product::class,
            'quantity' => 1,
            'price' => 20.00,
            // No from/until
        ]);

        $this->expectException(\Exception::class);

        $cart->checkout();

        // Verify no stock was claimed if validation failed
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);
        $this->assertEquals(3, $this->parkingPool->getPoolMaxQuantity($from, $until));
    }

    #[Test]
    public function checkout_creates_purchase_with_correct_timespan()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cart = $this->user->currentCart();
        $cart->items()->create([
            'purchasable_id' => $this->parkingPool->id,
            'purchasable_type' => Product::class,
            'quantity' => 1,
            'price' => 20.00,
            'from' => $from,
            'until' => $until,
        ]);

        $cart->checkout();

        $purchase = ProductPurchase::where('cart_id', $cart->id)->first();

        $this->assertNotNull($purchase);
        $this->assertEquals($from->format('Y-m-d H:i:s'), $purchase->from->format('Y-m-d H:i:s'));
        $this->assertEquals($until->format('Y-m-d H:i:s'), $purchase->until->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function checkout_with_pool_product_using_legacy_parameters()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cart = $this->user->currentCart();

        // Use legacy parameters instead of from/until fields
        $cart->items()->create([
            'purchasable_id' => $this->parkingPool->id,
            'purchasable_type' => Product::class,
            'quantity' => 1,
            'price' => 20.00,
            'from' => $from,
            'until' => $until,
            'parameters' => [
                'from' => $from->toDateTimeString(),
                'until' => $until->toDateTimeString(),
            ],
        ]);

        $cart->checkout();

        $this->assertTrue($cart->isConverted());
    }

    #[Test]
    public function checkout_pool_product_claims_stock_with_cart_reference()
    {
        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $cart = $this->user->currentCart();
        $cart->items()->create([
            'purchasable_id' => $this->parkingPool->id,
            'purchasable_type' => Product::class,
            'quantity' => 2,
            'price' => 20.00,
            'from' => $from,
            'until' => $until,
        ]);

        $cart->checkout();

        // Verify claims have cart as reference
        $spot1Claim = $this->parkingSpot1->stocks()
            ->where('reference_type', get_class($cart))
            ->where('reference_id', $cart->id)
            ->first();

        // At least one spot should have the cart as reference
        $this->assertTrue(
            $spot1Claim !== null ||
                $this->parkingSpot2->stocks()->where('reference_type', get_class($cart))->exists() ||
                $this->parkingSpot3->stocks()->where('reference_type', get_class($cart))->exists()
        );
    }
}
