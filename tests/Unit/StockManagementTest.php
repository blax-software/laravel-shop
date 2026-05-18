<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_detects_low_stock()
    {
        $product = Product::factory()->withStocks(5)->create([
            'manage_stock' => true,
            'low_stock_threshold' => 10,
        ]);

        $this->assertTrue($product->isLowStock());
    }

    #[Test]
    public function it_detects_sufficient_stock()
    {
        $product = Product::factory()->withStocks(50)->create([
            'low_stock_threshold' => 10,
        ]);

        $this->assertFalse($product->isLowStock());
    }

    #[Test]
    public function it_marks_product_as_out_of_stock()
    {
        // manage_stock + no ledger entries IS the out-of-stock state under
        // the ledger-only model — there are no `in_stock` / `stock_status`
        // columns to fall back on anymore.
        $product = Product::factory()->create([
            'manage_stock' => true,
        ]);

        $this->assertFalse($product->isInStock());
        $this->assertSame(0, $product->getAvailableStock());
    }

    #[Test]
    public function products_without_stock_management_are_always_in_stock()
    {
        $product = Product::factory()->create([
            'manage_stock' => false,
        ]);

        // When stock management is disabled, product should be considered in stock
        $this->assertFalse($product->manage_stock);
    }
}
