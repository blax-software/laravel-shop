<?php

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductRelationsTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function it_filters_relations_by_relation_type()
    {
        $product = Product::factory()->create();
        $related = Product::factory()->create();
        $upsell = Product::factory()->create();
        $crossSell = Product::factory()->create();

        $product->productRelations()->attach($related->id, [
            'type' => ProductRelationType::RELATED->value,
            'sort_order' => 1,
        ]);

        $product->productRelations()->attach($upsell->id, [
            'type' => ProductRelationType::UPSELL->value,
            'sort_order' => 2,
        ]);

        $product->productRelations()->attach($crossSell->id, [
            'type' => ProductRelationType::CROSS_SELL->value,
            'sort_order' => 3,
        ]);

        $this->assertTrue($product->relatedProducts()->get()->contains($related));
        $this->assertTrue($product->upsellProducts()->get()->contains($upsell));
        $this->assertTrue($product->crossSellProducts()->get()->contains($crossSell));

        $this->assertSame(1, $product->relatedProducts()->first()->pivot->sort_order);
    }

    /** @test */
    public function relations_by_type_accepts_enum_or_string()
    {
        $product = Product::factory()->create();
        $related = Product::factory()->create();

        $product->productRelations()->attach($related->id, [
            'type' => ProductRelationType::RELATED->value,
        ]);

        $this->assertTrue($product->relationsByType(ProductRelationType::RELATED)->exists());
        $this->assertTrue($product->relationsByType('related')->exists());
    }

    /** @test */
    public function it_can_be_queried_by_specific_relation_types()
    {
        $withUpsell = Product::factory()->create();
        $upsell = Product::factory()->create();
        $withCrossSell = Product::factory()->create();
        $crossSell = Product::factory()->create();
        $unrelated = Product::factory()->create();

        $withUpsell->productRelations()->attach($upsell->id, [
            'type' => ProductRelationType::UPSELL->value,
            'sort_order' => 1,
        ]);

        $withCrossSell->productRelations()->attach($crossSell->id, [
            'type' => ProductRelationType::CROSS_SELL->value,
            'sort_order' => 2,
        ]);

        $upsellMatch = Product::whereHas('upsellProducts', function ($query) use ($upsell) {
            $query->whereKey($upsell->id);
        })->get();

        $crossSellMatch = Product::whereHas('crossSellProducts', function ($query) use ($crossSell) {
            $query->whereKey($crossSell->id);
        })->get();

        $this->assertTrue($upsellMatch->contains($withUpsell));
        $this->assertFalse($upsellMatch->contains($withCrossSell));
        $this->assertFalse($upsellMatch->contains($unrelated));

        $this->assertTrue($crossSellMatch->contains($withCrossSell));
        $this->assertFalse($crossSellMatch->contains($withUpsell));
        $this->assertFalse($crossSellMatch->contains($unrelated));
    }
}
