<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Console\Commands\ShopListPurchasesCommand;
use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * shop:list:purchases is the cross-cutting view of every consumption event
 * (loan, booking, sale). The command supports three filters (product,
 * purchaser, status) plus --limit; this file exercises each one and the
 * empty-state branch.
 */
class CommandListPurchasesTest extends TestCase
{
    use RefreshDatabase;

    private Product $book;
    private User $alice;
    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->book = Product::create([
            'name' => 'Hyperion',
            'sku' => 'HYP-LP',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => false,
        ]);
        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
    }

    private function purchase(User $purchaser, PurchaseStatus $status, int $amountPaid = 0): ProductPurchase
    {
        return ProductPurchase::create([
            'purchasable_id' => $this->book->id,
            'purchasable_type' => Product::class,
            'purchaser_id' => $purchaser->id,
            'purchaser_type' => User::class,
            'quantity' => 1,
            'amount' => 2500,
            'amount_paid' => $amountPaid,
            'status' => $status,
        ]);
    }

    #[Test]
    public function empty_state_shows_the_no_purchases_message(): void
    {
        $exit = Artisan::call(ShopListPurchasesCommand::class);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No purchases found.', $output);
    }

    #[Test]
    public function unfiltered_call_lists_every_purchase_with_purchasable_and_purchaser_names(): void
    {
        $this->purchase($this->alice, PurchaseStatus::COMPLETED, 2500);
        $this->purchase($this->bob, PurchaseStatus::PENDING);

        Artisan::call(ShopListPurchasesCommand::class);
        $output = Artisan::output();

        $this->assertStringContainsString('Hyperion', $output);
        $this->assertStringContainsString('Alice', $output);
        $this->assertStringContainsString('Bob', $output);
        $this->assertStringContainsString('Showing 2 purchase(s)', $output);
    }

    #[Test]
    public function product_argument_scopes_to_one_purchasable(): void
    {
        $other = Product::create([
            'name' => 'Other Book',
            'sku' => 'OTH-LP',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => false,
        ]);
        $this->purchase($this->alice, PurchaseStatus::COMPLETED);
        ProductPurchase::create([
            'purchasable_id' => $other->id,
            'purchasable_type' => Product::class,
            'purchaser_id' => $this->bob->id,
            'purchaser_type' => User::class,
            'quantity' => 1,
            'amount' => 0,
            'amount_paid' => 0,
            'status' => PurchaseStatus::COMPLETED,
        ]);

        Artisan::call(ShopListPurchasesCommand::class, ['product' => $this->book->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('Hyperion', $output);
        $this->assertStringNotContainsString('Other Book', $output);
        $this->assertStringContainsString('Showing 1 purchase(s)', $output);
    }

    #[Test]
    public function purchaser_option_filters_by_buyer_id(): void
    {
        $this->purchase($this->alice, PurchaseStatus::COMPLETED);
        $this->purchase($this->bob, PurchaseStatus::COMPLETED);

        Artisan::call(ShopListPurchasesCommand::class, ['--purchaser' => $this->bob->id]);
        $output = Artisan::output();

        $this->assertStringContainsString('Bob', $output);
        $this->assertStringNotContainsString('Alice', $output);
        $this->assertStringContainsString('Showing 1 purchase(s)', $output);
    }

    #[Test]
    public function status_option_filters_by_purchase_status(): void
    {
        $this->purchase($this->alice, PurchaseStatus::COMPLETED);
        $this->purchase($this->bob, PurchaseStatus::PENDING);

        Artisan::call(ShopListPurchasesCommand::class, ['--status' => PurchaseStatus::PENDING->value]);
        $output = Artisan::output();

        $this->assertStringContainsString('Bob', $output);
        $this->assertStringNotContainsString('Alice', $output);
    }

    #[Test]
    public function limit_option_caps_the_result_set(): void
    {
        // Three purchases — cap to 2.
        $this->purchase($this->alice, PurchaseStatus::COMPLETED);
        $this->purchase($this->bob, PurchaseStatus::COMPLETED);
        $this->purchase(User::factory()->create(['name' => 'Cara']), PurchaseStatus::COMPLETED);

        Artisan::call(ShopListPurchasesCommand::class, ['--limit' => 2]);
        $output = Artisan::output();

        $this->assertStringContainsString('Showing 2 purchase(s)', $output);
    }

    #[Test]
    public function falls_back_to_id_when_purchasable_or_purchaser_was_deleted(): void
    {
        // Models can vanish (soft- or hard-deletes); the describe helpers must
        // not crash and should render a truncated ID instead of a blank cell.
        $purchase = $this->purchase($this->alice, PurchaseStatus::COMPLETED);
        $this->book->delete();
        $this->alice->delete();

        $exit = Artisan::call(ShopListPurchasesCommand::class);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('ID:', $output, 'fallback "ID: …" labels render for missing models');
    }
}
