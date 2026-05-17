<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Console\Commands\ShopListProductsCommand;
use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductAction;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

/**
 * shop:list:products is the operator's catalogue browser — every filter
 * combination should narrow the set predictably and the optional --with-X
 * flags should add their respective columns.
 */
class CommandListProductsTest extends TestCase
{
    use RefreshDatabase;

    private function newProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Product '.uniqid(),
            'sku' => 'P-'.uniqid(),
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => false,
        ], $overrides));
    }

    #[Test]
    public function unfiltered_call_lists_every_product_with_total_count(): void
    {
        $this->newProduct(['name' => 'Alpha']);
        $this->newProduct(['name' => 'Bravo']);

        $exit = Artisan::call(ShopListProductsCommand::class);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Alpha', $output);
        $this->assertStringContainsString('Bravo', $output);
        $this->assertStringContainsString('Total products: 2', $output);
    }

    #[Test]
    public function empty_catalogue_renders_the_empty_state_message(): void
    {
        $exit = Artisan::call(ShopListProductsCommand::class);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('No products found.', $output);
    }

    #[Test]
    public function status_filter_narrows_results(): void
    {
        $this->newProduct(['name' => 'Live Product', 'status' => ProductStatus::PUBLISHED]);
        $this->newProduct(['name' => 'Draft Product', 'status' => ProductStatus::DRAFT]);

        Artisan::call(ShopListProductsCommand::class, ['--status' => 'draft']);
        $output = Artisan::output();

        $this->assertStringContainsString('Draft Product', $output);
        $this->assertStringNotContainsString('Live Product', $output);
        $this->assertStringContainsString('Total products: 1', $output);
    }

    #[Test]
    public function visible_filter_and_hidden_filter_are_mutually_exclusive(): void
    {
        $this->newProduct(['name' => 'On Shelf', 'is_visible' => true]);
        $this->newProduct(['name' => 'Off Shelf', 'is_visible' => false]);

        Artisan::call(ShopListProductsCommand::class, ['--visible' => true]);
        $visibleOutput = Artisan::output();
        $this->assertStringContainsString('On Shelf', $visibleOutput);
        $this->assertStringNotContainsString('Off Shelf', $visibleOutput);

        Artisan::call(ShopListProductsCommand::class, ['--hidden' => true]);
        $hiddenOutput = Artisan::output();
        $this->assertStringContainsString('Off Shelf', $hiddenOutput);
        $this->assertStringNotContainsString('On Shelf', $hiddenOutput);
    }

    #[Test]
    public function type_filter_narrows_by_product_type(): void
    {
        $this->newProduct(['name' => 'Simple One', 'type' => ProductType::SIMPLE]);
        $this->newProduct(['name' => 'Loanable One', 'type' => ProductType::LOANABLE]);

        Artisan::call(ShopListProductsCommand::class, ['--type' => 'loanable']);
        $output = Artisan::output();

        $this->assertStringContainsString('Loanable One', $output);
        $this->assertStringNotContainsString('Simple One', $output);
    }

    #[Test]
    public function with_actions_flag_adds_an_actions_column_with_per_product_count(): void
    {
        $a = $this->newProduct(['name' => 'Has Actions']);
        $b = $this->newProduct(['name' => 'Bare']);

        ProductAction::create([
            'product_id' => $a->id,
            'events' => ['purchased'],
            'class' => 'App\\Welcome',
            'active' => true,
        ]);
        ProductAction::create([
            'product_id' => $a->id,
            'events' => ['refunded'],
            'class' => 'App\\Apology',
            'active' => true,
        ]);

        Artisan::call(ShopListProductsCommand::class, ['--with-actions' => true]);
        $output = Artisan::output();

        // The "Actions" column appears in the header.
        $this->assertStringContainsString('Actions', $output);
        // And the count for "Has Actions" must be 2.
        $this->assertMatchesRegularExpression('/Has Actions.*?\b2\b/', $output);
        // "Bare" still appears, with 0 actions.
        $this->assertStringContainsString('Bare', $output);
    }
}
