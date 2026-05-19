<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add purchase-limit columns to `products`:
 *
 *   - `max_per_cart`: maximum quantity of this product allowed in a single
 *     cart at once. NULL = unlimited (the default — preserves existing
 *     behaviour for every already-seeded product).
 *   - `max_per_user`: maximum quantity a single customer may ever buy across
 *     all their orders + their currently-open cart. NULL = unlimited.
 *
 * Both are nullable signed integers because "no cap" is the most common
 * configuration and treating 0 as "no cap" would be a foot-gun (an admin
 * setting `max_per_cart = 0` clearly means "do not sell," not "unlimited").
 * Enforcement lives in {@see \Blax\Shop\Models\Cart::addToCart()}.
 */
return new class extends Migration {
    public function up(): void
    {
        $table = config('shop.tables.products', 'products');

        if (!Schema::hasTable($table)) {
            return;
        }

        Schema::table($table, function (Blueprint $t) use ($table) {
            if (!Schema::hasColumn($table, 'max_per_cart')) {
                $t->integer('max_per_cart')->nullable()->after('low_stock_threshold');
            }
            if (!Schema::hasColumn($table, 'max_per_user')) {
                $t->integer('max_per_user')->nullable()->after('max_per_cart');
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
            $cols = [];
            if (Schema::hasColumn($table, 'max_per_cart')) {
                $cols[] = 'max_per_cart';
            }
            if (Schema::hasColumn($table, 'max_per_user')) {
                $cols[] = 'max_per_user';
            }
            if (!empty($cols)) {
                $t->dropColumn($cols);
            }
        });
    }
};
