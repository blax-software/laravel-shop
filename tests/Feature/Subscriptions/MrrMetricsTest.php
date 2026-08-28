<?php

namespace Blax\Shop\Tests\Feature\Subscriptions;

use Blax\Shop\Enums\PriceType;
use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\RecurringInterval;
use Blax\Shop\Facades\Shop;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Models\Subscription;
use Blax\Shop\Models\SubscriptionItem;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Covers Shop::mrr()/activeSubscribers()/mrrByProduct()/subscriptionMetrics().
 *
 * Regression anchors for the two ways an app's hand-rolled MRR goes wrong:
 * excluding still-billing subscribers (past_due, cancel-at-period-end), and
 * silently dropping line items whose price is not mirrored locally.
 */
class MrrMetricsTest extends TestCase
{
    use RefreshDatabase;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->userId = (string) User::factory()->create()->getKey();
    }

    private function product(string $name): Product
    {
        return Product::create([
            'name' => $name,
            'sku' => 'SKU-'.uniqid(),
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => false,
        ]);
    }

    private function price(Product $product, string $stripePrice, int $unitAmount, RecurringInterval|string|null $interval, int $intervalCount = 1, int $costAmount = 0): ProductPrice
    {
        return ProductPrice::create([
            'purchasable_type' => Product::class,
            'purchasable_id' => $product->id,
            'stripe_price_id' => $stripePrice,
            'type' => $interval === null ? PriceType::ONE_TIME : PriceType::RECURRING,
            'currency' => 'EUR',
            'unit_amount' => $unitAmount,
            'cost_amount' => $costAmount,
            'is_default' => true,
            'active' => true,
            'interval' => $interval,
            'interval_count' => $intervalCount,
        ]);
    }

    private function subscription(Product $product, string $status, string $stripePrice, ?\DateTimeInterface $endsAt = null): Subscription
    {
        $sub = Subscription::create([
            'user_id' => $this->userId,
            'product_id' => $product->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.uniqid(),
            'stripe_status' => $status,
            'stripe_price' => $stripePrice,
            'quantity' => 1,
            'ends_at' => $endsAt,
        ]);

        SubscriptionItem::create([
            'subscription_id' => $sub->id,
            'stripe_id' => 'si_'.uniqid(),
            'stripe_product' => 'prod_x',
            'stripe_price' => $stripePrice,
            'quantity' => 1,
        ]);

        return $sub;
    }

    #[Test]
    public function it_counts_live_subscribers_including_past_due_and_cancel_at_period_end(): void
    {
        $seat = $this->product('Full Seat');
        $this->price($seat, 'price_seat', 2550, RecurringInterval::MONTH);

        $this->subscription($seat, 'active', 'price_seat');
        $this->subscription($seat, 'past_due', 'price_seat');                    // still billing -> counts
        $this->subscription($seat, 'active', 'price_seat', now()->addMonth());   // cancel-at-period-end -> counts
        $this->subscription($seat, 'trialing', 'price_seat');                    // counts
        $this->subscription($seat, 'canceled', 'price_seat');                    // gone -> excluded

        $this->assertSame(4, Shop::activeSubscribers());
        $this->assertSame(4 * 2550, Shop::mrr());
    }

    #[Test]
    public function it_normalizes_every_interval_to_a_monthly_run_rate(): void
    {
        $p = $this->product('Simulator');
        $this->price($p, 'price_month', 1410, RecurringInterval::MONTH);
        $this->price($p, 'price_year', 13500, RecurringInterval::YEAR);        // ÷12
        $this->price($p, 'price_week', 610, RecurringInterval::WEEK);          // ×30.436875/7
        $this->price($p, 'price_quarter', 4900, RecurringInterval::MONTH, 3);  // ÷3

        $this->subscription($p, 'active', 'price_month');
        $this->subscription($p, 'active', 'price_year');
        $this->subscription($p, 'active', 'price_week');
        $this->subscription($p, 'active', 'price_quarter');

        $expected = 1410
            + (int) round(13500 / 12)
            + (int) round(610 * 30.436875 / 7)
            + (int) round(4900 / 3);

        $this->assertSame($expected, Shop::mrr());
    }

    #[Test]
    public function it_surfaces_line_items_whose_price_is_not_mirrored_rather_than_guessing(): void
    {
        $p = $this->product('Full Seat');
        $this->price($p, 'price_seat', 2550, RecurringInterval::MONTH);

        $this->subscription($p, 'active', 'price_seat');
        $this->subscription($p, 'active', 'price_missing'); // no ProductPrice mirrored

        $metrics = Shop::subscriptionMetrics();

        $this->assertSame(2550, $metrics['mrr']);         // unmirrored item excluded, never guessed
        $this->assertSame(1, $metrics['unpriced_items']); // but surfaced, not silent
        $this->assertSame(2, $metrics['subscribers']);
    }

    #[Test]
    public function it_computes_net_mrr_after_cost_of_goods(): void
    {
        $seat = $this->product('Full Seat');
        // €25.50/mo, with a €15.00/mo license cost basis (COGS).
        $this->price($seat, 'price_seat', 2550, RecurringInterval::MONTH, 1, 1500);
        // €135/yr, with a €12/yr cost (→ €1/mo after ÷12).
        $this->price($seat, 'price_year', 13500, RecurringInterval::YEAR, 1, 1200);

        $this->subscription($seat, 'active', 'price_seat');
        $this->subscription($seat, 'active', 'price_seat');
        $this->subscription($seat, 'active', 'price_year');

        $m = Shop::subscriptionMetrics();

        $expectedMrr = 2 * 2550 + (int) round(13500 / 12);
        $expectedCogs = 2 * 1500 + (int) round(1200 / 12);
        $this->assertSame($expectedMrr, $m['mrr']);
        $this->assertSame($expectedCogs, $m['cogs']);
        $this->assertSame($expectedMrr - $expectedCogs, $m['net_mrr']);
        $this->assertSame($expectedMrr - $expectedCogs, Shop::netMrr());
    }

    #[Test]
    public function it_splits_mrr_by_product(): void
    {
        $seat = $this->product('Full Seat');
        $sim = $this->product('Simulator');
        $this->price($seat, 'price_seat', 2550, RecurringInterval::MONTH);
        $this->price($sim, 'price_sim', 890, RecurringInterval::MONTH);

        $this->subscription($seat, 'active', 'price_seat');
        $this->subscription($seat, 'active', 'price_seat');
        $this->subscription($sim, 'active', 'price_sim');

        $byProduct = Shop::mrrByProduct();

        $this->assertSame(Shop::mrr(), $byProduct->sum());
        $this->assertCount(2, $byProduct);
        // arsort() puts the biggest product first; values are label-independent.
        $this->assertSame([5100, 890], array_values($byProduct->all()));
    }
}
