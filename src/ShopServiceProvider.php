<?php

declare(strict_types=1);

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
        $this->offerPublishing();

        $this->registerMigrations();

        $this->registerRouteMacros();

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
                \Blax\Shop\Console\Commands\ShopListCommand::class,
                \Blax\Shop\Console\Commands\ShopListProductsCommand::class,
                \Blax\Shop\Console\Commands\ShopListPurchasesCommand::class,
                \Blax\Shop\Console\Commands\ShopListCategoriesCommand::class,
                \Blax\Shop\Console\Commands\ShopListOrdersCommand::class,
                \Blax\Shop\Console\Commands\ShopListCartsCommand::class,
                \Blax\Shop\Console\Commands\ShopToggleActionCommand::class,
                \Blax\Shop\Console\Commands\ShopTestActionCommand::class,
                \Blax\Shop\Console\Commands\ShopStatsCommand::class,
                \Blax\Shop\Console\Commands\ShopStocksCommand::class,
                \Blax\Shop\Console\Commands\ShopAvailabilityCommand::class,
                \Blax\Shop\Console\Commands\ShopStocksClaimsCommand::class,
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
     * Auto-load the package's migrations so fresh installs work without
     * publishing. Disabled via `shop.run_migrations = false` for projects
     * that prefer to publish + manage migrations themselves.
     */
    protected function registerMigrations(): void
    {
        if (! config('shop.run_migrations', true)) {
            return;
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * Register Route macros that hosts can use to wire shop endpoints
     * concisely. Currently provides:
     *
     *   Route::shopLoans('loans', \App\Http\Controllers\LoanController::class)
     *     → GET    {prefix}                     index of caller's loans
     *     → GET    {prefix}/{purchase}          show a single loan
     *     → POST   {prefix}/{purchase}/extend   extend the due date
     *     → POST   {prefix}/{purchase}/return   return the item
     *
     * The controller must expose matching methods: index, show, extend,
     * returnLoan. The {purchase} route parameter is passed as a raw UUID
     * string — controllers typically resolve it through the authenticated
     * user's loans relation so ownership falls out for free.
     *
     * Loan creation (store) is intentionally not registered here: the URL
     * shape is host-specific because it depends on which product model is
     * being checked out (e.g. POST /books/{book}/loans). Hosts wire that
     * route themselves so they can use implicit route model binding on
     * their own model class.
     */
    protected function registerRouteMacros(): void
    {
        \Illuminate\Support\Facades\Route::macro('shopLoans', function (string $prefix, string $controller): void {
            \Illuminate\Support\Facades\Route::prefix($prefix)->group(function () use ($controller): void {
                \Illuminate\Support\Facades\Route::get('/', [$controller, 'index']);
                \Illuminate\Support\Facades\Route::get('/{purchase}', [$controller, 'show']);
                \Illuminate\Support\Facades\Route::post('/{purchase}/extend', [$controller, 'extend']);
                \Illuminate\Support\Facades\Route::post('/{purchase}/return', [$controller, 'returnLoan']);
            });
        });
    }

    /**
     * Set up publishing of config and migrations for `php artisan vendor:publish`.
     *
     * Migrations are published keeping the source filename so that any
     * migration already run via auto-load is marked as run for the
     * published copy too — no duplicate execution.
     */
    protected function offerPublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__ . '/../config/shop.php' => $this->app->configPath('shop.php'),
        ], ['shop-config', 'config']);

        $migrationsPath = __DIR__ . '/../database/migrations';
        $publishMap = [];
        foreach (glob($migrationsPath . '/*.php') as $sourcePath) {
            $publishMap[$sourcePath] = $this->app->databasePath('migrations/' . basename($sourcePath));
        }

        $this->publishes($publishMap, ['shop-migrations', 'migrations']);

        $this->publishes(
            array_merge(
                [__DIR__ . '/../config/shop.php' => $this->app->configPath('shop.php')],
                $publishMap,
            ),
            'shop',
        );
    }
}
