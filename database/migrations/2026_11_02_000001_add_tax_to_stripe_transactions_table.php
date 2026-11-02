<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The VAT contained in a ledger row's `amount`, in signed cents with the same
 * sign as `amount` (a refund carries the refunded share of its charge's VAT,
 * negative). Read from Stripe per charge: the charge's invoice, else the
 * Checkout Session of its payment intent. NULL = unknown (not looked up yet,
 * the lookup failed, or the row is not a charge share). Revenue without VAT is
 * then `SUM(amount) - SUM(COALESCE(tax, 0))`.
 *
 * Guarded so a re-run or a consumer that already added the column is left
 * untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('shop.tables.stripe_transactions', 'stripe_transactions');

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'tax')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->bigInteger('tax')->nullable()->after('net');
        });
    }

    public function down(): void
    {
        $table = config('shop.tables.stripe_transactions', 'stripe_transactions');

        if (Schema::hasTable($table) && Schema::hasColumn($table, 'tax')) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('tax');
            });
        }
    }
};
