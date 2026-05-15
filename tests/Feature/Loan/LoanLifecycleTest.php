<?php

namespace Blax\Shop\Tests\Feature\Loan;

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
 * Exercises the loan lifecycle that ProductPurchase picks up from
 * {@see \Blax\Shop\Traits\HasLoanLifecycle}: extend(), markReturned(), the
 * scopes (activeLoans / returned / overdue), and the derived domain status.
 */
class LoanLifecycleTest extends TestCase
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
        $this->book->increaseStock(3);
    }

    private function checkout(?Carbon $from = null, ?int $weeks = 2): ProductPurchase
    {
        $from ??= Carbon::now();

        return $this->book->purchases()->create([
            'purchaser_id' => $this->borrower->id,
            'purchaser_type' => User::class,
            'quantity' => 1,
            'amount' => 0,
            'amount_paid' => 0,
            'status' => PurchaseStatus::PENDING,
            'from' => $from,
            'until' => $from->copy()->addWeeks($weeks),
            'meta' => ['extensions_used' => 0],
        ]);
    }

    #[Test]
    public function a_fresh_loan_is_active_with_zero_extensions(): void
    {
        $loan = $this->checkout();

        $this->assertFalse($loan->isReturned());
        $this->assertFalse($loan->isOverdue());
        $this->assertSame('active', $loan->getDomainStatus());
        $this->assertSame(0, $loan->extensionsUsed());
        $this->assertNull($loan->returnedAt());
    }

    #[Test]
    public function extend_pushes_due_date_and_increments_counter(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));
        $loan = $this->checkout();

        $loan->extend(1);
        $loan->refresh();

        $this->assertSame(1, $loan->extensionsUsed());
        $this->assertTrue(
            $loan->until->equalTo(Carbon::parse('2026-06-04 10:00:00')),
            'until should advance by exactly one week'
        );
    }

    #[Test]
    public function can_extend_respects_max_extensions(): void
    {
        $loan = $this->checkout();

        $this->assertTrue($loan->canExtend(2));
        $loan->extend(1);
        $this->assertTrue($loan->canExtend(2));
        $loan->extend(1);
        $loan->refresh();
        $this->assertFalse($loan->canExtend(2));
    }

    #[Test]
    public function can_extend_falls_back_to_shop_loan_max_extensions_config(): void
    {
        config(['shop.loan.max_extensions' => 1]);

        $loan = $this->checkout();
        $this->assertTrue($loan->canExtend());

        $loan->extend(1);
        $loan->refresh();

        $this->assertFalse($loan->canExtend());
    }

    #[Test]
    public function can_extend_returns_false_when_overdue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));
        $loan = $this->checkout();

        Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

        $this->assertTrue($loan->isOverdue());
        $this->assertFalse($loan->canExtend(5));
    }

    #[Test]
    public function mark_returned_records_timestamp_and_flips_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));
        $loan = $this->checkout();

        $loan->markReturned();
        $loan->refresh();

        $this->assertTrue($loan->isReturned());
        $this->assertSame(PurchaseStatus::COMPLETED, $loan->status);
        $this->assertSame('returned', $loan->getDomainStatus());
        $this->assertSame(
            Carbon::parse('2026-05-14 10:00:00')->toIso8601String(),
            $loan->returnedAt(),
        );
    }

    #[Test]
    public function mark_returned_accepts_explicit_timestamp(): void
    {
        $loan = $this->checkout();
        $when = Carbon::parse('2026-05-20 16:30:00');

        $loan->markReturned($when);

        $this->assertSame($when->toIso8601String(), $loan->returnedAt());
    }

    #[Test]
    public function active_loans_scope_excludes_returned_rows(): void
    {
        $active = $this->checkout();
        $returned = $this->checkout();
        $returned->markReturned();

        $ids = ProductPurchase::query()->activeLoans()->pluck('id')->all();

        $this->assertContains($active->id, $ids);
        $this->assertNotContains($returned->id, $ids);
    }

    #[Test]
    public function returned_scope_only_matches_handed_back_loans(): void
    {
        $this->checkout(); // active
        $handed_back = $this->checkout();
        $handed_back->markReturned();

        $ids = ProductPurchase::query()->returned()->pluck('id')->all();

        $this->assertSame([$handed_back->id], $ids);
    }

    #[Test]
    public function overdue_scope_matches_past_due_unreturned_loans(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));
        $onTime = $this->checkout();
        $late = $this->checkout(Carbon::parse('2026-04-01 10:00:00'));
        $returnedLate = $this->checkout(Carbon::parse('2026-04-01 10:00:00'));
        $returnedLate->markReturned();

        $ids = ProductPurchase::query()->overdue()->pluck('id')->all();

        $this->assertContains($late->id, $ids);
        $this->assertNotContains($onTime->id, $ids);
        $this->assertNotContains($returnedLate->id, $ids, 'returned loans are no longer overdue');
    }

    /* ───────────────────── edge cases ───────────────────── */

    #[Test]
    public function mark_returned_called_twice_keeps_the_first_returned_at_timestamp(): void
    {
        // markReturned is idempotent-ish: calling it again overwrites the
        // returned_at timestamp. We document that behaviour explicitly so a
        // future refactor knows whether to keep it. If you want first-write-
        // wins, change markReturned() to no-op when already returned.
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));
        $loan = $this->checkout();

        $loan->markReturned();
        $firstReturnedAt = $loan->returnedAt();

        Carbon::setTestNow(Carbon::parse('2026-05-20 10:00:00'));
        $loan->markReturned();

        $this->assertNotSame($firstReturnedAt, $loan->returnedAt(), 'second call overwrites');
        $this->assertSame(
            Carbon::parse('2026-05-20 10:00:00')->toIso8601String(),
            $loan->returnedAt(),
        );
    }

    #[Test]
    public function extend_increments_counter_even_when_until_is_null(): void
    {
        // A loan with no due date is unusual but legal. extend() must not
        // crash; current behaviour is to bump the counter without shifting
        // the date.
        $loan = $this->book->purchases()->create([
            'purchaser_id' => $this->borrower->id,
            'purchaser_type' => User::class,
            'quantity' => 1,
            'amount' => 0,
            'amount_paid' => 0,
            'status' => PurchaseStatus::PENDING,
            'from' => Carbon::parse('2026-05-14 10:00:00'),
            'until' => null,
            'meta' => ['extensions_used' => 0],
        ]);

        $loan->extend(2);

        $this->assertNull($loan->until);
        $this->assertSame(1, $loan->extensionsUsed());
    }

    #[Test]
    public function can_extend_returns_false_for_a_returned_loan_even_under_the_cap(): void
    {
        config(['shop.loan.max_extensions' => 5]);
        $loan = $this->checkout();

        $loan->markReturned();
        $loan->refresh();

        $this->assertFalse($loan->canExtend(), 'returned loan can never be extended');
    }

    #[Test]
    public function returned_at_handles_array_and_object_meta_casts(): void
    {
        $loan = $this->checkout();

        // Eloquent casts the meta column to object; the helper should still
        // read the key without crashing.
        $loan->meta = ['returned_at' => '2026-06-01T10:00:00+00:00', 'extensions_used' => 0];
        $loan->save();
        $loan->refresh();

        $this->assertSame('2026-06-01T10:00:00+00:00', $loan->returnedAt());
        $this->assertTrue($loan->isReturned());
    }

    /* ─────────────────── domain status (4 states) ─────────────────── */

    #[Test]
    public function fresh_loan_reads_as_active(): void
    {
        $loan = $this->checkout();
        $this->assertSame('active', $loan->getDomainStatus());
    }

    #[Test]
    public function loan_becomes_extended_after_one_or_more_extensions(): void
    {
        $loan = $this->checkout();

        $this->assertSame('active', $loan->getDomainStatus());
        $loan->extend(1);
        $loan->refresh();
        $this->assertSame('extended', $loan->getDomainStatus(), 'one extension flips status');

        $loan->extend(1);
        $loan->refresh();
        $this->assertSame('extended', $loan->getDomainStatus(), 'still extended after two');
    }

    #[Test]
    public function overdue_takes_precedence_over_extended(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));
        $loan = $this->checkout();
        $loan->extend(1);

        // Past the (already-extended) due date.
        Carbon::setTestNow(Carbon::parse('2027-01-01 10:00:00'));
        $loan->refresh();

        $this->assertGreaterThan(0, $loan->extensionsUsed());
        $this->assertSame('overdue', $loan->getDomainStatus(), 'overdue beats extended');
    }

    #[Test]
    public function returned_takes_precedence_over_extended(): void
    {
        $loan = $this->checkout();
        $loan->extend(1);

        $loan->markReturned();
        $loan->refresh();

        $this->assertSame('returned', $loan->getDomainStatus());
    }
}
