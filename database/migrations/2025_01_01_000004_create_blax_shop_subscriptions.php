<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier-backed subscription tables, in the package's UUID convention.
 *
 * Mirrors Laravel Cashier's `subscriptions` / `subscription_items` schema (so
 * the package's Cashier-extending models work out of the box) but with UUID
 * primary keys to match the rest of the package, plus a nullable `product_id`
 * so a subscription can be linked to the {@see \Blax\Shop\Models\Product} it
 * sells (used to run product actions on the billing lifecycle), and the
 * `current_period_*` columns Cashier 15 syncs from Stripe.
 *
 * Guarded with `hasTable`, so an app that already owns a `subscriptions` table
 * (e.g. one published from Cashier) is left untouched — point
 * `shop.tables.subscriptions` at a different name if you need both.
 */
return new class extends Migration
{
    public function up(): void
    {
        $subscriptions = config('shop.tables.subscriptions', 'subscriptions');
        $subscriptionItems = config('shop.tables.subscription_items', 'subscription_items');

        if (! Schema::hasTable($subscriptions)) {
            Schema::create($subscriptions, function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('user_id')->index();
                $table->uuid('product_id')->nullable()->index();
                $table->string('type');
                $table->string('stripe_id')->unique();
                $table->string('stripe_status');
                $table->string('stripe_price')->nullable();
                $table->integer('quantity')->nullable();
                $table->timestamp('trial_ends_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->timestamp('current_period_start')->nullable();
                $table->timestamp('current_period_end')->nullable();
                $table->timestamps();

                $table->index(['user_id', 'stripe_status']);
            });
        }

        if (! Schema::hasTable($subscriptionItems)) {
            Schema::create($subscriptionItems, function (Blueprint $table) use ($subscriptions) {
                $table->uuid('id')->primary();
                $table->uuid('subscription_id');
                $table->string('stripe_id')->unique();
                $table->string('stripe_product');
                $table->string('stripe_price');
                $table->integer('quantity')->nullable();
                $table->timestamps();

                $table->index(['subscription_id', 'stripe_price']);
                $table->foreign('subscription_id')
                    ->references('id')->on($subscriptions)
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(config('shop.tables.subscription_items', 'subscription_items'));
        Schema::dropIfExists(config('shop.tables.subscriptions', 'subscriptions'));
    }
};
