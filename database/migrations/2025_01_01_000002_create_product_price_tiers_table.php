<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tier ladder for a ProductPrice when its billing_scheme is `tiered`.
 *
 * Each row describes one price tier — "rows are charged at this unit_amount
 * up to `up_to` units of usage". The last tier in the ladder has `up_to`
 * NULL (= unbounded). Mirrors Stripe's tiered-price model so we can sync
 * cleanly to the Stripe Price API later.
 *
 * Usage unit is up to the host's interpretation — for loanable products,
 * one "unit" = one day of borrowing. For metered subscriptions it could be
 * API calls, GB transferred, etc.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(config('shop.tables.product_price_tiers', 'product_price_tiers'))) {
            return;
        }

        Schema::create(
            config('shop.tables.product_price_tiers', 'product_price_tiers'),
            function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('price_id')
                    ->constrained(config('shop.tables.product_prices', 'product_prices'))
                    ->cascadeOnDelete();
                // null = unbounded ("up to infinity"). Otherwise: this tier
                // applies up to this many units (inclusive of the lower
                // boundary set by the previous tier's up_to).
                $table->unsignedInteger('up_to')->nullable();
                // Cents per unit consumed within this tier.
                $table->integer('unit_amount')->default(0);
                // Optional flat fee added when this tier is entered at all.
                $table->integer('flat_amount')->nullable();
                // Tie-breaker so the ladder reads in a deterministic order.
                $table->unsignedInteger('sort_order')->default(0);
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['price_id', 'sort_order'], 'ppt_price_sort_idx');
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(config('shop.tables.product_price_tiers', 'product_price_tiers'));
    }
};
