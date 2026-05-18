<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the redundant `in_stock` and `stock_status` columns from `products`.
 *
 * Both fields were stale denormalisations of "does the product have stock?".
 * Every consumer in the package already routes through the ProductStock
 * ledger:
 *
 *   - `HasStocks::isInStock()`    → checks `manage_stock` + getAvailableStock()
 *   - `HasStocks::scopeInStock()` → SUMs the ledger directly
 *
 * No code reads either column. Dropping them removes a foot-gun where the
 * column could disagree with the live ledger state (e.g. an order was placed,
 * stock dropped to 0, but nobody updated `in_stock` → frontend shows the
 * product as orderable when it isn't).
 *
 * Down restores the columns with their original defaults so a rollback is
 * lossless from a schema perspective (the data itself is not backfilled —
 * if you need it, derive it post-hoc from the ledger).
 */
return new class extends Migration {
    public function up(): void
    {
        $table = config('shop.tables.products', 'products');

        if (!Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($table) {
            $cols = [];
            if (Schema::hasColumn($table, 'in_stock')) {
                $cols[] = 'in_stock';
            }
            if (Schema::hasColumn($table, 'stock_status')) {
                $cols[] = 'stock_status';
            }
            if (!empty($cols)) {
                $t->dropColumn($cols);
            }
        });
    }

    public function down(): void
    {
        $table = config('shop.tables.products', 'products');

        if (!Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($table) {
            if (!Schema::hasColumn($table, 'in_stock')) {
                $t->boolean('in_stock')->default(true)->after('manage_stock');
            }
            if (!Schema::hasColumn($table, 'stock_status')) {
                $t->string('stock_status')->default('instock')->after('in_stock');
            }
        });
    }
};
