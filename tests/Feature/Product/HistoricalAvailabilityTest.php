<?php

namespace Blax\Shop\Tests\Feature\Product;

use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regression coverage for the bug where stock ledger entries with no
 * `expires_at` were treated as if they had always existed — so a DECREASE
 * row created today retroactively reduced availability for every prior day.
 *
 * Real-world reproducer (from a librarian's report):
 *   1. Book seeded with 5 copies at the start of the month
 *   2. A member loans one copy on day 17
 *   3. `shop:stocks:availability` for May rendered 4 available for ALL 31
 *      days — including May 1–16, which is wrong because the loan didn't
 *      exist yet on those days.
 *
 * The fix scopes COMPLETED stock entries to their `created_at` boundary in
 * the historical-query paths ({@see HasStocks::calendarAvailability} and
 * {@see HasStocks::availableOnDate}): an entry only contributes to
 * availability on date $X if it was persisted on or before $X. The
 * day-level comparison (against end-of-day of $X) means a row seeded
 * mid-day still counts for queries on that same day.
 *
 * {@see HasStocks::getAvailableStock} is intentionally NOT gated — it's
 * the "current snapshot" API and is called by {@see ProductStock::claim}
 * with future/past booking dates, where the caller wants the live
 * physical inventory. CLAIMED entries already model both ends of their
 * window via `claimed_from` / `expires_at`, so this fix only touches the
 * INCREASE/DECREASE/RETURN code path.
 */
class HistoricalAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function newProduct(): Product
    {
        return Product::create([
            'name' => 'Hyperion',
            'sku' => 'HYP-HIST',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => true,
        ]);
    }

    #[Test]
    public function decrease_today_does_not_retroactively_reduce_yesterday(): void
    {
        // Day 1: seed inventory. Day 10: loan one copy.
        Carbon::setTestNow(Carbon::parse('2026-05-01 09:00:00'));
        $product = $this->newProduct();
        $product->increaseStock(5);

        Carbon::setTestNow(Carbon::parse('2026-05-10 13:32:00'));
        $product->decreaseStock(1);

        // Availability on day 5 (before the loan): still 5 copies.
        $this->assertSame(
            5,
            $product->availableOnDate(Carbon::parse('2026-05-05 12:00:00')),
            'past dates must not be affected by a later DECREASE',
        );

        // Availability on day 10 after the loan happened: 4.
        $this->assertSame(
            4,
            $product->availableOnDate(Carbon::parse('2026-05-10 14:00:00')),
            'present-day availability reflects the loan',
        );

        // Availability on day 20 (no further activity): still 4.
        $this->assertSame(
            4,
            $product->availableOnDate(Carbon::parse('2026-05-20 09:00:00')),
        );
    }

    #[Test]
    public function increase_today_does_not_retroactively_inflate_yesterday(): void
    {
        // Symmetric guard: stock added today shouldn't make yesterday richer.
        Carbon::setTestNow(Carbon::parse('2026-05-01 09:00:00'));
        $product = $this->newProduct();
        $product->increaseStock(2);

        Carbon::setTestNow(Carbon::parse('2026-05-15 10:00:00'));
        $product->increaseStock(3); // restock — three more copies arrived

        $this->assertSame(
            2,
            $product->availableOnDate(Carbon::parse('2026-05-10 12:00:00')),
            'past dates only see the initial 2 copies',
        );
        $this->assertSame(
            5,
            $product->availableOnDate(Carbon::parse('2026-05-20 12:00:00')),
            'after the restock, total is 5',
        );
    }

    #[Test]
    public function calendar_availability_reflects_the_actual_timeline(): void
    {
        // This is the user's reported scenario almost verbatim. The library
        // had its 5 copies long before the queried month, so we seed in April
        // — otherwise the INCREASE row's created_at would itself split day 1
        // (legitimately) and complicate the assertions.
        Carbon::setTestNow(Carbon::parse('2026-04-15 09:00:00'));
        $product = $this->newProduct();
        $product->increaseStock(5);

        // Loan on May 17 13:32 — exactly as the bug report described.
        Carbon::setTestNow(Carbon::parse('2026-05-17 13:32:00'));
        $product->decreaseStock(1);

        $calendar = $product->calendarAvailability(
            Carbon::parse('2026-05-01 00:00:00'),
            Carbon::parse('2026-05-31 23:59:59'),
        );

        $dates = $calendar['dates'];

        // Days 1–16 (before the loan): full availability.
        foreach (['2026-05-01', '2026-05-10', '2026-05-16'] as $day) {
            $this->assertSame(
                ['min' => 5, 'max' => 5],
                $dates[$day],
                "calendar must show 5 available on {$day} (before the loan)",
            );
        }

        // Day 17 (the loan day itself): the calendar uses day-level
        // granularity for the created_at gate, so a stock change anywhere
        // during the day counts at every event on that day. Result: 4 across
        // the board, even though the actual transition happened at 13:32.
        // This is a deliberate trade-off — instant-level granularity created
        // test-fixture chaos for any seed-then-query-now pattern, since the
        // calendar's day boundaries always landed before the seed timestamp.
        $this->assertSame(
            ['min' => 4, 'max' => 4],
            $dates['2026-05-17'],
        );

        // Days 18–31 (loan still out, nothing returned): 4 available.
        foreach (['2026-05-18', '2026-05-25', '2026-05-31'] as $day) {
            $this->assertSame(
                ['min' => 4, 'max' => 4],
                $dates[$day],
                "calendar must show 4 available on {$day} (loan in effect)",
            );
        }
    }

    #[Test]
    public function calendar_summary_reports_the_correct_min_and_max(): void
    {
        // 5 copies seeded before the queried month, 1 loaned mid-month →
        // calendar should report max_available=5 (some days), min_available=4
        // (after the loan). Before the fix, both would have been 4 because
        // every day rendered 4-4.
        Carbon::setTestNow(Carbon::parse('2026-04-15 09:00:00'));
        $product = $this->newProduct();
        $product->increaseStock(5);

        Carbon::setTestNow(Carbon::parse('2026-05-17 13:32:00'));
        $product->decreaseStock(1);

        $calendar = $product->calendarAvailability(
            Carbon::parse('2026-05-01 00:00:00'),
            Carbon::parse('2026-05-31 23:59:59'),
        );

        $this->assertSame(5, $calendar['max_available'], 'peak availability for the month is 5');
        $this->assertSame(4, $calendar['min_available'], 'trough availability is 4 after the loan');
    }

    #[Test]
    public function available_on_date_in_the_future_anticipates_no_changes(): void
    {
        // Asking about a future date with no scheduled changes should return
        // current availability — the loan persists, so 4 stays 4.
        Carbon::setTestNow(Carbon::parse('2026-05-17 13:32:00'));
        $product = $this->newProduct();
        $product->increaseStock(5);
        $product->decreaseStock(1);

        $this->assertSame(
            4,
            $product->availableOnDate(Carbon::parse('2026-06-15 12:00:00')),
        );
    }
}
