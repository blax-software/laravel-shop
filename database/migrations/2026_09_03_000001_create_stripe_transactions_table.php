<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A user-independent mirror of Stripe's BalanceTransaction ledger.
 *
 * Every money movement Stripe records (charge, refund, dispute, adjustment, …)
 * gets one immutable row keyed on the `txn_` id, carrying the signed gross
 * `amount`, the Stripe `fee`, and the resulting `net` — so "revenue net of
 * fees and refunds" is a plain `SUM(net)`, and a deleted customer never
 * removes their history (nothing here references a local user).
 *
 * Money columns are signed `bigInteger` (cents): a refund's amount/net are
 * negative. Guarded with `hasTable` so a consumer that already owns the table
 * name is left untouched — repoint `shop.tables.stripe_transactions` to keep
 * both.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('shop.tables.stripe_transactions', 'stripe_transactions');

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint) {
            $blueprint->uuid('id')->primary();

            // The Stripe BalanceTransaction id (`txn_…`) — the upsert key.
            $blueprint->string('stripe_id')->unique();

            // Stripe `type` (charge, payment, refund, payment_refund, adjustment,
            // dispute, stripe_fee, payout, …) and `reporting_category`.
            $blueprint->string('source_type')->nullable()->index();
            $blueprint->string('reporting_category')->nullable();

            // The object that produced it: ch_/pi_/re_/di_/in_/po_.
            $blueprint->string('source_id')->nullable()->index();

            // Signed money, in cents. Refunds/disputes are negative.
            $blueprint->bigInteger('amount')->default(0);   // gross
            $blueprint->bigInteger('fee')->default(0);      // Stripe fee
            $blueprint->bigInteger('net')->default(0);      // amount - fee
            $blueprint->string('currency', 8)->nullable();

            // Customer snapshot — survives the local user being deleted.
            $blueprint->string('customer_id')->nullable()->index();
            $blueprint->string('customer_email')->nullable();

            $blueprint->string('description')->nullable();

            // Stripe's own timestamps (NOT our write time).
            $blueprint->timestamp('created')->nullable()->index();
            $blueprint->timestamp('available_on')->nullable();

            $blueprint->json('meta')->nullable();

            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('shop.tables.stripe_transactions', 'stripe_transactions'));
    }
};
