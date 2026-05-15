<?php

namespace Blax\Shop\Tests\Feature\Loan;

use Blax\Shop\Enums\BillingScheme;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Http\Resources\PurchaseResource;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Models\ProductPriceTier;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Tiered loan pricing is a property of the ProductPrice, not of the host
 * app's config. Each ProductPrice with billing_scheme=tiered owns a ladder
 * of ProductPriceTier rows; ProductPrice::calculateForUsage($days) walks
 * the ladder and returns total cents owed. ProductPurchase::calculateCost()
 * delegates through `price_id` (or the purchasable's defaultPrice()).
 *
 * Covers:
 *   - per_unit price → flat per-day billing
 *   - tiered price → Stripe-style `up_to` walk with multi-tier spans
 *   - the user-facing library scenario (free 2 weeks → €1/day → €2/day @ 2 months)
 *   - returned-loan cap (cost frozen at meta.returned_at)
 *   - per-call price override
 *   - fractional days
 *   - PurchaseResource surfacing accrued_cost
 *   - no-price purchase → zero cost (free loan)
 */
class LoanPricingTest extends TestCase
{
    use RefreshDatabase;

    private User $borrower;
    private Product $book;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));

        $this->borrower = User::factory()->create();
        $this->book = Product::factory()->create([
            'name' => 'Hyperion',
            'type' => ProductType::LOANABLE,
            'manage_stock' => true,
        ]);
        $this->book->increaseStock(1);
    }

    /**
     * Build a tiered ProductPrice with the given ladder.
     *
     * @param  array<int, array{up_to: ?int, unit_amount: int}>  $tiers
     */
    private function tieredPrice(array $tiers, bool $default = true): ProductPrice
    {
        $price = ProductPrice::factory()->create([
            'purchasable_id' => $this->book->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 0,
            'billing_scheme' => BillingScheme::TIERED,
            'is_default' => $default,
        ]);

        foreach ($tiers as $i => $tier) {
            ProductPriceTier::factory()->create([
                'price_id' => $price->id,
                'up_to' => $tier['up_to'] ?? null,
                'unit_amount' => $tier['unit_amount'],
                'sort_order' => $i,
            ]);
        }

        return $price->load('tiers');
    }

    private function loan(
        Carbon $from,
        ?Carbon $until = null,
        ?ProductPrice $price = null
    ): ProductPurchase {
        return $this->book->purchases()->create([
            'purchaser_id' => $this->borrower->id,
            'purchaser_type' => User::class,
            'price_id' => $price?->id,
            'quantity' => 1,
            'amount' => 0,
            'amount_paid' => 0,
            'status' => PurchaseStatus::PENDING,
            'from' => $from,
            'until' => $until ?? $from->copy()->addWeeks(2),
            'meta' => ['extensions_used' => 0],
        ]);
    }

    #[Test]
    public function a_loan_with_no_associated_price_costs_nothing(): void
    {
        $loan = $this->loan(Carbon::parse('2026-01-01 10:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00'));

        $this->assertSame(0, $loan->accruedCost());
    }

    #[Test]
    public function per_unit_billing_scheme_is_flat_per_day(): void
    {
        // billing_scheme=per_unit → unit_amount × days.
        $price = ProductPrice::factory()->create([
            'purchasable_id' => $this->book->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 50,
            'billing_scheme' => BillingScheme::PER_UNIT,
            'is_default' => true,
        ]);

        $loan = $this->loan(Carbon::parse('2026-05-01 10:00:00'), price: $price);
        Carbon::setTestNow(Carbon::parse('2026-05-11 10:00:00'));

        $this->assertSame(500, $loan->accruedCost(), '10 days × 50c');
    }

    #[Test]
    public function tiered_billing_walks_the_ladder_with_up_to_boundaries(): void
    {
        $price = $this->tieredPrice([
            ['up_to' => 14, 'unit_amount' => 0],
            ['up_to' => 60, 'unit_amount' => 100],
            ['up_to' => null, 'unit_amount' => 200],
        ]);

        $loan = $this->loan(Carbon::parse('2026-01-01 10:00:00'), price: $price);

        // Day 10: free
        Carbon::setTestNow(Carbon::parse('2026-01-11 10:00:00'));
        $this->assertSame(0, $loan->accruedCost(), 'day 10 free');

        // Day 20: 14 free + 6 days at 100c
        Carbon::setTestNow(Carbon::parse('2026-01-21 10:00:00'));
        $this->assertSame(600, $loan->accruedCost(), 'day 20 = 6×100');

        // Day 75: 14 free + 46×100 + 15×200
        Carbon::setTestNow(Carbon::parse('2026-03-17 10:00:00'));
        $this->assertSame(7600, $loan->accruedCost(), 'day 75 = 4600 + 3000');
    }

    #[Test]
    public function the_user_specified_library_scenario(): void
    {
        // Library configuration: free for 14 days, then €1/day, then €2/day
        // after two months. Defined on the price model, not in config.
        $price = $this->tieredPrice([
            ['up_to' => 14, 'unit_amount' => 0],
            ['up_to' => 60, 'unit_amount' => 100],
            ['up_to' => null, 'unit_amount' => 200],
        ]);

        $loan = $this->loan(Carbon::parse('2026-01-01 10:00:00'), price: $price);

        $scenarios = [
            [0, 0, 'same-day return'],
            [7, 0, '1 week (grace)'],
            [14, 0, 'exactly 2 weeks (last free day)'],
            [15, 100, 'day 15 → 1 day at €1'],
            [30, 1600, 'day 30 → 16 days at €1'],
            [60, 4600, '2 months → 46 days at €1'],
            [61, 4800, 'day 61 → +1 day at €2'],
            [90, 10600, 'day 90 → 46×€1 + 30×€2'],
        ];

        foreach ($scenarios as [$days, $expected, $label]) {
            Carbon::setTestNow(Carbon::parse('2026-01-01 10:00:00')->addDays($days));
            $this->assertSame($expected, $loan->accruedCost(), "after {$days} days: {$label}");
        }
    }

    #[Test]
    public function calculate_cost_caps_at_return_time(): void
    {
        $price = $this->tieredPrice([
            ['up_to' => 14, 'unit_amount' => 0],
            ['up_to' => null, 'unit_amount' => 100],
        ]);

        $loan = $this->loan(Carbon::parse('2026-01-01 10:00:00'), price: $price);

        Carbon::setTestNow(Carbon::parse('2026-01-21 10:00:00')); // day 20 → 600c
        $loan->markReturned();
        $loan->refresh();

        // Time marches on; cost should remain frozen.
        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00:00'));
        $this->assertSame(600, $loan->accruedCost());
    }

    #[Test]
    public function calculate_cost_accepts_an_explicit_as_of_argument(): void
    {
        $price = $this->tieredPrice([
            ['up_to' => null, 'unit_amount' => 100],
        ]);
        $loan = $this->loan(Carbon::parse('2026-01-01 10:00:00'), price: $price);

        $this->assertSame(500, $loan->calculateCost(Carbon::parse('2026-01-06 10:00:00')));
        $this->assertSame(1500, $loan->calculateCost(Carbon::parse('2026-01-16 10:00:00')));
    }

    #[Test]
    public function calculate_cost_accepts_a_per_call_price_override(): void
    {
        // Loan has no price by default → 0.
        $loan = $this->loan(Carbon::parse('2026-01-01 10:00:00'));
        Carbon::setTestNow(Carbon::parse('2026-01-11 10:00:00'));
        $this->assertSame(0, $loan->accruedCost());

        // Per-call override with a tiered price.
        $override = $this->tieredPrice([
            ['up_to' => null, 'unit_amount' => 50],
        ], default: false);
        $this->assertSame(500, $loan->calculateCost(null, $override));
    }

    #[Test]
    public function fractional_days_are_billed_proportionally(): void
    {
        $price = $this->tieredPrice([
            ['up_to' => null, 'unit_amount' => 200],
        ]);
        $loan = $this->loan(Carbon::parse('2026-01-01 10:00:00'), price: $price);

        Carbon::setTestNow(Carbon::parse('2026-01-01 22:00:00')); // 0.5 days
        $this->assertSame(100, $loan->accruedCost());
    }

    #[Test]
    public function purchase_resource_surfaces_accrued_cost(): void
    {
        $price = $this->tieredPrice([
            ['up_to' => 14, 'unit_amount' => 0],
            ['up_to' => null, 'unit_amount' => 100],
        ]);
        $loan = $this->loan(Carbon::parse('2026-01-01 10:00:00'), price: $price);
        Carbon::setTestNow(Carbon::parse('2026-01-21 10:00:00'));

        $payload = PurchaseResource::make($loan)->toArray(Request::create('/'));

        $this->assertSame(600, $payload['accrued_cost']);
    }

    #[Test]
    public function purchase_resource_returns_null_accrued_cost_for_non_loan_purchases(): void
    {
        $purchase = $this->book->purchases()->create([
            'purchaser_id' => $this->borrower->id,
            'purchaser_type' => User::class,
            'quantity' => 1,
            'amount' => 5000,
            'amount_paid' => 5000,
            'status' => PurchaseStatus::COMPLETED,
            // no from/until — plain e-commerce purchase
        ]);

        $payload = PurchaseResource::make($purchase)->toArray(Request::create('/'));
        $this->assertNull($payload['accrued_cost']);
    }

    #[Test]
    public function product_price_calculate_for_usage_handles_zero_and_negative_usage(): void
    {
        $price = $this->tieredPrice([
            ['up_to' => null, 'unit_amount' => 100],
        ]);

        $this->assertSame(0, $price->calculateForUsage(0));
        $this->assertSame(0, $price->calculateForUsage(-3));
    }

    #[Test]
    public function product_price_flat_amount_is_added_per_entered_tier(): void
    {
        $price = ProductPrice::factory()->create([
            'purchasable_id' => $this->book->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 0,
            'billing_scheme' => BillingScheme::TIERED,
            'is_default' => true,
        ]);
        // Tier 1: 0-14 free with €5 flat-on-entry setup fee.
        // Tier 2: 14+ at €1/day with no flat fee.
        ProductPriceTier::factory()->create([
            'price_id' => $price->id,
            'up_to' => 14,
            'unit_amount' => 0,
            'flat_amount' => 500,
            'sort_order' => 0,
        ]);
        ProductPriceTier::factory()->create([
            'price_id' => $price->id,
            'up_to' => null,
            'unit_amount' => 100,
            'sort_order' => 1,
        ]);
        $price->load('tiers');

        // Within tier 1: only the flat 500c applies.
        $this->assertSame(500, $price->calculateForUsage(5));
        // Crosses both tiers: 500 (flat) + 0×14 (free days) + 100×6 (paid days)
        $this->assertSame(1100, $price->calculateForUsage(20));
    }

    /* ───────────────────── edge cases ───────────────────── */

    #[Test]
    public function tiered_price_with_no_tiers_falls_back_to_unit_amount(): void
    {
        // A ProductPrice with billing_scheme=tiered but an empty tier set
        // should NOT throw — it should treat unit_amount as a flat per-unit
        // rate, matching per_unit behaviour.
        $price = ProductPrice::factory()->create([
            'purchasable_id' => $this->book->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 75,
            'billing_scheme' => BillingScheme::TIERED,
            'is_default' => true,
        ]);

        $this->assertSame(0, $price->tiers()->count());
        $this->assertSame(750, $price->calculateForUsage(10));
    }

    #[Test]
    public function purchase_price_relation_returns_the_attached_price(): void
    {
        $price = $this->tieredPrice([
            ['up_to' => null, 'unit_amount' => 100],
        ]);
        $loan = $this->loan(Carbon::parse('2026-01-01 10:00:00'), price: $price);

        $this->assertInstanceOf(ProductPrice::class, $loan->price);
        $this->assertSame($price->id, $loan->price->id);
    }

    #[Test]
    public function calculate_cost_falls_back_to_purchasable_default_price_when_purchase_has_no_price_id(): void
    {
        // The purchase has no price_id set, but the Book has a default
        // price — calculateCost should resolve through purchasable->defaultPrice().
        $defaultPrice = $this->tieredPrice([
            ['up_to' => null, 'unit_amount' => 50],
        ], default: true);

        $loan = $this->loan(Carbon::parse('2026-01-01 10:00:00'));  // no price_id
        Carbon::setTestNow(Carbon::parse('2026-01-11 10:00:00'));   // 10 days

        $this->assertNull($loan->price_id);
        $this->assertSame(500, $loan->accruedCost(), 'fallback to defaultPrice → 10 × 50c');
    }

    #[Test]
    public function explicit_price_id_takes_precedence_over_default_price(): void
    {
        // Default price says €1/day, explicit price says €5/day.
        $this->tieredPrice([
            ['up_to' => null, 'unit_amount' => 100],
        ], default: true);

        $premiumPrice = $this->tieredPrice([
            ['up_to' => null, 'unit_amount' => 500],
        ], default: false);

        $loan = $this->loan(Carbon::parse('2026-01-01 10:00:00'), price: $premiumPrice);
        Carbon::setTestNow(Carbon::parse('2026-01-11 10:00:00'));   // 10 days

        $this->assertSame(5000, $loan->accruedCost(), 'uses premiumPrice not default');
    }
}
