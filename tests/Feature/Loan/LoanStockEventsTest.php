<?php

namespace Blax\Shop\Tests\Feature\Loan;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Events\StockDecreased;
use Blax\Shop\Events\StockDepleted;
use Blax\Shop\Events\StockIncreased;
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
    public function checkOutTo_dispatches_stock_decreased_with_correct_payload(): void
    {
        Event::fake([StockDecreased::class]);

        $this->book->checkOutTo($this->borrower);

        Event::assertDispatched(
            StockDecreased::class,
            fn (StockDecreased $e) => $e->product->is($this->book)
                && $e->availableAfter === 2
                && $e->entry instanceof ProductStock
                && (int) $e->entry->quantity === -1
                && $e->entry->type === StockType::DECREASE
                && $e->entry->status === StockStatus::COMPLETED,
        );
    }

    #[Test]
    public function checking_out_the_last_copy_dispatches_stock_depleted(): void
    {
        // 3 copies on the shelf — borrow all three; the third call crosses the
        // last-copy boundary so StockDepleted must fire alongside StockDecreased.
        $this->book->checkOutTo(User::factory()->create());
        $this->book->checkOutTo(User::factory()->create());

        Event::fake([StockDepleted::class, StockDecreased::class]);

        $this->book->checkOutTo($this->borrower);

        Event::assertDispatched(StockDecreased::class);
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
    public function restocking_after_a_full_loan_dispatches_stock_replenished(): void
    {
        // Single-copy book, borrow it (depletes to 0), then a host-driven
        // increaseStock(1) on the return path must cross 0→>0 and fire
        // StockReplenished. Mirrors what moonshiner-library does in
        // LoanController::returnLoan after $loan->markReturned().
        $single = EventLoanBook::create(['name' => 'Solitaire', 'sku' => 'SOL-EV-1']);
        $single->increaseStock(1);
        $loan = $single->checkOutTo($this->borrower);
        $loan->markReturned();

        $this->assertSame(0, $single->fresh()->getAvailableStock());

        Event::fake([StockReplenished::class, StockIncreased::class]);

        $single->increaseStock(1);

        Event::assertDispatched(StockIncreased::class);
        Event::assertDispatched(
            StockReplenished::class,
            fn (StockReplenished $e) => $e->product->is($single) && $e->availableAfter === 1,
        );
    }

    #[Test]
    public function restocking_when_other_copies_are_free_does_not_dispatch_replenished(): void
    {
        // 3-copy book, borrow 1 → 2 available. Returning that copy goes 2→3,
        // NOT a 0→>0 transition, so StockReplenished must stay silent.
        $loan = $this->book->checkOutTo($this->borrower);
        $loan->markReturned();

        Event::fake([StockReplenished::class]);

        $this->book->increaseStock(1);

        Event::assertNotDispatched(StockReplenished::class);
    }

    #[Test]
    public function event_wiring_holds_across_a_full_borrow_return_cycle(): void
    {
        // Full sequence: borrow → return-restock. We assert the relative count
        // and payload of each event in one go so a future refactor that splits
        // the path can't pass the per-step tests while breaking the rollup.
        Event::fake([
            StockDecreased::class,
            StockIncreased::class,
            StockDepleted::class,
            StockReplenished::class,
        ]);

        $loan = $this->book->checkOutTo($this->borrower);
        $loan->markReturned();
        $this->book->increaseStock(1);

        Event::assertDispatchedTimes(StockDecreased::class, 1);
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
