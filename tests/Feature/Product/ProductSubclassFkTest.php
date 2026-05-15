<?php

namespace Blax\Shop\Tests\Feature\Product;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductAction;
use Blax\Shop\Models\ProductAttribute;
use Blax\Shop\Models\ProductStock;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regression test for the FK-on-subclass package fix.
 *
 * Eloquent's `hasMany` infers the foreign key from the parent model class
 * name. Without an explicit `'product_id'` the relation on a Product
 * subclass would look for `book_id` / `widget_id` / etc., breaking
 * stocks(), attributes() and actions().
 *
 * This test exercises a Product subclass (`SubclassedProduct`) and asserts
 * that those relations still hit `product_id` and resolve cleanly.
 */
class ProductSubclassFkTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function has_stocks_works_for_a_product_subclass(): void
    {
        $product = SubclassedProduct::create([
            'name' => 'Subclassed',
            'type' => ProductType::SIMPLE,
            'manage_stock' => true,
        ]);

        // increaseStock writes a row in product_stocks and reads it back.
        // If the trait inferred `subclassed_product_id` the insert would
        // fail; the SELECT in getAvailableStock() would silently return 0.
        $product->increaseStock(7);

        $this->assertSame(7, $product->getAvailableStock());
        $this->assertInstanceOf(ProductStock::class, $product->stocks()->first());
        $this->assertSame((string) $product->id, (string) $product->stocks()->first()->product_id);
    }

    #[Test]
    public function attributes_relation_works_for_a_product_subclass(): void
    {
        $product = SubclassedProduct::create([
            'name' => 'Subclassed',
            'type' => ProductType::SIMPLE,
        ]);

        ProductAttribute::create([
            'product_id' => $product->id,
            'key' => 'colour',
            'value' => 'red',
        ]);

        $this->assertCount(1, $product->attributes()->get());
        $this->assertSame('colour', $product->attributes()->first()->key);
    }

    #[Test]
    public function actions_relation_works_for_a_product_subclass(): void
    {
        $product = SubclassedProduct::create([
            'name' => 'Subclassed',
            'type' => ProductType::SIMPLE,
        ]);

        ProductAction::create([
            'product_id' => $product->id,
            'events' => ['purchased'],
            'class' => 'App\\Jobs\\SendReceipt',
            'method' => 'handle',
            'active' => true,
        ]);

        $this->assertCount(1, $product->actions()->get());
    }

    #[Test]
    public function eloquent_morph_map_for_purchasable_resolves_subclass(): void
    {
        $product = SubclassedProduct::create([
            'name' => 'Subclassed',
            'type' => ProductType::SIMPLE,
            'manage_stock' => false,
        ]);

        // The polymorphic purchases() relation should hit
        // purchasable_type=SubclassedProduct, not Product.
        $purchase = $product->purchases()->create([
            'purchaser_type' => 'App\\Models\\User',
            'purchaser_id' => '00000000-0000-0000-0000-000000000000',
            'quantity' => 1,
            'amount' => 0,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $this->assertSame(SubclassedProduct::class, $purchase->purchasable_type);
        $this->assertCount(1, $product->purchases()->get());
    }
}

/**
 * Bare Product subclass for the FK-invariant test. Lives in the test file
 * because it's not useful elsewhere — its only job is to exist as
 * "a Product subclass" so Eloquent's default conventions get challenged.
 */
class SubclassedProduct extends Product
{
    // Intentionally empty.
}
