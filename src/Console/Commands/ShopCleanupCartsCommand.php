<?php

namespace Blax\Shop\Console\Commands;

use Blax\Shop\Facades\Shop;
use Blax\Shop\Models\Cart;
use Illuminate\Console\Command;

class ShopCleanupCartsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shop:cleanup-carts
        {--expire : Only expire stale carts without deleting}
        {--delete : Only delete old carts without expiring}
        {--dry-run : Show what would be done without making changes}
        {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire stale carts and delete old unused carts';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $onlyExpire = $this->option('expire');
        $onlyDelete = $this->option('delete');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        // If neither flag is set, do both
        $doExpire = !$onlyDelete || $onlyExpire;
        $doDelete = !$onlyExpire || $onlyDelete;

        $expirationMinutes = config('shop.cart.expiration_minutes', 60);
        $deletionHours = config('shop.cart.deletion_hours', 24);

        $this->info('Cart Cleanup');
        $this->info('============');
        $this->newLine();

        // Show configuration
        $this->info("Configuration:");
        $this->line("  • Expiration threshold: {$expirationMinutes} minutes of inactivity");
        $this->line("  • Deletion threshold: {$deletionHours} hours of inactivity");
        $this->newLine();

        $expiredCount = 0;
        $deletedCount = 0;

        // Handle expiration
        if ($doExpire) {
            $cartsToExpire = Cart::shouldExpire()->get();
            $expiredCount = $cartsToExpire->count();

            $this->info("Carts to expire: {$expiredCount}");

            if ($expiredCount > 0) {
                if ($dryRun) {
                    $this->warn("  [DRY RUN] Would expire {$expiredCount} carts");
                    $this->table(
                        ['ID', 'Customer', 'Items', 'Last Activity', 'Created'],
                        $cartsToExpire->map(fn($cart) => [
                            substr($cart->id, 0, 8) . '...',
                            $cart->customer_id ? substr($cart->customer_id, 0, 8) . '...' : 'Guest',
                            $cart->items()->count(),
                            $cart->last_activity_at?->diffForHumans() ?? $cart->updated_at->diffForHumans(),
                            $cart->created_at->diffForHumans(),
                        ])->toArray()
                    );
                } else {
                    Shop::expireStaleCarts();
                    $this->info("  ✓ Expired {$expiredCount} carts");
                }
            }
            $this->newLine();
        }

        // Handle deletion
        if ($doDelete) {
            $cartsToDelete = Cart::shouldDelete()->get();
            $deletedCount = $cartsToDelete->count();

            $this->info("Carts to delete: {$deletedCount}");

            if ($deletedCount > 0) {
                if ($dryRun) {
                    $this->warn("  [DRY RUN] Would delete {$deletedCount} carts");
                    $this->table(
                        ['ID', 'Status', 'Customer', 'Items', 'Last Activity', 'Created'],
                        $cartsToDelete->map(fn($cart) => [
                            substr($cart->id, 0, 8) . '...',
                            $cart->status->value,
                            $cart->customer_id ? substr($cart->customer_id, 0, 8) . '...' : 'Guest',
                            $cart->items()->count(),
                            $cart->last_activity_at?->diffForHumans() ?? $cart->updated_at->diffForHumans(),
                            $cart->created_at->diffForHumans(),
                        ])->toArray()
                    );
                } else {
                    if (!$force && !$this->confirm("Delete {$deletedCount} carts permanently?")) {
                        $this->info('Deletion cancelled.');
                        return self::SUCCESS;
                    }

                    Shop::deleteOldCarts();
                    $this->info("  ✓ Deleted {$deletedCount} carts");
                }
            }
            $this->newLine();
        }

        // Summary
        $this->info('Summary');
        $this->info('-------');
        if ($dryRun) {
            $this->warn('[DRY RUN] No changes were made');
        }
        $this->line("  • Carts expired: {$expiredCount}");
        $this->line("  • Carts deleted: {$deletedCount}");

        return self::SUCCESS;
    }
}
