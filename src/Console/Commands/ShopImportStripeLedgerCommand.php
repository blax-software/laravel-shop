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
 */
class ShopImportStripeLedgerCommand extends Command
{
    protected $signature = 'shop:import-stripe-ledger
                            {--dry-run : Report what would change, write nothing}
                            {--since= : Only import transactions created on/after this date (YYYY-MM-DD)}
                            {--limit=100 : Stripe page size (max 100)}';

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
        return \Blax\Shop\Facades\Shop::ledgerRowFromBalanceTransaction($txn);
    }
}
