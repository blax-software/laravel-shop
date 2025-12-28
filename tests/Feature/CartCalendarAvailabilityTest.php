<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

class CartCalendarAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function createUserWithCart(): array
    {
        $user = User::create([
            'id' => \Illuminate\Support\Str::uuid(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $cart = Cart::factory()->forCustomer($user)->create();

        return [$user, $cart];
    }

    #[Test]
    public function it_returns_unlimited_availability_for_empty_cart()
    {
        [$user, $cart] = $this->createUserWithCart();

        $availability = $cart->calendarAvailability();

        $this->assertEquals(PHP_INT_MAX, $availability['max_available']);
        $this->assertEquals(PHP_INT_MAX, $availability['min_available']);
        $this->assertEmpty($availability['dates']);
    }

    #[Test]
    public function it_returns_availability_for_single_product_in_cart()
    {
        [$user, $cart] = $this->createUserWithCart();

        $product = Product::factory()->withStocks(50)->withPrices(1, 1000)->create();
        $cart->addToCart($product, 1);

        $availability = $cart->calendarAvailability();

        $this->assertEquals(50, $availability['max_available']);
        $this->assertEquals(50, $availability['min_available']);
        $this->assertCount(31, $availability['dates']);

        // All dates should have 50 available
        foreach ($availability['dates'] as $dateKey => $dayData) {
            $this->assertEquals(['min' => 50, 'max' => 50], $dayData, "Failed for date: $dateKey");
        }
    }

    #[Test]
    public function it_returns_minimum_availability_across_multiple_products()
    {
        [$user, $cart] = $this->createUserWithCart();

        $product1 = Product::factory()->withStocks(100)->withPrices(1, 1000)->create();
        $product2 = Product::factory()->withStocks(30)->withPrices(1, 500)->create();

        $cart->addToCart($product1, 1);
        $cart->addToCart($product2, 1);

        $availability = $cart->calendarAvailability();

        // The cart availability should be limited by the product with less stock
        $this->assertEquals(30, $availability['max_available']);
        $this->assertEquals(30, $availability['min_available']);
    }

    #[Test]
    public function it_considers_required_quantity_when_calculating_sets()
    {
        [$user, $cart] = $this->createUserWithCart();

        // Product with 10 units in stock
        $product = Product::factory()->withStocks(10)->withPrices(1, 1000)->create();

        // Add 3 of this product to cart
        $cart->addToCart($product, 3);

        $availability = $cart->calendarAvailability();

        // With 10 in stock and 3 required per cart, we can fulfill 3 complete sets (10 / 3 = 3)
        $this->assertEquals(3, $availability['max_available']);
        $this->assertEquals(3, $availability['min_available']);
    }

    #[Test]
    public function it_shows_availability_for_cart_with_booking_products()
    {
        [$user, $cart] = $this->createUserWithCart();

        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product->increaseStock(5);

        // Create a price for the product
        $product->prices()->create([
            'unit_amount' => 10000,
            'currency' => 'usd',
            'is_default' => true,
        ]);

        $cart->addToCart($product, 1, [], now()->addDays(5), now()->addDays(10));

        $availability = $cart->calendarAvailability();

        $this->assertEquals(5, $availability['max_available']);
        $this->assertEquals(5, $availability['min_available']);
    }

    #[Test]
    public function it_shows_reduced_availability_when_stock_is_claimed()
    {
        [$user, $cart] = $this->createUserWithCart();

        $product = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        // Create a price for the product
        $product->prices()->create([
            'unit_amount' => 10000,
            'currency' => 'usd',
            'is_default' => true,
        ]);

        // Claim 3 units for days 5-10
        $product->claimStock(
            quantity: 3,
            from: now()->startOfDay()->addDays(5),
            until: now()->endOfDay()->addDays(10)
        );

        $cart->addToCart($product, 1, [], now()->addDays(5), now()->addDays(10));

        $availability = $cart->calendarAvailability();

        // Before claim period (days 0-4): 10 available
        $this->assertEquals(['min' => 10, 'max' => 10], $availability['dates'][now()->toDateString()]);
        $this->assertEquals(['min' => 10, 'max' => 10], $availability['dates'][now()->addDays(4)->toDateString()]);

        // During claim period (days 5-10): 7 available (10 - 3)
        // Day 5: claim starts at startOfDay, so min=max=7 for the whole day
        $this->assertEquals(['min' => 7, 'max' => 7], $availability['dates'][now()->addDays(5)->toDateString()]);
        $this->assertEquals(['min' => 7, 'max' => 7], $availability['dates'][now()->addDays(7)->toDateString()]);

        // After claim period: 10 available
        $this->assertEquals(['min' => 10, 'max' => 10], $availability['dates'][now()->addDays(15)->toDateString()]);
    }

    #[Test]
    public function it_shows_availability_for_cart_with_pool_products()
    {
        [$user, $cart] = $this->createUserWithCart();

        // Create pool product
        $pool = Product::factory()->create([
            'name' => 'Hotel Rooms',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create a price for the pool
        $pool->prices()->create([
            'unit_amount' => 15000,
            'currency' => 'usd',
            'is_default' => true,
        ]);

        // Create 3 single items with stock
        $singles = [];
        for ($i = 1; $i <= 3; $i++) {
            $single = Product::factory()->create([
                'name' => "Room 10{$i}",
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);
            $single->increaseStock(1);

            // Create a price for each single
            $single->prices()->create([
                'unit_amount' => 15000,
                'currency' => 'usd',
                'is_default' => true,
            ]);

            $singles[] = $single;
        }

        foreach ($singles as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        $cart->addToCart($pool, 1, [], now()->addDays(5), now()->addDays(10));

        $availability = $cart->calendarAvailability();

        $this->assertEquals(3, $availability['max_available']);
        $this->assertEquals(3, $availability['min_available']);
    }

    #[Test]
    public function it_shows_reduced_pool_availability_with_claims()
    {
        [$user, $cart] = $this->createUserWithCart();

        // Create pool product
        $pool = Product::factory()->create([
            'name' => 'Hotel Rooms',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create a price for the pool
        $pool->prices()->create([
            'unit_amount' => 15000,
            'currency' => 'usd',
            'is_default' => true,
        ]);

        // Create 3 single items with stock
        $singles = [];
        for ($i = 1; $i <= 3; $i++) {
            $single = Product::factory()->create([
                'name' => "Room 10{$i}",
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);
            $single->increaseStock(1);

            $single->prices()->create([
                'unit_amount' => 15000,
                'currency' => 'usd',
                'is_default' => true,
            ]);

            $singles[] = $single;
        }

        foreach ($singles as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        // Claim single1 from day 5 to day 10
        $singles[0]->claimStock(
            quantity: 1,
            from: now()->startOfDay()->addDays(5),
            until: now()->endOfDay()->addDays(10)
        );

        $cart->addToCart($pool, 1, [], now()->addDays(5), now()->addDays(10));

        $availability = $cart->calendarAvailability();

        // Before claim: 3 available
        $this->assertEquals(['min' => 3, 'max' => 3], $availability['dates'][now()->addDays(4)->toDateString()]);

        // During claim: 2 available
        $this->assertEquals(['min' => 2, 'max' => 2], $availability['dates'][now()->addDays(7)->toDateString()]);

        // After claim: 3 available
        $this->assertEquals(['min' => 3, 'max' => 3], $availability['dates'][now()->addDays(15)->toDateString()]);
    }

    #[Test]
    public function it_handles_custom_date_range()
    {
        [$user, $cart] = $this->createUserWithCart();

        $product = Product::factory()->withStocks(25)->withPrices(1, 1000)->create();
        $cart->addToCart($product, 1);

        $from = now()->addDays(10);
        $until = now()->addDays(20);

        $availability = $cart->calendarAvailability($from, $until);

        $this->assertCount(11, $availability['dates']); // 10 to 20 inclusive
        $this->assertEquals(25, $availability['max_available']);
        $this->assertEquals(25, $availability['min_available']);
    }

    #[Test]
    public function it_returns_minimum_sets_across_multiple_products_with_different_quantities()
    {
        [$user, $cart] = $this->createUserWithCart();

        // Product 1: 20 in stock, need 4 = 5 sets available
        $product1 = Product::factory()->withStocks(20)->withPrices(1, 1000)->create();

        // Product 2: 15 in stock, need 5 = 3 sets available
        $product2 = Product::factory()->withStocks(15)->withPrices(1, 500)->create();

        $cart->addToCart($product1, 4);
        $cart->addToCart($product2, 5);

        $availability = $cart->calendarAvailability();

        // Cart can only be fulfilled 3 times (limited by product2: 15/5 = 3)
        $this->assertEquals(3, $availability['max_available']);
        $this->assertEquals(3, $availability['min_available']);
    }

    #[Test]
    public function it_handles_products_without_stock_management()
    {
        [$user, $cart] = $this->createUserWithCart();

        // Product without stock management (unlimited)
        $product = Product::factory()->create([
            'manage_stock' => false,
        ]);
        $product->prices()->create([
            'unit_amount' => 1000,
            'currency' => 'usd',
            'is_default' => true,
        ]);

        $cart->addToCart($product, 1);

        $availability = $cart->calendarAvailability();

        // Unlimited availability
        $this->assertEquals(PHP_INT_MAX, $availability['max_available']);
        $this->assertEquals(PHP_INT_MAX, $availability['min_available']);
    }

    #[Test]
    public function it_combines_limited_and_unlimited_products()
    {
        [$user, $cart] = $this->createUserWithCart();

        // Limited product
        $limitedProduct = Product::factory()->withStocks(10)->withPrices(1, 1000)->create();

        // Unlimited product
        $unlimitedProduct = Product::factory()->create([
            'manage_stock' => false,
        ]);
        $unlimitedProduct->prices()->create([
            'unit_amount' => 500,
            'currency' => 'usd',
            'is_default' => true,
        ]);

        $cart->addToCart($limitedProduct, 2);
        $cart->addToCart($unlimitedProduct, 1);

        $availability = $cart->calendarAvailability();

        // Limited by the limited product: 10 / 2 = 5 sets
        $this->assertEquals(5, $availability['max_available']);
        $this->assertEquals(5, $availability['min_available']);
    }

    #[Test]
    public function it_returns_item_details_for_debugging()
    {
        [$user, $cart] = $this->createUserWithCart();

        $product1 = Product::factory()->withStocks(50)->withPrices(1, 1000)->create([
            'name' => 'Product One',
        ]);
        $product2 = Product::factory()->withStocks(30)->withPrices(1, 500)->create([
            'name' => 'Product Two',
        ]);

        $cart->addToCart($product1, 2);
        $cart->addToCart($product2, 1);

        $availability = $cart->calendarAvailability();

        $this->assertArrayHasKey('items', $availability);
        $this->assertCount(2, $availability['items']);

        // Verify item details are included
        $itemKeys = array_keys($availability['items']);
        foreach ($itemKeys as $key) {
            $item = $availability['items'][$key];
            $this->assertArrayHasKey('product_id', $item);
            $this->assertArrayHasKey('product_name', $item);
            $this->assertArrayHasKey('required_quantity', $item);
            $this->assertArrayHasKey('availability', $item);
        }
    }

    #[Test]
    public function it_handles_overlapping_claims_for_multiple_products()
    {
        [$user, $cart] = $this->createUserWithCart();

        // Product 1: 100 stock, claim 30 on days 5-10
        $product1 = Product::factory()->create([
            'name' => 'Product 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product1->increaseStock(100);
        $product1->prices()->create([
            'unit_amount' => 1000,
            'currency' => 'usd',
            'is_default' => true,
        ]);

        $product1->claimStock(
            quantity: 30,
            from: now()->startOfDay()->addDays(5),
            until: now()->endOfDay()->addDays(10)
        );

        // Product 2: 50 stock, claim 20 on days 8-15
        $product2 = Product::factory()->create([
            'name' => 'Product 2',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $product2->increaseStock(50);
        $product2->prices()->create([
            'unit_amount' => 500,
            'currency' => 'usd',
            'is_default' => true,
        ]);

        $product2->claimStock(
            quantity: 20,
            from: now()->startOfDay()->addDays(8),
            until: now()->endOfDay()->addDays(15)
        );

        $cart->addToCart($product1, 1, [], now()->addDays(5), now()->addDays(10));
        $cart->addToCart($product2, 1, [], now()->addDays(5), now()->addDays(10));

        $availability = $cart->calendarAvailability();

        // Day 0-4: product1=100, product2=50 -> min(100, 50) = 50
        $this->assertEquals(['min' => 50, 'max' => 50], $availability['dates'][now()->toDateString()]);

        // Day 6-7: product1=70, product2=50 -> min(70, 50) = 50
        $this->assertEquals(['min' => 50, 'max' => 50], $availability['dates'][now()->addDays(6)->toDateString()]);

        // Day 9: product1=70, product2=30 -> min(70, 30) = 30
        $this->assertEquals(['min' => 30, 'max' => 30], $availability['dates'][now()->addDays(9)->toDateString()]);

        // Day 12: product1=100, product2=30 -> min(100, 30) = 30
        $this->assertEquals(['min' => 30, 'max' => 30], $availability['dates'][now()->addDays(12)->toDateString()]);

        // Day 20: product1=100, product2=50 -> min(100, 50) = 50
        $this->assertEquals(['min' => 50, 'max' => 50], $availability['dates'][now()->addDays(20)->toDateString()]);
    }

    #[Test]
    public function it_handles_same_product_added_multiple_times()
    {
        [$user, $cart] = $this->createUserWithCart();

        $product = Product::factory()->withStocks(15)->withPrices(1, 1000)->create();

        // Add the same product twice
        $cart->addToCart($product, 2);
        $cart->addToCart($product, 3);

        $availability = $cart->calendarAvailability();

        // Total required: 5, available: 15 -> 3 sets (15 / 5 = 3)
        $this->assertEquals(3, $availability['max_available']);
        $this->assertEquals(3, $availability['min_available']);
    }

    #[Test]
    public function it_returns_zero_when_no_stock_available()
    {
        [$user, $cart] = $this->createUserWithCart();

        $product = Product::factory()->create([
            'manage_stock' => true,
        ]);
        // No stock added
        $product->prices()->create([
            'unit_amount' => 1000,
            'currency' => 'usd',
            'is_default' => true,
        ]);

        $cart->addToCart($product, 1);

        $availability = $cart->calendarAvailability();

        $this->assertEquals(0, $availability['max_available']);
        $this->assertEquals(0, $availability['min_available']);
    }
}
