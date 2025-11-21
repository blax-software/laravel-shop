<?php

namespace Blax\Shop;

use Blax\Shop\Console\Commands\ShopReinstallCommand;
use Illuminate\Support\ServiceProvider;

class ShopServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/shop.php',
            'shop'
        );
    }

    public function boot()
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/shop.php' => config_path('shop.php'),
        ], 'shop-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../database/migrations/create_blax_shop_tables.php.stub' => $this->getMigrationFileName('create_blax_shop_tables.php'),
        ], 'shop-migrations');

        // Load routes if enabled (API only)
        if (config('shop.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/api.php');
        }

        // Register commands
        if ($this->app->runningInConsole()) {
            $this->commands([
                ShopReinstallCommand::class,
                \Blax\Shop\Console\Commands\ReleaseExpiredStocks::class,
                \Blax\Shop\Console\Commands\ShopListProductsCommand::class,
                \Blax\Shop\Console\Commands\ShopListActionsCommand::class,
                \Blax\Shop\Console\Commands\ShopToggleActionCommand::class,
                \Blax\Shop\Console\Commands\ShopTestActionCommand::class,
                \Blax\Shop\Console\Commands\ShopListPurchasesCommand::class,
                \Blax\Shop\Console\Commands\ShopAvailableActionsCommand::class,
                \Blax\Shop\Console\Commands\ShopStatsCommand::class,
            ]);
        }
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
