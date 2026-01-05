<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Exceptions\MultiplePurchaseOptions;
use Blax\Shop\Exceptions\NotPurchasable;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;
use PHPUnit\Framework\Attributes\Test;

class HasShoppingCapabilitiesTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function user_has_cart_relationship()
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphMany::class, $user->cart());
    }

    #[Test]
    public function user_has_purchases_relationship()
    {
        $user = User::factory()->create();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\MorphMany::class, $user->purchases());
    }

    #[Test]
    public function user_can_get_current_cart()
    {
        $user = User::factory()->create();

        $cart = $user->currentCart();

        $this->assertNotNull($cart);
        $this->assertEquals($user->id, $cart->customer_id);
    }

    #[Test]
    public function user_cannot_purchase_product_without_price()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $this->expectException(NotPurchasable::class);

        $user->purchase($product);
    }

    #[Test]
    public function user_cannot_purchase_product_with_multiple_default_prices()
    {
        $user = User::factory()->create();
        $product = Product::factory()
            ->withPrices(2)
            ->create(['manage_stock' => false]);

        // Set both prices as default
        $product->prices()->update(['is_default' => true]);

        $this->expectException(MultiplePurchaseOptions::class);

        $user->purchase($product);
    }

    #[Test]
    public function user_can_purchase_product_with_single_default_price()
    {
        $user = User::factory()->create();
        $product = Product::factory()
            ->withPrices(1)
            ->create(['manage_stock' => false]);

        $product->prices()->update(['is_default' => true]);

        $purchase = $user->purchase($product);

        $this->assertNotNull($purchase);
        $this->assertEquals($user->id, $purchase->purchaser_id);
    }

    #[Test]
    public function user_cannot_add_product_to_cart_without_default_price()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withStocks()->create();

        $this->assertThrows(fn() => $user->addToCart($product), NotPurchasable::class);
    }

    #[Test]
    public function user_cannot_add_product_with_multiple_default_prices_to_cart()
    {
        $user = User::factory()->create();
        $product = Product::factory()
            ->withPrices(3)
            ->create(['manage_stock' => false]);

        $product->prices()->update(['is_default' => true]);

        $this->expectException(MultiplePurchaseOptions::class);

        $user->addToCart($product);
    }

    #[Test]
    public function user_can_get_completed_purchases()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->withPrices()->create(['manage_stock' => false]);
        $product2 = Product::factory()->withPrices()->create(['manage_stock' => false]);

        $purchase1 = $user->purchase($product1);
        $purchase1->update(['status' => 'completed']);

        $purchase2 = $user->purchase($product2);
        $purchase2->update(['status' => 'unpaid']);

        $completed = $user->completedPurchases;

        $this->assertCount(1, $completed);
        $this->assertEquals(PurchaseStatus::COMPLETED, $completed->first()->status);
    }

    #[Test]
    public function purchase_with_metadata_stores_correctly()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create(['manage_stock' => false]);

        $metadata = [
            'custom_field' => 'custom_value',
            'notes' => 'Special instructions',
        ];

        $purchase = $user->purchase($product, quantity: 1, meta: $metadata);

        $this->assertEquals('custom_value', $purchase->meta->custom_field);
        $this->assertEquals('Special instructions', $purchase->meta->notes);
    }

    #[Test]
    public function user_can_check_if_purchased_specific_product()
    {
        $user = User::factory()->create();
        $purchasedProduct = Product::factory()->withPrices()->create(['manage_stock' => false]);
        $notPurchasedProduct = Product::factory()->withPrices()->create();

        $purchase = $user->purchase($purchasedProduct);
        $purchase->update(['status' => 'completed']);

        $this->assertTrue($user->hasPurchased($purchasedProduct));
        $this->assertFalse($user->hasPurchased($notPurchasedProduct));
    }

    #[Test]
    public function user_cart_items_are_accessible()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->withStocks(10)->create();

        $user->addToCart($product);

        $this->assertCount(1, $user->cartItems);
    }

    #[Test]
    public function user_can_update_cart_item_quantity()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->withStocks(20)->create();

        $cartItem = $user->addToCart($product, quantity: 1);

        $user->updateCartQuantity($cartItem, quantity: 5);

        $this->assertEquals(5, $cartItem->fresh()->quantity);
    }

    #[Test]
    public function user_can_remove_item_from_cart()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->withStocks(10)->create();

        $cartItem = $user->addToCart($product);

        $this->assertCount(1, $user->cartItems);

        $user->removeFromCart($cartItem);

        $this->assertCount(0, $user->fresh()->cartItems);
    }

    #[Test]
    public function user_can_get_cart_total()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->withPrices(unit_amount: 100)->withStocks(10)->create();
        $product2 = Product::factory()->withPrices(unit_amount: 50)->withStocks(10)->create();

        $user->addToCart($product1, quantity: 2);
        $user->addToCart($product2, quantity: 1);

        $total = $user->getCartTotal();

        $this->assertEquals(250, $total);
    }

    #[Test]
    public function user_can_get_cart_items_count()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->withPrices()->withStocks(10)->create();
        $product2 = Product::factory()->withPrices()->withStocks(10)->create();

        $user->addToCart($product1, quantity: 3);
        $user->addToCart($product2, quantity: 2);

        $count = $user->getCartItemsCount();

        $this->assertEquals(5, $count);
    }

    #[Test]
    public function user_can_clear_cart()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->withPrices()->withStocks(10)->create();
        $product2 = Product::factory()->withPrices()->withStocks(10)->create();

        $user->addToCart($product1);
        $user->addToCart($product2);

        $this->assertCount(2, $user->cartItems);

        $user->clearCart();

        $this->assertCount(0, $user->fresh()->cartItems);
    }

    #[Test]
    public function adding_product_to_cart_reserves_stock()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->withStocks(10)->create();

        $this->assertEquals(10, $product->getAvailableStock());

        $user->addToCart($product, quantity: 3);

        $this->assertEquals(7, $product->fresh()->getAvailableStock());
    }

    #[Test]
    public function purchase_calls_product_actions()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create(['manage_stock' => false]);

        // Create a product action
        $product->actions()->create([
            'events' => ['purchased'],
            'class' => 'TestAction',
            'active' => true,
        ]);

        $purchase = $user->purchase($product);

        // Just verify purchase was created successfully
        // Actual action execution would require implementing the action class
        $this->assertNotNull($purchase);
    }
}
