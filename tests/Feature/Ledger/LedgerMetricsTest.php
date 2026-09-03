<?php

namespace Blax\Shop\Tests\Feature\Ledger;

use Blax\Shop\Facades\Shop;
use Blax\Shop\Models\StripeTransaction;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * Covers the Stripe ledger stat primitives: net-of-fees daily rollup, window
 * totals, earliest-record anchor, and the revenue-type filter.
 *
 * The point of the ledger is that it is user-independent — none of these rows
 * reference a user, so a deleted customer never removes their money history.
 */
class LedgerMetricsTest extends TestCase
{
    use RefreshDatabase;

    private function txn(array $attrs): StripeTransaction
    {
        return StripeTransaction::create(array_merge([
            'stripe_id' => 'txn_' . bin2hex(random_bytes(6)),
            'source_type' => 'charge',
            'currency' => 'eur',
            'created' => Carbon::parse('2025-10-01'),
        ], $attrs));
    }

    #[Test]
    public function net_is_gross_minus_fees_and_refunds_per_day(): void
    {
        // Two charges and a refund on the same day.
        $this->txn(['amount' => 2550, 'fee' => 104, 'net' => 2446, 'created' => Carbon::parse('2025-11-05 09:00')]);
        $this->txn(['amount' => 8700, 'fee' => 281, 'net' => 8419, 'created' => Carbon::parse('2025-11-05 14:00')]);
        $this->txn(['source_type' => 'refund', 'amount' => -2550, 'fee' => 0, 'net' => -2550, 'created' => Carbon::parse('2025-11-05 18:00')]);

        $days = Shop::revenueLedgerByDay(Carbon::parse('2025-11-01'), Carbon::parse('2025-11-30'));

        $this->assertCount(1, $days);
        $row = $days->first();
        $this->assertSame('2025-11-05', $row->date);
        $this->assertSame(2550 + 8700 - 2550, $row->gross);
        $this->assertSame(104 + 281, $row->fee);
        $this->assertSame(2446 + 8419 - 2550, $row->net);
        $this->assertSame(3, $row->count);
    }

    #[Test]
    public function totals_split_gross_refunds_fees_and_net(): void
    {
        $this->txn(['amount' => 2550, 'fee' => 104, 'net' => 2446]);
        $this->txn(['amount' => 4900, 'fee' => 172, 'net' => 4728]);
        $this->txn(['source_type' => 'refund', 'amount' => -2550, 'fee' => 0, 'net' => -2550]);

        $totals = Shop::ledgerTotals(Carbon::parse('2025-01-01'), Carbon::parse('2026-12-31'));

        $this->assertSame(2550 + 4900, $totals['gross']);
        $this->assertSame(-2550, $totals['refunds']);
        $this->assertSame(104 + 172, $totals['fees']);
        $this->assertSame(2446 + 4728 - 2550, $totals['net']);
        $this->assertSame(3, $totals['count']);
    }

    #[Test]
    public function payouts_and_transfers_are_excluded_from_revenue(): void
    {
        $this->txn(['amount' => 5000, 'fee' => 175, 'net' => 4825]);
        // A payout moves already-counted money to the bank — not revenue.
        $this->txn(['source_type' => 'payout', 'amount' => -4825, 'fee' => 0, 'net' => -4825]);

        $totals = Shop::ledgerTotals(Carbon::parse('2025-01-01'), Carbon::parse('2026-12-31'));

        $this->assertSame(4825, $totals['net'], 'payout must not drag net down');
        $this->assertSame(1, $totals['count']);
    }

    #[Test]
    public function earliest_returns_the_first_created_timestamp(): void
    {
        $this->assertNull(Shop::ledgerEarliest());

        $this->txn(['amount' => 1000, 'net' => 1000, 'created' => Carbon::parse('2025-10-15')]);
        $this->txn(['amount' => 1000, 'net' => 1000, 'created' => Carbon::parse('2025-10-03')]);

        $this->assertSame('2025-10-03', Shop::ledgerEarliest()->toDateString());
    }
}
