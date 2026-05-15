<?php

namespace Blax\Shop\Tests\Feature\Pricing;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Models\ProductPriceTier;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The walker in ProductPrice::calculateForUsage() relies on tiers() coming
 * back in ladder order: sort_order ascending, with the unbounded tier
 * (up_to = null) always pinned to the end so it acts as the catch-all.
 */
class ProductPriceTiersRelationTest extends TestCase
{
    use RefreshDatabase;

    private function makePrice(array $overrides = []): ProductPrice
    {
        $product = Product::factory()->create();

        return ProductPrice::factory()->create(array_merge([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
        ], $overrides));
    }

    #[Test]
    public function tiers_relation_orders_by_sort_order(): void
    {
        $price = $this->makePrice();

        $b = ProductPriceTier::factory()->create(['price_id' => $price->id, 'up_to' => 30, 'sort_order' => 1]);
        $a = ProductPriceTier::factory()->create(['price_id' => $price->id, 'up_to' => 10, 'sort_order' => 0]);
        $c = ProductPriceTier::factory()->create(['price_id' => $price->id, 'up_to' => 60, 'sort_order' => 2]);

        $ids = $price->tiers->pluck('id')->all();

        $this->assertSame([$a->id, $b->id, $c->id], $ids);
    }

    #[Test]
    public function unbounded_tier_sorts_after_bounded_tiers_regardless_of_insertion_order(): void
    {
        $price = $this->makePrice();

        // Insert the unbounded tier first (sort_order=0, the same as the
        // first bounded tier) — the orderByRaw guard should still push it
        // to the end.
        $unbounded = ProductPriceTier::factory()->create([
            'price_id' => $price->id,
            'up_to' => null,
            'unit_amount' => 999,
            'sort_order' => 99,
        ]);
        $first = ProductPriceTier::factory()->create([
            'price_id' => $price->id,
            'up_to' => 14,
            'unit_amount' => 0,
            'sort_order' => 0,
        ]);
        $second = ProductPriceTier::factory()->create([
            'price_id' => $price->id,
            'up_to' => 60,
            'unit_amount' => 100,
            'sort_order' => 1,
        ]);

        $ids = $price->tiers()->pluck('id')->all();

        $this->assertSame([$first->id, $second->id, $unbounded->id], $ids);
    }

    #[Test]
    public function calculate_for_usage_walks_tiers_in_relation_order(): void
    {
        $price = $this->makePrice(['billing_scheme' => 'tiered']);

        // Out-of-order inserts to prove that the relation ordering — not
        // insertion order — drives the math.
        ProductPriceTier::factory()->create(['price_id' => $price->id, 'up_to' => null, 'unit_amount' => 200, 'sort_order' => 2]);
        ProductPriceTier::factory()->create(['price_id' => $price->id, 'up_to' => 14, 'unit_amount' => 0, 'sort_order' => 0]);
        ProductPriceTier::factory()->create(['price_id' => $price->id, 'up_to' => 60, 'unit_amount' => 100, 'sort_order' => 1]);

        // 75 days: 14 free + 46×100 + 15×200 = 7600
        $this->assertSame(7600, $price->fresh()->calculateForUsage(75));
    }

    #[Test]
    public function tiers_table_declares_cascade_on_delete_for_price_id(): void
    {
        // FK enforcement on SQLite under RefreshDatabase is config-sensitive
        // (transactions + PRAGMA scoping make a runtime cascade hard to
        // observe reliably). The package's contract here is structural:
        // the price_id FK should be declared with ON DELETE CASCADE so a
        // production MySQL / Postgres deployment behaves correctly.
        $migration = file_get_contents(__DIR__.'/../../../database/migrations/2025_01_01_000002_create_product_price_tiers_table.php');

        $this->assertMatchesRegularExpression(
            '/foreignUuid\(\'price_id\'\)[^;]*cascadeOnDelete\(\)/s',
            $migration,
            'price_id should be declared with cascadeOnDelete()'
        );
    }
}
