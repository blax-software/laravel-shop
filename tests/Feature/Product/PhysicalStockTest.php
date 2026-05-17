<?php

namespace Blax\Shop\Tests\Feature\Product;

use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Coverage for the new physical_stock accessor on Product (via HasStocks).
 *
 * The concept: physical = available + currently claimed + active loans.
 * It represents the count of units the business still owns regardless of
 * whether they're temporarily checked out. The three test classes below
 * each exercise the formula on a different product shape:
 *
 *   - Tomato shop  → DECREASE is permanent, physical == available.
 *   - Library book → DECREASE on loan, INCREASE on return; physical stays
 *                    at the catalogue size throughout the cycle.
 *   - Hotel room   → CLAIMED for bookings; physical includes active claims.
 */
class PhysicalStockTest extends TestCase
{
    use RefreshDatabase;

    private function tomato(int $initialStock): Product
    {
        $tomato = Product::create([
            'name' => 'Heirloom Tomato',
            'sku' => 'TOM-PHYS',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => true,
        ]);
        $tomato->increaseStock($initialStock);

        return $tomato;
    }

    private function book(int $copies): Product
    {
        $book = Product::create([
            'name' => 'Hyperion',
            'sku' => 'HYP-PHYS',
            'type' => ProductType::LOANABLE,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => true,
        ]);
        $book->increaseStock($copies);

        return $book;
    }

    private function room(): Product
    {
        $room = Product::create([
            'name' => 'Suite 1',
            'sku' => 'ROOM-PHYS',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => true,
        ]);
        $room->increaseStock(1);

        return $room;
    }

    /* ──────────────── consumable (tomato shop) ──────────────── */

    #[Test]
    public function tomato_shop_physical_equals_available_with_no_activity(): void
    {
        $tomato = $this->tomato(10);

        $this->assertSame(10, $tomato->getPhysicalStock());
        $this->assertSame(10, $tomato->physical_stock);
        $this->assertSame(10, $tomato->getAvailableStock());
    }

    #[Test]
    public function selling_a_tomato_reduces_physical_and_available_in_lockstep(): void
    {
        // A sale is recorded as a permanent DECREASE — no offsetting active
        // loan or claim. Both physical and available drop by the sold count.
        $tomato = $this->tomato(10);
        $tomato->decreaseStock(3);

        $this->assertSame(7, $tomato->getAvailableStock(), '3 sold → 7 on the shelf');
        $this->assertSame(7, $tomato->getPhysicalStock(), 'sold tomatoes are eaten — gone from physical inventory too');
    }

    /* ──────────────── loanable (library book) ──────────────── */

    #[Test]
    public function loaning_a_book_drops_available_but_not_physical(): void
    {
        // Loaned books still belong to the library — physical stays at the
        // catalogue size, available drops by the loaned count.
        $book = $this->book(5);
        $borrower = User::factory()->create();

        $book->checkOutTo($borrower);

        $fresh = $book->fresh();
        $this->assertSame(4, $fresh->getAvailableStock(), 'one copy is out → 4 on the shelf');
        $this->assertSame(5, $fresh->getPhysicalStock(), 'the loaned copy is still ours → physical = 5');
    }

    #[Test]
    public function returning_a_loan_restores_available_and_physical_stays_steady(): void
    {
        $book = $this->book(5);
        $borrower = User::factory()->create();

        $loan = $book->checkOutTo($borrower);
        $this->assertSame(5, $book->fresh()->getPhysicalStock());

        // Host-driven return: mark + restock (mirrors moonshiner's
        // LoanController::returnLoan).
        $loan->markReturned();
        $book->increaseStock(1);

        $fresh = $book->fresh();
        $this->assertSame(5, $fresh->getAvailableStock());
        $this->assertSame(5, $fresh->getPhysicalStock(), 'physical never wavered through the loan cycle');
    }

    #[Test]
    public function multiple_concurrent_loans_each_contribute_to_physical(): void
    {
        $book = $this->book(5);
        $book->checkOutTo(User::factory()->create());
        $book->checkOutTo(User::factory()->create());
        $book->checkOutTo(User::factory()->create());

        $fresh = $book->fresh();
        $this->assertSame(2, $fresh->getAvailableStock(), '3 out → 2 on shelf');
        $this->assertSame(5, $fresh->getPhysicalStock(), '3 active loans + 2 available = 5 owned');
    }

    /* ──────────────── booking (hotel room) ──────────────── */

    #[Test]
    public function active_claim_counts_toward_physical(): void
    {
        // A claim active right now (e.g. a cart reservation) holds the unit
        // back from new use, but the business still owns it.
        Carbon::setTestNow(Carbon::parse('2026-05-17 12:00:00'));
        $room = $this->room();
        $room->claimStock(
            1,
            null,
            Carbon::parse('2026-05-17 09:00:00'),
            Carbon::parse('2026-05-17 18:00:00'),
            'cart hold',
        );

        $fresh = $room->fresh();
        $this->assertSame(0, $fresh->getAvailableStock());
        $this->assertSame(1, $fresh->getCurrentlyClaimedStock());
        $this->assertSame(1, $fresh->getPhysicalStock(), 'claimed-but-still-ours counts as physical');
    }

    #[Test]
    public function future_only_claim_does_not_inflate_physical(): void
    {
        // A booking starting tomorrow doesn't reduce today's available stock,
        // so it must not double-count toward today's physical either.
        Carbon::setTestNow(Carbon::parse('2026-05-17 12:00:00'));
        $room = $this->room();
        $room->claimStock(
            1,
            null,
            Carbon::parse('2026-05-20 09:00:00'),
            Carbon::parse('2026-05-21 09:00:00'),
            'next-week booking',
        );

        $fresh = $room->fresh();
        $this->assertSame(1, $fresh->getAvailableStock(), 'future booking does not reduce today');
        $this->assertSame(0, $fresh->getCurrentlyClaimedStock(), 'future claim is not "current"');
        $this->assertSame(1, $fresh->getPhysicalStock(), 'physical = 1 + 0 + 0 = 1');
    }

    /* ──────────────── unmanaged stock ──────────────── */

    #[Test]
    public function unmanaged_stock_reports_infinite_physical(): void
    {
        // manage_stock=false makes available/physical both PHP_INT_MAX — the
        // package's universal "no scarcity" signal.
        $product = Product::create([
            'name' => 'eBook',
            'sku' => 'EB-PHYS',
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'is_visible' => true,
            'manage_stock' => false,
        ]);

        $this->assertSame(PHP_INT_MAX, $product->getPhysicalStock());
    }

    /* ──────────────── distinct from existing accessors ──────────────── */

    #[Test]
    public function physical_does_not_inflate_after_a_library_loan_return_cycle(): void
    {
        // Regression: getMaxStocksAttribute sums every INCREASE row — including
        // the +1 from a loan return — so for a borrow-and-return cycle it
        // overstates "Assigned" as 6 on a 5-copy book. physical_stock uses the
        // available+claims+loans formula instead and stays correctly at 5.
        $book = $this->book(5);
        $loan = $book->checkOutTo(User::factory()->create());
        $loan->markReturned();
        $book->increaseStock(1);

        $fresh = $book->fresh();
        $this->assertSame(6, $fresh->getMaxStocksAttribute(), 'documented limitation: max inflates per cycle');
        $this->assertSame(5, $fresh->getPhysicalStock(), 'physical stays at the real owned count');
    }

    #[Test]
    public function loan_quantity_above_one_aggregates_into_physical(): void
    {
        // Defensive coverage: real-world loans are always quantity=1, but the
        // formula sums purchase.quantity rather than counting rows, so a
        // hypothetical multi-unit loan would still account correctly.
        $book = $this->book(10);
        $book->decreaseStock(3); // simulate the stock-side of a 3-unit loan
        $borrower = User::factory()->create();
        ProductPurchase::create([
            'purchasable_id' => $book->id,
            'purchasable_type' => Product::class,
            'purchaser_id' => $borrower->id,
            'purchaser_type' => User::class,
            'quantity' => 3,
            'amount' => 0,
            'amount_paid' => 0,
            'status' => PurchaseStatus::PENDING,
            'from' => now(),
            'until' => now()->addWeeks(2),
            'meta' => ['extensions_used' => 0],
        ]);

        $fresh = $book->fresh();
        $this->assertSame(7, $fresh->getAvailableStock());
        $this->assertSame(10, $fresh->getPhysicalStock(), '7 on shelf + 3 on loan = 10 owned');
    }
}
