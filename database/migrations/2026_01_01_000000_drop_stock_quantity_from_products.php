<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the redundant `stock_quantity` column from `products`.
 *
 * Stock is the responsibility of the `ProductStock` ledger table; the
 * `stock_quantity` column on `products` is a stale denormalisation that
 * caused frontends to mis-read availability (e.g. treating "no ledger
 * entries yet" as "out of stock" when looking at this column). Drop it.
 *
 * If you want to seed initial stock during product creation, call
 * `$product->increaseStock($qty)` after `Product::create([...])` — that
 * writes a single INCREASE entry into `ProductStock`, the same way every
 * other stock change flows through the system.
 *
 * The down() restores the column for rollback only; it does NOT backfill
 * historical values. Up before rolling back: aggregate the ledger into a
 * temporary value if you actually need a number per product.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = config('shop.tables.products', 'products');

        if (!Schema::hasTable($table)) {
            return;
        }

        if (Schema::hasColumn($table, 'stock_quantity')) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('stock_quantity');
            });
        }
    }

    public function down(): void
    {
        $table = config('shop.tables.products', 'products');

        if (!Schema::hasTable($table)) {
            return;
        }

        if (!Schema::hasColumn($table, 'stock_quantity')) {
            Schema::table($table, function (Blueprint $t) {
                $t->integer('stock_quantity')->default(0)->after('manage_stock');
            });
        }
    }
};
