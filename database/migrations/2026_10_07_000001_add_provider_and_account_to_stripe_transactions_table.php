<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The money ledger spans more than one account: Stripe, plus any PayPal
 * account a payment settles into. `provider` says which system a row came
 * from (`stripe`, `paypal`), `account` which account of that provider (the
 * PayPal merchant account id; null = the app's one Stripe account).
 *
 * Existing rows are all Stripe, which the column default covers. Guarded so a
 * re-run or a consumer that already added the columns is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('shop.tables.stripe_transactions', 'stripe_transactions');

        if (! Schema::hasTable($table)) {
            return;
        }

        if (! Schema::hasColumn($table, 'provider')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('provider', 16)->default('stripe')->after('stripe_id')->index();
            });
        }

        if (! Schema::hasColumn($table, 'account')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->string('account')->nullable()->after('provider')->index();
            });
        }
    }

    public function down(): void
    {
        $table = config('shop.tables.stripe_transactions', 'stripe_transactions');

        if (! Schema::hasTable($table)) {
            return;
        }

        foreach (['account', 'provider'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                Schema::table($table, function (Blueprint $blueprint) use ($column) {
                    $blueprint->dropIndex([$column]);
                    $blueprint->dropColumn($column);
                });
            }
        }
    }
};
