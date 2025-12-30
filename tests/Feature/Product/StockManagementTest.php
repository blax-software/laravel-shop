<?php

namespace Blax\Shop\Tests\Feature\Product;

use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductStock;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class StockManagementTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_can_claim_stock_for_a_product()
    {
        $product = Product::factory()
            ->withStocks(100)
            ->create();

        $claim = $product->claimStock(
            quantity: 10,
            until: now()->addHours(2)
        );

        $this->assertNotNull($claim);
        $this->assertEquals(10, $claim->quantity);
        $this->assertEquals(90, $product->getAvailableStock());
    }

    #[Test]
    public function it_cannot_claim_more_stock_than_available()
    {
        $product = Product::factory()
            ->withStocks(5)
            ->create();

        $claim = null;

        $this->assertThrows(fn() => $claim = $product->claimStock(15), NotEnoughStockException::class);

        $this->assertNull($claim);
        $this->assertEquals(5, $product->getAvailableStock());
    }

    #[Test]
    public function it_can_release_claimed_stock()
    {
        $product = Product::factory()
            ->withStocks(100)
            ->create();

        $claim = $product->claimStock(
            quantity: 10,
            until: now()->addHours(2)
        );

        $this->assertEquals(90, $product->getAvailableStock());

        $claim->release();

        $this->assertEquals(100, $product->refresh()->getAvailableStock());
        $this->assertNotNull($claim->fresh()->released_at);
    }

    #[Test]
    public function it_can_check_if_stock_is_pending()
    {
        $product = Product::factory()->withStocks(10)->create();

        $claim = $product->claimStock(5);

        $pending = ProductStock::pending()->where('id', $claim->id)->first();

        $this->assertNotNull($pending);
        $this->assertNull($pending->released_at);
    }

    #[Test]
    public function it_can_check_if_stock_is_released()
    {
        $product = Product::factory()->withStocks(50)->create();

        $claim = $product->claimStock(5);

        $claim->release();

        $released = ProductStock::released()->where('id', $claim->id)->first();

        $this->assertNotNull($released);
        $this->assertNotNull($released->released_at);
    }

    #[Test]
    public function it_can_distinguish_temporary_and_permanent_claims()
    {
        $product = Product::factory()->withStocks(100)->create();

        $permanentClaim = $product->claimStock(
            quantity: 10
        );

        $temporaryClaim = $product->claimStock(
            quantity: 5,
            until: now()->addHours(1)
        );

        $this->assertTrue($permanentClaim->isPermanent());
        $this->assertFalse($permanentClaim->isTemporary());

        $this->assertTrue($temporaryClaim->isTemporary());
        $this->assertFalse($temporaryClaim->isPermanent());
    }

    #[Test]
    public function it_belongs_to_a_product()
    {
        $product = Product::factory()->withStocks(20)->create();

        $claim = $product->claimStock(5);

        $this->assertInstanceOf(Product::class, $claim->product);
        $this->assertEquals($product->id, $claim->product->id);
    }

    #[Test]
    public function product_has_many_stock_records()
    {
        $product = Product::factory()->withStocks(30)->create();

        $product->increaseStock(10);
        $product->increaseStock(10);
        $product->increaseStock(50);

        $this->assertCount(4, $product->stocks);
        $this->assertInstanceOf(ProductStock::class, $product->stocks->first());
        $this->assertEquals(30 + 10 + 10 + 50, $product->getAvailableStock());
    }

    #[Test]
    public function it_can_get_active_stock_claims()
    {
        $product = Product::factory()->withStocks(100)->create();

        $activeClaim = $product->claimStock(
            quantity: 10,
            until: now()->addHours(2)
        );

        $expiredClaim = $product->claimStock(
            quantity: 5,
            until: now()->subHours(1)
        );

        $activeClaims = $product->claims()->get();

        $this->assertCount(1, $activeClaims);
        $this->assertEquals($activeClaim->id, $activeClaims->first()->id);
    }

    #[Test]
    public function it_cannot_release_stock_twice()
    {
        $product = Product::factory()->withStocks()->create();

        $claim = $product->claimStock(5);

        $this->assertTrue($claim->release());
        $this->assertFalse($claim->release());
    }

    #[Test]
    public function it_can_store_claim_note()
    {
        $product = Product::factory()->withStocks()->create();

        $note = "Customer requested to hold this item for 2 days.";

        $claim = $product->claimStock(
            quantity: 5,
            note: $note
        );

        $this->assertEquals($note, $claim->note);
    }

    #[Test]
    public function it_calculates_available_stock_correctly()
    {
        $product = Product::factory()->withStocks(100)->create();

        $claim1 = $product->claimStock(
            quantity: 10,
            until: now()->addHours(2)
        );

        $claim2 = $product->claimStock(
            quantity: 5,
            until: now()->addHours(1)
        );

        $claim1->refresh();
        $claim2->refresh();

        $this->assertEquals(85, $product->refresh()->getAvailableStock());
    }

    #[Test]
    public function product_tracks_low_stock_threshold()
    {
        $product = Product::factory()
            ->withStocks(12)
            ->create([
                'low_stock_threshold' => 10,
            ]);

        $this->assertFalse($product->isLowStock());

        $product->decreaseStock(8);

        $this->assertTrue($product->fresh()->isLowStock());
    }

    #[Test]
    public function it_updates_in_stock_status_automatically()
    {
        $product = Product::factory()
            ->withStocks(10)
            ->create();

        $product->decreaseStock(10);

        $this->assertFalse($product->fresh()->isInStock());
    }

    #[Test]
    public function it_can_adjust_stock_with_from_parameter_for_claimed_type()
    {
        $product = Product::factory()->withStocks(100)->create();
        $fromDate = now()->addDays(3);
        $untilDate = now()->addDays(10);

        $result = $product->adjustStock(
            type: \Blax\Shop\Enums\StockType::CLAIMED,
            quantity: 15,
            until: $untilDate,
            from: $fromDate
        );

        // adjustStock(CLAIMED) now returns ProductStock (delegates to claimStock)
        $this->assertInstanceOf(\Blax\Shop\Models\ProductStock::class, $result);

        // Now creates two entries: DECREASE (COMPLETED) + CLAIMED (PENDING)
        $claimedStock = $product->stocks()->where('type', 'claimed')->first();
        $this->assertNotNull($claimedStock);
        $this->assertEquals(15, $claimedStock->quantity); // Positive quantity
        $this->assertEquals($fromDate->format('Y-m-d H:i:s'), $claimedStock->claimed_from->format('Y-m-d H:i:s'));
        $this->assertEquals($untilDate->format('Y-m-d H:i:s'), $claimedStock->expires_at->format('Y-m-d H:i:s'));

        // Check for the DECREASE entry
        $decreaseStock = $product->stocks()->where('type', 'decrease')->first();
        $this->assertNotNull($decreaseStock);
        $this->assertEquals(-15, $decreaseStock->quantity);
    }

    #[Test]
    public function it_uses_now_as_default_from_date_for_claimed_type()
    {
        $product = Product::factory()->withStocks(100)->create();

        $result = $product->adjustStock(
            type: \Blax\Shop\Enums\StockType::CLAIMED,
            quantity: 10
        );

        // adjustStock(CLAIMED) now returns ProductStock
        $this->assertInstanceOf(\Blax\Shop\Models\ProductStock::class, $result);

        $claimedStock = $product->stocks()->where('type', 'claimed')->first();
        // claimed_from defaults to null when not provided (claim active immediately)
        $this->assertNull($claimedStock->claimed_from);
    }

    #[Test]
    public function it_does_not_set_claimed_from_for_non_claimed_types()
    {
        $product = Product::factory()->withStocks(100)->create();

        $product->adjustStock(
            type: \Blax\Shop\Enums\StockType::INCREASE,
            quantity: 10,
            from: now()->addDays(5)
        );

        $stock = $product->stocks()->where('type', 'increase')->first();
        $this->assertNull($stock->claimed_from);
    }

    #[Test]
    public function it_can_adjust_stock_with_note_parameter()
    {
        $product = Product::factory()->withStocks(100)->create();
        $note = 'Customer requested extra units for bulk order #12345';

        $result = $product->adjustStock(
            type: \Blax\Shop\Enums\StockType::INCREASE,
            quantity: 50,
            note: $note
        );

        $this->assertTrue($result);

        $stock = $product->stocks()->where('type', 'increase')->where('quantity', 50)->first();
        $this->assertNotNull($stock);
        $this->assertEquals($note, $stock->note);
    }

    #[Test]
    public function it_can_adjust_stock_with_referencable_model()
    {
        $product = Product::factory()->withStocks(100)->create();
        $referencedProduct = Product::factory()->create();

        $result = $product->adjustStock(
            type: \Blax\Shop\Enums\StockType::DECREASE,
            quantity: 10,
            referencable: $referencedProduct
        );

        $this->assertTrue($result);

        $stock = $product->stocks()->where('type', 'decrease')->where('quantity', -10)->first();
        $this->assertNotNull($stock);
        $this->assertEquals(Product::class, $stock->reference_type);
        $this->assertEquals($referencedProduct->id, $stock->reference_id);
    }

    #[Test]
    public function it_can_adjust_stock_with_all_parameters_combined()
    {
        $product = Product::factory()->withStocks(100)->create();
        $referencedProduct = Product::factory()->create();
        $fromDate = now()->addDays(2);
        $untilDate = now()->addDays(7);
        $note = 'Reserved for special event booking';

        $result = $product->adjustStock(
            type: \Blax\Shop\Enums\StockType::CLAIMED,
            quantity: 25,
            until: $untilDate,
            from: $fromDate,
            note: $note,
            referencable: $referencedProduct
        );

        // adjustStock(CLAIMED) now returns ProductStock
        $this->assertInstanceOf(\Blax\Shop\Models\ProductStock::class, $result);

        $stock = $product->stocks()->where('type', 'claimed')->first();
        $this->assertNotNull($stock);
        $this->assertEquals(25, $stock->quantity); // Positive quantity
        $this->assertEquals('pending', $stock->status->value);
        $this->assertEquals($fromDate->format('Y-m-d H:i:s'), $stock->claimed_from->format('Y-m-d H:i:s'));
        $this->assertEquals($untilDate->format('Y-m-d H:i:s'), $stock->expires_at->format('Y-m-d H:i:s'));
        $this->assertEquals($note, $stock->note);
        $this->assertEquals(Product::class, $stock->reference_type);
        $this->assertEquals($referencedProduct->id, $stock->reference_id);
    }

    #[Test]
    public function it_adjusts_stock_with_correct_quantity_signs_based_on_type()
    {
        $product = Product::factory()->withStocks(100)->create();

        // INCREASE should add stock (positive)
        $product->adjustStock(
            type: \Blax\Shop\Enums\StockType::INCREASE,
            quantity: 20
        );
        $increaseStock = $product->stocks()->where('type', 'increase')->where('quantity', 20)->first();
        $this->assertNotNull($increaseStock);
        $this->assertEquals(20, $increaseStock->quantity);

        // RETURN should add stock (positive)
        $product->adjustStock(
            type: \Blax\Shop\Enums\StockType::RETURN,
            quantity: 15
        );
        $returnStock = $product->stocks()->where('type', 'return')->where('quantity', 15)->first();
        $this->assertNotNull($returnStock);
        $this->assertEquals(15, $returnStock->quantity);

        // DECREASE should remove stock (negative)
        $product->adjustStock(
            type: \Blax\Shop\Enums\StockType::DECREASE,
            quantity: 10
        );
        $decreaseStock = $product->stocks()->where('type', 'decrease')->where('quantity', -10)->first();
        $this->assertNotNull($decreaseStock);
        $this->assertEquals(-10, $decreaseStock->quantity);

        // CLAIMED now creates two entries: DECREASE + CLAIMED (positive quantity)
        $product->adjustStock(
            type: \Blax\Shop\Enums\StockType::CLAIMED,
            quantity: 5
        );
        $claimedStock = $product->stocks()->where('type', 'claimed')->where('quantity', 5)->first();
        $this->assertNotNull($claimedStock);
        $this->assertEquals(5, $claimedStock->quantity); // Now positive

        // And also creates a DECREASE entry
        $claimedDecrease = $product->stocks()->where('type', 'decrease')->where('quantity', -5)->first();
        $this->assertNotNull($claimedDecrease);
        $this->assertEquals(-5, $claimedDecrease->quantity);
    }

    #[Test]
    public function it_returns_false_when_adjusting_stock_with_management_disabled()
    {
        $product = Product::factory()->create([
            'manage_stock' => false,
        ]);

        $result = $product->adjustStock(
            type: \Blax\Shop\Enums\StockType::INCREASE,
            quantity: 10
        );

        $this->assertFalse($result);
        $this->assertCount(0, $product->stocks);
    }

    #[Test]
    public function it_shows_calendar_availability_correctly_with_claimed_stock()
    {
        $product = Product::factory()->withStocks(50)->create();

        // Claim stock from day 3 to day 7
        $product->claimStock(
            quantity: 20,
            from: now()->endOfDay()->addDays(3),
            until: now()->endOfDay()->subHours(6)->addDays(7)
        );

        $product->claimStock(
            quantity: 2,
            from: now()->endOfDay()->addDays(1),
            until: now()->endOfDay()->addDays(2)
        );

        $product->claimStock(
            quantity: 5,
            from: now()->endOfDay()->addDays(10),
            until: now()->endOfDay()->addDays(22)
        );

        $availability = $product->calendarAvailability();

        $this->assertEquals(50, $availability['max_available']);
        $this->assertEquals(30, $availability['min_available']);
        $this->assertCount(31, $availability['dates']);

        // Check specific dates
        $this->assertEquals(['min' => 50, 'max' => 50], $availability['dates'][now()->toDateString()]);
        $this->assertEquals(['min' => 48, 'max' => 50], $availability['dates'][now()->addDays(1)->toDateString()]);
        $this->assertEquals(['min' => 48, 'max' => 50], $availability['dates'][now()->addDays(2)->toDateString()]);
        $this->assertEquals(['min' => 30, 'max' => 50], $availability['dates'][now()->addDays(3)->toDateString()]);
        $this->assertEquals(['min' => 30, 'max' => 30], $availability['dates'][now()->addDays(4)->toDateString()]);
        $this->assertEquals(['min' => 30, 'max' => 50], $availability['dates'][now()->addDays(7)->toDateString()]);
        $this->assertEquals(['min' => 50, 'max' => 50], $availability['dates'][now()->addDays(8)->toDateString()]);
        $this->assertEquals(['min' => 45, 'max' => 45], $availability['dates'][now()->addDays(11)->toDateString()]);
        $this->assertEquals(['min' => 45, 'max' => 50], $availability['dates'][now()->addDays(22)->toDateString()]);
        $this->assertEquals(['min' => 50, 'max' => 50], $availability['dates'][now()->addDays(23)->toDateString()]);

        $minValues = array_column($availability['dates'], 'min');
        $valueCounts = array_count_values($minValues);

        $this->assertEquals(11, $valueCounts['50']);
        $this->assertEquals(2, $valueCounts['48']);
        $this->assertEquals(13, $valueCounts['45']);
        $this->assertEquals(5, $valueCounts['30']);

        // Test custom range
        $customAvailability = $product->calendarAvailability(
            from: now()->addDays(3),
            until: now()->addDays(10)
        );

        $this->assertCount(8, $customAvailability['dates']); // Day 3 to Day 10 inclusive
        $this->assertEquals(50, $customAvailability['max_available']);
        $this->assertEquals(30, $customAvailability['min_available']);

        $customMinValues = array_column($customAvailability['dates'], 'min');
        $customValueCounts = array_count_values($customMinValues);
        $this->assertEquals(5, $customValueCounts['30']); // Days 3, 4, 5, 6, 7
        $this->assertEquals(2, $customValueCounts['50']); // Days 8, 9
        $this->assertEquals(1, $customValueCounts['45']); // Day 10

        // dayAvailability
        $dayAvailability = $product->dayAvailability(now()->addDays(7));

        $this->assertEquals(30, $dayAvailability['00:00']);
        $this->assertArrayHasKey(now()->endOfDay()->subHours(6)->addDays(7)->format('H:i'), $dayAvailability);
        $this->assertEquals(50, @$dayAvailability[now()->endOfDay()->subHours(6)->addDays(7)->format('H:i')]);
    }
}
