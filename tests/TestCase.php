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
        $migration = include __DIR__ . '/../database/migrations/create_blax_shop_tables.php.stub';
        $migration->up();

        $migration = include __DIR__ . '/../database/migrations/add_stripe_to_users_table.php.stub';
        $migration->up();
    }
}
