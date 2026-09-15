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
    public function customer_totals_attribute_by_id_or_email(): void
    {
        // Two charges + a refund under one customer.
        $this->txn(['amount' => 2000, 'fee' => 69, 'net' => 1931, 'customer_id' => 'cus_A', 'customer_email' => 'a@x.io']);
        $this->txn(['amount' => 5000, 'fee' => 172, 'net' => 4828, 'customer_id' => 'cus_A', 'customer_email' => 'a@x.io']);
        $this->txn(['source_type' => 'refund', 'amount' => -2000, 'fee' => 0, 'net' => -2000, 'customer_id' => 'cus_A']);
        // A guest charge that carries only the email (no customer id yet).
        $this->txn(['amount' => 1500, 'fee' => 55, 'net' => 1445, 'customer_id' => null, 'customer_email' => 'a@x.io']);
        // Another customer's money must never leak in.
        $this->txn(['amount' => 9900, 'net' => 9560, 'customer_id' => 'cus_B', 'customer_email' => 'b@x.io']);

        // By customer id alone: the two cus_A charges minus the refund.
        $byId = Shop::customerLedgerTotals('cus_A');
        $this->assertSame(2000 + 5000 - 2000, $byId['amount']);
        $this->assertSame(2000 + 5000, $byId['gross']);
        $this->assertSame(-2000, $byId['refunds']);
        $this->assertSame(69 + 172, $byId['fees']);
        $this->assertSame(3, $byId['count']);

        // By id OR email: also pulls in the email-only guest charge.
        $byBoth = Shop::customerLedgerTotals('cus_A', 'a@x.io');
        $this->assertSame(2000 + 5000 - 2000 + 1500, $byBoth['amount']);
        $this->assertSame(4, $byBoth['count']);

        // No identity → zero, never a match-everything.
        $this->assertSame(0, Shop::customerLedgerTotals([], [])['amount']);
        $this->assertSame(0, Shop::customerLedgerTotals('', '')['count']);
    }

    #[Test]
    public function amount_by_customer_groups_and_omits_null_customer(): void
    {
        $this->txn(['amount' => 2000, 'net' => 1931, 'customer_id' => 'cus_A']);
        $this->txn(['amount' => 5000, 'net' => 4828, 'customer_id' => 'cus_A']);
        $this->txn(['amount' => 9900, 'net' => 9560, 'customer_id' => 'cus_B']);
        $this->txn(['amount' => 1500, 'net' => 1445, 'customer_id' => null]); // guest — omitted

        $map = Shop::ledgerAmountByCustomer();

        $this->assertSame(7000, $map['cus_A']);
        $this->assertSame(9900, $map['cus_B']);
        $this->assertSame(2, $map->count());
    }

    // Fixtures mirror what stripe-php hands us at runtime — the code reads
    // properties off the objects, so plain objects exercise it exactly. (Real
    // Stripe objects are avoided here: their constructFrom touches the global
    // object-type map, which is fragile under the full suite's autoload state.)
    #[Test]
    public function records_and_upserts_a_balance_transaction(): void
    {
        $txn = (object) [
            'id' => 'txn_rec1', 'type' => 'charge', 'amount' => 2000, 'fee' => 69, 'net' => 1931,
            'currency' => 'eur', 'created' => 1730000000,
            'source' => (object) ['id' => 'ch_rec1', 'customer' => 'cus_A', 'billing_details' => (object) ['email' => 'a@x.io']],
        ];

        $this->assertSame('created', Shop::recordBalanceTransaction($txn));
        $row = StripeTransaction::where('stripe_id', 'txn_rec1')->firstOrFail();
        $this->assertSame(2000, $row->amount);
        $this->assertSame('cus_A', $row->customer_id);
        $this->assertSame('a@x.io', $row->customer_email);
        $this->assertSame('ch_rec1', $row->source_id);

        // Re-recording the same txn upserts in place (no duplicate) and updates.
        $txn->net = 1900;
        $this->assertSame('updated', Shop::recordBalanceTransaction($txn));
        $this->assertSame(1, StripeTransaction::where('stripe_id', 'txn_rec1')->count());
        $this->assertSame(1900, StripeTransaction::where('stripe_id', 'txn_rec1')->value('net'));
    }

    #[Test]
    public function never_downgrades_a_known_customer_to_null(): void
    {
        Shop::recordBalanceTransaction((object) [
            'id' => 'txn_ref1', 'type' => 'refund', 'amount' => -2000, 'net' => -2000, 'currency' => 'eur', 'created' => 1730000000,
            'source' => (object) ['id' => 'ch_ref1', 'customer' => 'cus_B', 'receipt_email' => 'b@x.io'],
        ]);
        $this->assertSame('cus_B', StripeTransaction::where('stripe_id', 'txn_ref1')->value('customer_id'));

        // A later importer pass that can't resolve the buyer must NOT null it out.
        Shop::recordBalanceTransaction((object) [
            'id' => 'txn_ref1', 'type' => 'refund', 'amount' => -2000, 'net' => -2000, 'currency' => 'eur', 'created' => 1730000000,
            'source' => 're_orphan',
        ]);
        $this->assertSame('cus_B', StripeTransaction::where('stripe_id', 'txn_ref1')->value('customer_id'));
        $this->assertSame('b@x.io', StripeTransaction::where('stripe_id', 'txn_ref1')->value('customer_email'));
    }

    #[Test]
    public function sync_ledger_for_charge_ignores_non_charge_ids(): void
    {
        // Guard returns before any Stripe call for null / empty / non-ch_ ids.
        $this->assertSame(0, Shop::syncLedgerForCharge(null));
        $this->assertSame(0, Shop::syncLedgerForCharge(''));
        $this->assertSame(0, Shop::syncLedgerForCharge('pi_123'));
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
