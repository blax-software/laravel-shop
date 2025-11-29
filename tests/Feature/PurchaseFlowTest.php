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
        $product = Product::factory()->withPrices(1, 2999)->create([
            'manage_stock' => false,
        ]);

        $cartItem = $user->addToCart($product, quantity: 2);

        $this->assertInstanceOf(CartItem::class, $cartItem);
        $this->assertEquals(2, $cartItem->quantity);
        $this->assertEquals($product->id, $cartItem->purchasable_id);
    }

    /** @test */
    public function user_can_get_cart_items()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->withStocks(5)->withPrices()->create();
        $product2 = Product::factory()->withStocks(5)->withPrices(2)->create();

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
        $product = Product::factory()->withStocks(5)->withPrices()->create();

        $cartItem = $user->addToCart($product->prices()->first(), quantity: 1);

        $user->updateCartQuantity($cartItem, quantity: 5);

        $this->assertEquals(5, $cartItem->fresh()->quantity);
    }

    /** @test */
    public function user_can_remove_item_from_cart()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withStocks(5)->withPrices()->create();

        $cartItem = $user->addToCart($product->prices()->first(), quantity: 1);

        $this->assertCount(1, $user->cartItems);

        $user->removeFromCart($cartItem);

        $this->assertCount(0, $user->refresh()->cartItems);
    }

    /** @test */
    public function user_can_checkout_cart()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->withStocks(5)->withPrices(3)->create(['manage_stock' => false]);
        $product2 = Product::factory()->withStocks(5)->withPrices(3)->create(['manage_stock' => false]);

        $user->addToCart($product1, quantity: 2);
        $user->addToCart($product2, quantity: 1);

        $product1->update(['manage_stock' => true]);
        $product2->update(['manage_stock' => true]);

        // Assert cart customer is user
        $this->assertEquals($user->id, $user->currentCart()?->customer->id);

        $this->assertThrows(fn() => $user->checkoutCart(), NotEnoughStockException::class);

        $product1->update(['manage_stock' => false]);
        $product2->increaseStock(5);

        $cart = $user->checkoutCart();
        $purchases = $cart->purchases;

        $this->assertCount(2, $purchases);
        $this->assertEquals('unpaid', $purchases[0]->status);
        $this->assertEquals('unpaid', $purchases[1]->status);
    }

    /** @test */
    public function user_can_get_cart_total()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->withStocks()->withPrices(unit_amount: 40)->create();
        $product2 = Product::factory()->withStocks()->withPrices(unit_amount: 60)->create();

        $this->assertNotNull($product1->getCurrentPrice());
        $this->assertNotNull($product2->getCurrentPrice());

        $user->addToCart($product1, quantity: 2);
        $user->addToCart($product2, quantity: 1);

        $total = $user->getCartTotal();

        $this->assertEquals(140.00, $total);
    }

    /** @test */
    public function user_can_get_cart_items_count()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->withStocks()->withPrices()->create();
        $product2 = Product::factory()->withStocks()->withPrices()->create();

        $user->addToCart($product1, quantity: 3);
        $user->addToCart($product2, quantity: 2);

        $count = $user->getCartItemsCount();

        $this->assertEquals(5, $count);
    }

    /** @test */
    public function user_can_clear_cart()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->withStocks()->withPrices()->create();
        $product2 = Product::factory()->withStocks()->withPrices()->create();

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
        $purchasedProduct = Product::factory()->withPrices()->create(['manage_stock' => false]);
        $notPurchasedProduct = Product::factory()->withPrices()->create();

        $productPurchase = $user->purchase($purchasedProduct, quantity: 1);
        $productPurchase->update(['status' => 'completed']);


        $this->assertTrue($user->hasPurchased($purchasedProduct));
        $this->assertFalse($user->hasPurchased($notPurchasedProduct));
    }

    /** @test */
    public function user_can_get_completed_purchases()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->withStocks()->withPrices()->create();
        $product2 = Product::factory()->withStocks()->withPrices()->create();
        $product3 = Product::factory()->withStocks()->withPrices()->create();

        $user->purchase($product1, quantity: 1);
        $user->purchase($product2, quantity: 1);
        $user->addToCart($product3, quantity: 1);

        $completed = $user->purchases;

        $this->assertCount(2, $completed);
    }

    /** @test */
    public function purchase_reduces_stock_when_managed()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create();
        $product->increaseStock(10);

        $user->purchase($product, quantity: 3);

        $this->assertEquals(7, $product->AvailableStocks);
    }

    /** @test */
    public function cannot_purchase_more_than_available_stock()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create();

        $this->expectException(NotEnoughStockException::class);

        $user->purchase($product, quantity: 10);
    }

    /** @test */
    public function adding_to_cart_checks_stock_availability()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices(2)->withStocks(3)->create();

        $this->assertThrows(fn() => $user->addToCart($product, quantity: 5), NotEnoughStockException::class);
    }

    /** @test */
    public function purchase_can_store_metadata()
    {
        $user = User::factory()->create();
        $product = Product::factory()->withPrices()->create(['manage_stock' => false]);

        $purchase = $user->purchase($product, quantity: 1, meta: [
            'gift_message' => 'Happy Birthday!',
            'gift_wrap' => true,
        ]);

        $this->assertEquals('Happy Birthday!', $purchase->meta->gift_message ?? null);
    }

    /** @test */
    public function purchase_can_be_associated_with_cart()
    {
        $user = User::factory()->create();
        $cart = Cart::create(['user_id' => $user->id]);
        $product = Product::factory()->create();

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
        $product = Product::factory()->withPrices()->create(['manage_stock' => false]);

        $user->addToCart($product, quantity: 1);
        $cart = $user->currentCart();

        if ($cart) {
            $this->assertNull($cart->converted_at);

            $cart = $user->checkoutCart();

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
        $product = Product::factory()->withPrices()->create([
            'manage_stock' => false,
        ]);

        $purchase = $user->purchase($product, quantity: 2);

        $this->assertEquals(2, $purchase->quantity);
        $this->assertEquals(0, $purchase->amount_paid);
        $this->assertGreaterThan(0, $purchase->amount);
    }

    /** @test */
    public function cart_total_is_correct_after_checkout()
    {
        $user = User::factory()->create();
        $product1 = Product::factory()->withStocks()->withPrices(1, unit_amount: 30)->create();
        $product2 = Product::factory()->withStocks()->withPrices(1, unit_amount: 70)->create();

        $user->addToCart($product1, quantity: 1);
        $user->addToCart($product2, quantity: 2);

        $cart = $user->checkoutCart();

        $this->assertEquals(170.00, $cart->getTotal());
    }
}
