<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Console\Commands\ReleaseExpiredStocks;
use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

/**
 * shop:release-expired-stocks delegates to ProductStock::releaseExpired().
 * The command should: report the released count, honour the
 * shop.stock.auto_release_expired config flag, and be a no-op when nothing
 * has actually expired.
 */
class CommandReleaseExpiredStocksTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        return Product::create([
            'name' => 'Reservable',
            'sku' => 'RES-'.uniqid(),
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => true,
        ]);
    }

    #[Test]
    public function returns_zero_count_when_nothing_has_expired(): void
    {
        $product = $this->product();
        $product->increaseStock(5);
        // An active, unexpired claim that should NOT be released.
        $product->claimStock(
            1,
            null,
            Carbon::now()->subMinutes(5),
            Carbon::now()->addHours(2),
            'active claim',
        );

        $exit = Artisan::call(ReleaseExpiredStocks::class);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Released 0 expired stock claim(s).', $output);
    }

    #[Test]
    public function releases_only_expired_claims_and_reports_the_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));

        $product = $this->product();
        $product->increaseStock(3);

        // Expired claim — claim window ended an hour ago.
        $product->claimStock(
            1,
            null,
            Carbon::parse('2026-05-14 08:00:00'),
            Carbon::parse('2026-05-14 09:00:00'),
            'expired claim',
        );
        // Active claim — still open.
        $product->claimStock(
            1,
            null,
            Carbon::parse('2026-05-14 09:30:00'),
            Carbon::parse('2026-05-14 18:00:00'),
            'active claim',
        );

        $exit = Artisan::call(ReleaseExpiredStocks::class);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Released 1 expired stock claim(s).', $output);

        // The expired claim flipped to COMPLETED status (released), the active
        // one still has PENDING.
        $pendingClaims = $product->stocks()
            ->where('type', StockType::CLAIMED->value)
            ->where('status', StockStatus::PENDING->value)
            ->get();
        $this->assertCount(1, $pendingClaims);
        $this->assertSame('active claim', $pendingClaims->first()->note);
    }

    #[Test]
    public function short_circuits_when_auto_release_is_disabled_in_config(): void
    {
        config(['shop.stock.auto_release_expired' => false]);

        $product = $this->product();
        $product->increaseStock(2);
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));
        $product->claimStock(
            1,
            null,
            Carbon::parse('2026-05-14 08:00:00'),
            Carbon::parse('2026-05-14 09:00:00'),
            'would-be-released',
        );

        $exit = Artisan::call(ReleaseExpiredStocks::class);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Auto-release is disabled in config.', $output);

        // The claim is still PENDING since the command bailed.
        $stillPending = $product->stocks()
            ->where('type', StockType::CLAIMED->value)
            ->where('status', StockStatus::PENDING->value)
            ->count();
        $this->assertSame(1, $stillPending);
    }
}
