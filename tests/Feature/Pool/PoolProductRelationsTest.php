<?php

namespace Blax\Shop\Tests\Feature\Pool;

use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;


class PoolProductRelationsTest extends TestCase
{
    #[Test]
    public function it_creates_reverse_pool_relation_when_attaching_single_items()
    {
        // Create pool product
        $pool = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
        ]);

        // Create single items
        $spot1 = Product::factory()->create([
            'name' => 'Parking Spot 1',
            'type' => ProductType::BOOKING,
        ]);

        $spot2 = Product::factory()->create([
            'name' => 'Parking Spot 2',
            'type' => ProductType::BOOKING,
        ]);

        // Use the helper method to attach single items
        $pool->attachSingleItems([$spot1->id, $spot2->id]);

        // Assert pool has single items
        $this->assertEquals(2, $pool->singleProducts()->count());
        $this->assertTrue($pool->singleProducts->contains($spot1));
        $this->assertTrue($pool->singleProducts->contains($spot2));

        // Assert single items have reverse pool relation
        $this->assertEquals(1, $spot1->poolProducts()->count());
        $this->assertEquals(1, $spot2->poolProducts()->count());
        $this->assertTrue($spot1->poolProducts->contains($pool));
        $this->assertTrue($spot2->poolProducts->contains($pool));

        // Verify pivot type
        $spot1Pivot = $spot1->productRelations()->where('related_product_id', $pool->id)->first();
        $this->assertEquals(ProductRelationType::POOL->value, $spot1Pivot->pivot->type);
    }

    #[Test]
    public function it_can_attach_single_item_using_id()
    {
        $pool = Product::factory()->create([
            'name' => 'Parking Pool',
            'type' => ProductType::POOL,
        ]);

        $spot = Product::factory()->create([
            'name' => 'Parking Spot',
            'type' => ProductType::BOOKING,
        ]);

        // Attach single ID (not array)
        $pool->attachSingleItems($spot->id);

        $this->assertEquals(1, $pool->singleProducts()->count());
        $this->assertTrue($pool->singleProducts->contains($spot));
        $this->assertEquals(1, $spot->poolProducts()->count());
        $this->assertTrue($spot->poolProducts->contains($pool));
    }

    #[Test]
    public function it_throws_exception_when_non_pool_tries_to_attach_single_items()
    {
        $regularProduct = Product::factory()->create([
            'type' => ProductType::SIMPLE,
        ]);

        $spot = Product::factory()->create([
            'type' => ProductType::BOOKING,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('This method is only for pool products');
        $regularProduct->attachSingleItems($spot->id);
    }

    #[Test]
    public function single_item_can_belong_to_multiple_pools()
    {
        // Create two pools
        $pool1 = Product::factory()->create([
            'name' => 'Zone A Pool',
            'type' => ProductType::POOL,
        ]);

        $pool2 = Product::factory()->create([
            'name' => 'Zone B Pool',
            'type' => ProductType::POOL,
        ]);

        // Create single item
        $spot = Product::factory()->create([
            'name' => 'Flexible Spot',
            'type' => ProductType::BOOKING,
        ]);

        // Attach to both pools
        $pool1->attachSingleItems($spot->id);
        $pool2->attachSingleItems($spot->id);

        // Assert spot belongs to both pools
        $this->assertEquals(2, $spot->poolProducts()->count());
        $this->assertTrue($spot->poolProducts->contains($pool1));
        $this->assertTrue($spot->poolProducts->contains($pool2));

        // Assert each pool has the spot
        $this->assertTrue($pool1->singleProducts->contains($spot));
        $this->assertTrue($pool2->singleProducts->contains($spot));
    }

    #[Test]
    public function it_can_get_all_pools_for_a_single_item()
    {
        $pool1 = Product::factory()->create(['type' => ProductType::POOL, 'name' => 'Pool 1']);
        $pool2 = Product::factory()->create(['type' => ProductType::POOL, 'name' => 'Pool 2']);
        $pool3 = Product::factory()->create(['type' => ProductType::POOL, 'name' => 'Pool 3']);

        $spot = Product::factory()->create(['type' => ProductType::BOOKING, 'name' => 'Spot']);

        $pool1->attachSingleItems($spot->id);
        $pool2->attachSingleItems($spot->id);
        $pool3->attachSingleItems($spot->id);

        $pools = $spot->poolProducts;

        $this->assertCount(3, $pools);
        $this->assertTrue($pools->contains('name', 'Pool 1'));
        $this->assertTrue($pools->contains('name', 'Pool 2'));
        $this->assertTrue($pools->contains('name', 'Pool 3'));
    }

    #[Test]
    public function legacy_manual_attach_still_works()
    {
        // Test that old way of attaching still works (without reverse relation)
        $pool = Product::factory()->create([
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);
        $spot = Product::factory()->create(['type' => ProductType::BOOKING]);

        // Old way using productRelations()->attach() directly
        $pool->productRelations()->attach($spot->id, ['type' => ProductRelationType::SINGLE->value]);

        // Pool should have the single item
        $this->assertEquals(1, $pool->singleProducts()->count());
        $this->assertTrue($pool->singleProducts->contains($spot));

        // But single item won't have reverse relation (unless manually added)
        // This is expected behavior for legacy code
        $this->assertEquals(0, $spot->poolProducts()->count());
    }
}
