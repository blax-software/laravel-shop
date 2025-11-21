<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_detects_low_stock()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 5,
            'low_stock_threshold' => 10,
        ]);

        $this->assertTrue($product->isLowStock());
    }

    /** @test */
    public function it_detects_sufficient_stock()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 50,
            'low_stock_threshold' => 10,
        ]);

        $this->assertFalse($product->isLowStock());
    }

    /** @test */
    public function it_marks_product_as_out_of_stock()
    {
        $product = Product::factory()->create([
            'manage_stock' => true,
            'stock_quantity' => 0,
            'in_stock' => false,
            'stock_status' => 'outofstock',
        ]);

        $this->assertFalse($product->in_stock);
        $this->assertEquals('outofstock', $product->stock_status);
    }

    /** @test */
    public function products_without_stock_management_are_always_in_stock()
    {
        $product = Product::factory()->create([
            'manage_stock' => false,
            'stock_quantity' => 0,
        ]);

        // When stock management is disabled, product should be considered in stock
        $this->assertFalse($product->manage_stock);
    }
}
