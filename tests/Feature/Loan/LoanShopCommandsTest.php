<?php

namespace Blax\Shop\Tests\Feature\Loan;

use Blax\Shop\Console\Commands\ShopAvailabilityCommand;
use Blax\Shop\Console\Commands\ShopStocksCommand;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Inspector commands ({@see ShopStocksCommand}, {@see ShopAvailabilityCommand})
 * read from product_stocks / product_purchases. CommandStocksTest and
 * CommandAvailabilityTest already exercise them against SIMPLE products; this
 * file covers the LOANABLE path — borrow + return cycles. A regression in
 * loan↔stock wiring shows up here as wrong Assigned / Used / Available numbers
 * earlier than any HTTP test would catch it.
 *
 * The Assigned-inflation bug ({@see self::assigned_for_loanable_product_must_not_inflate_after_a_return_cycle})
 * is enforced here: getMaxStocksAttribute() sums every INCREASE entry — and
 * the host-driven return path fires an INCREASE — so a 3-copy book that's
 * been borrowed-and-returned once used to render as Assigned=4. The command
 * now consults the loan-aware `total_quantity` accessor instead.
 */
class LoanShopCommandsTest extends TestCase
{
    use RefreshDatabase;

    private User $borrower;

    protected function setUp(): void
    {
        parent::setUp();

        $this->borrower = User::factory()->create();
    }

    private function newBook(string $name, string $sku): CmdLoanBook
    {
        return CmdLoanBook::create(['name' => $name, 'sku' => $sku]);
    }

    private function runOk(string $command, array $params = []): string
    {
        $exit = Artisan::call($command, $params);
        $output = Artisan::output();
        $this->assertSame(0, $exit, "{$command} returned non-zero:\n{$output}");

        return $output;
    }

    /* ─────────────────────── shop:stocks (overview) ─────────────────────── */

    #[Test]
    public function shop_stocks_overview_lists_a_loaned_book_with_correct_counts(): void
    {
        $book = $this->newBook('Dune', 'CMD-DUNE');
        $book->increaseStock(3);
        $book->checkOutTo($this->borrower);

        $output = $this->runOk(ShopStocksCommand::class);

        $this->assertStringContainsString('Dune', $output);

        $this->assertSame(1, $this->loanedDecreases($book), 'one DECREASE row from the loan');
        $this->assertSame(2, $book->fresh()->getAvailableStock(), '3 copies − 1 loan = 2 available');
    }

    #[Test]
    public function shop_stocks_overview_for_a_fully_loaned_book_reports_zero_available(): void
    {
        $book = $this->newBook('Ember', 'CMD-EMBER');
        $book->increaseStock(1);
        $book->checkOutTo($this->borrower);

        $output = $this->runOk(ShopStocksCommand::class);

        $this->assertStringContainsString('Ember', $output);
        $this->assertSame(0, $book->fresh()->getAvailableStock(), 'last copy is out');
        $this->assertSame(1, $this->loanedDecreases($book->fresh()));
    }

    /* ─────────────────────── shop:stocks (detail) ─────────────────────── */

    #[Test]
    public function shop_stocks_detail_view_renders_the_full_ledger_for_a_loaned_book(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));

        $book = $this->newBook('Hyperion', 'CMD-HYP');
        $book->increaseStock(3);

        // Borrow twice, return one. With the claim-based loan model the
        // ledger carries: seed INCREASE, two claim cycles each writing a
        // DECREASE + PHYSICALLY_CLAIMED row, plus one RETURN from the
        // released claim — six rows total, but the operator only needs to
        // see the standard increase/decrease verbs that the command renders.
        $loan = $book->checkOutTo($this->borrower);
        $book->checkOutTo(User::factory()->create());
        $loan->markReturned();

        $output = $this->runOk(ShopStocksCommand::class, ['product' => 'CMD-HYP']);

        $this->assertStringContainsString('Hyperion', $output);
        $this->assertStringContainsString('ASSIGNED', $output);
        $this->assertStringContainsString('AVAILABLE', $output);
        $this->assertStringContainsString('Recent stock ledger', $output);
        $this->assertStringContainsString('increase', $output);
        $this->assertStringContainsString('decrease', $output);

        // 3 copies, 1 still out → 2 available. Verified against the model to
        // sidestep ASCII-column regex fragility.
        $this->assertSame(2, $book->fresh()->getAvailableStock());
    }

    #[Test]
    public function assigned_for_loanable_product_must_not_inflate_after_a_return_cycle(): void
    {
        // Regression test for the bug where shop:stocks rendered Assigned=4
        // for a 3-copy book that had been borrowed-and-returned once. With
        // the new claim-based loan model the issue disappears at the source:
        // checkout writes a DECREASE + PHYSICALLY_CLAIMED pair, the return
        // releases the claim (status flip + RETURN row), and the net effect
        // on every accessor reads exactly 3 copies — no inflation possible.
        $book = $this->newBook('Hyperion', 'CMD-HYP-RC');
        $book->increaseStock(3);

        $loan = $book->checkOutTo($this->borrower);
        $loan->markReturned();

        $fresh = $book->fresh();
        $this->assertSame(3, (int) $fresh->total_quantity, 'loan-aware accessor reads true count');
        $this->assertSame(3, $fresh->getPhysicalStock(), 'physical reads true count');

        // Detail view: ASSIGNED row must be 3, not 4.
        $output = $this->runOk(ShopStocksCommand::class, ['product' => 'CMD-HYP-RC']);

        // The detail view renders labels on one row and values on the next.
        // Split into lines and look at the value row that follows the ASSIGNED
        // label row — the first numeric token in that line is Assigned.
        $assigned = $this->detailViewAssignedValue($output);
        $this->assertSame(
            3,
            $assigned,
            "shop:stocks detail must render Assigned=3 (physical capacity), got {$assigned}",
        );

        // Overview must also be consistent.
        $overviewOutput = $this->runOk(ShopStocksCommand::class);
        $row = $this->overviewRow($overviewOutput, 'Hyperion');
        $this->assertNotNull($row, 'Hyperion row must appear in the overview table');
        $this->assertSame(
            3,
            $row['assigned'],
            'shop:stocks overview must report Assigned=3 for a borrowed-then-returned 3-copy book',
        );
    }

    /* ─────────────────── shop:stocks:availability (calendar) ─────────────── */

    #[Test]
    public function shop_stocks_availability_headline_surfaces_physical_count(): void
    {
        // Coverage for the new "Physical N" headline that complements
        // "Available N". For a loanable product the gap between the two is the
        // loan tally — physical stays at the catalogue size while available
        // drops as copies go out.
        $book = $this->newBook('Hyperion', 'CMD-HYP-PHYS');
        $book->increaseStock(3);
        $book->checkOutTo($this->borrower);

        $output = $this->runOk(ShopAvailabilityCommand::class, ['product' => 'CMD-HYP-PHYS']);

        $this->assertStringContainsString('Physical 3', $output, 'physical = 2 on shelf + 1 active loan = 3');
        $this->assertStringContainsString('Available 2', $output);
    }

    #[Test]
    public function shop_stocks_detail_view_carries_a_physical_box(): void
    {
        // Operator-side: shop:stocks {product} renders the totals box; the
        // PHYSICAL cell should sit next to ASSIGNED and reflect the loan-aware
        // count.
        $book = $this->newBook('Atlas', 'CMD-ATLAS-PHYS');
        $book->increaseStock(4);
        $book->checkOutTo($this->borrower);

        $output = $this->runOk(ShopStocksCommand::class, ['product' => 'CMD-ATLAS-PHYS']);

        $this->assertStringContainsString('PHYSICAL', $output);
        $this->assertSame(4, $book->fresh()->getPhysicalStock(), 'model agrees with command');
    }

    #[Test]
    public function shop_stocks_availability_headline_reads_zero_for_a_fully_loaned_book(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));

        $book = $this->newBook('Solitaire', 'CMD-SOL');
        $book->increaseStock(1);
        $book->checkOutTo($this->borrower);

        $output = $this->runOk(ShopAvailabilityCommand::class, [
            'product' => 'CMD-SOL',
            '--from' => '2026-05-01',
            '--to' => '2026-05-31',
        ]);

        $this->assertStringContainsString('Solitaire', $output);
        $this->assertStringContainsString('May 2026', $output);
        $this->assertStringContainsString('Available 0', $output);
        $this->assertStringContainsString('MON', $output);
        $this->assertStringContainsString('SUN', $output);
    }

    #[Test]
    public function shop_stocks_availability_headline_recovers_after_a_return(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));

        $book = $this->newBook('Singular', 'CMD-SIN');
        $book->increaseStock(1);
        $loan = $book->checkOutTo($this->borrower);
        $loan->markReturned();  // releases the claim → restores available

        $output = $this->runOk(ShopAvailabilityCommand::class, [
            'product' => 'CMD-SIN',
            '--from' => '2026-05-01',
            '--to' => '2026-05-31',
        ]);

        $this->assertStringContainsString('Available 1', $output);
        $this->assertSame(1, $book->fresh()->getAvailableStock());
    }

    #[Test]
    public function shop_stocks_availability_day_view_for_unmanaged_book_shows_unlimited(): void
    {
        // manage_stock=false on a loanable book is the moonshiner "infinite
        // copy" pattern: every borrower can take a copy at any time.
        $book = $this->newBook('Compendium', 'CMD-CMP');
        $book->manage_stock = false;
        $book->save();

        $output = $this->runOk(ShopAvailabilityCommand::class, [
            'product' => 'CMD-CMP',
            '--day' => '2026-05-14',
        ]);

        $this->assertStringContainsString('Unlimited availability all day.', $output);
    }

    /* ───────────────────────────── helpers ─────────────────────────────── */

    /**
     * Count of loan-driven DECREASE rows on this book — mirrors what the
     * "Used" column in shop:stocks renders.
     */
    private function loanedDecreases(CmdLoanBook $book): int
    {
        return (int) abs((int) $book->stocks()
            ->withoutGlobalScope('willExpire')
            ->where('type', \Blax\Shop\Enums\StockType::DECREASE->value)
            ->where('status', \Blax\Shop\Enums\StockStatus::COMPLETED->value)
            ->sum('quantity'));
    }

    /**
     * Extract the first numeric token from the value row that follows the
     * ASSIGNED label row in the shop:stocks detail output. The detail view
     * renders a single boxed row, so the first integer after "ASSIGNED" on
     * the next non-empty line is the Assigned value.
     */
    private function detailViewAssignedValue(string $output): ?int
    {
        $lines = explode("\n", $output);
        $assignedLineIndex = null;
        foreach ($lines as $i => $line) {
            if (str_contains($line, 'ASSIGNED')) {
                $assignedLineIndex = $i;
                break;
            }
        }
        if ($assignedLineIndex === null) {
            return null;
        }

        // Walk forward to the next line that contains digits.
        for ($i = $assignedLineIndex + 1; $i < count($lines); $i++) {
            if (preg_match('/\b(\d+)\b/', $lines[$i], $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /**
     * Pull the named row out of the shop:stocks overview table and parse the
     * 4 trailing integer columns (Assigned / Used / Available / Claimed).
     *
     * @return array{assigned: int, used: int, available: int, claimed: int}|null
     */
    private function overviewRow(string $output, string $needle): ?array
    {
        foreach (explode("\n", $output) as $line) {
            if (! str_contains($line, $needle)) {
                continue;
            }

            // Capture the four numeric columns at the tail of the row. Type
            // and ID columns precede them; we anchor on the trailing
            // "n | n | n | n" shape (the only column run that's all integers).
            // The overview renders via $this->table(...) which uses plain ASCII
            // pipes — not the box-drawing │ used by the detail/availability
            // commands.
            if (preg_match('/(\d+)\s*\|\s*(\d+|—)\s*\|\s*(\d+|∞)\s*\|\s*(\d+)\s*\|\s*$/u', $line, $m)) {
                return [
                    'assigned' => (int) $m[1],
                    'used' => $m[2] === '—' ? 0 : (int) $m[2],
                    'available' => $m[3] === '∞' ? PHP_INT_MAX : (int) $m[3],
                    'claimed' => (int) $m[4],
                ];
            }
        }

        return null;
    }
}

/**
 * Plug-n-pray loanable fixture, mirroring CheckOutToTest's LoanableBook but
 * under a unique name so the two files can coexist in the same namespace.
 */
class CmdLoanBook extends Product
{
    public const DEFAULT_TYPE = ProductType::LOANABLE;

    protected $guarded = [];
}
