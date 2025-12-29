<?php

namespace Blax\Shop;

use Blax\Shop\Console\Commands\ShopCleanupCartsCommand;
use Blax\Shop\Console\Commands\ShopReinstallCommand;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

class ShopServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/shop.php',
            'shop'
        );

        // Register service bindings
        $this->app->singleton('shop.service', function ($app) {
            return new \Blax\Shop\Services\ShopService();
        });

        $this->app->singleton('shop.cart', function ($app) {
            return new \Blax\Shop\Services\CartService();
        });
    }

    public function boot()
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/shop.php' => config_path('shop.php'),
        ], ['shop-config', 'config']);

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/create_blax_shop_tables.php.stub' => $this->getMigrationFileName('create_blax_shop_tables.php'),
            __DIR__ . '/../database/migrations/add_stripe_to_users_table.php.stub' => $this->getMigrationFileName('add_stripe_to_users_table.php'),
        ], ['shop-migrations', 'migrations']);

        // Publish all shop assets
        $this->publishes([
            __DIR__ . '/../config/shop.php' => config_path('shop.php'),
            __DIR__ . '/../database/migrations/create_blax_shop_tables.php.stub' => $this->getMigrationFileName('create_blax_shop_tables.php'),
            __DIR__ . '/../database/migrations/add_stripe_to_users_table.php.stub' => $this->getMigrationFileName('add_stripe_to_users_table.php'),
        ], 'shop');

        // Load routes if enabled (API only)
        if (config('shop.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        }

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                ShopReinstallCommand::class,
                ShopCleanupCartsCommand::class,
                \Blax\Shop\Console\Commands\ReleaseExpiredStocks::class,
                \Blax\Shop\Console\Commands\ShopListProductsCommand::class,
                \Blax\Shop\Console\Commands\ShopToggleActionCommand::class,
                \Blax\Shop\Console\Commands\ShopTestActionCommand::class,
                \Blax\Shop\Console\Commands\ShopListPurchasesCommand::class,
                \Blax\Shop\Console\Commands\ShopStatsCommand::class,
                \Blax\Shop\Console\Commands\ShopAddExampleProducts::class,
                \Blax\Shop\Console\Commands\ShopSetupStripeWebhooksCommand::class,
            ]);
        }

        // Register scheduled tasks
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            // Cleanup carts every hour if auto_cleanup is enabled
            if (config('shop.cart.auto_cleanup', true)) {
                $schedule->command('shop:cleanup-carts', ['--force'])
                    ->hourly()
                    ->withoutOverlapping()
                    ->runInBackground();
            }

            // Release expired stocks every 5 minutes
            if (config('shop.stock.auto_release_expired', true)) {
                $schedule->command('shop:release-expired-stocks')
                    ->everyFiveMinutes()
                    ->withoutOverlapping();
            }
        });
    }

    /**
     * Returns existing migration file if found, else uses the current timestamp.
     */
    protected function getMigrationFileName(string $migrationFileName): string
    {
        $timestamp = date('Y_m_d_His');

        $filesystem = $this->app->make(\Illuminate\Filesystem\Filesystem::class);

        return \Illuminate\Support\Collection::make([$this->app->databasePath() . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR])
            ->flatMap(fn($path) => $filesystem->glob($path . '*_' . $migrationFileName))
            ->push($this->app->databasePath() . "/migrations/{$timestamp}_{$migrationFileName}")
            ->first();
    }
}
