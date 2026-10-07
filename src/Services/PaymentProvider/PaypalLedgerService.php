<?php

declare(strict_types=1);

namespace Blax\Shop\Services\PaymentProvider;

use Blax\Shop\Models\StripeTransaction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors PayPal accounts into the money ledger (the `stripe_transactions`
 * table, rows with `provider` = paypal), read-only through PayPal's
 * Transaction Search reporting API.
 *
 * Why: PayPal checkouts that run through Stripe settle into our PayPal account.
 * Stripe's ledger row books the buyer's gross and Stripe's fee, but PayPal
 * takes its own fee inside the PayPal account, which Stripe never sees. So for
 * every Stripe-originated PayPal payment or refund this books one `paypal_fee`
 * row (amount 0, fee = PayPal's fee, net = -fee). Summing the ledger then
 * gives gross once and both fees.
 *
 * When PayPal sweeps a Stripe payment straight on to Stripe (a T20xx transfer
 * on the same transaction), Stripe already charged PayPal's fee as a
 * `payment_method_passthrough_fee` inside its own fee, so that row is booked
 * as `paypal_fee_passthrough`, which is not a revenue type and is not counted
 * twice.
 *
 * Direct PayPal sales (no Stripe reference) are booked in full: `payment`,
 * `payment_refund`, `dispute`, account fees as `paypal_fee`. Holds, sweeps,
 * currency conversions and withdrawals move money that is already counted and
 * use non-revenue types (`transfer`, `payout`, `payment_sent`, `pending`).
 */
class PaypalLedgerService
{
    /** PayPal's Transaction Search allows at most 31 days per request. */
    protected const WINDOW_DAYS = 31;

    /** @var array<string, array{token: string, expires: int}> */
    protected array $tokens = [];

    /**
     * Configured accounts that have credentials.
     *
     * @return list<array{name: string, client_id: string, secret: string, base_url: string}>
     */
    public function accounts(): array
    {
        $base = (string) config('shop.paypal.base_url', 'https://api-m.paypal.com');
        $accounts = [];

        foreach ((array) config('shop.paypal.accounts', []) as $i => $account) {
            $id = $account['client_id'] ?? null;
            $secret = $account['secret'] ?? null;
            if (! is_string($id) || $id === '' || ! is_string($secret) || $secret === '') {
                continue;
            }

            $accounts[] = [
                'name' => (string) ($account['name'] ?? "paypal-{$i}"),
                'client_id' => $id,
                'secret' => $secret,
                'base_url' => rtrim((string) ($account['base_url'] ?? $base), '/'),
            ];
        }

        return $accounts;
    }

    /**
     * Balances per currency plus the merchant `account_id` and PayPal's
     * `last_refresh_time` (reporting data lags real time by up to ~3 hours).
     *
     * @param  array{name: string, client_id: string, secret: string, base_url: string}  $account
     * @return array<string, mixed>
     */
    public function balances(array $account): array
    {
        return $this->get($account, '/v1/reporting/balances', [
            'as_of_time' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * Every transaction on the account between two instants, oldest window
     * first. Splits the range into 31-day windows and follows pagination.
     *
     * @param  array{name: string, client_id: string, secret: string, base_url: string}  $account
     * @return list<array<string, mixed>>
     */
    public function transactions(array $account, \DateTimeInterface $from, \DateTimeInterface $until): array
    {
        $all = [];
        $start = Carbon::instance($from)->utc();
        $until = Carbon::instance($until)->utc();

        while ($start->lt($until)) {
            $end = $start->copy()->addDays(self::WINDOW_DAYS)->subSecond()->min($until);
            $page = 1;

            do {
                $body = $this->get($account, '/v1/reporting/transactions', [
                    'start_date' => $start->format('Y-m-d\TH:i:s\Z'),
                    'end_date' => $end->format('Y-m-d\TH:i:s\Z'),
                    'fields' => 'transaction_info,payer_info',
                    'page_size' => 500,
                    'page' => $page,
                ]);

                foreach ((array) ($body['transaction_details'] ?? []) as $t) {
                    $all[] = $t;
                }

                $pages = (int) ($body['total_pages'] ?? 1);
                $page++;
            } while ($page <= $pages);

            $start = $end->copy()->addSecond();
        }

        return $all;
    }

    /**
     * Turn a batch of PayPal `transaction_details` into ledger rows. The batch
     * is classified as a whole, because whether a Stripe payment was swept on
     * to Stripe is told by a separate transfer row on the same transaction.
     *
     * @param  list<array<string, mixed>>  $transactions
     * @return list<array<string, mixed>>
     */
    public function ledgerRows(array $transactions, ?string $account): array
    {
        $swept = [];
        foreach ($transactions as $t) {
            $info = (array) ($t['transaction_info'] ?? []);
            if (str_starts_with((string) ($info['transaction_event_code'] ?? ''), 'T20') && $this->stripeReference($info)) {
                $swept[$this->groupKey($info)] = true;
            }
        }

        $rows = [];
        foreach ($transactions as $t) {
            if ($row = $this->ledgerRow($t, $account, $swept)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Idempotently upsert one PayPal ledger row (key = PayPal transaction id),
     * filling the buyer from the Stripe ledger when the payment came through
     * Stripe. Never downgrades a known buyer back to null. Returns 'created'
     * or 'updated'.
     *
     * @param  array<string, mixed>  $row
     */
    public function record(array $row): string
    {
        $model = $this->ledgerModel();
        $row = $this->attributeBuyer($row);
        $existing = $model::query()->where('stripe_id', $row['stripe_id'])->first();

        if ($existing) {
            $row['customer_id'] ??= $existing->customer_id;
            $row['customer_email'] ??= $existing->customer_email;
            $existing->fill($row)->save();

            return 'updated';
        }

        try {
            $model::query()->create($row);
        } catch (\Illuminate\Database\QueryException $e) {
            // A concurrent import (manual run during the scheduled one) inserted
            // the same transaction first: update that row instead.
            $existing = $model::query()->where('stripe_id', $row['stripe_id'])->first();
            if (! $existing) {
                throw $e;
            }
            $existing->fill($row)->save();

            return 'updated';
        }

        return 'created';
    }

    /**
     * Fetch and record one account's transactions. Stops at PayPal's last
     * reporting refresh, so the window never asks for data PayPal has not
     * published yet.
     *
     * @param  array{name: string, client_id: string, secret: string, base_url: string}  $account
     * @return array{account: string, seen: int, created: int, updated: int, rows: list<array<string, mixed>>}
     */
    public function import(array $account, \DateTimeInterface $from, bool $dryRun = false): array
    {
        $balances = $this->balances($account);
        $accountId = (string) ($balances['account_id'] ?? $account['name']);
        $until = isset($balances['last_refresh_time'])
            ? Carbon::parse($balances['last_refresh_time'])->min(now())
            : now()->subHours(3);

        $rows = $this->ledgerRows($this->transactions($account, $from, $until), $accountId);
        $created = 0;
        $updated = 0;

        foreach ($rows as $row) {
            if ($dryRun) {
                $this->ledgerModel()::query()->where('stripe_id', $row['stripe_id'])->exists() ? $updated++ : $created++;

                continue;
            }

            $this->record($row) === 'created' ? $created++ : $updated++;
        }

        return ['account' => $accountId, 'seen' => count($rows), 'created' => $created, 'updated' => $updated, 'rows' => $rows];
    }

    /** Where an incremental import of this account should start. */
    public function resumeFrom(?string $accountId, int $overlapDays = 14): Carbon
    {
        // PayPal keeps three years of history; that is the first run's reach.
        $floor = now()->subYears(3)->addDay()->startOfDay();

        if (! $accountId || ! $this->hasProviderColumns()) {
            return $floor;
        }

        $latest = $this->ledgerModel()::query()
            ->where('provider', 'paypal')
            ->where('account', $accountId)
            ->max('created');

        return $latest ? Carbon::parse($latest)->subDays($overlapDays)->max($floor) : $floor;
    }

    public function hasProviderColumns(): bool
    {
        $table = (new ($this->ledgerModel()))->getTable();

        return Schema::hasColumn($table, 'provider') && Schema::hasColumn($table, 'account');
    }

    /**
     * @param  array<string, mixed>  $t
     * @param  array<string, bool>  $swept
     * @return array<string, mixed>|null
     */
    protected function ledgerRow(array $t, ?string $account, array $swept): ?array
    {
        $info = (array) ($t['transaction_info'] ?? []);
        $id = $info['transaction_id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        $code = (string) ($info['transaction_event_code'] ?? '');
        $group = substr($code, 0, 3);
        $amount = $this->cents($info['transaction_amount']['value'] ?? 0);
        // PayPal signs the fee from the balance's view (-0.47 charged, +0.08
        // returned on a refund); the ledger stores the fee as a cost.
        $paypalFee = $this->cents($info['fee_amount']['value'] ?? 0);
        $fee = -$paypalFee;
        $stripeRef = $this->stripeReference($info);
        $status = $info['transaction_status'] ?? null;

        $meta = array_filter([
            'event_code' => $code,
            'status' => $status,
            'paypal_amount' => $amount,
            'paypal_fee' => $paypalFee,
            'reference_id' => $info['paypal_reference_id'] ?? null,
            'stripe_reference' => $stripeRef,
            'subject' => $info['transaction_subject'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if ($status !== 'S') {
            // Pending, denied or reversed: not money yet. Re-classified on a
            // later import once PayPal settles it.
            $type = 'pending';
        } elseif ($stripeRef && (($group === 'T00' && $amount > 0) || $group === 'T11')) {
            // Stripe books the gross (and the refund); PayPal adds only its fee.
            $type = isset($swept[$this->groupKey($info)]) ? 'paypal_fee_passthrough' : 'paypal_fee';
            $amount = 0;
        } elseif ($stripeRef) {
            $type = 'transfer';
        } else {
            [$type, $amount, $fee] = match (true) {
                $group === 'T00' && $amount > 0 => ['payment', $amount, $fee],
                $group === 'T00' => ['payment_sent', $amount, $fee],
                $group === 'T11' => ['payment_refund', $amount, $fee],
                $group === 'T12' => ['dispute', $amount, $fee],
                // Account fees arrive as the transaction amount itself.
                $group === 'T01' => ['paypal_fee', 0, $fee - $amount],
                $group === 'T04' => ['payout', $amount, $fee],
                default => ['transfer', $amount, $fee],
            };
        }

        $created = isset($info['transaction_initiation_date']) ? Carbon::parse($info['transaction_initiation_date']) : null;
        $payer = (array) ($t['payer_info'] ?? []);

        return [
            'stripe_id' => $id,
            'provider' => 'paypal',
            'account' => $account,
            'source_type' => $type,
            'reporting_category' => $code ?: null,
            'source_id' => $stripeRef ?? $id,
            'amount' => $amount,
            'fee' => $fee,
            'net' => $amount - $fee,
            'currency' => isset($info['transaction_amount']['currency_code']) ? strtolower((string) $info['transaction_amount']['currency_code']) : null,
            'customer_id' => null,
            'customer_email' => is_string($payer['email_address'] ?? null) ? $payer['email_address'] : null,
            'description' => isset($info['transaction_subject']) ? mb_substr((string) $info['transaction_subject'], 0, 255) : null,
            'created' => $created,
            'available_on' => $created,
            'meta' => $meta,
        ];
    }

    /**
     * Copy the buyer from the Stripe side: the Stripe charge row for a payment
     * (matched on its payment intent), else the PayPal payment a refund refers
     * to. The PayPal payer email stays as the fallback.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function attributeBuyer(array $row): array
    {
        $ref = $row['meta']['stripe_reference'] ?? null;
        if (! $ref) {
            return $row;
        }

        $model = $this->ledgerModel();
        $match = null;

        if (str_starts_with($ref, 'pi_')) {
            $match = $model::query()->where('meta->payment_intent', $ref)->whereNotNull('customer_id')->first();
        }

        if (! $match && ($origin = $row['meta']['reference_id'] ?? null)) {
            $match = $model::query()->where('stripe_id', $origin)->whereNotNull('customer_id')->first();
        }

        if ($match) {
            $row['customer_id'] = $match->customer_id;
            $row['customer_email'] = $match->customer_email ?? $row['customer_email'];
        }

        return $row;
    }

    /**
     * The Stripe object a PayPal transaction belongs to. Stripe writes
     * `acct_…:pi_…:…` (payments) or `acct_…:pyr_…:…:refund` into PayPal's
     * custom field.
     *
     * @param  array<string, mixed>  $info
     */
    protected function stripeReference(array $info): ?string
    {
        $custom = (string) ($info['custom_field'] ?? '');

        return preg_match('/^acct_[A-Za-z0-9]+:((?:pi|py|ch|pyr|re)_[A-Za-z0-9]+)/', $custom, $m) ? $m[1] : null;
    }

    /**
     * The PayPal payment a row belongs to: refunds, holds and transfers point
     * at it through `paypal_reference_id`; the payment itself is its own id.
     *
     * @param  array<string, mixed>  $info
     */
    protected function groupKey(array $info): string
    {
        return ($info['paypal_reference_id_type'] ?? null) === 'TXN' && ! empty($info['paypal_reference_id'])
            ? (string) $info['paypal_reference_id']
            : (string) ($info['transaction_id'] ?? '');
    }

    protected function cents(mixed $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /**
     * @param  array{name: string, client_id: string, secret: string, base_url: string}  $account
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function get(array $account, string $path, array $query = []): array
    {
        $response = Http::withToken($this->token($account))
            ->acceptJson()
            ->timeout(30)
            ->retry(2, 1000, throw: false)
            ->get($account['base_url'] . $path, $query);

        if ($response->failed()) {
            throw new \RuntimeException(sprintf(
                'PayPal %s failed for account "%s": %s %s',
                $path,
                $account['name'],
                $response->status(),
                (string) ($response->json('message') ?? $response->json('error_description') ?? '')
            ));
        }

        return (array) $response->json();
    }

    /** @param  array{name: string, client_id: string, secret: string, base_url: string}  $account */
    protected function token(array $account): string
    {
        $cached = $this->tokens[$account['client_id']] ?? null;
        if ($cached && $cached['expires'] > time() + 60) {
            return $cached['token'];
        }

        $response = Http::asForm()
            ->withBasicAuth($account['client_id'], $account['secret'])
            ->acceptJson()
            ->timeout(30)
            ->post($account['base_url'] . '/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        $token = $response->json('access_token');
        if ($response->failed() || ! is_string($token)) {
            throw new \RuntimeException(sprintf(
                'PayPal authentication failed for account "%s": %s',
                $account['name'],
                (string) ($response->json('error_description') ?? $response->status())
            ));
        }

        $this->tokens[$account['client_id']] = ['token' => $token, 'expires' => time() + (int) ($response->json('expires_in') ?? 300)];

        return $token;
    }

    /** @return class-string<\Illuminate\Database\Eloquent\Model> */
    protected function ledgerModel(): string
    {
        return config('shop.models.stripe_transaction', StripeTransaction::class);
    }
}
