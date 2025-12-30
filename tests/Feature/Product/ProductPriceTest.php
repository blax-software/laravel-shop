<?php

namespace Blax\Shop\Tests\Feature\Product;

use Blax\Shop\Enums\BillingScheme;
use Blax\Shop\Enums\PriceType;
use Blax\Shop\Enums\RecurringInterval;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class ProductPriceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_create_a_product_price()
    {
        $product = Product::factory()->create();

        $price = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 99.99,
            'currency' => 'USD',
            'is_default' => true,
            'active' => true,
        ]);

        $this->assertDatabaseHas('product_prices', [
            'id' => $price->id,
            'purchasable_id' => $product->id,
            'unit_amount' => 99.99,
        ]);
    }

    #[Test]
    public function price_belongs_to_purchasable()
    {
        $product = Product::factory()->create();

        $price = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 50.00,
            'currency' => 'USD',
        ]);

        $this->assertInstanceOf(Product::class, $price->purchasable);
        $this->assertEquals($product->id, $price->purchasable->id);
    }

    #[Test]
    public function it_can_set_default_price()
    {
        $product = Product::factory()->create();

        $price1 = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 100.00,
            'currency' => 'USD',
            'is_default' => false,
        ]);

        $price2 = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 200.00,
            'currency' => 'USD',
            'is_default' => true,
        ]);

        $this->assertFalse($price1->is_default);
        $this->assertTrue($price2->is_default);
        $this->assertEquals($price2->id, $product->defaultPrice()->first()->id);
    }

    #[Test]
    public function it_can_set_recurring_price()
    {
        $product = Product::factory()->create();

        $price = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 29.99,
            'currency' => 'USD',
            'type' => 'recurring',
            'interval' => 'month',
            'interval_count' => 1,
            'trial_period_days' => 14,
        ]);

        $this->assertEquals(PriceType::RECURRING, $price->type);
        $this->assertEquals(RecurringInterval::MONTH, $price->interval);
        $this->assertEquals(1, $price->interval_count);
        $this->assertEquals(14, $price->trial_period_days);
    }

    #[Test]
    public function it_can_set_one_time_price()
    {
        $product = Product::factory()->create();

        $price = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 99.99,
            'currency' => 'USD',
            'type' => 'one_time',
        ]);

        $this->assertEquals(PriceType::ONE_TIME, $price->type);
        $this->assertNull($price->interval);
    }

    #[Test]
    public function it_can_scope_active_prices()
    {
        $product = Product::factory()->create();

        ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 50.00,
            'currency' => 'USD',
            'active' => true,
        ]);

        ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 75.00,
            'currency' => 'USD',
            'active' => false,
        ]);

        $activePrices = ProductPrice::isActive()->get();

        $this->assertCount(1, $activePrices);
        $this->assertTrue($activePrices->first()->active);
    }

    #[Test]
    public function it_returns_current_price_based_on_sale()
    {
        $product = Product::factory()->create();

        $price = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 100.00,
            'sale_unit_amount' => 80.00,
            'currency' => 'USD',
        ]);

        $this->assertEquals(80.00, $price->getCurrentPrice(true));
        $this->assertEquals(100.00, $price->getCurrentPrice(false));
    }

    #[Test]
    public function it_can_have_multiple_currencies()
    {
        $product = Product::factory()->create();

        $usdPrice = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 100.00,
            'currency' => 'USD',
        ]);

        $eurPrice = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 90.00,
            'currency' => 'EUR',
        ]);

        $this->assertCount(2, $product->prices);
        $this->assertEquals('USD', $usdPrice->currency);
        $this->assertEquals('EUR', $eurPrice->currency);
    }

    #[Test]
    public function it_can_store_price_metadata()
    {
        $product = Product::factory()->create();

        $price = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 100.00,
            'currency' => 'USD',
            'meta' => [
                'promo_code' => 'SAVE20',
                'features' => ['feature1', 'feature2'],
            ],
        ]);

        $this->assertEquals('SAVE20', $price->meta->promo_code);
        $this->assertEquals(['feature1', 'feature2'], $price->meta->features);
    }

    #[Test]
    public function it_can_deactivate_price()
    {
        $product = Product::factory()->create();

        $price = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 100.00,
            'currency' => 'USD',
            'active' => true,
        ]);

        $this->assertTrue($price->active);

        $price->update(['active' => false]);

        $this->assertFalse($price->fresh()->active);
    }

    #[Test]
    public function product_can_have_multiple_price_tiers()
    {
        $product = Product::factory()->create();

        ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'name' => 'Basic',
            'unit_amount' => 10.00,
            'currency' => 'USD',
        ]);

        ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'name' => 'Pro',
            'unit_amount' => 20.00,
            'currency' => 'USD',
        ]);

        ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'name' => 'Enterprise',
            'unit_amount' => 50.00,
            'currency' => 'USD',
        ]);

        $this->assertCount(3, $product->prices);
    }

    #[Test]
    public function it_can_set_billing_scheme()
    {
        $product = Product::factory()->create();

        $tieredPrice = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 100.00,
            'currency' => 'USD',
            'billing_scheme' => 'tiered',
        ]);

        $perUnitPrice = ProductPrice::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => get_class($product),
            'unit_amount' => 50.00,
            'currency' => 'USD',
            'billing_scheme' => 'per_unit',
        ]);

        $this->assertEquals(BillingScheme::TIERED, $tieredPrice->billing_scheme);
        $this->assertEquals(BillingScheme::PER_UNIT, $perUnitPrice->billing_scheme);
    }
}
