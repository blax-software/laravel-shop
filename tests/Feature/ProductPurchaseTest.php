<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class ProductPurchaseTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_create_a_purchase()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create([
            'manage_stock' => false,
        ]);

        $purchase = ProductPurchase::create([
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => 1,
            'amount' => 5000,
            'status' => 'unpaid',
        ]);

        $this->assertDatabaseHas('product_purchases', [
            'id' => $purchase->id,
            'purchaser_id' => $user->id,
            'status' => 'unpaid',
        ]);
    }

    #[Test]
    public function purchase_belongs_to_purchaser()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create([
            'manage_stock' => false,
        ]);

        $purchase = ProductPurchase::create([
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => 1,
            'amount' => 5000,
            'status' => 'unpaid',
        ]);

        $this->assertInstanceOf(User::class, $purchase->purchaser);
        $this->assertEquals($user->id, $purchase->purchaser->id);
    }

    #[Test]
    public function purchase_belongs_to_purchasable()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create([
            'manage_stock' => false,
        ]);

        $purchase = ProductPurchase::create([
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => 1,
            'amount' => 5000,
            'status' => 'unpaid',
        ]);

        $this->assertInstanceOf(Product::class, $purchase->purchasable);
        $this->assertEquals($product->id, $purchase->purchasable->id);
    }

    #[Test]
    public function it_can_have_different_statuses()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create([
            'manage_stock' => false,
        ]);

        $unpaidPurchase = ProductPurchase::create([
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => 1,
            'amount' => 5000,
            'status' => 'unpaid',
        ]);

        $completedPurchase = ProductPurchase::create([
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => 1,
            'amount' => 5000,
            'status' => 'completed',
        ]);

        $this->assertEquals(PurchaseStatus::UNPAID, $unpaidPurchase->status);
        $this->assertEquals(PurchaseStatus::COMPLETED, $completedPurchase->status);
    }

    #[Test]
    public function it_can_scope_completed_purchases()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create([
            'manage_stock' => false,
        ]);

        ProductPurchase::create([
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => 1,
            'amount' => 5000,
            'status' => 'completed',
        ]);

        ProductPurchase::create([
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => 1,
            'amount' => 5000,
            'status' => 'unpaid',
        ]);

        $completed = ProductPurchase::completed()->get();

        $this->assertCount(1, $completed);
        $this->assertEquals(PurchaseStatus::COMPLETED, $completed->first()->status);
    }

    #[Test]
    public function it_can_scope_cart_purchases()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create([
            'manage_stock' => false,
        ]);

        ProductPurchase::create([
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => 1,
            'amount' => 5000,
            'status' => 'cart',
        ]);

        ProductPurchase::create([
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => 1,
            'amount' => 5000,
            'status' => 'completed',
        ]);

        $inCart = ProductPurchase::inCart()->get();

        $this->assertCount(1, $inCart);
        $this->assertEquals(PurchaseStatus::CART, $inCart->first()->status);
    }

    #[Test]
    public function it_can_store_purchase_metadata()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create([
            'manage_stock' => false,
        ]);

        $purchase = ProductPurchase::create([
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => 1,
            'amount' => 5000,
            'status' => 'unpaid',
            'meta' => [
                'ip_address' => '192.168.1.1',
                'user_agent' => 'Mozilla/5.0',
            ],
        ]);

        $this->assertEquals('192.168.1.1', $purchase->meta->ip_address);
        $this->assertEquals('Mozilla/5.0', $purchase->meta->user_agent);
    }

    #[Test]
    public function it_can_track_amount_paid()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create([
            'manage_stock' => false,
        ]);

        $purchase = ProductPurchase::create([
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => 1,
            'amount' => 5000,
            'amount_paid' => 0,
            'status' => 'unpaid',
        ]);

        $this->assertEquals(5000, $purchase->amount);
        $this->assertEquals(0, $purchase->amount_paid);

        $purchase->update([
            'amount_paid' => 5000,
            'status' => 'completed',
        ]);

        $this->assertEquals(5000, $purchase->fresh()->amount_paid);
        $this->assertEquals(PurchaseStatus::COMPLETED, $purchase->fresh()->status);
    }

    #[Test]
    public function it_can_store_charge_id()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create([
            'manage_stock' => false,
        ]);

        $purchase = ProductPurchase::create([
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => 1,
            'amount' => 5000,
            'charge_id' => 'ch_123456789',
            'status' => 'completed',
        ]);

        $this->assertEquals('ch_123456789', $purchase->charge_id);
    }

    #[Test]
    public function it_tracks_purchase_quantity()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create([
            'manage_stock' => false,
        ]);

        $purchase = ProductPurchase::create([
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => 5,
            'amount' => 25000,
            'status' => 'unpaid',
        ]);

        $this->assertEquals(5, $purchase->quantity);
        $this->assertEquals(25000, $purchase->amount);
    }

    #[Test]
    public function user_can_have_multiple_purchases()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->withPrices()->create(['manage_stock' => false]);
        $product2 = Product::factory()->withPrices()->create(['manage_stock' => false]);

        ProductPurchase::create([
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'purchasable_id' => $product1->id,
            'purchasable_type' => get_class($product1),
            'quantity' => 1,
            'amount' => 5000,
            'status' => 'completed',
        ]);

        ProductPurchase::create([
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'purchasable_id' => $product2->id,
            'purchasable_type' => get_class($product2),
            'quantity' => 1,
            'amount' => 7500,
            'status' => 'completed',
        ]);

        $this->assertCount(2, $user->purchases);
    }

    #[Test]
    public function product_can_have_multiple_purchases()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $product = Product::factory()->withPrices()->create(['manage_stock' => false]);

        ProductPurchase::create([
            'purchaser_id' => $user1->id,
            'purchaser_type' => get_class($user1),
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => 1,
            'amount' => 5000,
            'status' => 'completed',
        ]);

        ProductPurchase::create([
            'purchaser_id' => $user2->id,
            'purchaser_type' => get_class($user2),
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'quantity' => 1,
            'amount' => 5000,
            'status' => 'completed',
        ]);

        $this->assertCount(2, $product->purchases);
    }
}
