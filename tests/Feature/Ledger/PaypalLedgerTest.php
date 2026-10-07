<?php

namespace Blax\Shop\Tests\Feature\Ledger;

use Blax\Shop\Facades\Shop;
use Blax\Shop\Models\StripeTransaction;
use Blax\Shop\Services\PaymentProvider\PaypalLedgerService;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;

/**
 * PayPal checkouts that run through Stripe settle into our PayPal account:
 * Stripe's ledger row carries the gross and Stripe's fee, the PayPal account
 * mirror adds PayPal's own fee. Shapes below are trimmed real Transaction
 * Search responses.
 */
class PaypalLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const ACCOUNT = 'PPMERCHANT1';

    private function pp(string $id, string $code, string $amount, ?string $fee = null, array $extra = []): array
    {
        return ['transaction_info' => array_filter(array_merge([
            'transaction_id' => $id,
            'transaction_event_code' => $code,
            'transaction_initiation_date' => '2026-09-20T16:01:50Z',
            'transaction_amount' => ['currency_code' => 'EUR', 'value' => $amount],
            'fee_amount' => $fee === null ? null : ['currency_code' => 'EUR', 'value' => $fee],
            'transaction_status' => 'S',
        ], $extra), fn ($v) => $v !== null)];
    }

    private function viaStripe(string $stripeId): array
    {
        return ['custom_field' => "acct_1QlaslLPtmuUEnJP:{$stripeId}:5f21ce203ae409b5"];
    }

    /** Stripe's own row for a PayPal payment that settled in PayPal (after 8aa727f). */
    private function stripePaypalRow(string $pi, int $gross, int $stripeFee, string $customer): void
    {
        StripeTransaction::create([
            'stripe_id' => 'txn_' . substr($pi, 3),
            'source_type' => 'payment',
            'source_id' => 'py_' . substr($pi, 3),
            'amount' => $gross,
            'fee' => $stripeFee,
            'net' => $gross - $stripeFee,
            'currency' => 'eur',
            'customer_id' => $customer,
            'customer_email' => 'buyer@x.io',
            'created' => Carbon::parse('2026-09-20 16:01:50'),
            'meta' => ['settled_outside_stripe' => true, 'payment_intent' => $pi],
        ]);
    }

    private function import(array $transactions): void
    {
        $service = app(PaypalLedgerService::class);
        foreach ($service->ledgerRows($transactions, self::ACCOUNT) as $row) {
            $service->record($row);
        }
    }

    private function totals(): array
    {
        return Shop::ledgerTotals(Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'));
    }

    #[Test]
    public function paypal_fee_of_a_stripe_payment_lowers_net_but_not_gross(): void
    {
        $this->stripePaypalRow('pi_A', 2040, 14, 'cus_A');

        $this->import([$this->pp('3BV48659EV009350B', 'T0003', '20.40', '-1.00', $this->viaStripe('pi_A'))]);

        $row = StripeTransaction::where('stripe_id', '3BV48659EV009350B')->firstOrFail();
        $this->assertSame('paypal', $row->provider);
        $this->assertSame(self::ACCOUNT, $row->account);
        $this->assertSame('paypal_fee', $row->source_type);
        $this->assertSame(0, $row->amount);
        $this->assertSame(100, $row->fee);
        $this->assertSame(-100, $row->net);
        $this->assertSame(2040, $row->meta->paypal_amount);
        $this->assertSame('cus_A', $row->customer_id);

        $totals = $this->totals();
        $this->assertSame(2040, $totals['gross']);
        $this->assertSame(14 + 100, $totals['fees']);
        $this->assertSame(2040 - 14 - 100, $totals['net']);

        // The buyer's amount is unchanged; their net carries both fees.
        $buyer = Shop::customerLedgerTotals('cus_A');
        $this->assertSame(2040, $buyer['amount']);
        $this->assertSame(2040 - 114, $buyer['net']);
    }

    #[Test]
    public function payments_swept_into_stripe_are_not_charged_twice(): void
    {
        // Nov 2025 shape: Stripe's row already includes PayPal's 0.47 as a
        // payment_method_passthrough_fee, and PayPal swept the money on.
        StripeTransaction::create([
            'stripe_id' => 'txn_swept', 'source_type' => 'payment', 'source_id' => 'py_swept',
            'amount' => 280, 'fee' => 58, 'net' => 222, 'currency' => 'eur', 'created' => Carbon::parse('2026-09-20'),
        ]);
        StripeTransaction::create([
            'stripe_id' => 'txn_swept_refund', 'source_type' => 'payment_refund', 'source_id' => 'pyr_1',
            'amount' => -280, 'fee' => -8, 'net' => -272, 'currency' => 'eur', 'created' => Carbon::parse('2026-09-20'),
        ]);

        $ref = ['paypal_reference_id' => 'PAY1', 'paypal_reference_id_type' => 'TXN'];
        $this->import([
            $this->pp('PAY1', 'T0003', '2.80', '-0.47', $this->viaStripe('pi_S')),
            $this->pp('HOLD1', 'T5000', '-2.33', null, $this->viaStripe('pi_S') + $ref),
            $this->pp('REL1', 'T5001', '2.33', null, $this->viaStripe('pi_S') + $ref),
            $this->pp('SWEEP1', 'T2002', '-2.33', null, $this->viaStripe('pi_S') + $ref),
            $this->pp('REF1', 'T1107', '-2.80', '0.08', $this->viaStripe('pyr_1') + $ref),
            $this->pp('SWEEP2', 'T2002', '2.72', null, $this->viaStripe('pyr_1') + $ref),
        ]);

        $this->assertSame('paypal_fee_passthrough', StripeTransaction::where('stripe_id', 'PAY1')->value('source_type'));
        $this->assertSame('paypal_fee_passthrough', StripeTransaction::where('stripe_id', 'REF1')->value('source_type'));
        $this->assertSame('transfer', StripeTransaction::where('stripe_id', 'SWEEP1')->value('source_type'));

        // Only Stripe's rows count: 2.80 - 2.80 gross, 0.58 - 0.08 fees.
        $totals = $this->totals();
        $this->assertSame(280, $totals['gross']);
        $this->assertSame(-280, $totals['refunds']);
        $this->assertSame(50, $totals['fees']);
        $this->assertSame(-50, $totals['net']);
    }

    #[Test]
    public function refund_settled_in_paypal_returns_part_of_the_fee(): void
    {
        $this->import([
            $this->pp('PAY2', 'T0003', '19.00', '-0.96', $this->viaStripe('pi_R')),
            $this->pp('REF2', 'T1107', '-19.00', '0.57', $this->viaStripe('pyr_2') + ['paypal_reference_id' => 'PAY2', 'paypal_reference_id_type' => 'TXN']),
        ]);

        $refund = StripeTransaction::where('stripe_id', 'REF2')->firstOrFail();
        $this->assertSame('paypal_fee', $refund->source_type);
        $this->assertSame(0, $refund->amount);
        $this->assertSame(-57, $refund->fee);
        $this->assertSame(57, $refund->net);
        $this->assertSame(-39, $this->totals()['net']);
    }

    #[Test]
    public function direct_paypal_sales_count_in_full_and_withdrawals_do_not(): void
    {
        $this->import([
            $this->pp('DIRECT1', 'T0006', '50.00', '-2.00', ['transaction_subject' => 'Gift voucher']),
            $this->pp('DREF1', 'T1107', '-10.00', '0.20', ['paypal_reference_id' => 'DIRECT1', 'paypal_reference_id_type' => 'TXN']),
            $this->pp('ACCFEE', 'T0106', '-1.50'),
            $this->pp('OUT1', 'T0400', '-30.00'),
            $this->pp('SENT1', 'T0001', '-12.00'),
            $this->pp('PEND1', 'T0006', '9.00', '-0.50', ['transaction_status' => 'P']),
        ]);

        $types = StripeTransaction::pluck('source_type', 'stripe_id');
        $this->assertSame('payment', $types['DIRECT1']);
        $this->assertSame('payment_refund', $types['DREF1']);
        $this->assertSame('paypal_fee', $types['ACCFEE']);
        $this->assertSame('payout', $types['OUT1']);
        $this->assertSame('payment_sent', $types['SENT1']);
        $this->assertSame('pending', $types['PEND1']);

        $totals = $this->totals();
        $this->assertSame(5000, $totals['gross']);
        $this->assertSame(-1000, $totals['refunds']);
        $this->assertSame(200 - 20 + 150, $totals['fees']);
        $this->assertSame(5000 - 1000 - 330, $totals['net']);
    }

    #[Test]
    public function totals_split_per_account_and_sum_to_the_whole(): void
    {
        $this->stripePaypalRow('pi_A', 2040, 14, 'cus_A');
        StripeTransaction::create([
            'stripe_id' => 'txn_card', 'source_type' => 'charge', 'amount' => 5000, 'fee' => 100, 'net' => 4900,
            'currency' => 'eur', 'created' => Carbon::parse('2026-09-21'),
        ]);
        $this->import([$this->pp('PAYA', 'T0003', '20.40', '-1.00', $this->viaStripe('pi_A'))]);

        $from = Carbon::parse('2026-01-01');
        $until = Carbon::parse('2026-12-31');
        $split = Shop::ledgerTotalsByAccount($from, $until)->keyBy('provider');

        $this->assertSame(7040, $split['stripe']['gross']);
        $this->assertSame(7040 - 114, $split['stripe']['net']);
        $this->assertSame(self::ACCOUNT, $split['paypal']['account']);
        $this->assertSame(-100, $split['paypal']['net']);
        $this->assertSame(Shop::ledgerTotals($from, $until)['net'], $split->sum('net'));
    }

    #[Test]
    public function stripe_rows_remember_their_payment_intent(): void
    {
        Shop::recordBalanceTransaction((object) [
            'id' => 'txn_pi', 'type' => 'payment', 'amount' => 0, 'fee' => 14, 'net' => -14,
            'currency' => 'eur', 'created' => 1730000000,
            'source' => (object) ['id' => 'py_pi', 'object' => 'charge', 'amount' => 2040, 'payment_intent' => 'pi_X', 'customer' => 'cus_X'],
        ]);

        $row = StripeTransaction::where('stripe_id', 'txn_pi')->firstOrFail();
        $this->assertSame('pi_X', $row->meta->payment_intent);
        $this->assertSame('stripe', $row->fresh()->provider);
    }

    #[Test]
    public function command_imports_each_account_read_only_and_is_idempotent(): void
    {
        config()->set('shop.paypal.accounts', [
            ['name' => 'main', 'client_id' => 'id-main', 'secret' => 'secret-main'],
            ['name' => 'unset', 'client_id' => null, 'secret' => null],
        ]);
        $this->stripePaypalRow('pi_A', 2040, 14, 'cus_A');

        Http::fake([
            '*/v1/oauth2/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            '*/v1/reporting/balances*' => Http::response([
                'account_id' => self::ACCOUNT,
                'last_refresh_time' => now()->subHours(2)->utc()->format('Y-m-d\TH:i:s\Z'),
                'balances' => [],
            ]),
            '*/v1/reporting/transactions*' => function ($request) {
                // One transaction, served only by the window that contains it.
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $start = Carbon::parse($query['start_date']);
                $end = Carbon::parse($query['end_date']);
                $at = Carbon::parse('2026-09-20T16:01:50Z');

                return Http::response([
                    'transaction_details' => $at->between($start, $end)
                        ? [$this->pp('3BV48659EV009350B', 'T0003', '20.40', '-1.00', $this->viaStripe('pi_A'))]
                        : [],
                    'total_pages' => 1,
                ]);
            },
        ]);

        $this->travelTo(Carbon::parse('2026-10-07 12:00'));

        $this->artisan('shop:import-paypal-ledger', ['--since' => '2026-09-01'])->assertSuccessful();
        $this->artisan('shop:import-paypal-ledger')->assertSuccessful();

        $this->assertSame(1, StripeTransaction::where('provider', 'paypal')->count());
        $this->assertSame(-100, (int) StripeTransaction::where('provider', 'paypal')->sum('net'));

        Http::assertNotSent(fn ($request) => $request->method() !== 'GET' && ! str_ends_with($request->url(), '/v1/oauth2/token'));
    }

    #[Test]
    public function a_required_import_throws_when_unconfigured_or_failing(): void
    {
        config()->set('shop.paypal.required', true);
        config()->set('shop.paypal.accounts', [['name' => 'default', 'client_id' => null, 'secret' => null]]);

        try {
            $this->artisan('shop:import-paypal-ledger')->run();
            $this->fail('an unconfigured required import must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('PAYPAL_CLIENT_ID', $e->getMessage());
        }

        config()->set('shop.paypal.accounts', [['name' => 'main', 'client_id' => 'id', 'secret' => 'bad']]);
        Http::fake(['*/v1/oauth2/token' => Http::response(['error' => 'invalid_client', 'error_description' => 'Client Authentication failed'], 401)]);

        try {
            $this->artisan('shop:import-paypal-ledger')->run();
            $this->fail('a failing required import must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Client Authentication failed', $e->getMessage());
        }
    }

    #[Test]
    public function command_without_accounts_is_a_no_op(): void
    {
        config()->set('shop.paypal.accounts', [['name' => 'default', 'client_id' => null, 'secret' => null]]);
        Http::fake();

        $this->artisan('shop:import-paypal-ledger')->assertSuccessful();

        Http::assertNothingSent();
    }
}
