<?php

namespace Blax\Shop\Tests\Feature\Loan;

use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Events\LoanCreated;
use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Blax\Shop\Traits\IsLoanableProduct;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Coverage for {@see IsLoanableProduct::checkOutTo()} — the atomic
 * decreaseStock + purchase row + LoanCreated event path that every host
 * controller uses to start a loan. Until this file existed, the trait that
 * production code actually depends on had zero direct test coverage; the
 * existing Loan tests exercise {@see HasLoanLifecycle} (extend / mark
 * returned / scopes) but always assemble loan rows by hand.
 */
class CheckOutToTest extends TestCase
{
    use RefreshDatabase;

    private User $borrower;
    private LoanableBook $book;

    protected function setUp(): void
    {
        parent::setUp();

        $this->borrower = User::factory()->create();
        $this->book = LoanableBook::create([
            'name' => 'Hyperion',
            'sku' => '9780553283686',
        ]);
        $this->book->increaseStock(3);
    }

    #[Test]
    public function it_creates_a_pending_purchase_decrements_stock_and_dispatches_loan_created(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));
        Event::fake([LoanCreated::class]);

        $loan = $this->book->checkOutTo($this->borrower);

        $this->assertInstanceOf(ProductPurchase::class, $loan);
        $this->assertTrue($loan->exists);
        $this->assertSame(PurchaseStatus::PENDING, $loan->status);
        $this->assertSame(1, $loan->quantity);
        $this->assertSame(0, (int) $loan->amount);
        $this->assertSame(0, (int) $loan->amount_paid);
        $this->assertSame(
            Carbon::parse('2026-05-14 10:00:00')->toDateTimeString(),
            $loan->from->toDateTimeString(),
        );
        $this->assertSame(
            Carbon::parse('2026-05-28 10:00:00')->toDateTimeString(),
            $loan->until->toDateTimeString(),
        );
        $this->assertSame(0, (int) ((array) $loan->meta)['extensions_used'] ?? 99);

        $this->assertSame(2, $this->book->fresh()->getAvailableStock());

        Event::assertDispatched(
            LoanCreated::class,
            fn (LoanCreated $event) => $event->loan->is($loan),
        );
    }

    #[Test]
    public function it_honours_the_explicit_weeks_argument(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));

        $loan = $this->book->checkOutTo($this->borrower, weeks: 4);

        $this->assertSame(
            Carbon::parse('2026-06-11 10:00:00')->toDateTimeString(),
            $loan->until->toDateTimeString(),
        );
    }

    #[Test]
    public function it_falls_back_to_the_shop_loan_default_duration_weeks_config(): void
    {
        config(['shop.loan.default_duration_weeks' => 3]);
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));

        $loan = $this->book->checkOutTo($this->borrower);

        $this->assertSame(
            Carbon::parse('2026-06-04 10:00:00')->toDateTimeString(),
            $loan->until->toDateTimeString(),
        );
    }

    #[Test]
    public function it_throws_not_enough_stock_when_no_copies_are_available(): void
    {
        $only = LoanableBook::create(['name' => 'Solitaire', 'sku' => 'S-1']);
        $only->increaseStock(1);

        $only->checkOutTo($this->borrower);

        $this->expectException(NotEnoughStockException::class);
        $only->checkOutTo(User::factory()->create());
    }

    #[Test]
    public function it_is_atomic_no_purchase_row_remains_when_stock_decrement_fails(): void
    {
        // Stock is 0; decreaseStock throws inside the transaction. The
        // wrapping DB::transaction must roll back, leaving no purchase row.
        $empty = LoanableBook::create(['name' => 'Out of Print', 'sku' => 'OOP-1']);

        $baseline = ProductPurchase::query()
            ->where('purchasable_id', $empty->id)
            ->count();

        try {
            $empty->checkOutTo($this->borrower);
            $this->fail('checkOutTo should have thrown NotEnoughStockException.');
        } catch (NotEnoughStockException) {
            // expected
        }

        $this->assertSame(
            $baseline,
            ProductPurchase::query()->where('purchasable_id', $empty->id)->count(),
            'A failed checkOutTo must not leave a dangling purchase row.',
        );
    }

    #[Test]
    public function contention_on_a_single_copy_is_resolved_first_caller_wins(): void
    {
        // Two borrowers race for the only copy. The first call succeeds; the
        // second must fail with NotEnoughStockException — the controller's
        // job is then to surface that as a friendly validation error.
        $single = LoanableBook::create(['name' => 'Singular', 'sku' => 'SNG-1']);
        $single->increaseStock(1);

        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $single->checkOutTo($alice);

        $this->expectException(NotEnoughStockException::class);
        $single->checkOutTo($bob);
    }

    #[Test]
    public function manage_stock_false_serves_unlimited_concurrent_borrowers(): void
    {
        // manage_stock=false ⇒ getAvailableStock returns PHP_INT_MAX and
        // decreaseStock short-circuits, so checkOutTo never blocks.
        $infinite = LoanableBook::create([
            'name' => 'The Infinite Compendium',
            'sku' => 'INF-1',
            'manage_stock' => false,
        ]);

        $borrowers = User::factory()->count(5)->create();
        foreach ($borrowers as $borrower) {
            $infinite->checkOutTo($borrower);
        }

        $this->assertSame(
            5,
            ProductPurchase::query()->where('purchasable_id', $infinite->id)->count(),
        );
    }

    #[Test]
    public function mark_returned_does_not_restore_stock_intentionally(): void
    {
        // Locking-in regression test for an opinionated design choice: the
        // package's markReturned() flips lifecycle state but leaves stock
        // alone. Hosts that model loans as borrow-and-return (rather than
        // permanent ownership transfer) must follow up with an explicit
        // increaseStock(1) — see moonshiner-library's LoanController.
        $loan = $this->book->checkOutTo($this->borrower);
        $availableAfterCheckout = $this->book->fresh()->getAvailableStock();

        $loan->markReturned();

        $this->assertSame(
            $availableAfterCheckout,
            $this->book->fresh()->getAvailableStock(),
            'markReturned() must not change stock — hosts opt in to that explicitly.',
        );
    }
}

/**
 * Minimal loanable fixture: extending Product picks up the package's
 * polymorphism, the IsLoanableProduct trait wires up checkOutTo and the
 * total_quantity / available_quantity virtuals. Both base and subclass
 * resolve to the `products` table via Product::__construct, so no migration
 * is needed.
 */
class LoanableBook extends Product
{
    use IsLoanableProduct;

    protected $guarded = [];
}
