<?php

namespace Blax\Shop\Tests\Feature\Ledger;

use Blax\Shop\Facades\Shop;
use Blax\Shop\Models\StripeTransaction;
use Blax\Shop\Services\ShopService;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * The VAT (`tax`) inside each ledger row comes from Stripe per charge: the
 * charge's invoice (pro-rated by the cash captured), else the Checkout Session
 * of its payment intent. Refunds carry the refunded share, negative.
 *
 * Stripe is faked through the container-bound `\Stripe\StripeClient`; plain
 * objects stand in for stripe-php's (see LedgerMetricsTest for why).
 */
class LedgerTaxTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A StripeClient stand-in answering `invoices|charges|refunds->retrieve($id)`
     * and `checkout->sessions->all(['payment_intent' => …])` from `$responses`
     * (`'invoices.retrieve' => [id => object|Throwable]`, `'sessions.all' =>
     * [pi => session]`). Every call lands in `->calls`.
     */
    private function fakeStripe(array $responses = []): object
    {
        $fake = new class($responses)
        {
            public array $calls = [];

            public object $invoices;

            public object $charges;

            public object $refunds;

            public object $checkout;

            public function __construct(public array $responses)
            {
                $make = fn (string $name) => new class($this, $name)
                {
                    public function __construct(private object $outer, private string $name) {}

                    public function retrieve($id, $params = null, $opts = null)
                    {
                        return $this->outer->answer("{$this->name}.retrieve", (string) $id);
                    }

                    public function all($params = null, $opts = null)
                    {
                        return $this->outer->answer("{$this->name}.all", (string) ($params['payment_intent'] ?? ''));
                    }
                };

                $this->invoices = $make('invoices');
                $this->charges = $make('charges');
                $this->refunds = $make('refunds');
                $this->checkout = (object) ['sessions' => $make('sessions')];
            }

            public function answer(string $op, string $key)
            {
                $this->calls[] = "{$op}:{$key}";
                $answer = $this->responses[$op][$key] ?? null;

                if ($answer instanceof \Throwable) {
                    throw $answer;
                }
                if ($op === 'sessions.all') {
                    return (object) ['data' => $answer ? [$answer] : []];
                }
                if ($answer === null) {
                    throw new \RuntimeException("No such object: {$key}");
                }

                return $answer;
            }
        };

        $this->app->instance(\Stripe\StripeClient::class, $fake);

        return $fake;
    }

    /** DE 19 % inclusive: 23.80 gross holds 3.80 VAT. */
    private function invoice(string $id = 'in_1', int $total = 2380, ?int $tax = 380, array $extra = []): object
    {
        return (object) array_merge(['id' => $id, 'object' => 'invoice', 'total' => $total, 'tax' => $tax], $extra);
    }

    private function charge(string $id, int $captured, $invoice = null, ?string $paymentIntent = null): object
    {
        return (object) [
            'id' => $id, 'object' => 'charge', 'amount' => $captured, 'amount_captured' => $captured,
            'currency' => 'eur', 'customer' => 'cus_T', 'invoice' => $invoice, 'payment_intent' => $paymentIntent,
        ];
    }

    private function chargeTxn(string $id, object|string $source, int $amount, string $type = 'charge'): object
    {
        return (object) [
            'id' => $id, 'type' => $type, 'amount' => $amount, 'fee' => 50, 'net' => $amount - 50,
            'currency' => 'eur', 'created' => strtotime('2026-10-05'), 'source' => $source,
        ];
    }

    private function refundTxn(string $id, object|string $source, int $amount, string $type = 'refund'): object
    {
        return (object) [
            'id' => $id, 'type' => $type, 'amount' => $amount, 'fee' => 0, 'net' => $amount,
            'currency' => 'eur', 'created' => strtotime('2026-10-06'), 'source' => $source,
        ];
    }

    private function row(string $stripeId): StripeTransaction
    {
        return StripeTransaction::where('stripe_id', $stripeId)->firstOrFail();
    }

    #[Test]
    public function invoice_vat_is_the_charge_share_of_the_invoice(): void
    {
        $fake = $this->fakeStripe();

        // The webhook expands the invoice on the charge: no extra call.
        Shop::recordBalanceTransaction($this->chargeTxn('txn_1', $this->charge('ch_1', 2380, $this->invoice()), 2380));

        // An invoice paid half from the customer's credit balance: only the
        // cash part's VAT counts.
        Shop::recordBalanceTransaction($this->chargeTxn('txn_2', $this->charge('ch_2', 1190, $this->invoice('in_2')), 1190));

        // Reverse charge / untaxed: Stripe says 0, so 0 (never a flat 19/119).
        Shop::recordBalanceTransaction($this->chargeTxn('txn_3', $this->charge('ch_3', 2000, $this->invoice('in_3', 2000, null, ['total_tax_amounts' => []])), 2000));

        $this->assertSame(380, $this->row('txn_1')->tax);
        $this->assertSame('invoice', $this->row('txn_1')->meta->tax_source);
        $this->assertSame(190, $this->row('txn_2')->tax);
        $this->assertSame(0, $this->row('txn_3')->tax);
        $this->assertSame([], $fake->calls);
    }

    #[Test]
    public function an_invoice_id_is_retrieved_once_and_total_tax_amounts_count_when_tax_is_absent(): void
    {
        $fake = $this->fakeStripe(['invoices.retrieve' => [
            'in_9' => (object) ['id' => 'in_9', 'total' => 4760, 'total_tax_amounts' => [
                (object) ['amount' => 760, 'inclusive' => true],
            ]],
        ]]);

        // Importer shape: the expanded charge carries the invoice id only. Two
        // partial charges of one invoice share the lookup.
        Shop::recordBalanceTransaction($this->chargeTxn('txn_a', $this->charge('ch_a', 2380, 'in_9'), 2380));
        Shop::recordBalanceTransaction($this->chargeTxn('txn_b', $this->charge('ch_b', 2380, 'in_9'), 2380));

        $this->assertSame(380, $this->row('txn_a')->tax);
        $this->assertSame(380, $this->row('txn_b')->tax);
        $this->assertSame(['invoices.retrieve:in_9'], $fake->calls);
    }

    #[Test]
    public function a_one_off_checkout_takes_the_vat_from_its_checkout_session(): void
    {
        $fake = $this->fakeStripe(['sessions.all' => [
            'pi_once' => (object) ['id' => 'cs_1', 'amount_total' => 4990, 'total_details' => (object) ['amount_tax' => 797]],
        ]]);

        Shop::recordBalanceTransaction($this->chargeTxn('txn_cs', $this->charge('ch_cs', 4990, null, 'pi_once'), 4990));

        $row = $this->row('txn_cs');
        $this->assertSame(797, $row->tax);
        $this->assertSame('checkout_session', $row->meta->tax_source);
        $this->assertSame('pi_once', $row->meta->payment_intent);
        $this->assertSame(['sessions.all:pi_once'], $fake->calls);
    }

    #[Test]
    public function a_payment_settled_in_paypal_gets_vat_on_the_charge_gross(): void
    {
        $this->fakeStripe(['sessions.all' => [
            'pi_pp' => (object) ['amount_total' => 2040, 'total_details' => (object) ['amount_tax' => 326]],
        ]]);

        // Stripe books amount 0; the ledger books the charge gross and its VAT.
        Shop::recordBalanceTransaction((object) [
            'id' => 'txn_pp', 'type' => 'payment', 'amount' => 0, 'fee' => 14, 'net' => -14,
            'currency' => 'eur', 'created' => strtotime('2026-10-05'),
            'source' => (object) ['id' => 'py_pp', 'object' => 'charge', 'amount' => 2040, 'amount_captured' => 2040,
                'currency' => 'eur', 'invoice' => null, 'payment_intent' => 'pi_pp'],
        ]);

        $this->assertSame(2040, $this->row('txn_pp')->amount);
        $this->assertSame(326, $this->row('txn_pp')->tax);
    }

    #[Test]
    public function a_refund_carries_the_refunded_share_of_the_charge_vat_negated(): void
    {
        $fake = $this->fakeStripe();
        $charge = $this->charge('ch_r', 2380, $this->invoice(), 'pi_r');

        // Webhook shape: the refund's source is the retrieved charge.
        Shop::recordBalanceTransaction($this->chargeTxn('txn_c', $charge, 2380), $charge);
        Shop::recordBalanceTransaction($this->refundTxn('txn_r1', $charge, -1000), $charge);

        // Importer shape, in a fresh process: the refund knows its charge by id
        // and the VAT comes off the charge's ledger row, without a Stripe call.
        $refund = (object) ['id' => 're_2', 'object' => 'refund', 'amount' => 1380, 'charge' => 'ch_r', 'payment_intent' => 'pi_r'];
        (new ShopService)->recordBalanceTransaction($this->refundTxn('txn_r2', $refund, -1380));

        $this->assertSame(380, $this->row('txn_c')->tax);
        $this->assertSame(-160, $this->row('txn_r1')->tax); // 1000 × 380 / 2380 = 159.7
        $this->assertSame(-220, $this->row('txn_r2')->tax); // 1380 × 380 / 2380 = 220.3
        $this->assertSame([], $fake->calls);
    }

    #[Test]
    public function a_refund_imported_before_its_charge_costs_one_call_for_both(): void
    {
        // The importer pages newest first, so a refund comes before its charge.
        $fake = $this->fakeStripe(['charges.retrieve' => [
            'ch_n' => $this->charge('ch_n', 2380, $this->invoice('in_n'), 'pi_n'),
        ]]);

        $refund = (object) ['id' => 're_n', 'object' => 'refund', 'amount' => 2380, 'charge' => 'ch_n', 'payment_intent' => 'pi_n'];
        Shop::recordBalanceTransaction($this->refundTxn('txn_rn', $refund, -2380));
        Shop::recordBalanceTransaction($this->chargeTxn('txn_cn', $this->charge('ch_n', 2380, 'in_n', 'pi_n'), 2380));

        $this->assertSame(-380, $this->row('txn_rn')->tax);
        $this->assertSame(380, $this->row('txn_cn')->tax);
        $this->assertSame(['charges.retrieve:ch_n'], $fake->calls);
    }

    #[Test]
    public function a_bounced_direct_debit_takes_its_vat_back(): void
    {
        $this->fakeStripe();
        $charge = $this->charge('py_sepa', 2380, $this->invoice('in_sepa'));

        Shop::recordBalanceTransaction($this->chargeTxn('txn_sepa', $charge, 2380, 'payment'), $charge);
        Shop::recordBalanceTransaction((object) [
            'id' => 'txn_bounce', 'type' => 'payment_failure_refund', 'amount' => -2380, 'fee' => 315, 'net' => -2695,
            'currency' => 'eur', 'created' => strtotime('2026-10-09'),
        ], $charge);

        $this->assertSame(380, $this->row('txn_sepa')->tax);
        $this->assertSame(-380, $this->row('txn_bounce')->tax);
    }

    #[Test]
    public function a_failed_lookup_leaves_tax_null_and_still_records_the_row(): void
    {
        $fake = $this->fakeStripe(['invoices.retrieve' => ['in_x' => new \RuntimeException('Stripe is down')]]);

        $this->assertSame('created', Shop::recordBalanceTransaction($this->chargeTxn('txn_x', $this->charge('ch_x', 2380, 'in_x'), 2380)));

        $row = $this->row('txn_x');
        $this->assertNull($row->tax);
        $this->assertSame(2380, $row->amount);
        $this->assertSame(2330, $row->net);
        $this->assertObjectNotHasProperty('tax_source', $row->meta);

        // The next import, with Stripe back, fills it.
        $fake->responses['invoices.retrieve']['in_x'] = $this->invoice('in_x');
        (new ShopService)->recordBalanceTransaction($this->chargeTxn('txn_x', $this->charge('ch_x', 2380, 'in_x'), 2380));
        $this->assertSame(380, $this->row('txn_x')->tax);
    }

    #[Test]
    public function without_stripe_credentials_the_lookup_fails_quietly(): void
    {
        // No bound client and no secret: stripeClient() throws, the row stays.
        Shop::recordBalanceTransaction($this->chargeTxn('txn_ns', $this->charge('ch_ns', 2380, 'in_ns'), 2380));

        $this->assertNull($this->row('txn_ns')->tax);
        $this->assertSame(2380, $this->row('txn_ns')->amount);
    }

    #[Test]
    public function a_known_vat_is_kept_on_reimport_without_a_stripe_call(): void
    {
        $fake = $this->fakeStripe(['invoices.retrieve' => ['in_k' => $this->invoice('in_k')]]);
        $txn = $this->chargeTxn('txn_k', $this->charge('ch_k', 2380, 'in_k'), 2380);

        Shop::recordBalanceTransaction($txn);
        (new ShopService)->recordBalanceTransaction($txn);

        $this->assertSame(380, $this->row('txn_k')->tax);
        $this->assertSame(['invoices.retrieve:in_k'], $fake->calls);
    }

    #[Test]
    public function a_charge_stripe_holds_no_vat_for_is_marked_and_not_asked_again(): void
    {
        $fake = $this->fakeStripe(); // no session for pi_none
        $txn = $this->chargeTxn('txn_none', $this->charge('ch_none', 1500, null, 'pi_none'), 1500);

        Shop::recordBalanceTransaction($txn);
        (new ShopService)->recordBalanceTransaction($txn);

        $row = $this->row('txn_none');
        $this->assertNull($row->tax);
        $this->assertSame('none', $row->meta->tax_source);
        $this->assertSame(['sessions.all:pi_none'], $fake->calls);
    }

    #[Test]
    public function fees_and_payouts_carry_no_vat(): void
    {
        $fake = $this->fakeStripe();

        Shop::recordBalanceTransaction((object) [
            'id' => 'txn_fee', 'type' => 'stripe_fee', 'amount' => -14, 'fee' => 0, 'net' => -14,
            'currency' => 'eur', 'created' => strtotime('2026-10-05'),
        ]);
        Shop::recordBalanceTransaction((object) [
            'id' => 'txn_po', 'type' => 'payout', 'amount' => -5000, 'fee' => 0, 'net' => -5000,
            'currency' => 'eur', 'created' => strtotime('2026-10-05'), 'source' => 'po_1',
        ]);

        $this->assertNull($this->row('txn_fee')->tax);
        $this->assertNull($this->row('txn_po')->tax);
        $this->assertSame([], $fake->calls);
    }

    #[Test]
    public function sync_ledger_for_charge_expands_the_invoice_and_books_charge_and_refund_vat(): void
    {
        config(['services.stripe.secret' => 'sk_test_fake']);
        $fake = $this->fakeStripe();

        $charge = $this->charge('ch_sync', 2380, $this->invoice('in_sync'), 'pi_sync');
        $charge->balance_transaction = (object) [
            'id' => 'txn_sync', 'type' => 'charge', 'amount' => 2380, 'fee' => 50, 'net' => 2330,
            'currency' => 'eur', 'created' => strtotime('2026-10-05'),
        ];
        $charge->refunds = (object) ['data' => [(object) ['id' => 're_sync', 'amount' => 1190, 'balance_transaction' => (object) [
            'id' => 'txn_sync_r', 'type' => 'refund', 'amount' => -1190, 'fee' => 0, 'net' => -1190,
            'currency' => 'eur', 'created' => strtotime('2026-10-06'),
        ]]]];

        $service = new class($charge) extends ShopService
        {
            public array $retrieved = [];

            public function __construct(private object $fixture) {}

            protected function retrieveLedgerSyncCharge(string $chargeId): object
            {
                $this->retrieved[] = $chargeId;

                return $this->fixture;
            }
        };

        $this->assertSame(2, $service->syncLedgerForCharge('ch_sync'));
        $this->assertSame(['ch_sync'], $service->retrieved);
        $this->assertSame(380, $this->row('txn_sync')->tax);
        $this->assertSame(-190, $this->row('txn_sync_r')->tax);
        $this->assertSame([], $fake->calls);
    }

    #[Test]
    public function aggregates_sum_the_signed_vat_and_count_unknown_as_zero(): void
    {
        $make = fn (array $a) => StripeTransaction::create(array_merge([
            'stripe_id' => 'txn_'.bin2hex(random_bytes(6)), 'source_type' => 'charge', 'currency' => 'eur',
            'customer_id' => 'cus_A', 'created' => Carbon::parse('2026-10-05 10:00'),
        ], $a));

        $make(['amount' => 2380, 'fee' => 50, 'net' => 2330, 'tax' => 380]);
        $make(['amount' => 4990, 'fee' => 90, 'net' => 4900, 'tax' => 797]);
        $make(['source_type' => 'refund', 'amount' => -1190, 'net' => -1190, 'tax' => -190]);
        $make(['amount' => 1000, 'fee' => 30, 'net' => 970, 'tax' => null]); // unknown
        $make(['source_type' => 'stripe_fee', 'amount' => 0, 'fee' => 14, 'net' => -14, 'customer_id' => null]);
        $make(['source_type' => 'payout', 'amount' => -5000, 'net' => -5000, 'tax' => 999, 'customer_id' => null]); // not revenue

        $from = Carbon::parse('2026-10-01');
        $until = Carbon::parse('2026-10-31');
        $vat = 380 + 797 - 190;

        $totals = Shop::ledgerTotals($from, $until);
        $this->assertSame($vat, $totals['tax']);
        $this->assertSame(['gross', 'refunds', 'fees', 'net', 'count', 'tax'], array_keys($totals));

        $day = Shop::revenueLedgerByDay($from, $until)->first();
        $this->assertSame($vat, $day->tax);
        $this->assertSame($totals['net'], $day->net);

        $byAccount = Shop::ledgerTotalsByAccount($from, $until);
        $this->assertSame($vat, $byAccount->sum('tax'));
        $this->assertSame('stripe', $byAccount->first()['provider']);

        $this->assertSame($vat, Shop::customerLedgerTotals('cus_A')['tax']);
        $this->assertSame(0, Shop::customerLedgerTotals([], [])['tax']);
        $this->assertSame(0, Shop::ledgerTotals(Carbon::parse('2020-01-01'), Carbon::parse('2020-01-02'))['tax']);
    }

    #[Test]
    public function backfill_fills_missing_vat_read_only_and_idempotently(): void
    {
        config(['services.stripe.secret' => 'sk_test_fake']);
        $fake = $this->fakeStripe([
            'charges.retrieve' => [
                'ch_b1' => $this->charge('ch_b1', 2380, $this->invoice('in_b1'), 'pi_b1'),
                'ch_b2' => $this->charge('ch_b2', 1190, $this->invoice('in_b2'), 'pi_b2'), // half paid from credit
                'ch_b3' => $this->charge('ch_b3', 1500, null, 'pi_b3'),                    // Stripe holds no VAT
                'ch_bx' => new \RuntimeException('Stripe is down'),
            ],
        ]);

        $make = fn (array $a) => StripeTransaction::create(array_merge([
            'stripe_id' => 'txn_'.bin2hex(random_bytes(6)), 'source_type' => 'charge', 'currency' => 'eur',
            'created' => Carbon::parse('2026-10-05'), 'fee' => 0,
        ], $a));

        $make(['source_id' => 'ch_b1', 'amount' => 2380, 'net' => 2380, 'meta' => ['payment_intent' => 'pi_b1']]);
        $make(['source_id' => 'ch_b2', 'amount' => 1190, 'net' => 1190, 'meta' => ['payment_intent' => 'pi_b2']]);
        $make(['source_id' => 'ch_b3', 'amount' => 1500, 'net' => 1500, 'meta' => ['payment_intent' => 'pi_b3']]);
        $make(['source_id' => 'ch_bx', 'amount' => 999, 'net' => 999]);
        // An importer refund row: known by its re_ id and payment intent only.
        $make(['source_type' => 'refund', 'source_id' => 're_b1', 'amount' => -1190, 'net' => -1190, 'meta' => ['payment_intent' => 'pi_b1']]);
        // Already known, not a charge share, or not Stripe's: left alone.
        $make(['source_id' => 'ch_done', 'amount' => 2380, 'net' => 2380, 'tax' => 380]);
        $make(['source_type' => 'payout', 'source_id' => 'po_1', 'amount' => -5000, 'net' => -5000]);
        $make(['provider' => 'paypal', 'source_type' => 'payment', 'source_id' => 'PP-1', 'amount' => 3000, 'net' => 2900]);

        // Dry run: totals, no writes.
        $dry = Shop::backfillLedgerTax(true);
        $this->assertSame(['scanned' => 5, 'filled' => 3, 'tax' => 380 + 190 - 190, 'none' => 1, 'failed' => 1], $dry);
        $this->assertSame(1, StripeTransaction::whereNotNull('tax')->count());
        // Reads only, one per charge; the refund reuses its charge's VAT.
        $this->assertEqualsCanonicalizing([
            'charges.retrieve:ch_b1', 'charges.retrieve:ch_b2', 'charges.retrieve:ch_b3',
            'sessions.all:pi_b3', 'charges.retrieve:ch_bx',
        ], $fake->calls);

        $this->artisan('shop:import-stripe-ledger', ['--backfill-tax' => true])
            ->assertSuccessful();

        $this->assertSame(380, StripeTransaction::where('source_id', 'ch_b1')->value('tax'));
        $this->assertSame(190, StripeTransaction::where('source_id', 'ch_b2')->value('tax'));
        $this->assertNull(StripeTransaction::where('source_id', 'ch_b3')->value('tax'));
        $this->assertSame('none', StripeTransaction::where('source_id', 'ch_b3')->firstOrFail()->meta->tax_source);
        $this->assertNull(StripeTransaction::where('source_id', 'ch_bx')->value('tax'));
        $this->assertSame(-190, StripeTransaction::where('source_id', 're_b1')->value('tax'));
        $this->assertNull(StripeTransaction::where('source_id', 'po_1')->value('tax'));
        $this->assertNull(StripeTransaction::where('source_id', 'PP-1')->value('tax'));

        // Again: only the failed row is tried; the "none" row only with recheck.
        $fake->calls = [];
        $again = (new ShopService)->backfillLedgerTax();
        $this->assertSame(['scanned' => 1, 'filled' => 0, 'tax' => 0, 'none' => 0, 'failed' => 1], $again);
        $this->assertSame(['charges.retrieve:ch_bx'], $fake->calls);

        $recheck = (new ShopService)->backfillLedgerTax(false, null, true);
        $this->assertSame(2, $recheck['scanned']);
        $this->assertSame(1, $recheck['none']);
    }

    #[Test]
    public function before_the_migration_rows_save_without_tax_and_totals_report_zero(): void
    {
        // A consumer that publishes migrations may update the package first.
        \Illuminate\Support\Facades\Schema::table('stripe_transactions', fn ($t) => $t->dropColumn('tax'));
        $fake = $this->fakeStripe();
        $txn = $this->chargeTxn('txn_old', $this->charge('ch_old', 2380, $this->invoice()), 2380);

        $this->assertArrayNotHasKey('tax', Shop::ledgerRowFromBalanceTransaction($txn));
        $this->assertSame('created', Shop::recordBalanceTransaction($txn));
        $this->assertSame('updated', Shop::recordBalanceTransaction($txn));

        $totals = Shop::ledgerTotals(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $this->assertSame(2380, $totals['gross']);
        $this->assertSame(0, $totals['tax']);
        $this->assertSame(0, Shop::revenueLedgerByDay(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))->first()->tax);
        $this->assertSame(0, Shop::ledgerTotalsByAccount(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'))->first()['tax']);
        $this->assertSame(0, Shop::customerLedgerTotals('cus_T')['tax']);
        $this->assertSame(['scanned' => 0, 'filled' => 0, 'tax' => 0, 'none' => 0, 'failed' => 0], Shop::backfillLedgerTax());
        $this->assertSame([], $fake->calls);
    }

    #[Test]
    public function backfill_dry_run_command_prints_totals_and_writes_nothing(): void
    {
        config(['services.stripe.secret' => 'sk_test_fake']);
        $this->fakeStripe(['charges.retrieve' => ['ch_d' => $this->charge('ch_d', 2380, $this->invoice('in_d'))]]);

        StripeTransaction::create([
            'stripe_id' => 'txn_d', 'source_type' => 'charge', 'source_id' => 'ch_d', 'amount' => 2380, 'fee' => 0,
            'net' => 2380, 'currency' => 'eur', 'created' => Carbon::parse('2026-10-05'),
        ]);

        $this->artisan('shop:import-stripe-ledger', ['--backfill-tax' => true, '--dry-run' => true])
            ->expectsOutputToContain('[dry-run] would fill VAT for 1 of 1 rows without it: 3.80 in total')
            ->assertSuccessful();

        $this->assertNull($this->row('txn_d')->tax);
    }
}
