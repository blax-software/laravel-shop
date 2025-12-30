<?php

namespace Blax\Shop\Tests\Feature\Product;

use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductStock;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

class ProductStockTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_creates_stock_record_on_increase()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(10);

        $this->assertDatabaseHas('product_stocks', [
            'product_id' => $product->id,
            'quantity' => 10,
            'type' => 'increase',
        ]);
    }

    #[Test]
    public function it_creates_stock_record_on_decrease()
    {
        $product = Product::factory()->create(['manage_stock' => true]);
        $product->increaseStock(20);

        $product->decreaseStock(5);

        $this->assertDatabaseHas('product_stocks', [
            'product_id' => $product->id,
            'quantity' => -5,
            'type' => 'decrease',
        ]);
    }

    #[Test]
    public function stock_belongs_to_product()
    {
        $product = Product::factory()->create(['manage_stock' => true]);
        $product->increaseStock(10);

        $stock = $product->stocks()->first();

        $this->assertInstanceOf(Product::class, $stock->product);
        $this->assertEquals($product->id, $stock->product->id);
    }

    #[Test]
    public function product_has_many_stock_records()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(10);
        $product->increaseStock(5);
        $product->decreaseStock(3);

        $this->assertCount(3, $product->stocks);
    }

    #[Test]
    public function available_stock_considers_all_records()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(50);
        $product->increaseStock(30);
        $product->decreaseStock(20);

        $this->assertEquals(60, $product->getAvailableStock());
    }

    #[Test]
    public function claim_reduces_available_stock()
    {
        $product = Product::factory()->withStocks(100)->create();

        $claim = $product->claimStock(25);

        $this->assertEquals(75, $product->getAvailableStock());
        $this->assertNotNull($claim);
    }

    #[Test]
    public function releasing_claim_increases_available_stock()
    {
        $product = Product::factory()->withStocks(100)->create();

        $claim = $product->claimStock(25);
        $this->assertEquals(75, $product->getAvailableStock());

        $claim->release();

        $this->assertEquals(100, $product->refresh()->getAvailableStock());
    }

    #[Test]
    public function permanent_claim_has_no_expiry()
    {
        $product = Product::factory()->withStocks(50)->create();

        $claim = $product->claimStock(10);

        $this->assertNull($claim->expires_at);
        $this->assertTrue($claim->isPermanent());
    }

    #[Test]
    public function temporary_claim_has_expiry()
    {
        $product = Product::factory()->withStocks(50)->create();

        $claim = $product->claimStock(
            quantity: 10,
            until: now()->addHours(2)
        );

        $this->assertNotNull($claim->expires_at);
        $this->assertTrue($claim->isTemporary());
    }

    #[Test]
    public function claim_can_have_note()
    {
        $product = Product::factory()->withStocks(50)->create();

        $note = 'Claimed for VIP customer';
        $claim = $product->claimStock(
            quantity: 10,
            note: $note
        );

        $this->assertEquals($note, $claim->note);
    }

    #[Test]
    public function cannot_claim_more_than_available()
    {
        $product = Product::factory()->withStocks(10)->create();

        $this->expectException(NotEnoughStockException::class);

        $product->claimStock(15);
    }

    #[Test]
    public function pending_scope_returns_unreleased_claims()
    {
        $product = Product::factory()->withStocks(100)->create();

        $pending = $product->claimStock(10);
        $released = $product->claimStock(5);
        $released->release();

        $pendingClaims = ProductStock::pending()->get();

        $this->assertTrue($pendingClaims->contains($pending));
        $this->assertFalse($pendingClaims->contains($released));
    }

    #[Test]
    public function released_scope_returns_released_claims()
    {
        $product = Product::factory()->withStocks(100)->create();

        $pending = $product->claimStock(10);
        $released = $product->claimStock(5);
        $released->release();

        $releasedClaims = ProductStock::released()->get();

        $this->assertFalse($releasedClaims->contains($pending));
        $this->assertTrue($releasedClaims->contains($released));
    }

    #[Test]
    public function expired_claims_dont_affect_available_stock()
    {
        $product = Product::factory()->withStocks(100)->create();

        $product->claimStock(
            quantity: 20,
            until: now()->subHour()
        );

        // Expired claims should be counted in available stock
        $available = $product->claims()->get();

        $this->assertEquals(0, $available->count());
    }

    #[Test]
    public function cannot_release_stock_twice()
    {
        $product = Product::factory()->withStocks(50)->create();

        $claim = $product->claimStock(10);

        $this->assertTrue($claim->release());
        $this->assertFalse($claim->release());
    }

    #[Test]
    public function stock_status_is_tracked()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(10);

        $stock = $product->stocks()->first();

        $this->assertEquals(StockStatus::COMPLETED, $stock->status);
    }

    #[Test]
    public function product_without_stock_management_returns_max_stock()
    {
        $product = Product::factory()->create(['manage_stock' => false]);

        $available = $product->getAvailableStock();

        $this->assertEquals(PHP_INT_MAX, $available);
    }

    #[Test]
    public function product_without_stock_management_doesnt_create_records()
    {
        $product = Product::factory()->create(['manage_stock' => false]);

        $result = $product->increaseStock(10);

        $this->assertFalse($result);
        $this->assertCount(0, $product->stocks);
    }

    #[Test]
    public function claim_without_stock_management_returns_null()
    {
        $product = Product::factory()->create(['manage_stock' => false]);

        $claim = $product->claimStock(10);

        $this->assertNull($claim);
    }

    #[Test]
    public function available_stocks_attribute_accessor_works()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(25);
        $product->increaseStock(15);

        $this->assertEquals(40, $product->AvailableStocks);
    }

    #[Test]
    public function claims_method_filters_active_only()
    {
        $product = Product::factory()->withStocks(100)->create();

        $active = $product->claimStock(10, until: now()->addDay());
        $expired = $product->claimStock(5, until: now()->subDay());

        $claims = $product->claims()->get();

        $this->assertCount(1, $claims);
        $this->assertEquals($active->id, $claims->first()->id);
    }

    #[Test]
    public function can_adjust_stock()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(20);
        $this->assertEquals(20, $product->getAvailableStock());

        $product->adjustStock(
            type: \Blax\Shop\Enums\StockType::DECREASE,
            quantity: 5
        );
        $this->assertEquals(15, $product->getAvailableStock());

        $product->adjustStock(
            type: \Blax\Shop\Enums\StockType::INCREASE,
            quantity: 10
        );
        $this->assertEquals(25, $product->getAvailableStock());

        // Also with until
        $product->adjustStock(
            type: \Blax\Shop\Enums\StockType::DECREASE,
            quantity: 5,
            until: now()->addDay()
        );
        $this->assertEquals(20, $product->getAvailableStock());

        $this->travel(23)->hours();

        $this->assertEquals(20, $product->getAvailableStock());

        $this->travel(2)->days();

        $this->assertEquals(25, $product->getAvailableStock());
    }

    #[Test]
    public function it_can_claim_stock_with_claimed_from_date()
    {
        $product = Product::factory()->withStocks(100)->create();

        $claimedFrom = now()->addDays(5);
        $until = now()->addDays(10);

        $claim = $product->claimStock(
            quantity: 20,
            from: $claimedFrom,
            until: $until
        );

        $this->assertNotNull($claim);
        $this->assertEquals($claimedFrom->format('Y-m-d H:i:s'), $claim->claimed_from->format('Y-m-d H:i:s'));
        $this->assertEquals($until->format('Y-m-d H:i:s'), $claim->expires_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_can_check_available_stock_on_a_date()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Claim stock from day 5 to day 10
        $product->claimStock(
            quantity: 30,
            from: now()->addDays(5),
            until: now()->addDays(10)
        );

        // Should have full stock available before claimed_from date
        $availableOnDay3 = $product->availableOnDate(now()->addDays(3));
        $this->assertEquals(100, $availableOnDay3);

        // Should have reduced stock during claimed period
        $availableOnDay7 = $product->availableOnDate(now()->addDays(7));
        $this->assertEquals(70, $availableOnDay7);

        // Should have full stock available after expires_at date
        $availableOnDay12 = $product->availableOnDate(now()->addDays(12));
        $this->assertEquals(100, $availableOnDay12);
    }

    #[Test]
    public function it_can_handle_multiple_overlapping_claims_on_date()
    {
        $product = Product::factory()->withStocks(100)->create();

        // First claim: days 5-10
        $product->claimStock(
            quantity: 20,
            from: now()->addDays(5),
            until: now()->addDays(10)
        );

        // Second claim: days 8-15
        $product->claimStock(
            quantity: 30,
            from: now()->addDays(8),
            until: now()->addDays(15)
        );

        // Day 6: only first claim is active
        $availableOnDay6 = $product->availableOnDate(now()->addDays(6));
        $this->assertEquals(80, $availableOnDay6);

        // Day 9: both claims are active
        $availableOnDay9 = $product->availableOnDate(now()->addDays(9));
        $this->assertEquals(50, $availableOnDay9);

        // Day 13: only second claim is active
        $availableOnDay13 = $product->availableOnDate(now()->addDays(13));
        $this->assertEquals(70, $availableOnDay13);

        // Day 20: no claims active
        $availableOnDay20 = $product->availableOnDate(now()->addDays(20));
        $this->assertEquals(100, $availableOnDay20);
    }

    #[Test]
    public function it_handles_claims_without_claimed_from_as_immediately_claimed()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Claim without claimed_from (immediately claimed)
        $product->claimStock(
            quantity: 25,
            until: now()->addDays(10)
        );

        // Should be claimed immediately
        $availableNow = $product->availableOnDate(now());
        $this->assertEquals(75, $availableNow);

        // Should still be claimed on day 7
        $availableOnDay7 = $product->availableOnDate(now()->addDays(7));
        $this->assertEquals(75, $availableOnDay7);

        // Should be released after expiry
        $availableOnDay12 = $product->availableOnDate(now()->addDays(12));
        $this->assertEquals(100, $availableOnDay12);
    }

    #[Test]
    public function it_handles_permanent_claims_without_expires_at()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Permanent claim from day 5 onwards
        $product->claimStock(
            quantity: 40,
            from: now()->addDays(5)
        );

        // Before claimed_from: full stock available
        $availableOnDay3 = $product->availableOnDate(now()->addDays(3));
        $this->assertEquals(100, $availableOnDay3);

        // After claimed_from: reduced stock
        $availableOnDay10 = $product->availableOnDate(now()->addDays(10));
        $this->assertEquals(60, $availableOnDay10);

        // Far future: still reduced (permanent claim)
        $availableOnDay100 = $product->availableOnDate(now()->addDays(100));
        $this->assertEquals(60, $availableOnDay100);
    }

    #[Test]
    public function available_on_date_scope_filters_correctly()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Create various claims
        $claim1 = $product->claimStock(
            quantity: 10,
            from: now()->addDays(5),
            until: now()->addDays(10)
        );

        $claim2 = $product->claimStock(
            quantity: 15,
            from: now()->addDays(8),
            until: now()->addDays(15)
        );

        $claim3 = $product->claimStock(
            quantity: 20,
            from: now()->addDays(20),
            until: now()->addDays(25)
        );

        // Test scope on day 7 - should only include claim1
        $claimsOnDay7 = \Blax\Shop\Models\ProductStock::availableOnDate(now()->addDays(7))
            ->where('product_id', $product->id)
            ->get();

        $this->assertCount(1, $claimsOnDay7);
        $this->assertEquals($claim1->id, $claimsOnDay7->first()->id);

        // Test scope on day 12 - should only include claim2
        $claimsOnDay12 = \Blax\Shop\Models\ProductStock::availableOnDate(now()->addDays(12))
            ->where('product_id', $product->id)
            ->get();

        $this->assertCount(1, $claimsOnDay12);
        $this->assertEquals($claim2->id, $claimsOnDay12->first()->id);
    }

    #[Test]
    public function it_can_get_claimed_stock_amount()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Claim some stock
        $product->claimStock(quantity: 25);
        $product->claimStock(quantity: 15);

        // Should return total claimed
        $this->assertEquals(40, $product->getCurrentlyClaimedStock());
    }

    #[Test]
    public function it_checks_if_claim_is_active()
    {
        $product = Product::factory()->withStocks(100)->create();

        $claim = $product->claimStock(quantity: 10);

        $this->assertTrue($claim->isActive());

        $claim->release();

        $this->assertFalse($claim->fresh()->isActive());
    }

    #[Test]
    public function it_releases_expired_claims()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Create expired claim
        $expiredClaim = $product->claimStock(
            quantity: 20,
            until: now()->subHour()
        );

        // Create active claim
        $activeClaim = $product->claimStock(
            quantity: 15,
            until: now()->addHours(2)
        );

        // Release expired claims
        $count = \Blax\Shop\Models\ProductStock::releaseExpired();

        $this->assertEquals(1, $count);
        $this->assertEquals(StockStatus::COMPLETED, $expiredClaim->fresh()->status);
        $this->assertEquals(StockStatus::PENDING, $activeClaim->fresh()->status);
    }

    #[Test]
    public function it_has_reference_relationship()
    {
        $product = Product::factory()->withStocks(100)->create();
        $user = \Workbench\App\Models\User::factory()->create();

        $claim = $product->claimStock(
            quantity: 10,
            reference: $user,
            note: 'Reserved for user'
        );

        $this->assertNotNull($claim->reference);
        $this->assertEquals($user->id, $claim->reference->id);
        $this->assertEquals(get_class($user), $claim->reference_type);
    }

    #[Test]
    public function it_handles_return_stock_type()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $product->increaseStock(50);
        $product->decreaseStock(10);

        // Use adjustStock with RETURN type (adds stock back)
        $product->adjustStock(
            type: StockType::RETURN,
            quantity: 5
        );

        // Refresh to get updated stock
        $product = $product->fresh();

        // Should have 45 total (50 - 10 + 5)
        $this->assertEquals(45, $product->getAvailableStock());

        // Verify the return entry exists
        $returnEntry = $product->stocks()->where('type', StockType::RETURN->value)->first();
        $this->assertNotNull($returnEntry);
        $this->assertEquals(5, $returnEntry->quantity);
    }

    #[Test]
    public function temporary_scope_filters_correctly()
    {
        $product = Product::factory()->withStocks(100)->create();

        $temporary = $product->claimStock(quantity: 10, until: now()->addDay());
        $permanent = $product->claimStock(quantity: 20);

        $temporaryStocks = \Blax\Shop\Models\ProductStock::temporary()->get();
        $permanentStocks = \Blax\Shop\Models\ProductStock::permanent()->get();

        $this->assertTrue($temporaryStocks->contains($temporary));
        $this->assertFalse($temporaryStocks->contains($permanent));
        $this->assertTrue($permanentStocks->contains($permanent));
        $this->assertFalse($permanentStocks->contains($temporary));
    }

    #[Test]
    public function it_tracks_stock_with_custom_status()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        // Add stock with PENDING status
        $product->adjustStock(
            type: StockType::INCREASE,
            quantity: 50,
            status: StockStatus::PENDING
        );

        // Should not be counted in available stock (only COMPLETED counts)
        $this->assertEquals(0, $product->getAvailableStock());

        // Mark as completed
        $stockEntry = $product->stocks()->where('type', StockType::INCREASE->value)->first();
        $stockEntry->status = StockStatus::COMPLETED;
        $stockEntry->save();

        // Now should be available
        $this->assertEquals(50, $product->fresh()->getAvailableStock());
    }

    #[Test]
    public function backward_compatibility_accessors_work()
    {
        $product = Product::factory()->withStocks(100)->create();

        $claim = $product->claimStock(
            quantity: 10,
            until: now()->addDays(5)
        );

        // Test released_at accessor (should be null for pending)
        $this->assertNull($claim->released_at);

        // Test until_at accessor (alias for expires_at)
        $this->assertEquals($claim->expires_at->format('Y-m-d'), $claim->until_at->format('Y-m-d'));

        // Release the claim
        $claim->release();

        // Now released_at should return updated_at
        $this->assertNotNull($claim->fresh()->released_at);
        $this->assertEquals($claim->fresh()->updated_at->format('Y-m-d H:i:s'), $claim->fresh()->released_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function adjust_stock_increase_type_affects_available_stock_correctly()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        $this->assertEquals(0, $product->getAvailableStock());

        // Add stock using adjustStock with INCREASE type
        $product->adjustStock(
            type: StockType::INCREASE,
            quantity: 50
        );

        $this->assertEquals(50, $product->getAvailableStock());

        // Add more stock
        $product->adjustStock(
            type: StockType::INCREASE,
            quantity: 30
        );

        $this->assertEquals(80, $product->getAvailableStock());
    }

    #[Test]
    public function adjust_stock_decrease_type_affects_available_stock_correctly()
    {
        $product = Product::factory()->withStocks(100)->create();

        $this->assertEquals(100, $product->getAvailableStock());

        // Decrease stock using adjustStock
        $product->adjustStock(
            type: StockType::DECREASE,
            quantity: 20
        );

        $this->assertEquals(80, $product->getAvailableStock());

        // Decrease more stock
        $product->adjustStock(
            type: StockType::DECREASE,
            quantity: 15
        );

        $this->assertEquals(65, $product->getAvailableStock());
    }

    #[Test]
    public function adjust_stock_return_type_affects_available_stock_correctly()
    {
        $product = Product::factory()->withStocks(50)->create();
        $product->decreaseStock(10);

        $this->assertEquals(40, $product->getAvailableStock());

        // Return stock using adjustStock with RETURN type
        $product->adjustStock(
            type: StockType::RETURN,
            quantity: 8
        );

        $this->assertEquals(48, $product->getAvailableStock());
    }

    #[Test]
    public function adjust_stock_claimed_type_affects_available_and_claimed_stock_correctly()
    {
        $product = Product::factory()->withStocks(100)->create();

        $this->assertEquals(100, $product->getAvailableStock());
        $this->assertEquals(0, $product->getCurrentlyClaimedStock());

        // Claim stock using adjustStock with CLAIMED type
        // Note: adjustStock(CLAIMED) now delegates to claimStock() for consistency
        // This creates: DECREASE (COMPLETED) + CLAIMED (PENDING)
        $product->adjustStock(
            type: StockType::CLAIMED,
            quantity: 25
        );

        // Available stock is reduced by the DECREASE entry
        $this->assertEquals(75, $product->getAvailableStock());

        // Claimed stock shows the pending claim (always positive now)
        $this->assertEquals(25, $product->getCurrentlyClaimedStock());
    }
    #[Test]
    public function adjust_stock_with_until_parameter_expires_correctly()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Add stock with expiration date in the future
        $product->adjustStock(
            type: StockType::INCREASE,
            quantity: 50,
            until: now()->addDays(5)
        );

        // Should be available now
        $this->assertEquals(150, $product->getAvailableStock());

        // Travel to after expiration
        $this->travel(6)->days();

        // Should no longer be counted as available
        $this->assertEquals(100, $product->getAvailableStock());
    }

    #[Test]
    public function adjust_stock_claimed_with_from_and_until_affects_availability_by_date()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Claim stock from day 5 to day 10 using adjustStock
        // Now works correctly because adjustStock(CLAIMED) uses the same pattern as claimStock
        $product->adjustStock(
            type: StockType::CLAIMED,
            quantity: 30,
            from: now()->addDays(5),
            until: now()->addDays(10)
        );

        // Current available stock is unaffected until the claim starts
        $this->assertEquals(100, $product->getAvailableStock());

        // Check availability on specific dates
        $availableOnDay3 = $product->availableOnDate(now()->addDays(3));
        $this->assertEquals(100, $availableOnDay3); // Before claim starts

        $availableOnDay7 = $product->availableOnDate(now()->addDays(7));
        $this->assertEquals(70, $availableOnDay7); // During claim period

        $availableOnDay12 = $product->availableOnDate(now()->addDays(12));
        $this->assertEquals(100, $availableOnDay12); // After claim expires
    }
    #[Test]
    public function adjust_stock_multiple_claimed_types_accumulate_in_claimed_stock()
    {
        $product = Product::factory()->withStocks(200)->create();

        // Make multiple claims using adjustStock
        $product->adjustStock(
            type: StockType::CLAIMED,
            quantity: 20
        );

        $product->adjustStock(
            type: StockType::CLAIMED,
            quantity: 35
        );

        $product->adjustStock(
            type: StockType::CLAIMED,
            quantity: 15
        );

        // Available stock is reduced by all claims
        $this->assertEquals(130, $product->getAvailableStock());

        // Total claimed stock (always positive)
        $this->assertEquals(70, $product->getCurrentlyClaimedStock());
    }
    #[Test]
    public function adjust_stock_claimed_with_completed_status_does_not_count_as_claimed()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Note: adjustStock(CLAIMED) now always delegates to claimStock() which creates PENDING claims
        // This test no longer makes sense with the corrected implementation
        // Manual claim with COMPLETED status can still be created directly
        $product->stocks()->create([
            'type' => StockType::CLAIMED,
            'quantity' => 25,
            'status' => StockStatus::COMPLETED,
        ]);

        // Available stock unchanged (this is completed/released claim)
        $this->assertEquals(100, $product->getAvailableStock());

        // Should NOT count as claimed stock (only PENDING claims count)
        $this->assertEquals(0, $product->getCurrentlyClaimedStock());
    }

    #[Test]
    public function adjust_stock_with_mixed_types_calculates_correctly()
    {
        $product = Product::factory()->create(['manage_stock' => true]);

        // Start with 100
        $product->adjustStock(type: StockType::INCREASE, quantity: 100);
        $this->assertEquals(100, $product->getAvailableStock());

        // Claim 30 - now reduces available stock
        $product->adjustStock(
            type: StockType::CLAIMED,
            quantity: 30
        );
        $this->assertEquals(70, $product->getAvailableStock());
        $this->assertEquals(30, $product->getCurrentlyClaimedStock());

        // Decrease 20 (regular decrease with COMPLETED status)
        $product->adjustStock(type: StockType::DECREASE, quantity: 20);
        $this->assertEquals(50, $product->getAvailableStock());
        $this->assertEquals(30, $product->getCurrentlyClaimedStock());

        // Return 10 (adds back to stock)
        $product->adjustStock(type: StockType::RETURN, quantity: 10);
        $this->assertEquals(60, $product->getAvailableStock());
        $this->assertEquals(30, $product->getCurrentlyClaimedStock());

        // Increase 25
        $product->adjustStock(type: StockType::INCREASE, quantity: 25);
        $this->assertEquals(85, $product->getAvailableStock());
        $this->assertEquals(30, $product->getCurrentlyClaimedStock());
    }
    #[Test]
    public function adjust_stock_claimed_without_from_is_immediately_active()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Claim without 'from' date - should be immediately active
        $product->adjustStock(
            type: StockType::CLAIMED,
            quantity: 40,
            until: now()->addDays(10)
        );

        // Should be claimed right now
        $availableNow = $product->availableOnDate(now());
        $this->assertEquals(60, $availableNow);

        // Should still be claimed tomorrow
        $availableTomorrow = $product->availableOnDate(now()->addDays(1));
        $this->assertEquals(60, $availableTomorrow);

        // Should be released after expiration
        $availableAfter = $product->availableOnDate(now()->addDays(11));
        $this->assertEquals(100, $availableAfter);
    }
    #[Test]
    public function adjust_stock_claimed_without_until_is_permanent_claim()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Permanent claim from day 5 onwards (no until date)
        $product->adjustStock(
            type: StockType::CLAIMED,
            quantity: 35,
            from: now()->addDays(5)
        );

        // Before 'from' date: full stock available
        $availableOnDay3 = $product->availableOnDate(now()->addDays(3));
        $this->assertEquals(100, $availableOnDay3);

        // After 'from' date: permanently reduced
        $availableOnDay10 = $product->availableOnDate(now()->addDays(10));
        $this->assertEquals(65, $availableOnDay10);

        // Far future: still reduced (permanent)
        $availableOnDay100 = $product->availableOnDate(now()->addDays(100));
        $this->assertEquals(65, $availableOnDay100);
    }
    #[Test]
    public function adjust_stock_with_overlapping_claimed_periods_calculates_correctly()
    {
        $product = Product::factory()->withStocks(100)->create();

        // First claim: days 5-15
        $product->adjustStock(
            type: StockType::CLAIMED,
            quantity: 20,
            from: now()->addDays(5),
            until: now()->addDays(15)
        );

        $this->assertEquals(100, $product->getAvailableStock());
        $this->assertEquals(0, $product->getCurrentlyClaimedStock());
        $this->assertEquals(20, $product->getActiveAndPlannedClaimedStock());
        $this->assertEquals(20, $product->getFutureClaimedStock());

        // Second claim: days 10-20
        $product->adjustStock(
            type: StockType::CLAIMED,
            quantity: 30,
            from: now()->addDays(10),
            until: now()->addDays(20)
        );

        $this->assertEquals(100, $product->getAvailableStock());
        $this->assertEquals(0, $product->getCurrentlyClaimedStock());
        $this->assertEquals(50, $product->getActiveAndPlannedClaimedStock());
        $this->assertEquals(50, $product->getFutureClaimedStock());

        // Day 3: no claims active
        $this->assertEquals(100, $product->availableOnDate(now()->addDays(3)));

        // Day 7: only first claim active
        $this->assertEquals(80, $product->availableOnDate(now()->addDays(7)));

        // Day 12: both claims active
        $this->assertEquals(50, $product->availableOnDate(now()->addDays(12)));

        // Day 18: only second claim active
        $this->assertEquals(70, $product->availableOnDate(now()->addDays(18)));

        // Day 25: no claims active
        $this->assertEquals(100, $product->availableOnDate(now()->addDays(25)));

        // Current available stock (future claims do not reduce until active)
        $this->assertEquals(100, $product->getAvailableStock());

        $this->travel(6)->days();

        $this->assertEquals(80, $product->getAvailableStock());
        $this->assertEquals(20, $product->getCurrentlyClaimedStock());
        $this->assertEquals(50, $product->getActiveAndPlannedClaimedStock());
        $this->assertEquals(30, $product->getFutureClaimedStock());

        $this->travel(6)->days();

        $this->assertEquals(50, $product->getAvailableStock());
        $this->assertEquals(50, $product->getCurrentlyClaimedStock());
        $this->assertEquals(50, $product->getActiveAndPlannedClaimedStock());
        $this->assertEquals(0, $product->getFutureClaimedStock());
    }
    #[Test]
    public function adjust_stock_with_note_and_reference_tracks_correctly()
    {
        $product = Product::factory()->withStocks(100)->create();
        $user = \Workbench\App\Models\User::factory()->create();

        // Claim with note and reference
        $product->adjustStock(
            type: StockType::CLAIMED,
            quantity: 25,
            note: 'VIP customer reservation',
            referencable: $user
        );

        // Available stock is reduced
        $this->assertEquals(75, $product->getAvailableStock());
        // Claimed stock shows positive value
        $this->assertEquals(25, $product->getCurrentlyClaimedStock());

        // Verify note and reference are stored
        $claim = $product->stocks()->where('type', StockType::CLAIMED->value)->first();
        $this->assertEquals('VIP customer reservation', $claim->note);
        $this->assertEquals($user->id, $claim->reference_id);
        $this->assertEquals(get_class($user), $claim->reference_type);
    }

    #[Test]
    public function adjust_stock_expired_claims_dont_affect_current_availability()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Create an expired claim using adjustStock
        $product->adjustStock(
            type: StockType::CLAIMED,
            quantity: 30,
            from: now()->subDays(5),
            until: now()->subDays(1)
        );

        // Available stock is automatically restored (expired claims add stock back)
        $this->assertEquals(100, $product->getAvailableStock());

        // Claimed stock does NOT show expired claims (automatically excluded)
        $this->assertEquals(0, $product->getCurrentlyClaimedStock());

        // Active claims (not expired) should be empty
        $activeClaims = $product->claims()->get();
        $this->assertCount(0, $activeClaims);
    }

    #[Test]
    public function adjust_stock_releasing_claimed_updates_calculations()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Create claim using adjustStock
        $product->adjustStock(
            type: StockType::CLAIMED,
            quantity: 40
        );

        $this->assertEquals(60, $product->getAvailableStock());
        $this->assertEquals(40, $product->getCurrentlyClaimedStock());

        // Find and release the claim
        $claim = $product->stocks()
            ->where('type', StockType::CLAIMED->value)
            ->where('status', StockStatus::PENDING->value)
            ->first();

        $claim->release();

        // Claimed stock should drop to 0
        $this->assertEquals(0, $product->getCurrentlyClaimedStock());

        // Available stock is restored when claim is released
        $this->assertEquals(100, $product->getAvailableStock());
    }

    #[Test]
    public function stock_claim_creates_correct_transactions()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Claim 30 units for 5 days
        $claim = $product->claimStock(
            quantity: 30,
            until: now()->addDays(5)
        );

        $this->assertNotNull($claim);

        // Should create two entries: DECREASE (COMPLETED) + CLAIMED (PENDING)
        $decreaseEntry = $product->stocks()
            ->where('type', StockType::DECREASE->value)
            ->where('status', StockStatus::COMPLETED->value)
            ->first();

        $claimedEntry = $product->stocks()
            ->where('type', StockType::CLAIMED->value)
            ->where('status', StockStatus::PENDING->value)
            ->first();

        $this->assertNotNull($decreaseEntry, 'DECREASE entry should exist');
        $this->assertEquals(-30, $decreaseEntry->quantity);
        $this->assertEquals(StockStatus::COMPLETED, $decreaseEntry->status);

        $this->assertNotNull($claimedEntry, 'CLAIMED entry should exist');
        $this->assertEquals(30, $claimedEntry->quantity);
        $this->assertEquals(StockStatus::PENDING, $claimedEntry->status);
        $this->assertNotNull($claimedEntry->expires_at);
    }

    #[Test]
    public function claimed_stock_reduces_available_and_increases_claimed()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Initial state
        $this->assertEquals(100, $product->getAvailableStock());
        $this->assertEquals(0, $product->getCurrentlyClaimedStock());

        // Claim 30 units
        $product->claimStock(quantity: 30, until: now()->addDays(5));

        // Available stock should be reduced
        $this->assertEquals(70, $product->getAvailableStock());

        // Claimed stock should show the claim
        $this->assertEquals(30, $product->getCurrentlyClaimedStock());

        // Claim another 20 units
        $product->claimStock(quantity: 20, until: now()->addDays(3));

        // Available stock should be further reduced
        $this->assertEquals(50, $product->getAvailableStock());

        // Claimed stock should show both claims
        $this->assertEquals(50, $product->getCurrentlyClaimedStock());
    }

    #[Test]
    public function expired_claims_automatically_restore_available_stock()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Claim 30 units, expires in 5 days
        $product->claimStock(quantity: 30, until: now()->addDays(5));

        // During claim period: available reduced, claimed shows 30
        $this->assertEquals(70, $product->getAvailableStock());
        $this->assertEquals(30, $product->getCurrentlyClaimedStock());

        // Travel to day 3 (still within claim period)
        $this->travel(3)->days();

        $this->assertEquals(70, $product->getAvailableStock());
        $this->assertEquals(30, $product->getCurrentlyClaimedStock());

        // Travel to day 6 (after expiration)
        $this->travel(3)->days();

        // Available stock should be automatically restored
        $this->assertEquals(100, $product->getAvailableStock());

        // Claimed stock should be 0 (expired claims excluded)
        $this->assertEquals(0, $product->getCurrentlyClaimedStock());

        // No manual release() was called!
    }

    #[Test]
    public function multiple_claims_with_different_expirations_restore_progressively()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Claim 1: 20 units, expires in 3 days
        $product->claimStock(quantity: 20, until: now()->addDays(3));

        // Claim 2: 30 units, expires in 7 days
        $product->claimStock(quantity: 30, until: now()->addDays(7));

        // Initial state: both claims active
        $this->assertEquals(50, $product->getAvailableStock()); // 100 - 20 - 30
        $this->assertEquals(50, $product->getCurrentlyClaimedStock());   // 20 + 30

        // Travel to day 4 (first claim expired, second still active)
        $this->travel(4)->days();

        $this->assertEquals(70, $product->getAvailableStock()); // 100 - 30 (only second claim)
        $this->assertEquals(30, $product->getCurrentlyClaimedStock());   // Only second claim

        // Travel to day 8 (both claims expired)
        $this->travel(4)->days();

        $this->assertEquals(100, $product->getAvailableStock()); // All restored
        $this->assertEquals(0, $product->getCurrentlyClaimedStock());     // No claims
    }

    #[Test]
    public function permanent_claims_without_expiration_never_auto_restore()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Permanent claim (no until date)
        $product->claimStock(quantity: 25);

        // Available stock reduced, claimed shows 25
        $this->assertEquals(75, $product->getAvailableStock());
        $this->assertEquals(25, $product->getCurrentlyClaimedStock());

        // Travel far into the future
        $this->travel(100)->days();

        // Permanent claim never expires
        $this->assertEquals(75, $product->getAvailableStock());
        $this->assertEquals(25, $product->getCurrentlyClaimedStock());

        // Must manually release permanent claims
        $claim = $product->stocks()
            ->where('type', StockType::CLAIMED->value)
            ->where('status', StockStatus::PENDING->value)
            ->first();

        $claim->release();

        // Now stock is restored
        $this->assertEquals(100, $product->getAvailableStock());
        $this->assertEquals(0, $product->getCurrentlyClaimedStock());
    }

    #[Test]
    public function adjust_stock_claimed_also_auto_restores_after_expiration()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Use adjustStock instead of claimStock
        $product->adjustStock(
            type: StockType::CLAIMED,
            quantity: 40,
            until: now()->addDays(5)
        );

        // During claim period
        $this->assertEquals(60, $product->getAvailableStock());
        $this->assertEquals(40, $product->getCurrentlyClaimedStock());

        // After expiration
        $this->travel(6)->days();

        // Stock automatically restored
        $this->assertEquals(100, $product->getAvailableStock());
        $this->assertEquals(0, $product->getCurrentlyClaimedStock());
    }

    #[Test]
    public function claimed_stock_transactions_maintain_data_integrity()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Claim 35 units
        $claim = $product->claimStock(quantity: 35, until: now()->addDays(5));

        // Verify DECREASE entry
        $decrease = $product->stocks()
            ->where('type', StockType::DECREASE->value)
            ->latest()
            ->first();

        $this->assertEquals(-35, $decrease->quantity);
        $this->assertEquals(StockStatus::COMPLETED, $decrease->status);

        // Verify CLAIMED entry matches the returned claim
        $this->assertEquals($claim->id, $product->stocks()
            ->where('type', StockType::CLAIMED->value)
            ->latest()
            ->first()
            ->id);

        $this->assertEquals(35, $claim->quantity);
        $this->assertEquals(StockStatus::PENDING, $claim->status);

        // Verify totals
        $allCompleted = $product->stocks()
            ->where('status', StockStatus::COMPLETED->value)
            ->where('type', '!=', StockType::CLAIMED->value)
            ->sum('quantity');

        // Should be: initial increase (100) + decrease (-35) = 65
        // But getAvailableStock applies willExpire filter
        $this->assertEquals(65, $product->getAvailableStock());

        $allClaimed = $product->stocks()
            ->where('type', StockType::CLAIMED->value)
            ->where('status', StockStatus::PENDING->value)
            ->sum('quantity');

        $this->assertEquals(35, $allClaimed);
    }

    #[Test]
    public function can_get_all_stocks_including_pending_and_expired()
    {
        $product = Product::factory()->withStocks(100)->create();

        $product->adjustStock(type: StockType::INCREASE, quantity: 50);
        $this->assertEquals(150, $product->getAvailableStock());
        $this->assertEquals(0, $product->getCurrentlyClaimedStock());

        $product->adjustStock(type: StockType::DECREASE, quantity: 20);
        $this->assertEquals(130, $product->getAvailableStock());
        $this->assertEquals(0, $product->getCurrentlyClaimedStock());

        $product->adjustStock(type: StockType::CLAIMED, quantity: 30, until: now()->addDays(5));
        $this->assertEquals(100, $product->getAvailableStock());
        $this->assertEquals(30, $product->getCurrentlyClaimedStock());

        $product->adjustStock(type: StockType::CLAIMED, quantity: 10, until: now()->subDays(1));
        $this->assertEquals(100, $product->getAvailableStock());
        $this->assertEquals(30, $product->getCurrentlyClaimedStock());

        $product->adjustStock(type: StockType::CLAIMED, quantity: 10, from: now()->addDays(1), until: now()->addDays(5));
        $this->assertEquals(100, $product->getAvailableStock());
        $this->assertEquals(30, $product->getCurrentlyClaimedStock());

        $allStocks = $product->allStocks()->get();
        $types = $allStocks->pluck('type')->toArray();

        $this->assertCount(9, $allStocks);
        $this->assertContains(StockType::INCREASE, $types);
        $this->assertContains(StockType::DECREASE, $types);
        $this->assertEquals(3, count(array_filter($types, fn($t) => $t === StockType::CLAIMED)));

        $this->travel(2)->days();

        $this->assertEquals(90, $product->getAvailableStock());
        $this->assertEquals(40, $product->getCurrentlyClaimedStock());

        $this->travel(5)->days();

        $this->assertEquals(130, $product->getAvailableStock());
        $this->assertEquals(0, $product->getCurrentlyClaimedStock());
    }

    #[Test]
    public function get_claimed_stock_returns_active_pending_claims_only()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Create active claims (immediately claimed)
        $product->claimStock(quantity: 20);
        $product->claimStock(quantity: 15);

        // Create future claim (starts later)
        $product->claimStock(quantity: 10, from: now()->addDays(5));

        // Create expired claim
        $product->claimStock(quantity: 25, until: now()->subDay());

        // getCurrentlyClaimedStock should only count active claims: 20 + 15 = 35
        $this->assertEquals(35, $product->getCurrentlyClaimedStock());

        // Verify the future claim and expired claim are not counted
        $allClaims = $product->stocks()
            ->where('type', StockType::CLAIMED->value)
            ->get();

        $this->assertCount(4, $allClaims);
    }

    #[Test]
    public function get_claimed_stock_excludes_released_claims()
    {
        $product = Product::factory()->withStocks(100)->create();

        // Create claims
        $claim1 = $product->claimStock(quantity: 20);
        $claim2 = $product->claimStock(quantity: 30);

        $this->assertEquals(50, $product->getCurrentlyClaimedStock());

        // Release one claim
        $claim1->release();

        // Should only count remaining active claim
        $this->assertEquals(30, $product->fresh()->getCurrentlyClaimedStock());

        // Release the other claim
        $claim2->release();

        // Should be zero when all released
        $this->assertEquals(0, $product->fresh()->getCurrentlyClaimedStock());
    }

    #[Test]
    public function get_claimed_stock_accounts_for_claim_expiration()
    {
        $product = Product::factory()->withStocks(200)->create();

        // Create claims with different expiration dates
        $product->claimStock(quantity: 50, until: now()->addDays(2));
        $product->claimStock(quantity: 75, until: now()->addDays(10));
        $product->claimStock(quantity: 30); // permanent claim (no expiration)

        // Initially, all should be claimed
        $this->assertEquals(155, $product->getCurrentlyClaimedStock());

        // Travel to day 3 - first claim should have expired
        $this->travel(3)->days();
        $this->assertEquals(105, $product->fresh()->getCurrentlyClaimedStock());

        // Travel to day 11 - second claim should have expired
        $this->travel(9)->days();
        $this->assertEquals(30, $product->fresh()->getCurrentlyClaimedStock());

        // Permanent claim should never expire
        $this->travel(100)->days();
        $this->assertEquals(30, $product->fresh()->getCurrentlyClaimedStock());
    }

    #[Test]
    public function get_total_planned_claimed_stock_includes_future_claims()
    {
        $product = Product::factory()->withStocks(300)->create();

        // Create active claims
        $product->claimStock(quantity: 25);

        // Create future claims (start later)
        $product->claimStock(quantity: 50, from: now()->addDays(2));
        $product->claimStock(quantity: 35, from: now()->addDays(7));

        // Create permanent claim from specific date
        $product->claimStock(quantity: 40, from: now()->addDays(15));

        // getActiveAndPlannedClaimedStock should include all PENDING claims regardless of start date
        // This includes: 25 + 50 + 35 + 40 = 150
        $this->assertEquals(150, $product->getActiveAndPlannedClaimedStock());

        // getCurrentlyClaimedStock should only include active ones (started)
        $this->assertEquals(25, $product->getCurrentlyClaimedStock());
    }

    #[Test]
    public function get_total_planned_claimed_stock_excludes_expired_claims()
    {
        $product = Product::factory()->withStocks(200)->create();

        // Create various claims
        $product->claimStock(quantity: 30, until: now()->subDay()); // already expired
        $product->claimStock(quantity: 40, until: now()->addDays(5)); // will expire
        $product->claimStock(quantity: 50); // permanent

        // Should only count non-expired PENDING claims: 40 + 50 = 90
        $this->assertEquals(90, $product->getActiveAndPlannedClaimedStock());
    }

    #[Test]
    public function get_total_planned_claimed_stock_excludes_released_claims()
    {
        $product = Product::factory()->withStocks(200)->create();

        // Create claims
        $claim1 = $product->claimStock(quantity: 35, from: now()->addDays(3));
        $claim2 = $product->claimStock(quantity: 45, from: now()->addDays(8));
        $claim3 = $product->claimStock(quantity: 25);

        $this->assertEquals(105, $product->getActiveAndPlannedClaimedStock());

        // Release one future claim
        $claim1->release();

        // Should not count released claims: 45 + 25 = 70
        $this->assertEquals(70, $product->fresh()->getActiveAndPlannedClaimedStock());

        // Release another
        $claim2->release();

        // Should only count unreleased: 25
        $this->assertEquals(25, $product->fresh()->getActiveAndPlannedClaimedStock());
    }

    #[Test]
    public function get_future_claimed_stock_without_from_date_parameter()
    {
        $product = Product::factory()->withStocks(300)->create();

        // Create immediate claim (no claimed_from)
        $product->claimStock(quantity: 20);

        // Create future claims
        $product->claimStock(quantity: 30, from: now()->addDays(2));
        $product->claimStock(quantity: 40, from: now()->addDays(5));

        // Without parameter, getFutureClaimedStock should only count claims with claimed_from > now()
        $this->assertEquals(70, $product->getFutureClaimedStock());

        // Travel forward
        $this->travel(3)->days();

        // Now the first future claim (day 2) is active, so only the day 5 one remains
        $this->assertEquals(40, $product->fresh()->getFutureClaimedStock());

        // Travel more
        $this->travel(3)->days();

        // All future claims have started
        $this->assertEquals(0, $product->fresh()->getFutureClaimedStock());
    }

    #[Test]
    public function get_future_claimed_stock_with_from_date_parameter()
    {
        $product = Product::factory()->withStocks(300)->create();

        // Create claims
        $product->claimStock(quantity: 20); // no claimed_from
        $product->claimStock(quantity: 30, from: now()->addDays(3));
        $product->claimStock(quantity: 40, from: now()->addDays(8));
        $product->claimStock(quantity: 25, from: now()->addDays(15));

        // Get claims starting from day 5 onwards
        $fromDay5 = $product->getFutureClaimedStock(now()->addDays(5));
        $this->assertEquals(65, $fromDay5); // 40 + 25

        // Get claims starting from day 10 onwards
        $fromDay10 = $product->getFutureClaimedStock(now()->addDays(10));
        $this->assertEquals(25, $fromDay10); // 25

        // Get claims starting from future date with no claims
        $fromDay20 = $product->getFutureClaimedStock(now()->addDays(20));
        $this->assertEquals(0, $fromDay20);
    }

    #[Test]
    public function get_future_claimed_stock_excludes_expired_claims()
    {
        $product = Product::factory()->withStocks(300)->create();

        // Future claim that expires before being used
        $product->claimStock(quantity: 30, from: now()->addDays(2), until: now()->addDays(3));

        // Future claim with normal expiration
        $product->claimStock(quantity: 50, from: now()->addDays(5), until: now()->addDays(10));

        // Without parameter, both are future claims
        $this->assertEquals(80, $product->getFutureClaimedStock());

        // Travel to day 4 (first claim expired on day 3, second still active)
        $this->travel(4)->days();

        // getFutureClaimedStock uses willExpire() which filters out expired claims
        // The first claim is expired, so only the second one counts
        // The second claim still starts in the future (day 5 from original, which is tomorrow)
        $this->assertEquals(50, $product->fresh()->getFutureClaimedStock());
    }

    #[Test]
    public function claimed_stock_methods_work_together()
    {
        $product = Product::factory()->withStocks(500)->create();

        // Create a mix of claims
        $product->claimStock(quantity: 50); // active now
        $product->claimStock(quantity: 75, from: now()->addDays(3)); // future
        $product->claimStock(quantity: 40, from: now()->addDays(7), until: now()->addDays(10)); // future with expiry
        $product->claimStock(quantity: 60); // another active now

        // Test all three methods
        $this->assertEquals(110, $product->getCurrentlyClaimedStock()); // 50 + 60 (active claims)
        $this->assertEquals(225, $product->getActiveAndPlannedClaimedStock()); // all 4 claims
        $this->assertEquals(115, $product->getFutureClaimedStock()); // 75 + 40 (future claims)

        // Release one active claim
        $activeClaimId = $product->stocks()
            ->where('type', StockType::CLAIMED->value)
            ->where('status', StockStatus::PENDING->value)
            ->whereNull('claimed_from')
            ->first()
            ->id;

        $product->stocks()->find($activeClaimId)->release();

        // Recalculate
        $this->assertEquals(60, $product->fresh()->getCurrentlyClaimedStock()); // only one active now
        $this->assertEquals(175, $product->fresh()->getActiveAndPlannedClaimedStock()); // 3 remaining
        $this->assertEquals(115, $product->fresh()->getFutureClaimedStock()); // future ones unchanged
    }

    #[Test]
    public function claimed_stock_methods_return_zero_for_unmanaged_stock()
    {
        $product = Product::factory()->create(['manage_stock' => false]);

        // Try to claim stock (should return null)
        $claim = $product->claimStock(quantity: 50);
        $this->assertNull($claim);

        // Methods should still return 0
        $this->assertEquals(0, $product->getCurrentlyClaimedStock());
        $this->assertEquals(0, $product->getActiveAndPlannedClaimedStock());
        $this->assertEquals(0, $product->getFutureClaimedStock());
    }
}
