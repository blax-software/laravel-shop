<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Type-safety regression cover for stock helpers.
 *
 * Past bugs that this suite is here to prevent from sneaking back in:
 *
 *   - `abs()` called on a PDO-returned numeric string under strict_types,
 *     blowing up with "Argument #1 must be of type int|float, string given".
 *   - `(float)` / `(bool)` casts collapsing 0 / null / empty string into
 *     incorrect signals (e.g. "0 stock" → false, but `null` → false too,
 *     hiding a missing-config bug).
 *   - `isInStock()` / `getAvailableStock()` returning the wrong type
 *     (string instead of int/bool) after a column rename.
 *
 * Every assertion below pins down a *return type* in addition to a value
 * so a future change that returns the right number-shaped string will
 * still fail loudly.
 */
class StockTypeSafetyTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Return-type contracts
    // ------------------------------------------------------------------

    #[Test]
    public function get_available_stock_returns_int(): void
    {
        $product = Product::factory()->withStocks(10)->create();

        $value = $product->getAvailableStock();
        $this->assertIsInt($value, 'getAvailableStock must return int (PDO sum returns string)');
        $this->assertSame(10, $value);
    }

    #[Test]
    public function get_available_stock_returns_int_max_when_stock_unmanaged(): void
    {
        $product = Product::factory()->create(['manage_stock' => false]);

        $value = $product->getAvailableStock();
        $this->assertIsInt($value);
        $this->assertSame(PHP_INT_MAX, $value);
    }

    #[Test]
    public function get_available_stock_never_returns_negative_under_overclaim(): void
    {
        $product = Product::factory()->withStocks(2)->create();

        // Overclaim past available — `max(0, ...)` must keep the result ≥ 0.
        $product->stocks()->create([
            'quantity' => -5,                         // claim 5 of 2
            'type' => StockType::CLAIMED,
            'status' => StockStatus::PENDING,
            'claimed_from' => Carbon::now()->subHour(),
            'expires_at' => Carbon::now()->addHour(),
        ]);

        $value = $product->getAvailableStock();
        $this->assertIsInt($value);
        $this->assertGreaterThanOrEqual(0, $value, 'Must never be negative — UI/cart math depends on this');
    }

    #[Test]
    public function is_in_stock_returns_strict_bool(): void
    {
        $stocked = Product::factory()->withStocks(1)->create();
        $depleted = Product::factory()->create(['manage_stock' => true]); // no ledger
        $unlimited = Product::factory()->create(['manage_stock' => false]);

        // Strict type — not truthy/falsy, must be the literal bool values.
        $this->assertSame(true, $stocked->isInStock());
        $this->assertSame(false, $depleted->isInStock());
        $this->assertSame(true, $unlimited->isInStock());
    }

    // ------------------------------------------------------------------
    // abs() boundary — claim sums come back as negative numbers, all
    // claim-related getters must report them as positive integers.
    // ------------------------------------------------------------------

    #[Test]
    public function get_currently_claimed_stock_returns_positive_int(): void
    {
        $product = Product::factory()->withStocks(10)->create();

        // Two active claims totalling -7 in the raw sum.
        foreach ([-3, -4] as $qty) {
            $product->stocks()->create([
                'quantity' => $qty,
                'type' => StockType::CLAIMED,
                'status' => StockStatus::PENDING,
                'claimed_from' => Carbon::now()->subHour(),
                'expires_at' => Carbon::now()->addHour(),
            ]);
        }

        $value = $product->getCurrentlyClaimedStock();
        $this->assertIsInt($value, 'abs() boundary — must return int, not numeric string');
        $this->assertSame(7, $value, 'abs() must flip the sign — claims store negative quantities');
    }

    #[Test]
    public function get_currently_claimed_stock_ignores_future_only_claims(): void
    {
        $product = Product::factory()->withStocks(10)->create();

        // Future-only claim — getCurrentlyClaimedStock filters on
        // `claimed_from <= now()` so this row must NOT contribute.
        $product->stocks()->create([
            'quantity' => -5,
            'type' => StockType::CLAIMED,
            'status' => StockStatus::PENDING,
            'claimed_from' => Carbon::now()->addDays(7),
            'expires_at' => Carbon::now()->addDays(10),
        ]);

        $this->assertSame(0, $product->getCurrentlyClaimedStock());
    }

    #[Test]
    public function get_active_and_planned_claimed_stock_includes_future_claims(): void
    {
        $product = Product::factory()->withStocks(10)->create();

        $product->stocks()->create([
            'quantity' => -5,
            'type' => StockType::CLAIMED,
            'status' => StockStatus::PENDING,
            'claimed_from' => Carbon::now()->addDays(7),
            'expires_at' => Carbon::now()->addDays(10),
        ]);

        $value = $product->getActiveAndPlannedClaimedStock();
        $this->assertIsInt($value);
        $this->assertSame(5, $value);
    }

    #[Test]
    public function get_future_claimed_stock_returns_positive_int(): void
    {
        $product = Product::factory()->withStocks(10)->create();

        $product->stocks()->create([
            'quantity' => -2,
            'type' => StockType::CLAIMED,
            'status' => StockStatus::PENDING,
            'claimed_from' => Carbon::now()->addDays(7),
            'expires_at' => Carbon::now()->addDays(10),
        ]);

        $value = $product->getFutureClaimedStock();
        $this->assertIsInt($value);
        $this->assertSame(2, $value);
    }

    // ------------------------------------------------------------------
    // Composite helper — physical_stock is "available + currently claimed"
    // and historically blew up because the two terms ended up as
    // (int + string), producing a string concatenation.
    // ------------------------------------------------------------------

    #[Test]
    public function physical_stock_returns_int_sum_of_available_and_claimed(): void
    {
        $product = Product::factory()->withStocks(10)->create();

        // Use the canonical claim API — it creates both the CLAIMED PENDING
        // row AND the paired DECREASE COMPLETED row that subtracts from
        // baseStock. Without the paired DECREASE the available count
        // wouldn't drop, so this test also indirectly verifies that
        // `claimStock()` keeps the ledger internally consistent.
        $product->claimStock(
            quantity: 4,
            from: Carbon::now()->subHour(),
            until: Carbon::now()->addHour(),
            note: 'Type-safety test claim',
        );

        $this->assertSame(6, $product->getAvailableStock(), 'baseStock - paired DECREASE');
        $this->assertSame(4, $product->getCurrentlyClaimedStock(), 'abs() of negative claim sum');

        $value = $product->getPhysicalStock();
        $this->assertIsInt($value);
        $this->assertSame(10, $value, 'available (6) + claimed (4) = physical inventory (10)');
    }

    // ------------------------------------------------------------------
    // isAvailableForBooking — the consumer of all the above; surfaces
    // any sign / cast bug as a wrong availability decision.
    // ------------------------------------------------------------------

    #[Test]
    public function is_available_for_booking_returns_strict_bool(): void
    {
        $product = Product::factory()->withStocks(1)->create();

        $from = Carbon::now()->addDays(1);
        $until = Carbon::now()->addDays(3);

        $this->assertSame(true, $product->isAvailableForBooking($from, $until, 1));
        $this->assertSame(false, $product->isAvailableForBooking($from, $until, 2));
    }

    #[Test]
    public function is_available_for_booking_subtracts_overlapping_claims_correctly(): void
    {
        $product = Product::factory()->withStocks(2)->create();

        // Pre-existing claim taking 1 unit for days 1–3.
        $product->stocks()->create([
            'quantity' => -1,
            'type' => StockType::CLAIMED,
            'status' => StockStatus::PENDING,
            'claimed_from' => Carbon::now()->addDays(1),
            'expires_at' => Carbon::now()->addDays(3),
        ]);

        // 1 unit left for overlapping window — qty=1 OK, qty=2 NOT.
        $this->assertTrue(
            $product->isAvailableForBooking(Carbon::now()->addDays(2), Carbon::now()->addDays(4), 1)
        );
        $this->assertFalse(
            $product->isAvailableForBooking(Carbon::now()->addDays(2), Carbon::now()->addDays(4), 2)
        );
    }
}
