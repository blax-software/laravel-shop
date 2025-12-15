<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Exceptions\HasNoDefaultPriceException;
use Blax\Shop\Exceptions\HasNoPriceException;
use Blax\Shop\Facades\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Workbench\App\Models\User;

class ProductPricingValidationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        auth()->login($this->user);
    }

    /** @test */
    public function it_throws_exception_when_product_has_no_prices()
    {
        $product = Product::factory()->create([
            'name' => 'No Price Product',
            'type' => ProductType::SIMPLE,
        ]);

        $this->expectException(HasNoPriceException::class);
        $this->expectExceptionMessage('has no pricing configured');

        Cart::add($product, 1);
    }

    /** @test */
    public function it_throws_exception_when_product_has_multiple_prices_but_no_default()
    {
        $product = Product::factory()->create([
            'name' => 'Multi Price Product',
            'type' => ProductType::SIMPLE,
        ]);

        // Create multiple prices, none marked as default
        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'is_default' => false,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 7000,
            'is_default' => false,
        ]);

        $this->expectException(HasNoDefaultPriceException::class);
        $this->expectExceptionMessage('none are marked as default');

        Cart::add($product, 1);
    }

    /** @test */
    public function it_throws_exception_when_product_has_single_price_not_marked_as_default()
    {
        $product = Product::factory()->create([
            'name' => 'Single Non-Default Price',
            'type' => ProductType::SIMPLE,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'is_default' => false,  // Not marked as default
        ]);

        $this->expectException(HasNoDefaultPriceException::class);
        $this->expectExceptionMessage("not marked as default");

        Cart::add($product, 1);
    }

    /** @test */
    public function it_throws_exception_when_product_has_multiple_default_prices()
    {
        $product = Product::factory()->create([
            'name' => 'Multiple Defaults',
            'type' => ProductType::SIMPLE,
        ]);

        // Create multiple prices, all marked as default
        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 7000,
            'is_default' => true,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 9000,
            'is_default' => true,
        ]);

        $this->expectException(HasNoDefaultPriceException::class);
        $this->expectExceptionMessage('3 prices marked as default');

        Cart::add($product, 1);
    }

    /** @test */
    public function it_allows_adding_product_with_valid_default_price()
    {
        $product = Product::factory()->create([
            'name' => 'Valid Product',
            'type' => ProductType::SIMPLE,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'is_default' => true,
        ]);

        $cartItem = Cart::add($product, 1);

        $this->assertNotNull($cartItem);
        $this->assertEquals($product->id, $cartItem->purchasable_id);
    }

    /** @test */
    public function it_allows_product_with_one_default_and_multiple_non_default_prices()
    {
        $product = Product::factory()->create([
            'name' => 'Mixed Prices',
            'type' => ProductType::SIMPLE,
            'manage_stock' => true,
        ]);
        $product->increaseStock(10);

        // One default price
        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'is_default' => true,
        ]);

        // Multiple non-default prices
        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 4500,
            'is_default' => false,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 4000,
            'is_default' => false,
        ]);

        $cartItem = Cart::add($product, 1);

        $this->assertNotNull($cartItem);
        $this->assertEquals($product->id, $cartItem->purchasable_id);
    }

    /** @test */
    public function it_throws_exception_when_pool_has_no_price_and_single_items_have_no_prices()
    {
        $pool = Product::factory()->create([
            'name' => 'Pool Without Prices',
            'type' => ProductType::POOL,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        $pool->productRelations()->attach($spot1->id, [
            'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
        ]);

        $this->expectException(HasNoPriceException::class);
        $this->expectExceptionMessage('Pool product');
        $this->expectExceptionMessage('has no pricing configured');

        Cart::add($pool, 1);
    }

    /** @test */
    public function it_allows_pool_with_no_direct_price_but_single_items_have_prices()
    {
        $pool = Product::factory()->create([
            'name' => 'Pool With Inherited Prices',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        ProductPrice::factory()->create([
            'purchasable_id' => $spot1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'is_default' => true,
        ]);

        $pool->productRelations()->attach($spot1->id, [
            'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
        ]);

        $cartItem = Cart::add($pool, 1);

        $this->assertNotNull($cartItem);
        $this->assertEquals($pool->id, $cartItem->purchasable_id);
    }

    /** @test */
    public function it_allows_pool_with_direct_price_even_if_single_items_have_no_prices()
    {
        $pool = Product::factory()->create([
            'name' => 'Pool With Direct Price',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $pool->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 6000,
            'is_default' => true,
        ]);

        $spot1 = Product::factory()->create([
            'name' => 'Spot 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $spot1->increaseStock(1);

        $pool->productRelations()->attach($spot1->id, [
            'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
        ]);

        $cartItem = Cart::add($pool, 1);

        $this->assertNotNull($cartItem);
        $this->assertEquals($pool->id, $cartItem->purchasable_id);
    }

    /** @test */
    public function validate_pricing_returns_errors_array_without_throwing()
    {
        $product = Product::factory()->create([
            'name' => 'Test Product',
            'type' => ProductType::SIMPLE,
        ]);

        $result = $product->validatePricing(throwExceptions: false);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('no prices', $result['errors'][0]);
    }

    /** @test */
    public function validate_pricing_with_valid_price_returns_valid()
    {
        $product = Product::factory()->create([
            'name' => 'Valid Product',
            'type' => ProductType::SIMPLE,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 5000,
            'is_default' => true,
        ]);

        $result = $product->validatePricing(throwExceptions: false);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }
}
