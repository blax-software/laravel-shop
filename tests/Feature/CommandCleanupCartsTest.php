<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Console\Commands\ShopCleanupCartsCommand;
use Blax\Shop\Enums\CartStatus;
use Blax\Shop\Models\Cart;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

/**
 * shop:cleanup-carts has a 2-stage state machine:
 *  - expire  → ACTIVE carts past `shop.cart.expiration_minutes` of inactivity
 *              flip to EXPIRED status (still persisted).
 *  - delete  → carts past `shop.cart.deletion_hours` of inactivity are removed
 *              outright (CONVERTED carts are excluded).
 *
 * Flags --expire and --delete narrow to one stage; --dry-run reports without
 * mutating; --force skips the deletion confirmation prompt.
 */
class CommandCleanupCartsTest extends TestCase
{
    use RefreshDatabase;

    /** A stale ACTIVE cart with last_activity_at well past expiration. */
    private function staleActiveCart(): Cart
    {
        $cart = Cart::create([
            'session_id' => 'sess-stale-'.uniqid(),
            'status' => CartStatus::ACTIVE,
        ]);
        // Backdate last_activity_at past the default 60-minute expiration.
        $cart->forceFill(['last_activity_at' => now()->subHours(3)])->saveQuietly();

        return $cart;
    }

    /** A cart old enough for deletion (past `deletion_hours`). */
    private function deletionCandidateCart(?CartStatus $status = CartStatus::EXPIRED): Cart
    {
        $cart = Cart::create([
            'session_id' => 'sess-old-'.uniqid(),
            'status' => $status,
        ]);
        $cart->forceFill(['last_activity_at' => now()->subDays(2)])->saveQuietly();

        return $cart;
    }

    #[Test]
    public function default_run_with_force_expires_and_deletes_in_one_pass(): void
    {
        $stale = $this->staleActiveCart();
        $old = $this->deletionCandidateCart();

        $exit = Artisan::call(ShopCleanupCartsCommand::class, ['--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Cart Cleanup', $output);
        $this->assertStringContainsString('Carts expired:', $output);
        $this->assertStringContainsString('Carts deleted:', $output);

        // Old cart is gone, stale cart was expired in place.
        $this->assertNull(Cart::find($old->id));
        $refreshed = Cart::find($stale->id);
        // The stale cart was eligible for both expire AND delete (past 2 days)
        // — but it isn't past deletion_hours here (only 3 hours of inactivity),
        // so it should survive as EXPIRED.
        $this->assertNotNull($refreshed);
        $this->assertSame(CartStatus::EXPIRED, $refreshed->status);
    }

    #[Test]
    public function expire_flag_does_not_delete(): void
    {
        $stale = $this->staleActiveCart();
        $old = $this->deletionCandidateCart();

        Artisan::call(ShopCleanupCartsCommand::class, ['--expire' => true]);

        // Stale cart status flipped to EXPIRED, but the old cart that was
        // eligible for deletion is still present.
        $this->assertSame(CartStatus::EXPIRED, Cart::find($stale->id)->status);
        $this->assertNotNull(Cart::find($old->id));
    }

    #[Test]
    public function delete_flag_does_not_expire(): void
    {
        $stale = $this->staleActiveCart();
        $old = $this->deletionCandidateCart();

        Artisan::call(ShopCleanupCartsCommand::class, [
            '--delete' => true,
            '--force' => true,
        ]);

        // The active stale cart is still ACTIVE (not expired this run).
        $this->assertSame(CartStatus::ACTIVE, Cart::find($stale->id)->status);
        // Old cart was deleted.
        $this->assertNull(Cart::find($old->id));
    }

    #[Test]
    public function dry_run_reports_intent_without_mutating_anything(): void
    {
        $stale = $this->staleActiveCart();
        $old = $this->deletionCandidateCart();

        Artisan::call(ShopCleanupCartsCommand::class, ['--dry-run' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('[DRY RUN]', $output);

        // No mutations: stale is still ACTIVE, old is still present.
        $this->assertSame(CartStatus::ACTIVE, Cart::find($stale->id)->status);
        $this->assertNotNull(Cart::find($old->id));
    }

    #[Test]
    public function fresh_carts_are_untouched(): void
    {
        // A brand-new cart created in this test is well under any threshold.
        $fresh = Cart::create([
            'session_id' => 'sess-fresh-1',
            'status' => CartStatus::ACTIVE,
        ]);

        Artisan::call(ShopCleanupCartsCommand::class, ['--force' => true]);

        $refreshed = Cart::find($fresh->id);
        $this->assertNotNull($refreshed);
        $this->assertSame(CartStatus::ACTIVE, $refreshed->status);
    }

    #[Test]
    public function converted_carts_are_never_deleted(): void
    {
        // Even when wildly stale, a CONVERTED cart must survive cleanup —
        // it's the historical record of a completed checkout.
        $converted = Cart::create([
            'session_id' => 'sess-converted-1',
            'status' => CartStatus::CONVERTED,
        ]);
        $converted->forceFill([
            'last_activity_at' => now()->subDays(10),
            'converted_at' => now()->subDays(10),
        ])->saveQuietly();

        Artisan::call(ShopCleanupCartsCommand::class, ['--force' => true]);

        $this->assertNotNull(Cart::find($converted->id));
        $this->assertSame(CartStatus::CONVERTED, Cart::find($converted->id)->status);
    }

    #[Test]
    public function summary_includes_count_of_carts_expired_and_deleted(): void
    {
        $this->staleActiveCart();
        $this->staleActiveCart();
        $this->deletionCandidateCart();

        Artisan::call(ShopCleanupCartsCommand::class, ['--force' => true]);
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/Carts expired:\s*2\b/', $output);
        $this->assertMatchesRegularExpression('/Carts deleted:\s*1\b/', $output);
    }
}
