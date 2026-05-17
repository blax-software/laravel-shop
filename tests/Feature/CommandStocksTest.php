<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Console\Commands\ShopStocksClaimsCommand;
use Blax\Shop\Console\Commands\ShopStocksCommand;
use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

class CommandStocksTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function shop_stocks_without_arg_lists_every_product_with_stock_columns(): void
    {
        $a = Product::create(['name' => 'Alpha', 'sku' => 'A', 'type' => ProductType::SIMPLE, 'status' => ProductStatus::PUBLISHED, 'manage_stock' => true, 'is_visible' => true]);
        $a->increaseStock(5);

        $b = Product::create(['name' => 'Bravo', 'sku' => 'B', 'type' => ProductType::SIMPLE, 'status' => ProductStatus::PUBLISHED, 'manage_stock' => false, 'is_visible' => true]);

        $exit = Artisan::call(ShopStocksCommand::class);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Alpha', $output);
        $this->assertStringContainsString('Bravo', $output);
        $this->assertStringContainsString('Assigned', $output);
        $this->assertStringContainsString('Available', $output);
        $this->assertStringContainsString('Claimed', $output);
        $this->assertStringContainsString('∞', $output, 'manage_stock=false products report as ∞');
    }

    #[Test]
    public function shop_stocks_with_arg_renders_a_detail_view_with_totals_and_ledger(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 09:00:00'));

        $product = Product::create([
            'name' => 'Hyperion',
            'sku' => 'HYP-1',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => true,
            'is_visible' => true,
        ]);
        $product->increaseStock(5);
        $product->decreaseStock(2);

        $exit = Artisan::call(ShopStocksCommand::class, ['product' => 'HYP-1']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Hyperion', $output);
        $this->assertStringContainsString('ASSIGNED', $output);
        $this->assertStringContainsString('USED', $output);
        $this->assertStringContainsString('AVAILABLE', $output);
        $this->assertStringContainsString('Recent stock ledger', $output);
        $this->assertStringContainsString('increase', $output);
        $this->assertStringContainsString('decrease', $output);
    }

    #[Test]
    public function shop_stocks_detail_announces_unlimited_when_stock_management_is_off(): void
    {
        Product::create([
            'name' => 'Open Manual',
            'sku' => 'OM-1',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => false,
            'is_visible' => true,
        ]);

        $exit = Artisan::call(ShopStocksCommand::class, ['product' => 'OM-1']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Stock management is OFF', $output);
    }

    #[Test]
    public function shop_stocks_fails_gracefully_for_unknown_product(): void
    {
        $exit = Artisan::call(ShopStocksCommand::class, ['product' => 'does-not-exist']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("No product matched 'does-not-exist'.", $output);
    }

    #[Test]
    public function shop_stocks_claims_lists_active_and_upcoming_claims(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 09:00:00'));

        $product = Product::create([
            'name' => 'Solitaire',
            'sku' => 'SOL-1',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => true,
            'is_visible' => true,
        ]);
        $product->increaseStock(2);
        $product->claimStock(1, null, Carbon::parse('2026-05-14 08:00:00'), Carbon::parse('2026-05-14 18:00:00'), 'active claim');
        $product->claimStock(1, null, Carbon::parse('2026-05-20 09:00:00'), Carbon::parse('2026-05-21 09:00:00'), 'future claim');

        $exit = Artisan::call(ShopStocksClaimsCommand::class, ['product' => 'SOL-1']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Solitaire', $output);
        $this->assertStringContainsString('Claim From', $output);
        $this->assertStringContainsString('active', $output);
        $this->assertStringContainsString('upcoming', $output);
        $this->assertStringContainsString('active claim', $output);
        $this->assertStringContainsString('future claim', $output);
    }

    #[Test]
    public function shop_stocks_claims_with_active_filter_hides_upcoming_claims(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 09:00:00'));

        $product = Product::create([
            'name' => 'Solitaire',
            'sku' => 'SOL-2',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => true,
            'is_visible' => true,
        ]);
        $product->increaseStock(2);
        $product->claimStock(1, null, Carbon::parse('2026-05-14 08:00:00'), Carbon::parse('2026-05-14 18:00:00'), 'active claim');
        $product->claimStock(1, null, Carbon::parse('2026-05-20 09:00:00'), Carbon::parse('2026-05-21 09:00:00'), 'future claim');

        $exit = Artisan::call(ShopStocksClaimsCommand::class, ['product' => 'SOL-2', '--active' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('active claim', $output);
        $this->assertStringNotContainsString('future claim', $output);
    }

    #[Test]
    public function shop_stocks_claims_reports_empty_when_none_pending(): void
    {
        Product::create([
            'name' => 'Quiet Book',
            'sku' => 'Q-1',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => true,
            'is_visible' => true,
        ])->increaseStock(3);

        $exit = Artisan::call(ShopStocksClaimsCommand::class, ['product' => 'Q-1']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('no pending claims found', $output);
    }
}
