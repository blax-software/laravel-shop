<?php

namespace Blax\Shop\Tests\Unit\Models;

use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Models\ProductPriceTier;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class ProductPriceTierTest extends TestCase
{
    use RefreshDatabase;

    private function makePrice(): ProductPrice
    {
        $product = Product::factory()->create();

        return ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
        ]);
    }

    #[Test]
    public function it_uses_the_configured_table_name(): void
    {
        $tier = new ProductPriceTier();

        $this->assertSame(
            config('shop.tables.product_price_tiers', 'product_price_tiers'),
            $tier->getTable(),
        );
    }

    #[Test]
    public function casts_keep_integers_and_object_meta(): void
    {
        $tier = ProductPriceTier::factory()->create([
            'price_id' => $this->makePrice()->id,
            'up_to' => 14,
            'unit_amount' => 199,
            'flat_amount' => 500,
            'sort_order' => 3,
            'meta' => ['note' => 'first-tier grace'],
        ]);
        $tier->refresh();

        $this->assertSame(14, $tier->up_to);
        $this->assertIsInt($tier->up_to);
        $this->assertSame(199, $tier->unit_amount);
        $this->assertSame(500, $tier->flat_amount);
        $this->assertSame(3, $tier->sort_order);
        $this->assertIsObject($tier->meta);
        $this->assertSame('first-tier grace', $tier->meta->note);
    }

    #[Test]
    public function up_to_can_be_null_to_represent_an_unbounded_tier(): void
    {
        $tier = ProductPriceTier::factory()->create([
            'price_id' => $this->makePrice()->id,
            'up_to' => null,
            'unit_amount' => 200,
        ]);

        $this->assertNull($tier->up_to);
    }

    #[Test]
    public function price_relation_resolves_back_to_the_parent_price(): void
    {
        $price = $this->makePrice();
        $tier = ProductPriceTier::factory()->create([
            'price_id' => $price->id,
            'unit_amount' => 99,
        ]);

        $this->assertInstanceOf(ProductPrice::class, $tier->price);
        $this->assertSame($price->id, $tier->price->id);
    }
}
