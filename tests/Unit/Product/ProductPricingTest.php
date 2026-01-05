<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;


class ProductPricingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_regular_price_when_not_on_sale()
    {
        $product = Product::factory()->withPrices(2, 10000)->create();

        $this->assertEquals(2, $product->prices()->count());
        $this->assertFalse($product->isOnSale());
        $this->assertNotNull($product->defaultPrice()->first());
        $this->assertEquals(10000, $product->getCurrentPrice());
    }

    #[Test]
    public function it_returns_sale_price_when_on_sale()
    {
        $product = Product::factory()
            ->withPrices(1, 10000)
            ->create([
                'sale_start' => now()->subDay(),
                'sale_end' => now()->addDay(),
            ]);

        $price = $product->prices()->first();
        $price->sale_unit_amount = 8000;

        $price->save();

        $this->assertEquals(8000, $product->getCurrentPrice());
    }

    #[Test]
    public function it_returns_regular_price_when_sale_has_ended()
    {
        $product = Product::factory()->withPrices(1, 10000)->create([
            'sale_start' => now()->subWeek(),
            'sale_end' => now()->addHour(),
        ]);

        $price = $product->prices()->first();
        $price->sale_unit_amount = 8000;
        $price->save();

        $this->assertEquals(8000, $product->getCurrentPrice());

        $product->update([
            'sale_end' => now()->subHour(),
        ]);

        $this->assertEquals(10000, $product->getCurrentPrice());
    }

    #[Test]
    public function it_returns_regular_price_when_sale_hasnt_started()
    {
        $product = Product::factory()->withPrices(1, 10000)->create([
            'sale_start' => now()->addDay(),
            'sale_end' => now()->addWeek(),
        ]);

        $price = $product->prices()->first();
        $price->sale_unit_amount = 8000;
        $price->save();

        $this->assertEquals(10000, $product->getCurrentPrice());

        $product->update([
            'sale_start' => now()->subHour(),
        ]);

        $this->assertEquals(8000, $product->getCurrentPrice());
    }
}
