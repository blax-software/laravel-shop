<?php

namespace Blax\Shop\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShopReinstallCommand extends Command
{
    protected $signature = 'shop:reinstall 
                            {--force : Force the operation without confirmation}
                            {--fresh : Drop tables without confirmation}';

    protected $description = 'Drop and recreate all shop tables (USE WITH CAUTION)';

    protected array $shopTables = [
        'product_relations',
        'product_stock_logs',
        'product_actions',
        'product_stocks',
        'product_attributes',
        'product_prices',
        'product_category_product',
        'product_categories',
        'product_purchases',
        'order_items',
        'orders',
        'cart_items',
        'carts',
    ];

    public function handle()
    {
        if (!$this->option('force') && !$this->option('fresh')) {
            $this->error('⚠️  WARNING: This will DELETE ALL shop data!');

            if (!$this->confirm('Are you absolutely sure you want to continue?')) {
                $this->info('Operation cancelled.');
                return 0;
            }

            if (!$this->confirm('This action cannot be undone. Continue?')) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        $this->info('Starting shop reinstallation...');

        // Disable foreign key checks
        Schema::disableForeignKeyConstraints();

        $this->dropShopTables();
        $this->runMigrations();

        // Re-enable foreign key checks
        Schema::enableForeignKeyConstraints();

        $this->info('✅ Shop tables reinstalled successfully!');

        return 0;
    }

    protected function dropShopTables(): void
    {
        $this->info('Dropping shop tables...');

        // Add products table from config
        $productsTable = config('shop.tables.products', 'products');
        $allTables = array_merge([$productsTable], $this->shopTables);

        foreach ($allTables as $table) {
            if (Schema::hasTable($table)) {
                Schema::dropIfExists($table);
                $this->line("  - Dropped: {$table}");
            }
        }

        // Remove migration records
        $this->removeMigrationRecords();
    }

    protected function removeMigrationRecords(): void
    {
        $this->info('Cleaning migration records...');

        DB::table('migrations')
            ->where('migration', 'like', '%shop%')
            ->orWhere('migration', 'like', '%product%')
            ->orWhere('migration', 'like', '%cart%')
            ->orWhere('migration', 'like', '%order%')
            ->delete();
    }

    protected function runMigrations(): void
    {
        $this->info('Running shop migrations...');

        $this->call('migrate', [
            '--path' => 'database/migrations/create_blax_shop_tables.php.stub',
            '--force' => true,
        ]);
    }
}
