<?php

namespace Blax\Shop\Tests\Feature\Loan;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Events\StockClaimed;
use Blax\Shop\Events\StockDepleted;
use Blax\Shop\Events\StockIncreased;
use Blax\Shop\Events\StockReleased;
use Blax\Shop\Events\StockReplenished;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductStock;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Loans go through HasStocks::decreaseStock() and (host-driven) increaseStock()
 * for return, so they automatically participate in the StockDecreased /
 * StockIncreased / StockDepleted / StockReplenished event chain. EventsWiredUpTest
 * proves those events fire for direct decrease/increase calls; this file
 * pins down that the LOAN-driven paths (checkOutTo, markReturned-then-restock)
 * benefit from the same wiring, so external listeners (low-stock alerts,
 * search reindex, librarian notifications) react identically regardless of
 * whether stock moved via a checkout or a loan.
 */
class LoanStockEventsTest extends TestCase
{
    use RefreshDatabase;

    private User $borrower;
    private EventLoanBook $book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->borrower = User::factory()->create();
        $this->book = EventLoanBook::create(['name' => 'Hyperion', 'sku' => 'HYP-EV-1']);
        $this->book->increaseStock(3);
    }

    #[Test]
    public function checkOutTo_dispatches_stock_claimed_with_a_physically_claimed_row(): void
    {
        // Loans go through the claim machinery (PHYSICALLY_CLAIMED type), so
        // the canonical "stock has moved" event is StockClaimed — not
        // StockDecreased. The bookkeeping DECREASE row that claimStock writes
        // internally bypasses the public decreaseStock() path (and so does
        // not fire StockDecreased), matching how bookings have always worked.
        Event::fake([StockClaimed::class]);

        $loan = $this->book->checkOutTo($this->borrower);

        Event::assertDispatched(
            StockClaimed::class,
            fn (StockClaimed $e) => $e->product->is($this->book)
                && $e->entry instanceof ProductStock
                && (int) $e->entry->quantity === 1
                && $e->entry->type === StockType::PHYSICALLY_CLAIMED
                && $e->entry->status === StockStatus::PENDING
                && (string) $e->entry->reference_id === (string) $loan->id,
        );
    }

    #[Test]
    public function checking_out_the_last_copy_dispatches_stock_depleted(): void
    {
        // 3 copies on the shelf — borrow all three. The third call crosses
        // the last-copy boundary; claimStock's dispatchStockTransitions fires
        // StockDepleted regardless of whether the decrement came from a
        // direct decreaseStock or a claim.
        $this->book->checkOutTo(User::factory()->create());
        $this->book->checkOutTo(User::factory()->create());

        Event::fake([StockDepleted::class, StockClaimed::class]);

        $this->book->checkOutTo($this->borrower);

        Event::assertDispatched(StockClaimed::class);
        Event::assertDispatched(
            StockDepleted::class,
            fn (StockDepleted $e) => $e->product->is($this->book),
        );
    }

    #[Test]
    public function partial_checkout_does_not_dispatch_stock_depleted(): void
    {
        Event::fake([StockDepleted::class]);

        $this->book->checkOutTo($this->borrower);

        Event::assertNotDispatched(StockDepleted::class);
    }

    #[Test]
    public function returning_a_fully_loaned_book_dispatches_replenished_and_released(): void
    {
        // Single-copy book, borrow it (depletes to 0), then return it.
        // markReturned() releases the paired claim, which creates the
        // offsetting RETURN entry via the package's release() helper — so
        // StockIncreased + StockReleased both fire, and the 0→1 boundary
        // crossing additionally triggers StockReplenished. No host call
        // to increaseStock() needed.
        $single = EventLoanBook::create(['name' => 'Solitaire', 'sku' => 'SOL-EV-1']);
        $single->increaseStock(1);
        $loan = $single->checkOutTo($this->borrower);

        $this->assertSame(0, $single->fresh()->getAvailableStock());

        Event::fake([StockReplenished::class, StockIncreased::class, StockReleased::class]);

        $loan->markReturned();

        Event::assertDispatched(StockIncreased::class);
        Event::assertDispatched(StockReleased::class);
        Event::assertDispatched(
            StockReplenished::class,
            fn (StockReplenished $e) => $e->product->is($single) && $e->availableAfter === 1,
        );
    }

    #[Test]
    public function returning_when_other_copies_are_free_does_not_dispatch_replenished(): void
    {
        // 3-copy book, borrow 1 → 2 available. Returning goes 2→3, NOT a
        // 0→>0 transition, so StockReplenished must stay silent.
        $loan = $this->book->checkOutTo($this->borrower);

        Event::fake([StockReplenished::class]);

        $loan->markReturned();

        Event::assertNotDispatched(StockReplenished::class);
    }

    #[Test]
    public function event_wiring_holds_across_a_full_borrow_return_cycle(): void
    {
        // Full sequence: borrow → return. checkOutTo fires StockClaimed (no
        // StockDecreased — claim machinery bypasses it). markReturned fires
        // StockReleased + StockIncreased (the release writes a RETURN entry
        // through increaseStock). The 3→2 and 2→3 transitions are boundary-
        // free so StockDepleted/StockReplenished stay quiet.
        Event::fake([
            StockClaimed::class,
            StockReleased::class,
            StockIncreased::class,
            StockDepleted::class,
            StockReplenished::class,
        ]);

        $loan = $this->book->checkOutTo($this->borrower);
        $loan->markReturned();

        Event::assertDispatchedTimes(StockClaimed::class, 1);
        Event::assertDispatchedTimes(StockReleased::class, 1);
        Event::assertDispatchedTimes(StockIncreased::class, 1);
        Event::assertNotDispatched(StockDepleted::class, '3→2 is not a depletion');
        Event::assertNotDispatched(StockReplenished::class, '2→3 is not a replenishment');
    }
}

/**
 * Same plug-n-pray fixture as CheckOutToTest's: declare DEFAULT_TYPE so the
 * MayBeLoanableProduct creating-hook flips the row into loan mode.
 */
class EventLoanBook extends Product
{
    public const DEFAULT_TYPE = ProductType::LOANABLE;

    protected $guarded = [];
}
