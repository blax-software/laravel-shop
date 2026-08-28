<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Royalty / minimum-licence-fee rule for a price (e.g. an Aircademy-style
 * content licence): the fee owed per unit sold is
 *   max(license_percent% of unit_amount, license_min_amount)
 * accounted per licence-term bracket (1–3mo / 6mo / 12mo). Lets the shop compute
 * a faithful net margin instead of a flat per-cycle cost. Null = no royalty.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('shop.tables.product_prices', 'product_prices');

        if (! Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($table) {
            if (! Schema::hasColumn($table, 'license_percent')) {
                $t->decimal('license_percent', 5, 2)->nullable()->after('cost_amount');
            }
            if (! Schema::hasColumn($table, 'license_min_amount')) {
                $t->integer('license_min_amount')->nullable()->after('license_percent');
            }
        });
    }

    public function down(): void
    {
        $table = config('shop.tables.product_prices', 'product_prices');

        Schema::table($table, function (Blueprint $t) use ($table) {
            foreach (['license_percent', 'license_min_amount'] as $col) {
                if (Schema::hasColumn($table, $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
