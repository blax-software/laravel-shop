<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Console\Commands\ShopReinstallCommand;
use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * shop:reinstall is the package's escape hatch — drop every shop table and
 * re-run the migrations from scratch. It is destructive by design and guarded
 * by two confirmation prompts unless --force or --fresh is passed.
 *
 * Coverage targets the --force happy path (no prompts) and the cancellation
 * branches; we don't poke at the migrations themselves (other tests cover
 * that) — only the command's orchestration.
 */
class CommandReinstallTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function force_flag_skips_prompts_and_recreates_the_schema(): void
    {
        // Seed some data so we can assert it's gone after the reinstall.
        Product::create([
            'name' => 'Doomed',
            'sku' => 'DOOMED-1',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => false,
        ]);
        Cart::create(['session_id' => 'doomed-cart']);

        $exit = Artisan::call(ShopReinstallCommand::class, ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Starting shop reinstallation', $output);
        $this->assertStringContainsString('Shop tables reinstalled successfully', $output);

        // Tables exist (migrations re-ran)…
        $this->assertTrue(Schema::hasTable('product_purchases'));
        $this->assertTrue(Schema::hasTable('product_stocks'));
        $this->assertTrue(Schema::hasTable('carts'));

        // …but the seeded rows are gone.
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Cart::count());
    }

    #[Test]
    public function declining_either_confirmation_short_circuits_with_a_cancelled_message(): void
    {
        // First confirmation says "no" → command exits without touching tables.
        Product::create([
            'name' => 'Survivor',
            'sku' => 'SURV-1',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => false,
        ]);

        $this->artisan(ShopReinstallCommand::class)
            ->expectsConfirmation('Are you absolutely sure you want to continue?', 'no')
            ->expectsOutputToContain('Operation cancelled.')
            ->assertExitCode(0);

        $this->assertSame(1, Product::count(), 'data survives a cancelled reinstall');
    }

    #[Test]
    public function declining_the_second_confirmation_also_short_circuits(): void
    {
        // First confirm says "yes", second says "no" — the second guard kicks
        // in before any destructive work happens.
        Product::create([
            'name' => 'Also Survivor',
            'sku' => 'SURV-2',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => false,
        ]);

        $this->artisan(ShopReinstallCommand::class)
            ->expectsConfirmation('Are you absolutely sure you want to continue?', 'yes')
            ->expectsConfirmation('This action cannot be undone. Continue?', 'no')
            ->expectsOutputToContain('Operation cancelled.')
            ->assertExitCode(0);

        $this->assertSame(1, Product::count());
    }

    #[Test]
    public function fresh_flag_is_synonymous_with_force_for_prompt_skipping(): void
    {
        // --fresh skips prompts just like --force; both are honoured by the
        // same `!force && !fresh` guard.
        Product::create([
            'name' => 'Doomed Too',
            'sku' => 'DOOMED-2',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => false,
        ]);

        $exit = Artisan::call(ShopReinstallCommand::class, ['--fresh' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, Product::count());
    }
}
