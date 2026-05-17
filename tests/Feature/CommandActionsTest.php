<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Console\Commands\ShopTestActionCommand;
use Blax\Shop\Console\Commands\ShopToggleActionCommand;
use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductAction;
use Blax\Shop\Models\ProductActionRun;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

/**
 * Coverage for the two ProductAction CLI helpers:
 *
 *  - shop:toggle-action  → flips the `active` flag (or honours --enable/--disable).
 *  - shop:test-action    → fires the action against its product, optionally synchronously.
 *
 * Until now neither command was tested. Adding coverage surfaced two
 * column-name bugs:
 *
 *   1. ShopToggleActionCommand wrote to a non-existent `enabled` column
 *      instead of `active` — toggle was a silent no-op (or a SQL error in
 *      strict mode), and the rendered "$action->action_class" was always blank.
 *   2. ShopTestActionCommand referenced `$action->action_class` (column is
 *      `class`) and called a non-existent `$action->execute(...)` method in
 *      the default (queued) path, so any non-`--sync` invocation crashed
 *      with "Call to undefined method".
 *
 * The tests below pin down the correct post-fix behaviour.
 */
class CommandActionsTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'name' => 'Actionable',
            'sku' => 'ACT-1',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => false,
        ]);
    }

    private function action(bool $active = true, string $class = TestActionListener::class): ProductAction
    {
        return ProductAction::create([
            'product_id' => $this->product->id,
            'events' => ['purchased'],
            'class' => $class,
            'method' => null,
            'defer' => false,
            'active' => $active,
        ]);
    }

    /* ───────────────────────── shop:toggle-action ─────────────────────── */

    #[Test]
    public function toggle_flips_active_when_no_flag_is_given(): void
    {
        $action = $this->action(active: true);

        Artisan::call(ShopToggleActionCommand::class, ['action-id' => $action->id]);
        $output = Artisan::output();

        $this->assertFalse((bool) $action->fresh()->active, 'active was true → false');
        $this->assertStringContainsString('disabled', $output);

        Artisan::call(ShopToggleActionCommand::class, ['action-id' => $action->id]);
        $this->assertTrue((bool) $action->fresh()->active, 'second toggle flips back to true');
    }

    #[Test]
    public function enable_flag_force_activates_the_action(): void
    {
        $action = $this->action(active: false);

        Artisan::call(ShopToggleActionCommand::class, [
            'action-id' => $action->id,
            '--enable' => true,
        ]);

        $this->assertTrue((bool) $action->fresh()->active);
    }

    #[Test]
    public function disable_flag_force_deactivates_the_action(): void
    {
        $action = $this->action(active: true);

        Artisan::call(ShopToggleActionCommand::class, [
            'action-id' => $action->id,
            '--disable' => true,
        ]);

        $this->assertFalse((bool) $action->fresh()->active);
    }

    #[Test]
    public function toggle_reports_an_error_for_an_unknown_action_id(): void
    {
        $exit = Artisan::call(ShopToggleActionCommand::class, [
            'action-id' => 'no-such-id',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not found', $output);
    }

    #[Test]
    public function toggle_summary_carries_the_actions_class_name(): void
    {
        // Regression: the command used to read $action->action_class (which
        // doesn't exist as a column or accessor), so the summary line printed
        // an empty class name. Verify the real `class` column is what surfaces.
        $action = $this->action(active: true, class: 'App\\Foo\\MyAction');

        Artisan::call(ShopToggleActionCommand::class, ['action-id' => $action->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('App\\Foo\\MyAction', $output);
    }

    /* ───────────────────────── shop:test-action ───────────────────────── */

    #[Test]
    public function test_action_errors_out_for_unknown_id(): void
    {
        $exit = Artisan::call(ShopTestActionCommand::class, ['action-id' => 'no-such-id']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not found', $output);
    }

    #[Test]
    public function test_action_runs_the_action_and_writes_an_action_run_row(): void
    {
        // Default (non-sync) path: the command must dispatch the action via
        // the package's normal action runner so a ProductActionRun row is
        // created. The test action below is sync (defer=false) and just sets
        // a flag, but the ProductActionRun row is the operator-visible signal
        // that the run happened.
        TestActionListener::$invocations = 0;
        $action = $this->action(active: true);

        // The command asks for confirmation; "no" returns early. We need
        // "yes" — Artisan::call accepts a callable but the simplest path
        // is to set --no-interaction so confirm() defaults to false…
        // Instead, mock the prompt via withAnswers when available; otherwise
        // patch the question by passing --no-interaction is wrong (defaults
        // to false). Use the alternative: artisan call with answers.
        $this->artisan(ShopTestActionCommand::class, ['action-id' => $action->id])
            ->expectsConfirmation('Do you want to proceed?', 'yes')
            ->expectsOutputToContain('Testing action: '.TestActionListener::class)
            ->expectsOutputToContain('completed successfully')
            ->assertExitCode(0);

        $this->assertGreaterThan(0, TestActionListener::$invocations, 'the action class was invoked');
        $this->assertGreaterThan(
            0,
            ProductActionRun::where('action_id', $action->id)->count(),
            'a ProductActionRun row records the execution',
        );
    }

    #[Test]
    public function test_action_sync_flag_calls_the_action_class_directly(): void
    {
        // --sync bypasses the queue and instantiates the action class with the
        // standard (product, productPurchase, event, ...) signature. The test
        // listener counts invocations so we can assert it actually ran.
        TestActionListener::$invocations = 0;
        $action = $this->action(active: true);

        $this->artisan(ShopTestActionCommand::class, [
            'action-id' => $action->id,
            '--sync' => true,
        ])
            ->expectsConfirmation('Do you want to proceed?', 'yes')
            ->expectsOutputToContain('Action executed synchronously.')
            ->assertExitCode(0);

        $this->assertSame(1, TestActionListener::$invocations);
    }

    #[Test]
    public function test_action_cancellation_short_circuits_with_zero_exit(): void
    {
        // Declining the confirmation must return early without executing the
        // action — proves that the prompt is gating execution properly.
        TestActionListener::$invocations = 0;
        $action = $this->action(active: true);

        $this->artisan(ShopTestActionCommand::class, ['action-id' => $action->id])
            ->expectsConfirmation('Do you want to proceed?', 'no')
            ->expectsOutputToContain('Test cancelled.')
            ->assertExitCode(0);

        $this->assertSame(0, TestActionListener::$invocations);
        $this->assertSame(0, ProductActionRun::where('action_id', $action->id)->count());
    }
}

/**
 * Trivial in-memory action class used by the test-action coverage. The
 * package's runner instantiates it with named params (product, productPurchase,
 * event, …extras); the constructor accepts everything via variadic-named
 * arguments so the package can call ->__invoke() to fire it.
 */
class TestActionListener
{
    public static int $invocations = 0;

    public function __construct(
        public ?\Blax\Shop\Models\Product $product = null,
        public ?\Blax\Shop\Models\ProductPurchase $productPurchase = null,
        public ?string $event = null,
    ) {}

    public function __invoke(): void
    {
        self::$invocations++;
    }
}
