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

            $existingId = $model::query()->where('stripe_id', $row['stripe_id'])->value('id');
            $model::query()->updateOrCreate(['stripe_id' => $row['stripe_id']], $row);
            $existingId ? $updated++ : $created++;
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
     * Flatten a Stripe BalanceTransaction (with `source` expanded) into a ledger row.
     *
     * @return array<string, mixed>
     */
    protected function rowFromTransaction($txn): array
    {
        $source = is_object($txn->source ?? null) ? $txn->source : null;
        $sourceId = $source?->id ?? (is_string($txn->source ?? null) ? $txn->source : null);

        // Customer snapshot — best effort from the expanded source object.
        $customerId = null;
        $customerEmail = null;
        if ($source) {
            $customerId = $source->customer ?? null;
            $customerEmail = ($source->billing_details->email ?? null)
                ?? ($source->receipt_email ?? null)
                ?? ($source->customer_email ?? null);
        }

        return [
            'stripe_id' => $txn->id,
            'source_type' => $txn->type ?? null,
            'reporting_category' => $txn->reporting_category ?? null,
            'source_id' => $sourceId,
            'amount' => (int) ($txn->amount ?? 0),
            'fee' => (int) ($txn->fee ?? 0),
            'net' => (int) ($txn->net ?? 0),
            'currency' => $txn->currency ?? null,
            'customer_id' => is_string($customerId) ? $customerId : null,
            'customer_email' => is_string($customerEmail) ? $customerEmail : null,
            'description' => $txn->description ?? null,
            'created' => isset($txn->created) ? Carbon::createFromTimestamp($txn->created) : null,
            'available_on' => isset($txn->available_on) ? Carbon::createFromTimestamp($txn->available_on) : null,
            'meta' => [
                'status' => $txn->status ?? null,
                'fee_details' => isset($txn->fee_details) ? json_decode(json_encode($txn->fee_details), true) : null,
            ],
        ];
    }
}
