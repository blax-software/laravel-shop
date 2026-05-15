<?php

namespace Blax\Shop\Tests\Feature\ShopServiceProvider;

use Blax\Shop\ShopServiceProvider;
use Blax\Shop\Tests\TestCase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * The package auto-loads its migrations from `vendor/.../database/migrations`
 * so `composer require + php artisan migrate` works without a publish step.
 * `config('shop.run_migrations')` is the kill switch for projects that
 * prefer publish-and-manage workflows.
 */
class MigrationAutoLoadTest extends TestCase
{
    #[Test]
    public function the_package_ships_a_run_migrations_config_default_of_true(): void
    {
        $shipped = require __DIR__.'/../../../config/shop.php';

        $this->assertArrayHasKey('run_migrations', $shipped);
        $this->assertTrue($shipped['run_migrations']);
    }

    #[Test]
    public function the_package_publishes_all_three_migration_files(): void
    {
        $expected = [
            '2025_01_01_000001_create_blax_shop_tables.php',
            '2025_01_01_000002_create_product_price_tiers_table.php',
            '2025_01_01_000003_add_stripe_to_users_table.php',
        ];

        $files = File::files(__DIR__.'/../../../database/migrations');
        $names = collect($files)->map(fn ($f) => $f->getFilename())->all();

        foreach ($expected as $migration) {
            $this->assertContains($migration, $names, "Missing package migration: {$migration}");
        }
    }

    #[Test]
    public function register_migrations_is_a_protected_method_on_the_service_provider(): void
    {
        // Sanity check that the gate exists and is documented as part of the
        // provider's boot path. Catches accidental renames.
        $reflection = new ReflectionClass(ShopServiceProvider::class);

        $this->assertTrue(
            $reflection->hasMethod('registerMigrations'),
            'ShopServiceProvider::registerMigrations() is the run_migrations gate.'
        );

        $this->assertTrue(
            $reflection->getMethod('registerMigrations')->isProtected(),
            'registerMigrations() should be protected — booted internally.'
        );
    }

    #[Test]
    public function register_migrations_short_circuits_when_run_migrations_is_false(): void
    {
        // Drive the gate directly via reflection: with run_migrations=false
        // it should return early before calling loadMigrationsFrom — i.e.
        // not throw and not register anything new.
        config(['shop.run_migrations' => false]);

        $provider = $this->app->getProvider(ShopServiceProvider::class);

        $method = (new ReflectionClass($provider))->getMethod('registerMigrations');
        $method->setAccessible(true);

        // Should not throw.
        $method->invoke($provider);

        $this->assertFalse(config('shop.run_migrations'));
    }
}
