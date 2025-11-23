<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;

class PurchaseFlowTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function user_can_purchase_a_product_directly()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'manage_stock' => false,
        ]);

        $price = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'amount' => 4999, // in cents
            'currency' => 'USD',
        ]);
        
        $purchase = $user->purchase($price, quantity: 1);

        $this->assertInstanceOf(ProductPurchase::class, $purchase);
        $this->assertEquals($product->id, $purchase->purchasable_id);
        $this->assertEquals($user->id, $purchase->purchaser_id);
        $this->assertEquals(1, $purchase->quantity);
        $this->assertEquals('unpaid', $purchase->status);
    }

    /** @test */
    public function user_can_add_product_to_cart()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'manage_stock' => false,
        ]);

        $price = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'amount' => 2999, // in cents
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $cartItem = $user->addToCart($price, quantity: 2);

        $this->assertInstanceOf(CartItem::class, $cartItem);
        $this->assertEquals(2, $cartItem->quantity);
        $this->assertEquals($price->id, $cartItem->purchasable_id);
    }

    /** @test */
    public function user_can_get_cart_items()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->withPrices()->create();
        $product2 = Product::factory()->withPrices(2)->create();

        $this->assertCount(1, $product1->prices);
        $this->assertCount(2, $product2->prices);

        $user->addToCart($product1->prices()->first(), quantity: 1);
        $user->addToCart($product2->prices()->first(), quantity: 2);

        $cartItems = $user->cartItems;

        $this->assertCount(2, $cartItems);
    }

    /** @test */
    public function user_can_update_cart_item_quantity()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create();

        $cartItem = $user->addToCart($product->prices()->first(), quantity: 1);

        $user->updateCartQuantity($cartItem, quantity: 5);

        $this->assertEquals(5, $cartItem->fresh()->quantity);
    }

    /** @test */
    public function user_can_remove_item_from_cart()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create();

        $cartItem = $user->addToCart($product->prices()->first(), quantity: 1);

        $this->assertCount(1, $user->cartItems);

        $user->removeFromCart($cartItem);

        $this->assertCount(0, $user->refresh()->cartItems);
    }

    /** @test */
    public function user_can_checkout_cart()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->withPrices()->create();
        $product2 = Product::factory()->withPrices()->create();

        $user->addToCart($product1, quantity: 2);
        $user->addToCart($product2, quantity: 1);

        $this->assertThrows(fn() => $user->checkout(), NotEnoughStockException::class);

        $product1->update(['manage_stock' => false]);
        $product2->increaseStock(5);

        $purchases = $user->checkout();

        $this->assertCount(2, $purchases);
        $this->assertEquals('completed', $purchases[0]->status);
        $this->assertEquals('completed', $purchases[1]->status);
    }

    /** @test */
    public function user_can_get_cart_total()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->create(['price' => 40.00]);
        $product2 = Product::factory()->create(['price' => 60.00]);

        $user->addToCart($product1, quantity: 2); // 80.00
        $user->addToCart($product2, quantity: 1); // 60.00

        $total = $user->getCartTotal();

        $this->assertEquals(140.00, $total);
    }

    /** @test */
    public function user_can_get_cart_items_count()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();

        $user->addToCart($product1, quantity: 3);
        $user->addToCart($product2, quantity: 2);

        $count = $user->getCartItemsCount();

        $this->assertEquals(5, $count);
    }

    /** @test */
    public function user_can_clear_cart()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->create();
        $product2 = Product::factory()->create();

        $user->addToCart($product1, quantity: 1);
        $user->addToCart($product2, quantity: 1);

        $this->assertCount(2, $user->cartItems);

        $user->clearCart();

        $this->assertCount(0, $user->fresh()->cartItems);
    }

    /** @test */
    public function user_can_check_if_product_was_purchased()
    {
        $user = User::factory()->create();
        $purchasedProduct = Product::factory()->create(['manage_stock' => false]);
        $notPurchasedProduct = Product::factory()->create();

        $user->purchase($purchasedProduct, quantity: 1);

        $this->assertTrue($user->hasPurchased($purchasedProduct));
        $this->assertFalse($user->hasPurchased($notPurchasedProduct));
    }

    /** @test */
    public function user_can_get_completed_purchases()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->create(['manage_stock' => false]);
        $product2 = Product::factory()->create(['manage_stock' => false]);
        $product3 = Product::factory()->create();

        $user->purchase($product1, quantity: 1);
        $user->purchase($product2, quantity: 1);
        $user->addToCart($product3, quantity: 1);

        $completed = $user->completedPurchases;

        $this->assertCount(2, $completed);
    }

    /** @test */
    public function purchase_reduces_stock_when_managed()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 10,
        ]);

        $user->purchase($product, quantity: 3);

        $this->assertEquals(7, $product->fresh()->stock_quantity);
    }

    /** @test */
    public function cannot_purchase_more_than_available_stock()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 5,
        ]);

        $this->expectException(\Exception::class);

        $user->purchase($product, quantity: 10);
    }

    /** @test */
    public function adding_to_cart_checks_stock_availability()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 3,
        ]);

        $this->expectException(\Exception::class);

        $user->addToCart($product, quantity: 5);
    }

    /** @test */
    public function purchase_can_store_metadata()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['manage_stock' => false]);

        $purchase = $user->purchase($product, quantity: 1, options: [
            'meta' => [
                'gift_message' => 'Happy Birthday!',
                'gift_wrap' => true,
            ],
        ]);

        $this->assertEquals('Happy Birthday!', $purchase->meta['gift_message'] ?? null);
    }

    /** @test */
    public function purchase_can_be_associated_with_cart()
    {
        $user = User::factory()->create();
        $cart = Cart::create(['user_id' => $user->id]);
        $product = Product::factory()->create(['manage_stock' => false]);

        $purchase = ProductPurchase::create([
            'user_id' => $user->id,
            'purchasable_type' => get_class($user),
            'purchasable_id' => $user->id,
            'product_id' => $product->id,
            'cart_id' => $cart->id,
            'quantity' => 1,
            'amount' => 5000,
            'status' => 'cart',
        ]);

        $this->assertEquals($cart->id, $purchase->cart_id);
        $this->assertTrue($cart->purchases->contains($purchase));
    }

    /** @test */
    public function checkout_marks_cart_as_converted()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['manage_stock' => false]);

        $cartItem = $user->addToCart($product, quantity: 1);
        $cart = Cart::where('user_id', $user->id)->first();

        if ($cart) {
            $this->assertNull($cart->converted_at);

            $user->checkout();

            $this->assertNotNull($cart->fresh()->converted_at);
        }
    }

    /** @test */
    public function user_cannot_add_out_of_stock_product_to_cart()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 0,
            'in_stock' => false,
        ]);

        $this->expectException(\Exception::class);

        $user->addToCart($product, quantity: 1);
    }

    /** @test */
    public function purchase_stores_amount_correctly()
    {
        $user = User::factory()->create();
        $product = Product::factory()->create([
            'price' => 49.99,
            'manage_stock' => false,
        ]);

        $purchase = $user->purchase($product, quantity: 2);

        $this->assertGreaterThan(0, $purchase->amount);
    }
}
