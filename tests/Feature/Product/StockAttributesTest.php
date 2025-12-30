<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class StockAttributesTest extends TestCase
{
    use RefreshDatabase;

    // ========================================
    // getAvailableStocksAttribute Tests
    // ========================================

    #[Test]
    public function available_stocks_returns_sum_of_completed_stock_entries()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        // Add some stock increases
        $product->increaseStock(50);
        $product->increaseStock(30);

        $this->assertEquals(80, $product->available_stocks);
    }

    #[Test]
    public function available_stocks_accounts_for_decreases()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(100);
        $product->decreaseStock(25);

        $this->assertEquals(75, $product->available_stocks);
    }

    #[Test]
    public function available_stocks_returns_zero_when_no_stock_entries()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $this->assertEquals(0, $product->available_stocks);
    }

    #[Test]
    public function available_stocks_reduced_by_claims()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(100);

        // Create a claim - this reduces available stock
        $product->claimStock(
            quantity: 20,
            reference: null,
            from: now(),
            until: now()->addDays(5)
        );

        // available_stocks should show 80 (100 - 20 claimed)
        $this->assertEquals(80, $product->available_stocks);
    }

    #[Test]
    public function available_stocks_excludes_expired_stock_entries()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(100);

        // Create a decrease that has already expired (should not count)
        $product->stocks()->create([
            'quantity' => -30,
            'type' => StockType::DECREASE,
            'status' => StockStatus::COMPLETED,
            'expires_at' => now()->subDay(), // Expired yesterday
        ]);

        // The expired decrease should not be counted
        $this->assertEquals(100, $product->available_stocks);
    }

    #[Test]
    public function available_stocks_includes_non_expired_stock_entries()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(100);

        // Create a decrease that will expire in the future (should count)
        $product->stocks()->create([
            'quantity' => -30,
            'type' => StockType::DECREASE,
            'status' => StockStatus::COMPLETED,
            'expires_at' => now()->addDays(5),
        ]);

        $this->assertEquals(70, $product->available_stocks);
    }

    #[Test]
    public function available_stocks_handles_multiple_increases_and_decreases()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(100);
        $product->increaseStock(50);
        $product->decreaseStock(20);
        $product->increaseStock(30);
        $product->decreaseStock(10);

        // 100 + 50 - 20 + 30 - 10 = 150
        $this->assertEquals(150, $product->available_stocks);
    }

    #[Test]
    public function available_stocks_excludes_pending_status_entries()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(100);

        // Create a PENDING entry (should not be counted in available_stocks)
        $product->stocks()->create([
            'quantity' => 50,
            'type' => StockType::INCREASE,
            'status' => StockStatus::PENDING,
        ]);

        // Only the COMPLETED entry should count
        $this->assertEquals(100, $product->available_stocks);
    }

    #[Test]
    public function available_stocks_includes_return_type_entries()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(100);
        $product->decreaseStock(30);

        // Create a return entry
        $product->stocks()->create([
            'quantity' => 15,
            'type' => StockType::RETURN,
            'status' => StockStatus::COMPLETED,
        ]);

        // 100 - 30 + 15 = 85
        $this->assertEquals(85, $product->available_stocks);
    }

    // ========================================
    // getMaxStocksAttribute Tests
    // ========================================

    #[Test]
    public function max_stocks_returns_php_int_max_when_stock_management_disabled()
    {
        $product = Product::factory()->create(['manage_stock' => false]);

        $this->assertEquals(PHP_INT_MAX, $product->max_stocks);
    }

    #[Test]
    public function max_stocks_shows_total_capacity_ignoring_claims()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(100);

        // Create a claim - this should NOT reduce max_stocks
        $product->claimStock(
            quantity: 30,
            reference: null,
            from: now(),
            until: now()->addDays(5)
        );

        // max_stocks should still show 100 (as if no claims existed)
        $this->assertEquals(100, $product->max_stocks);
    }

    #[Test]
    public function max_stocks_returns_zero_when_no_stock_entries()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $this->assertEquals(0, $product->max_stocks);
    }

    #[Test]
    public function max_stocks_sums_only_increases_and_returns()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(100);
        $product->increaseStock(50);
        $product->decreaseStock(20); // This is ignored by max_stocks

        // max_stocks ignores DECREASE, so 100 + 50 = 150
        $this->assertEquals(150, $product->max_stocks);
    }

    #[Test]
    public function max_stocks_with_multiple_claims_still_shows_full_capacity()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(200);

        // Create multiple claims - these should NOT reduce max_stocks
        $product->claimStock(20, null, now(), now()->addDays(3));
        $product->claimStock(30, null, now()->addDays(5), now()->addDays(10));
        $product->claimStock(10, null, now()->addDays(1), now()->addDays(2));

        // max_stocks should still be 200 (as if no claims existed)
        $this->assertEquals(200, $product->max_stocks);
    }

    #[Test]
    public function max_stocks_includes_return_type_entries()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(100);
        $product->decreaseStock(50); // Ignored by max_stocks

        $product->stocks()->create([
            'quantity' => 25,
            'type' => StockType::RETURN,
            'status' => StockStatus::COMPLETED,
        ]);

        // max_stocks = INCREASE + RETURN, ignoring DECREASE
        // 100 + 25 = 125
        $this->assertEquals(125, $product->max_stocks);
    }

    // ========================================
    // Comparison Tests (available_stocks vs max_stocks)
    // ========================================

    #[Test]
    public function available_stocks_less_than_max_stocks_when_decreases_exist()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(100);
        $product->decreaseStock(20);

        // available_stocks accounts for DECREASE
        $this->assertEquals(80, $product->available_stocks);
        
        // max_stocks ignores DECREASE (shows ceiling/capacity)
        $this->assertEquals(100, $product->max_stocks);
    }

    #[Test]
    public function available_stocks_less_than_max_stocks_when_claims_exist()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(100);

        // Claim 30 units
        $product->claimStock(30, null, now(), now()->addDays(5));

        // available_stocks should be reduced by claims
        $this->assertEquals(70, $product->available_stocks);

        // max_stocks should show full capacity as if no claims
        $this->assertEquals(100, $product->max_stocks);

        // The difference is the claimed amount
        $this->assertEquals(30, $product->max_stocks - $product->available_stocks);
    }

    #[Test]
    public function available_stocks_restored_when_claims_released()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(100);

        // max_stocks starts at 100
        $this->assertEquals(100, $product->max_stocks);

        // Create a claim
        $claim = $product->claimStock(
            quantity: 25,
            reference: null,
            from: now(),
            until: now()->addDays(5)
        );

        // available_stocks is reduced by the claim
        $this->assertEquals(75, $product->available_stocks);
        // max_stocks stays at 100 (ignores claims)
        $this->assertEquals(100, $product->max_stocks);

        // Release the claim - this creates a RETURN entry
        if ($claim) {
            $claim->release();
        }
        $product->refresh();

        // After release:
        // - available_stocks is restored (the DECREASE from claim is offset by RETURN)
        $this->assertEquals(100, $product->available_stocks);
        // - max_stocks increases by the RETURN amount (100 + 25 = 125)
        // This is expected because RETURN entries are counted as capacity additions
        $this->assertEquals(125, $product->max_stocks);
    }
}
