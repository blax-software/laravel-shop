<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Tests\TestCase;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class PoolProductStockTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_shows_calendar_availability_for_pool_product_without_claims()
    {
        // Create pool product
        $pool = Product::factory()->create([
            'name' => 'Hotel Rooms',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create 3 single items with stock
        $single1 = Product::factory()->create([
            'name' => 'Room 101',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single1->increaseStock(1);

        $single2 = Product::factory()->create([
            'name' => 'Room 102',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single2->increaseStock(1);

        $single3 = Product::factory()->create([
            'name' => 'Room 103',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single3->increaseStock(1);

        // Attach singles to pool
        foreach ([$single1, $single2, $single3] as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        // Get calendar availability for pool
        $availability = $pool->calendarAvailability();

        $this->assertEquals(3, $availability['max_available']);
        $this->assertEquals(3, $availability['min_available']);
        $this->assertCount(31, $availability['dates']);

        // All days should have 3 units available
        foreach ($availability['dates'] as $date => $dayAvailability) {
            $this->assertEquals(['min' => 3, 'max' => 3], $dayAvailability, "Failed for date: $date");
        }
    }

    #[Test]
    public function it_shows_calendar_availability_for_pool_product_with_claims()
    {
        // Create pool product
        $pool = Product::factory()->create([
            'name' => 'Hotel Rooms',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create 3 single items with stock
        $singles = [];
        for ($i = 1; $i <= 3; $i++) {
            $single = Product::factory()->create([
                'name' => "Room 10{$i}",
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);
            $single->increaseStock(1);
            $singles[] = $single;
        }

        foreach ($singles as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        // Claim single1 from day 5 to day 10
        $singles[0]->claimStock(
            quantity: 1,
            from: now()->startOfDay()->addDays(5),
            until: now()->endOfDay()->addDays(10)
        );

        // Claim single2 from day 8 to day 15
        $singles[1]->claimStock(
            quantity: 1,
            from: now()->startOfDay()->addDays(8),
            until: now()->endOfDay()->addDays(15)
        );

        $availability = $pool->calendarAvailability();

        $this->assertEquals(3, $availability['max_available']);
        $this->assertEquals(1, $availability['min_available']);

        // Day 0-4: All 3 available
        $this->assertEquals(['min' => 3, 'max' => 3], $availability['dates'][now()->toDateString()]);
        $this->assertEquals(['min' => 3, 'max' => 3], $availability['dates'][now()->addDays(4)->toDateString()]);

        // Day 5-7: Single1 claimed, 2 available
        $this->assertEquals(['min' => 2, 'max' => 2], $availability['dates'][now()->addDays(5)->toDateString()]);
        $this->assertEquals(['min' => 2, 'max' => 2], $availability['dates'][now()->addDays(7)->toDateString()]);

        // Day 8-10: Both single1 and single2 claimed, 1 available
        $this->assertEquals(['min' => 1, 'max' => 1], $availability['dates'][now()->addDays(8)->toDateString()]);
        // Day 10: single1's claim expires at endOfDay, so max becomes 2 at that moment
        $this->assertEquals(['min' => 1, 'max' => 2], $availability['dates'][now()->addDays(10)->toDateString()]);

        // Day 11-15: Single1 released, single2 still claimed, 2 available
        $this->assertEquals(['min' => 2, 'max' => 2], $availability['dates'][now()->addDays(11)->toDateString()]);
        // Day 15: single2's claim expires at endOfDay, so max becomes 3 at that moment
        $this->assertEquals(['min' => 2, 'max' => 3], $availability['dates'][now()->addDays(15)->toDateString()]);

        // Day 16+: All released, 3 available
        $this->assertEquals(['min' => 3, 'max' => 3], $availability['dates'][now()->addDays(16)->toDateString()]);
    }

    #[Test]
    public function it_shows_calendar_availability_for_pool_with_intraday_claim_changes()
    {
        $pool = Product::factory()->create([
            'name' => 'Meeting Rooms',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create 2 single items
        $single1 = Product::factory()->create([
            'name' => 'Room A',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single1->increaseStock(1);

        $single2 = Product::factory()->create([
            'name' => 'Room B',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single2->increaseStock(1);

        foreach ([$single1, $single2] as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        // Claim single1 from day 5 at 10:00 to day 5 at 18:00
        $single1->claimStock(
            quantity: 1,
            from: now()->startOfDay()->addDays(5)->setTime(10, 0),
            until: now()->startOfDay()->addDays(5)->setTime(18, 0)
        );

        $availability = $pool->calendarAvailability();

        // Day 5 should have min=1 (during claim) and max=2 (before/after claim)
        $this->assertEquals(['min' => 1, 'max' => 2], $availability['dates'][now()->addDays(5)->toDateString()]);

        // Other days should have 2 available
        $this->assertEquals(['min' => 2, 'max' => 2], $availability['dates'][now()->addDays(4)->toDateString()]);
        $this->assertEquals(['min' => 2, 'max' => 2], $availability['dates'][now()->addDays(6)->toDateString()]);
    }

    #[Test]
    public function it_shows_calendar_availability_for_pool_with_multiple_intraday_changes()
    {
        $pool = Product::factory()->create([
            'name' => 'Equipment Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Create 5 single items
        $singles = [];
        for ($i = 1; $i <= 5; $i++) {
            $single = Product::factory()->create([
                'name' => "Equipment {$i}",
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);
            $single->increaseStock(1);
            $singles[] = $single;
        }

        foreach ($singles as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        $targetDay = now()->addDays(7);

        // Multiple claims starting/ending on day 7
        // Claim 1: 08:00 - 12:00
        $singles[0]->claimStock(
            quantity: 1,
            from: $targetDay->copy()->setTime(8, 0),
            until: $targetDay->copy()->setTime(12, 0)
        );

        // Claim 2: 10:00 - 14:00
        $singles[1]->claimStock(
            quantity: 1,
            from: $targetDay->copy()->setTime(10, 0),
            until: $targetDay->copy()->setTime(14, 0)
        );

        // Claim 3: 13:00 - 17:00
        $singles[2]->claimStock(
            quantity: 1,
            from: $targetDay->copy()->setTime(13, 0),
            until: $targetDay->copy()->setTime(17, 0)
        );

        $availability = $pool->calendarAvailability();

        // Day 7: 
        // - 00:00-07:59: 5 available
        // - 08:00-09:59: 4 available (claim 1)
        // - 10:00-11:59: 3 available (claim 1 + 2)
        // - 12:00: claim 1 expires at this exact moment, so briefly all 3 claims overlap
        // - 12:00-12:59: 4 available (claim 2 only)
        // - 13:00-13:59: 3 available (claim 2 + 3)
        // - 14:00-16:59: 4 available (claim 3)
        // - 17:00-23:59: 5 available
        // Min is 2 because at 12:00 when claim 1 expires, it's still considered active with <=
        $this->assertEquals(['min' => 2, 'max' => 5], $availability['dates'][$targetDay->toDateString()]);
    }

    #[Test]
    public function it_shows_day_availability_for_pool_product()
    {
        $pool = Product::factory()->create([
            'name' => 'Parking Spots',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $singles = [];
        for ($i = 1; $i <= 3; $i++) {
            $single = Product::factory()->create([
                'name' => "Spot {$i}",
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);
            $single->increaseStock(1);
            $singles[] = $single;
        }

        foreach ($singles as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        $targetDay = now()->addDays(5);

        // Claim spot 1 from 08:00 to 16:00
        $singles[0]->claimStock(
            quantity: 1,
            from: $targetDay->copy()->setTime(8, 0),
            until: $targetDay->copy()->setTime(16, 0)
        );

        // Claim spot 2 from 12:00 to 20:00
        $singles[1]->claimStock(
            quantity: 1,
            from: $targetDay->copy()->setTime(12, 0),
            until: $targetDay->copy()->setTime(20, 0)
        );

        $dayAvailability = $pool->dayAvailability($targetDay);

        // Should have availability changes at specific times
        $this->assertArrayHasKey('00:00', $dayAvailability);
        $this->assertEquals(3, $dayAvailability['00:00']);

        $this->assertArrayHasKey('08:00', $dayAvailability);
        $this->assertEquals(2, $dayAvailability['08:00']);

        $this->assertArrayHasKey('12:00', $dayAvailability);
        $this->assertEquals(1, $dayAvailability['12:00']);

        $this->assertArrayHasKey('16:00', $dayAvailability);
        $this->assertEquals(2, $dayAvailability['16:00']);

        $this->assertArrayHasKey('20:00', $dayAvailability);
        $this->assertEquals(3, $dayAvailability['20:00']);
    }

    #[Test]
    public function it_handles_pool_with_mixed_stock_management()
    {
        $pool = Product::factory()->create([
            'name' => 'Mixed Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Single 1: manages stock
        $single1 = Product::factory()->create([
            'name' => 'Limited Item',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single1->increaseStock(1);

        // Single 2: doesn't manage stock (unlimited)
        $single2 = Product::factory()->create([
            'name' => 'Unlimited Item',
            'type' => ProductType::BOOKING,
            'manage_stock' => false,
        ]);

        foreach ([$single1, $single2] as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        // Claim the limited item
        $single1->claimStock(
            quantity: 1,
            from: now()->startOfDay()->addDays(5),
            until: now()->endOfDay()->addDays(10)
        );

        $availability = $pool->calendarAvailability();

        // Pool only counts managed singles (unmanaged have unlimited availability)
        // So the pool shows only the limited item's availability: 0 when claimed, 1 when not
        $this->assertEquals(['min' => 0, 'max' => 0], $availability['dates'][now()->addDays(5)->toDateString()]);
        $this->assertEquals(['min' => 1, 'max' => 1], $availability['dates'][now()->addDays(4)->toDateString()]);
    }

    #[Test]
    public function it_handles_pool_with_custom_date_range()
    {
        $pool = Product::factory()->create([
            'name' => 'Rental Equipment',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $singles = [];
        for ($i = 1; $i <= 4; $i++) {
            $single = Product::factory()->create([
                'name' => "Equipment {$i}",
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);
            $single->increaseStock(1);
            $singles[] = $single;
        }

        foreach ($singles as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        // Claim across various dates
        $singles[0]->claimStock(
            quantity: 1,
            from: now()->startOfDay()->addDays(2),
            until: now()->endOfDay()->addDays(5)
        );

        $singles[1]->claimStock(
            quantity: 1,
            from: now()->startOfDay()->addDays(4),
            until: now()->endOfDay()->addDays(8)
        );

        // Test custom range: days 3-7
        $availability = $pool->calendarAvailability(
            from: now()->addDays(3),
            until: now()->addDays(7)
        );

        $this->assertCount(5, $availability['dates']); // 5 days

        // Day 3: single1 claimed
        $this->assertEquals(['min' => 3, 'max' => 3], $availability['dates'][now()->addDays(3)->toDateString()]);

        // Day 4-5: both single1 and single2 claimed
        $this->assertEquals(['min' => 2, 'max' => 2], $availability['dates'][now()->addDays(4)->toDateString()]);
        // Day 5: single1's claim expires at endOfDay, so max becomes 3 at that moment
        $this->assertEquals(['min' => 2, 'max' => 3], $availability['dates'][now()->addDays(5)->toDateString()]);

        // Day 6-7: only single2 claimed
        $this->assertEquals(['min' => 3, 'max' => 3], $availability['dates'][now()->addDays(6)->toDateString()]);
        $this->assertEquals(['min' => 3, 'max' => 3], $availability['dates'][now()->addDays(7)->toDateString()]);
    }

    #[Test]
    public function it_handles_pool_with_overlapping_claims_on_same_single()
    {
        $pool = Product::factory()->create([
            'name' => 'Car Sharing',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $single = Product::factory()->create([
            'name' => 'Car 1',
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single->increaseStock(1);

        $pool->productRelations()->attach($single->id, [
            'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
        ]);

        $targetDay = now()->addDays(5);

        // Two claims on the same day - morning and evening
        $single->claimStock(
            quantity: 1,
            from: $targetDay->copy()->setTime(6, 0),
            until: $targetDay->copy()->setTime(12, 0)
        );

        $single->claimStock(
            quantity: 1,
            from: $targetDay->copy()->setTime(18, 0),
            until: $targetDay->copy()->setTime(22, 0)
        );

        $availability = $pool->calendarAvailability();

        // Day should show min=0 (during claims) and max=1 (between claims)
        $this->assertEquals(['min' => 0, 'max' => 1], $availability['dates'][$targetDay->toDateString()]);
    }

    #[Test]
    public function it_handles_empty_pool()
    {
        $pool = Product::factory()->create([
            'name' => 'Empty Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $availability = $pool->calendarAvailability();

        $this->assertEquals(0, $availability['max_available']);
        $this->assertEquals(0, $availability['min_available']);

        foreach ($availability['dates'] as $dayAvailability) {
            $this->assertEquals(['min' => 0, 'max' => 0], $dayAvailability);
        }
    }

    #[Test]
    public function it_handles_pool_with_all_singles_claimed_permanently()
    {
        $pool = Product::factory()->create([
            'name' => 'Sold Out Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $singles = [];
        for ($i = 1; $i <= 2; $i++) {
            $single = Product::factory()->create([
                'name' => "Item {$i}",
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);
            $single->increaseStock(1);
            $singles[] = $single;
        }

        foreach ($singles as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        // Claim all singles for the entire range
        foreach ($singles as $single) {
            $single->claimStock(
                quantity: 1,
                from: now()->startOfDay(),
                until: now()->endOfDay()->addDays(30)
            );
        }

        $availability = $pool->calendarAvailability();

        // Max is 2 on day 30 because claims expire at endOfDay, making items available at 23:59:59
        $this->assertEquals(2, $availability['max_available']);
        $this->assertEquals(0, $availability['min_available']);

        // All days except the last should have no availability
        $dates = array_values($availability['dates']);
        for ($i = 0; $i < count($dates) - 1; $i++) {
            $this->assertEquals(['min' => 0, 'max' => 0], $dates[$i], "Failed for day index {$i}");
        }
        // Last day (day 30) has max=2 due to claims expiring at endOfDay
        $this->assertEquals(['min' => 0, 'max' => 2], $dates[count($dates) - 1]);
    }

    #[Test]
    public function it_correctly_calculates_pool_availability_with_varying_single_stock()
    {
        $pool = Product::factory()->create([
            'name' => 'Variable Stock Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        // Different singles with different stock levels
        $single1 = Product::factory()->create([
            'name' => 'Item 1',
            'type' => ProductType::SIMPLE,
            'manage_stock' => true,
        ]);
        $single1->increaseStock(3); // 3 units

        $single2 = Product::factory()->create([
            'name' => 'Item 2',
            'type' => ProductType::SIMPLE,
            'manage_stock' => true,
        ]);
        $single2->increaseStock(5); // 5 units

        foreach ([$single1, $single2] as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        $availability = $pool->calendarAvailability();

        // Pool should show sum of available singles: 3 + 5 = 8
        $this->assertEquals(8, $availability['max_available']);
        $this->assertEquals(8, $availability['min_available']);

        foreach ($availability['dates'] as $dayAvailability) {
            $this->assertEquals(['min' => 8, 'max' => 8], $dayAvailability);
        }
    }

    #[Test]
    public function it_handles_pool_claims_expiring_mid_period()
    {
        $pool = Product::factory()->create([
            'name' => 'Conference Rooms',
            'type' => ProductType::POOL,
            'manage_stock' => false,
        ]);

        $singles = [];
        for ($i = 1; $i <= 3; $i++) {
            $single = Product::factory()->create([
                'name' => "Room {$i}",
                'type' => ProductType::BOOKING,
                'manage_stock' => true,
            ]);
            $single->increaseStock(1);
            $singles[] = $single;
        }

        foreach ($singles as $single) {
            $pool->productRelations()->attach($single->id, [
                'type' => \Blax\Shop\Enums\ProductRelationType::SINGLE->value,
            ]);
        }

        // Create a claim that expires in the middle of our test period
        $singles[0]->claimStock(
            quantity: 1,
            from: now()->startOfDay()->addDays(5),
            until: now()->startOfDay()->addDays(15)->setTime(14, 30) // Expires at 14:30 on day 15
        );

        $availability = $pool->calendarAvailability();

        // Day 15 should show the claim expiring during the day
        $day15 = $availability['dates'][now()->addDays(15)->toDateString()];
        $this->assertEquals(2, $day15['min']); // During claim
        $this->assertEquals(3, $day15['max']); // After 14:30

        // Day 16 onwards should be fully available
        $this->assertEquals(['min' => 3, 'max' => 3], $availability['dates'][now()->addDays(16)->toDateString()]);
    }
}
