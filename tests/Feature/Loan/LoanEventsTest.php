<?php

namespace Blax\Shop\Tests\Feature\Loan;

use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Events\LoanCreated;
use Blax\Shop\Events\LoanExtended;
use Blax\Shop\Events\LoanReturned;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Loan lifecycle domain events.
 *
 *   LoanCreated   — host dispatches it explicitly after creating a ProductPurchase
 *                   for a loanable item (the package can't tell loans apart from
 *                   carts / one-off purchases without ambiguity).
 *   LoanExtended  — dispatched from HasLoanLifecycle::extend()
 *   LoanReturned  — dispatched from HasLoanLifecycle::markReturned()
 */
class LoanEventsTest extends TestCase
{
    use RefreshDatabase;

    private User $borrower;
    private Product $book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->borrower = User::factory()->create();
        $this->book = Product::factory()->create([
            'name' => 'Hyperion',
            'type' => ProductType::LOANABLE,
            'manage_stock' => true,
        ]);
        $this->book->increaseStock(2);
    }

    private function loan(): ProductPurchase
    {
        return $this->book->purchases()->create([
            'purchaser_id' => $this->borrower->id,
            'purchaser_type' => User::class,
            'quantity' => 1,
            'amount' => 0,
            'amount_paid' => 0,
            'status' => PurchaseStatus::PENDING,
            'from' => Carbon::now(),
            'until' => Carbon::now()->addWeeks(2),
            'meta' => ['extensions_used' => 0],
        ]);
    }

    #[Test]
    public function extending_a_loan_fires_loan_extended(): void
    {
        Event::fake([LoanExtended::class]);

        $loan = $this->loan();
        $loan->extend(2);

        Event::assertDispatched(
            LoanExtended::class,
            fn (LoanExtended $event) =>
                $event->loan->is($loan)
                && $event->addedWeeks === 2
        );
    }

    #[Test]
    public function marking_a_loan_returned_fires_loan_returned(): void
    {
        Event::fake([LoanReturned::class]);

        $loan = $this->loan();
        $loan->markReturned();

        Event::assertDispatched(
            LoanReturned::class,
            fn (LoanReturned $event) => $event->loan->is($loan)
        );
    }

    #[Test]
    public function loan_created_is_a_host_dispatched_event(): void
    {
        // The package does NOT auto-dispatch LoanCreated — it can't reliably
        // distinguish loans from other ProductPurchase rows. Test that the
        // event class exists and can be dispatched by an integrating host.
        Event::fake([LoanCreated::class]);

        $loan = $this->loan();
        event(new LoanCreated($loan));

        Event::assertDispatched(
            LoanCreated::class,
            fn (LoanCreated $event) => $event->loan->is($loan)
        );
    }

    #[Test]
    public function extend_emits_each_call_with_the_correct_added_weeks(): void
    {
        Event::fake([LoanExtended::class]);

        $loan = $this->loan();
        $loan->extend(1);
        $loan->extend(3);

        Event::assertDispatchedTimes(LoanExtended::class, 2);

        $captured = [];
        Event::assertDispatched(LoanExtended::class, function (LoanExtended $event) use (&$captured) {
            $captured[] = $event->addedWeeks;
            return true;
        });

        $this->assertSame([1, 3], $captured);
    }
}
