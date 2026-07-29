<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(config('shop.tables.license_seats', 'license_seats'))) {
            return;
        }

        Schema::create(config('shop.tables.license_seats', 'license_seats'), function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('product_id');
            // Source: exactly one of these is set — the purchase or subscription
            // whose `quantity` this seat is one unit of.
            $table->uuid('product_purchase_id')->nullable();
            $table->uuid('subscription_id')->nullable();
            // Who currently holds the seat (polymorphic; null while free).
            $table->nullableUuidMorphs('assignee');
            $table->string('status')->default('unassigned'); // unassigned, assigned, revoked
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            // When the seat's grant lapses (subscription period end); null = no
            // expiry (one-time purchase seat).
            $table->timestamp('expires_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index(['product_purchase_id', 'status']);
            $table->index(['subscription_id', 'status']);

            $table->foreign('product_id')
                ->references('id')->on(config('shop.tables.products', 'products'))
                ->cascadeOnDelete();
            $table->foreign('product_purchase_id')
                ->references('id')->on(config('shop.tables.product_purchases', 'product_purchases'))
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('shop.tables.license_seats', 'license_seats'));
    }
};
