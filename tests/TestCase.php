<?php

namespace Blax\Shop\Tests;

use Blax\Shop\ShopServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        // The suite has grown past what 256M can comfortably sustain across
        // ~1,200 tests sharing in-memory SQLite + Orchestra Testbench fixtures.
        ini_set('memory_limit', '1024M');

        Factory::guessFactoryNamesUsing(
            fn(string $modelName) => match (true) {
                str_starts_with($modelName, 'Workbench\\App\\') => 'Workbench\\Database\\Factories\\' . class_basename($modelName) . 'Factory',
                default => 'Blax\\Shop\\Database\\Factories\\' . class_basename($modelName) . 'Factory'
            }
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            ShopServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Set up i18n config for HasMetaTranslation trait
        config()->set('app.i18n.supporting', [
            'en' => 'English',
            'es' => 'Spanish',
            'fr' => 'French',
        ]);

        // Create users table for testing
        $app['db']->connection()->getSchemaBuilder()->create('users', function ($table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        // Run package migrations
        $migration = include __DIR__ . '/../database/migrations/2025_01_01_000001_create_blax_shop_tables.php';
        $migration->up();

        $migration = include __DIR__ . '/../database/migrations/2025_01_01_000003_add_stripe_to_users_table.php';
        $migration->up();

        $migration = include __DIR__ . '/../database/migrations/2025_01_01_000002_create_product_price_tiers_table.php';
        $migration->up();

        $migration = include __DIR__ . '/../database/migrations/2026_01_01_000002_add_max_per_cart_and_max_per_user_to_products.php';
        $migration->up();
    }
}
