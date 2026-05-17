<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

class NextAvailableAtTest extends TestCase
{
    use RefreshDatabase;

    private function loanable(int $copies = 1): Product
    {
        $product = LoanableNextAvailableBook::create([
            'name' => 'Test Book',
            'sku' => 'NEXT-'.uniqid(),
        ]);
        $product->increaseStock($copies);

        return $product;
    }

    #[Test]
    public function returns_null_when_stock_is_currently_available(): void
    {
        $product = $this->loanable(copies: 2);
        $this->assertNull($product->nextAvailableAt());
    }

    #[Test]
    public function returns_the_earliest_loan_until_when_all_copies_are_loaned_out(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));
        $product = $this->loanable(copies: 2);

        $borrowerOne = User::factory()->create();
        $borrowerTwo = User::factory()->create();

        // Two loans, due at different times. Earliest is 2026-05-28.
        $product->purchases()->create([
            'purchaser_id' => $borrowerOne->getKey(),
            'purchaser_type' => User::class,
            'quantity' => 1,
            'amount' => 0,
            'amount_paid' => 0,
            'status' => PurchaseStatus::PENDING,
            'from' => Carbon::parse('2026-05-14 10:00:00'),
            'until' => Carbon::parse('2026-05-28 10:00:00'),
            'meta' => ['extensions_used' => 0],
        ]);
        $product->purchases()->create([
            'purchaser_id' => $borrowerTwo->getKey(),
            'purchaser_type' => User::class,
            'quantity' => 1,
            'amount' => 0,
            'amount_paid' => 0,
            'status' => PurchaseStatus::PENDING,
            'from' => Carbon::parse('2026-05-14 10:00:00'),
            'until' => Carbon::parse('2026-06-01 10:00:00'),
            'meta' => ['extensions_used' => 0],
        ]);
        $product->decreaseStock(2); // simulate the loans taking stock

        $next = $product->nextAvailableAt();

        $this->assertNotNull($next);
        $this->assertSame(
            Carbon::parse('2026-05-28 10:00:00')->toIso8601String(),
            $next->toIso8601String(),
        );
    }

    #[Test]
    public function returns_the_earliest_pending_claim_expiry_when_stock_is_fully_claimed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));
        $product = $this->loanable(copies: 1);

        // One claim that ends earlier than the loan-style flow would
        $product->claimStock(
            1,
            null,
            Carbon::parse('2026-05-14 10:00:00'),
            Carbon::parse('2026-05-20 10:00:00'),
            'pending claim'
        );

        $next = $product->nextAvailableAt();

        $this->assertNotNull($next);
        $this->assertSame(
            Carbon::parse('2026-05-20 10:00:00')->toIso8601String(),
            $next->toIso8601String(),
        );
    }

    #[Test]
    public function returns_the_minimum_of_loan_end_and_claim_end_when_both_present(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));
        $product = $this->loanable(copies: 2);

        // Loan ends 2026-06-01 (later)
        $product->purchases()->create([
            'purchaser_id' => User::factory()->create()->getKey(),
            'purchaser_type' => User::class,
            'quantity' => 1,
            'amount' => 0,
            'amount_paid' => 0,
            'status' => PurchaseStatus::PENDING,
            'from' => Carbon::parse('2026-05-14 10:00:00'),
            'until' => Carbon::parse('2026-06-01 10:00:00'),
            'meta' => ['extensions_used' => 0],
        ]);
        $product->decreaseStock(1);

        // Claim ends 2026-05-18 (earlier — this should win)
        $product->claimStock(
            1,
            null,
            Carbon::parse('2026-05-14 10:00:00'),
            Carbon::parse('2026-05-18 10:00:00'),
            'short claim'
        );

        $next = $product->nextAvailableAt();

        $this->assertNotNull($next);
        $this->assertSame(
            Carbon::parse('2026-05-18 10:00:00')->toIso8601String(),
            $next->toIso8601String(),
        );
    }

    #[Test]
    public function ignores_already_expired_claims(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));
        $product = $this->loanable(copies: 1);

        // Claim is already past expiry — should not be considered.
        $product->claimStock(
            1,
            null,
            Carbon::parse('2026-05-01 10:00:00'),
            Carbon::parse('2026-05-10 10:00:00'),
            'expired claim'
        );

        $next = $product->nextAvailableAt();

        // Stock was freed by the expired claim's RETURN row? No — claim isn't
        // released yet (releaseExpired() wasn't run). But the expires_at is
        // already in the past, so nextAvailableAt should return null
        // (nothing actively reserving stock counts as a freeing-up signal).
        $this->assertNull($next);
    }
}

class LoanableNextAvailableBook extends Product
{
    public const DEFAULT_TYPE = ProductType::LOANABLE;
    protected $guarded = [];
}
