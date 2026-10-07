<?php

declare(strict_types=1);

namespace Blax\Shop\Console\Commands;

use Blax\Shop\Services\PaymentProvider\PaypalLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Mirror every configured PayPal account into the money ledger, read-only via
 * PayPal's Transaction Search API. Idempotent: upserts on the PayPal
 * transaction id. Without --since an account resumes 14 days before its
 * newest row (the first run reaches back PayPal's three years).
 */
class ShopImportPaypalLedgerCommand extends Command
{
    protected $signature = 'shop:import-paypal-ledger
                            {--dry-run : Report what would change, write nothing}
                            {--since= : Only import transactions on/after this date (YYYY-MM-DD)}
                            {--account= : Only this configured account (its `name`)}';

    protected $description = 'Mirror PayPal account transactions into the money ledger (read-only).';

    public function handle(PaypalLedgerService $paypal): int
    {
        $accounts = $paypal->accounts();
        if ($only = $this->option('account')) {
            $accounts = array_values(array_filter($accounts, fn ($a) => $a['name'] === $only));
        }

        $required = (bool) config('shop.paypal.required', false);

        if (! $accounts) {
            $message = 'No PayPal account configured (shop.paypal.accounts), nothing to import.';
            if ($required) {
                throw new \RuntimeException($message . ' shop.paypal.required is on: set PAYPAL_CLIENT_ID and PAYPAL_SECRET.');
            }
            $this->info($message);

            return self::SUCCESS;
        }

        if (! $paypal->hasProviderColumns()) {
            $message = 'The ledger table has no provider/account columns yet. Run the laravel-shop migrations first.';
            if ($required) {
                throw new \RuntimeException($message);
            }
            $this->error($message);

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $failed = false;
        $errors = [];

        foreach ($accounts as $account) {
            try {
                $since = $this->option('since')
                    ? Carbon::parse($this->option('since'))->startOfDay()
                    : $paypal->resumeFrom($paypal->balances($account)['account_id'] ?? null);

                $result = $paypal->import($account, $since, $dry);
            } catch (\Throwable $e) {
                $this->error(sprintf('[%s] %s', $account['name'], $e->getMessage()));
                $errors[] = sprintf('[%s] %s', $account['name'], $e->getMessage());
                $failed = true;

                continue;
            }

            $byType = [];
            foreach ($result['rows'] as $row) {
                $byType[$row['source_type']] ??= ['rows' => 0, 'amount' => 0, 'fee' => 0, 'net' => 0];
                $byType[$row['source_type']]['rows']++;
                $byType[$row['source_type']]['amount'] += $row['amount'];
                $byType[$row['source_type']]['fee'] += $row['fee'];
                $byType[$row['source_type']]['net'] += $row['net'];
            }
            ksort($byType);

            $this->info(sprintf(
                '[%s] %s %s since %s: %d transactions, %d new, %d existing.',
                $account['name'],
                $dry ? '[dry-run] would import' : 'Imported',
                $result['account'],
                $since->toDateString(),
                $result['seen'],
                $result['created'],
                $result['updated']
            ));

            if ($byType) {
                $this->table(
                    ['type', 'rows', 'amount', 'fee', 'net'],
                    array_map(fn ($type, $t) => [
                        $type,
                        $t['rows'],
                        number_format($t['amount'] / 100, 2),
                        number_format($t['fee'] / 100, 2),
                        number_format($t['net'] / 100, 2),
                    ], array_keys($byType), $byType)
                );
            }
        }

        // Every account was tried; now make a required import's failure loud.
        if ($failed && $required) {
            throw new \RuntimeException('PayPal ledger import failed: ' . implode('; ', $errors));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
