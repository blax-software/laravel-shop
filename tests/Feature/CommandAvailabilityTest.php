<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Console\Commands\ShopAvailabilityCommand;
use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

class CommandAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_renders_a_calendar_for_a_simple_product_with_stock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 09:00:00'));

        $product = Product::create([
            'name' => 'Field Notebook',
            'sku' => 'NB-1',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => true,
            'is_visible' => true,
        ]);
        $product->increaseStock(3);

        $exit = Artisan::call(ShopAvailabilityCommand::class, ['product' => 'NB-1']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Field Notebook', $output);
        $this->assertStringContainsString('NB-1', $output);
        $this->assertStringContainsString('May 2026', $output);
        $this->assertStringContainsString('Full availability', $output);
        $this->assertStringContainsString('MAX AVAILABLE', $output);
        $this->assertStringContainsString('DAYS TRACKED', $output);
        $this->assertStringContainsString('LOW STOCK DAYS', $output);
        $this->assertStringContainsString('[14]', $output, 'today should be bracketed');
    }

    #[Test]
    public function it_surfaces_an_infinity_marker_when_stock_is_unmanaged(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 09:00:00'));

        Product::create([
            'name' => 'Open Source Manual',
            'sku' => 'OSM-1',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => false,
            'is_visible' => true,
        ]);

        $exit = Artisan::call(ShopAvailabilityCommand::class, ['product' => 'OSM-1']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Open Source Manual', $output);
        $this->assertStringContainsString('∞', $output);
    }

    #[Test]
    public function it_marks_out_of_stock_days_as_no_stock_in_the_stats(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 09:00:00'));

        Product::create([
            'name' => 'Sold Out Title',
            'sku' => 'SOLD-1',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => true,
            'is_visible' => true,
        ]);

        $exit = Artisan::call(ShopAvailabilityCommand::class, ['product' => 'SOLD-1']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('LOW STOCK DAYS', $output);
        // A 0-stock product across the whole month means every tracked day is a low-stock day
        $this->assertMatchesRegularExpression('/\b35\b/', $output, 'expected 35 low-stock days across the 5-week May grid');
    }

    #[Test]
    public function it_fails_gracefully_for_an_unknown_product(): void
    {
        $exit = Artisan::call(ShopAvailabilityCommand::class, ['product' => 'does-not-exist']);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("No product matched 'does-not-exist'.", $output);
    }

    #[Test]
    public function it_resolves_a_product_by_partial_name(): void
    {
        Product::create([
            'name' => 'Hyperion',
            'sku' => '9780553283686',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => true,
            'is_visible' => true,
        ])->increaseStock(2);

        $exit = Artisan::call(ShopAvailabilityCommand::class, ['product' => 'Hyper']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Hyperion', $output);
    }

    #[Test]
    public function it_renders_min_max_format_even_when_values_match(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 09:00:00'));

        Product::create([
            'name' => 'Stable Book',
            'sku' => 'STBL-1',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => true,
            'is_visible' => true,
        ])->increaseStock(3);

        $exit = Artisan::call(ShopAvailabilityCommand::class, ['product' => 'STBL-1']);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('3-3', $output, 'min-max format should be shown even when equal');
    }

    #[Test]
    public function it_renders_a_day_detail_with_the_event_timeline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 09:00:00'));

        $product = Product::create([
            'name' => 'Detail Book',
            'sku' => 'DT-1',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => true,
            'is_visible' => true,
        ]);
        $product->increaseStock(1);

        $exit = Artisan::call(ShopAvailabilityCommand::class, [
            'product' => 'DT-1',
            '--day' => '2026-05-14',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Detail Book', $output);
        $this->assertStringContainsString('Thursday, May 14, 2026', $output);
        $this->assertStringContainsString('Stock changes throughout the day', $output);
        $this->assertStringContainsString('00:00', $output);
        $this->assertStringContainsString('1 unit', $output);
        $this->assertStringContainsString('MIN STOCK', $output);
        $this->assertStringContainsString('MAX STOCK', $output);
    }

    #[Test]
    public function day_detail_announces_unlimited_when_stock_management_is_off(): void
    {
        Product::create([
            'name' => 'Open Source Manual',
            'sku' => 'OSM-2',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => false,
            'is_visible' => true,
        ]);

        $exit = Artisan::call(ShopAvailabilityCommand::class, [
            'product' => 'OSM-2',
            '--day' => '2026-05-14',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Unlimited availability all day', $output);
    }

    #[Test]
    public function it_honours_the_from_and_to_options(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 09:00:00'));

        Product::create([
            'name' => 'Forever Book',
            'sku' => 'FB-1',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => true,
            'is_visible' => true,
        ])->increaseStock(1);

        $exit = Artisan::call(ShopAvailabilityCommand::class, [
            'product' => 'FB-1',
            '--from' => '2026-07-01',
            '--to' => '2026-07-31',
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('July 2026', $output);
    }
}
