<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Console\Commands\ShopStatsCommand;
use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Order;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductAction;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

/**
 * shop:stats renders an at-a-glance dashboard of every domain object in the
 * package (products, actions, purchases, carts, orders). The command reads
 * counts straight off each model, so a future schema or scope change shows up
 * here as wrong totals — earlier than any HTTP/UI integration would catch it.
 */
class CommandStatsTest extends TestCase
{
    use RefreshDatabase;

    private function newProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Stat Product '.uniqid(),
            'sku' => 'STAT-'.uniqid(),
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => false,
        ], $overrides));
    }

    #[Test]
    public function shop_stats_runs_against_an_empty_database_with_zeroes(): void
    {
        $exit = Artisan::call(ShopStatsCommand::class);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('=== Shop Statistics ===', $output);
        $this->assertStringContainsString('Products: total', $output);
        $this->assertStringContainsString('Purchases: total', $output);
        $this->assertStringContainsString('Revenue (paid)', $output);
    }

    #[Test]
    public function shop_stats_breaks_products_down_by_status_and_visibility(): void
    {
        $this->newProduct(['status' => ProductStatus::PUBLISHED, 'is_visible' => true]);
        $this->newProduct(['status' => ProductStatus::PUBLISHED, 'is_visible' => false]);
        $this->newProduct(['status' => ProductStatus::DRAFT, 'is_visible' => true]);

        Artisan::call(ShopStatsCommand::class);
        $output = Artisan::output();

        // 3 total, 2 published, 2 visible — the command renders these as
        // standard table rows; we check the values appear adjacent to their
        // labels rather than as raw numbers (which could collide with other
        // row totals).
        $this->assertMatchesRegularExpression('/Products: total\s*\|\s*3\b/', $output);
        $this->assertMatchesRegularExpression('/Products: published\s*\|\s*2\b/', $output);
        $this->assertMatchesRegularExpression('/Products: visible\s*\|\s*2\b/', $output);
    }

    #[Test]
    public function shop_stats_breaks_actions_down_by_active_flag(): void
    {
        $product = $this->newProduct();
        ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Foo',
            'active' => true,
        ]);
        ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Bar',
            'active' => false,
        ]);

        Artisan::call(ShopStatsCommand::class);
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/Actions: total\s*\|\s*2\b/', $output);
        $this->assertMatchesRegularExpression('/Actions: active\s*\|\s*1\b/', $output);
        $this->assertMatchesRegularExpression('/Actions: inactive\s*\|\s*1\b/', $output);
    }

    #[Test]
    public function shop_stats_groups_purchases_by_status_and_sums_revenue(): void
    {
        $product = $this->newProduct();

        $userType = 'App\\Models\\User';
        ProductPurchase::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'purchaser_id' => 'u1',
            'purchaser_type' => $userType,
            'quantity' => 1,
            'amount' => 2500,
            'amount_paid' => 2500,
            'status' => PurchaseStatus::COMPLETED,
        ]);
        ProductPurchase::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'purchaser_id' => 'u2',
            'purchaser_type' => $userType,
            'quantity' => 1,
            'amount' => 1500,
            'amount_paid' => 1500,
            'status' => PurchaseStatus::COMPLETED,
        ]);
        ProductPurchase::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'purchaser_id' => 'u3',
            'purchaser_type' => $userType,
            'quantity' => 1,
            'amount' => 1000,
            'amount_paid' => 0,
            'status' => PurchaseStatus::PENDING,
        ]);

        Artisan::call(ShopStatsCommand::class);
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/Purchases: total\s*\|\s*3\b/', $output);
        $this->assertMatchesRegularExpression('/Purchases: completed\s*\|\s*2\b/', $output);
        $this->assertMatchesRegularExpression('/Purchases: pending\s*\|\s*1\b/', $output);

        // Revenue = sum(amount_paid) / 100 → 4000 cents = 40.00.
        $this->assertMatchesRegularExpression('/Revenue \(paid\)\s*\|\s*40\.00\b/', $output);
    }

    #[Test]
    public function shop_stats_reports_physical_units_summed_across_managed_products(): void
    {
        // Two managed products: 10 tomatoes (physical=10), library book with
        // 5 copies one of which is on loan (physical=5 since loans count).
        // Plus an unmanaged eBook that must NOT be summed in (would render ∞).
        $tomato = $this->newProduct(['manage_stock' => true]);
        $tomato->increaseStock(10);

        $book = Product::create([
            'name' => 'Library Book',
            'sku' => 'STAT-BOOK',
            'type' => ProductType::LOANABLE,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => true,
        ]);
        $book->increaseStock(5);
        $book->checkOutTo(\Workbench\App\Models\User::factory()->create());

        $this->newProduct(['manage_stock' => false]); // unmanaged, must skip

        Artisan::call(ShopStatsCommand::class);
        $output = Artisan::output();

        // Tomato 10 + Book 5 (loaned copy still counts) = 15 physical units.
        $this->assertMatchesRegularExpression('/Products: physical units\s*\|\s*15\b/', $output);
    }

    #[Test]
    public function shop_stats_includes_carts_and_orders_when_models_are_configured(): void
    {
        Cart::create(['session_id' => 'sess-stats-1']);
        Cart::create(['session_id' => 'sess-stats-2']);
        Order::create(['currency' => 'EUR']);

        Artisan::call(ShopStatsCommand::class);
        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/Carts: total\s*\|\s*2\b/', $output);
        $this->assertMatchesRegularExpression('/Orders: total\s*\|\s*1\b/', $output);
    }
}
