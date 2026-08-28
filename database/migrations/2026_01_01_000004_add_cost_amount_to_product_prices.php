<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost basis (COGS) per unit sold at a price: what it costs us to deliver one
 * unit — manufacturing, shipping, a content license or royalty. For a recurring
 * price it is the cost per billing period. Lets the shop report gross margin and
 * net MRR (revenue − cost) instead of top-line only. Integer cents; 0 = no cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('shop.tables.product_prices', 'product_prices');

        if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'cost_amount')) {
            Schema::table($table, function (Blueprint $t) {
                $t->integer('cost_amount')->default(0)->after('sale_unit_amount');
            });
        }
    }

    public function down(): void
    {
        $table = config('shop.tables.product_prices', 'product_prices');

        if (Schema::hasColumn($table, 'cost_amount')) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('cost_amount');
            });
        }
    }
};
