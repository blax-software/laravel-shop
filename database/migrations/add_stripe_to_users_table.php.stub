<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable(config('shop.tables.users', 'users'))) {
            Schema::table(config('shop.tables.users', 'users'), function (Blueprint $table) {
                if (!Schema::hasColumn(config('shop.tables.users', 'users'), 'stripe_id')) {
                    $table->string('stripe_id')->nullable()->index();
                    $table->string('pm_type')->nullable();
                    $table->string('pm_last_four', 4)->nullable();
                    $table->timestamp('trial_ends_at')->nullable();
                }
            });
        }else {
            throw new \Exception('Users table does not exist. Please run the initial migrations first.');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable(config('shop.tables.users', 'users'))) {
            Schema::table(config('shop.tables.users', 'users'), function (Blueprint $table) {
                if (Schema::hasColumn(config('shop.tables.users', 'users'), 'stripe_id')) {
                    $table->dropIndex([
                        'stripe_id',
                    ]);
 
                    $table->dropColumn([
                        'stripe_id',
                        'pm_type',
                        'pm_last_four',
                        'trial_ends_at',
                    ]);
                }
            });
        }
    }
};
