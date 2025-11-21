<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductPricingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_returns_regular_price_when_not_on_sale()
    {
        $product = Product::factory()->create([
            'regular_price' => 100,
            'sale_price' => null,
        ]);

        $this->assertEquals(100, $product->getCurrentPrice());
    }

    /** @test */
    public function it_returns_sale_price_when_on_sale()
    {
        $product = Product::factory()->create([
            'regular_price' => 100,
            'sale_price' => 80,
            'sale_start' => now()->subDay(),
            'sale_end' => now()->addDay(),
        ]);

        $this->assertEquals(80, $product->getCurrentPrice());
    }

    /** @test */
    public function it_returns_regular_price_when_sale_has_ended()
    {
        $product = Product::factory()->create([
            'regular_price' => 100,
            'sale_price' => 80,
            'sale_start' => now()->subDays(7),
            'sale_end' => now()->subDay(),
        ]);

        $this->assertEquals(100, $product->getCurrentPrice());
    }

    /** @test */
    public function it_returns_regular_price_when_sale_hasnt_started()
    {
        $product = Product::factory()->create([
            'regular_price' => 100,
            'sale_price' => 80,
            'sale_start' => now()->addDay(),
            'sale_end' => now()->addWeek(),
        ]);

        $this->assertEquals(100, $product->getCurrentPrice());
    }

    /** @test */
    public function it_calculates_discount_percentage()
    {
        $product = Product::factory()->create([
            'regular_price' => 100,
            'sale_price' => 75,
        ]);

        $discount = (($product->regular_price - $product->sale_price) / $product->regular_price) * 100;

        $this->assertEquals(25, $discount);
    }
}
