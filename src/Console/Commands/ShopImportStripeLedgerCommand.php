<?php

declare(strict_types=1);

namespace Blax\Shop\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Stripe\Stripe;

/**
 * Mirror Stripe's BalanceTransaction ledger into the local `stripe_transactions`
 * table. Pages EVERY balance transaction on the account — globally, not per
 * customer — so a charge whose customer was deleted (or never existed locally)
 * is still recorded. Idempotent: upserts on the `txn_` id and preserves Stripe's
 * own `created`/`available_on` timestamps.
 *
 * Each charge-backed row also gets its VAT (`tax`) from Stripe, looked up once
 * per row. `--backfill-tax` skips the import and only fills `tax` on existing
 * charge, payment and refund rows that have none yet (read-only against Stripe).
 */
class ShopImportStripeLedgerCommand extends Command
{
    protected $signature = 'shop:import-stripe-ledger
                            {--dry-run : Report what would change, write nothing}
                            {--since= : Only import transactions created on/after this date (YYYY-MM-DD)}
                            {--limit=100 : Stripe page size (max 100)}
                            {--backfill-tax : Only fill the VAT (tax) of existing charge, payment and refund rows that have none yet}
                            {--recheck : With --backfill-tax, also retry rows Stripe held no VAT data for last time}';

    protected $description = 'Mirror all Stripe balance transactions into the local ledger (user-independent).';

    public function handle(): int
    {
        $secret = config('services.stripe.secret')
            ?: config('cashier.secret')
            ?: env('STRIPE_SECRET');

        if (! $secret) {
            $this->error('No Stripe secret key configured (services.stripe.secret / cashier.secret / STRIPE_SECRET).');

            return self::FAILURE;
        }

        Stripe::setApiKey($secret);

        $dry = (bool) $this->option('dry-run');
        $model = config('shop.models.stripe_transaction', \Blax\Shop\Models\StripeTransaction::class);

        if ($this->option('backfill-tax')) {
            return $this->backfillTax($dry);
        }

        $params = [
            'limit' => max(1, min(100, (int) $this->option('limit'))),
            'expand' => ['data.source'],
        ];
        if ($since = $this->option('since')) {
            $params['created'] = ['gte' => Carbon::parse($since)->startOfDay()->timestamp];
        }

        $created = 0;
        $updated = 0;
        $seen = 0;
        $netCents = 0;

        try {
            $iterator = \Stripe\BalanceTransaction::all($params)->autoPagingIterator();
        } catch (\Throwable $e) {
            $this->error('Stripe API error: ' . $e->getMessage());

            return self::FAILURE;
        }

        foreach ($iterator as $txn) {
            $seen++;
            $row = $this->rowFromTransaction($txn);
            $netCents += $row['net'];

            $exists = $model::query()->where('stripe_id', $row['stripe_id'])->exists();

            if ($dry) {
                $exists ? $updated++ : $created++;

                if ($created + $updated <= 8) {
                    $this->line(sprintf(
                        '  %s  %-16s %10s  %s  %s',
                        $exists ? 'exists' : 'NEW   ',
                        $row['source_type'],
                        number_format($row['net'] / 100, 2),
                        $row['stripe_id'],
                        $row['customer_email'] ?? $row['customer_id'] ?? '—'
                    ));
                }

                continue;
            }

            $status = \Blax\Shop\Facades\Shop::recordBalanceTransaction($txn);
            $status === 'updated' ? $updated++ : $created++;
        }

        $this->newLine();
        $this->info(sprintf(
            '%s %d balance transactions: %d new, %d existing. Ledger net over scanned rows: %s.',
            $dry ? '[dry-run] would import' : 'Imported',
            $seen,
            $created,
            $updated,
            number_format($netCents / 100, 2)
        ));

        return self::SUCCESS;
    }

    /**
     * Flatten a Stripe BalanceTransaction (with `source` expanded) into a ledger
     * row. Delegates to the shared builder so the import and the real-time
     * webhook produce identical rows.
     *
     * @return array<string, mixed>
     */
    protected function rowFromTransaction($txn): array
    {
        // Without the VAT: recordBalanceTransaction() looks it up only where needed.
        return \Blax\Shop\Facades\Shop::ledgerRowFromBalanceTransaction($txn, null, false);
    }

    /** `--backfill-tax`: fill the missing VAT of existing rows from Stripe. */
    protected function backfillTax(bool $dry): int
    {
        $model = config('shop.models.stripe_transaction', \Blax\Shop\Models\StripeTransaction::class);
        if (! \Illuminate\Support\Facades\Schema::hasColumn((new $model)->getTable(), 'tax')) {
            $this->error('The ledger has no `tax` column yet. Run the migration that adds it first.');

            return self::FAILURE;
        }

        $since = $this->option('since') ? Carbon::parse($this->option('since'))->startOfDay() : null;

        $totals = \Blax\Shop\Facades\Shop::backfillLedgerTax(
            $dry,
            $since,
            (bool) $this->option('recheck'),
            function (array $t) {
                if ($t['scanned'] % 100 === 0) {
                    $this->line(sprintf('  … %d rows, %d filled, %s VAT', $t['scanned'], $t['filled'], number_format($t['tax'] / 100, 2)));
                }
            },
        );

        $this->newLine();
        $this->info(sprintf(
            '%s VAT for %d of %d rows without it: %s in total. Stripe holds no VAT data for %d, %d lookups failed%s.',
            $dry ? '[dry-run] would fill' : 'Filled',
            $totals['filled'],
            $totals['scanned'],
            number_format($totals['tax'] / 100, 2),
            $totals['none'],
            $totals['failed'],
            $totals['failed'] > 0 ? ' (left empty, tried again on the next run)' : ''
        ));

        return self::SUCCESS;
    }
}
