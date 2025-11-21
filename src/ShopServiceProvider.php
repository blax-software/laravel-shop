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
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'shop-migrations');

        // Load migrations
        if ($this->app->runningInConsole()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        }

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
}
