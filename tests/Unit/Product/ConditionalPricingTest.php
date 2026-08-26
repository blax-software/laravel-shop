<?php

namespace Blax\Shop\Tests\Unit\Product;

use Blax\Shop\Contracts\EntitlementChecker;
use Blax\Shop\Models\Product;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Test stub: satisfies `['role' => 'seat']` only for a buyer marked
 * 'seat-holder'. Stands in for a host app's laravel-roles-backed checker.
 */
class SeatCheckerStub implements EntitlementChecker
{
    public function satisfies(mixed $buyer, array $requirement): bool
    {
        return $buyer === 'seat-holder' && ($requirement['role'] ?? null) === 'seat';
    }
}

class ConditionalPricingTest extends TestCase
{
    use RefreshDatabase;

    private function productWithPrices(array $prices): Product
    {
        $product = Product::factory()->create();

        foreach ($prices as $attrs) {
            $product->prices()->create(array_merge([
                'type' => 'one_time',
                'billing_scheme' => 'per_unit',
                'currency' => 'EUR',
                'is_default' => false,
                'active' => true,
            ], $attrs));
        }

        return $product->refresh();
    }

    #[Test]
    public function requires_accessor_normalizes_meta_object(): void
    {
        $product = $this->productWithPrices([
            ['unit_amount' => 800, 'meta' => ['requires' => ['role' => 'seat', 'label' => '8€ with Full Seat']]],
        ]);

        $price = $product->prices()->first();

        $this->assertTrue($price->isConditional());
        $this->assertSame(['role' => 'seat', 'label' => '8€ with Full Seat'], $price->requires());
        $this->assertSame('8€ with Full Seat', $price->requiresLabel());
    }

    #[Test]
    public function standalone_price_is_not_conditional(): void
    {
        $product = $this->productWithPrices([['unit_amount' => 4500]]);
        $price = $product->prices()->first();

        $this->assertFalse($price->isConditional());
        $this->assertNull($price->requires());
        $this->assertNull($price->requiresLabel());
    }

    #[Test]
    public function requires_satisfied_by_matches_owned_signals(): void
    {
        $product = $this->productWithPrices([
            ['unit_amount' => 800, 'meta' => ['requires' => ['role' => 'seat']]],
            ['unit_amount' => 4500],
        ]);

        [$conditional, $standalone] = [
            $product->prices()->conditional()->first(),
            $product->prices()->standalone()->first(),
        ];

        $this->assertTrue($conditional->requiresSatisfiedBy(['role:seat']));
        $this->assertTrue($conditional->requiresSatisfiedBy(['product:x', 'role:seat']));
        $this->assertFalse($conditional->requiresSatisfiedBy(['role:other']));
        $this->assertFalse($conditional->requiresSatisfiedBy([]));
        // A non-conditional price is always satisfied.
        $this->assertTrue($standalone->requiresSatisfiedBy([]));
    }

    #[Test]
    public function resolve_price_picks_cheaper_conditional_when_buyer_qualifies(): void
    {
        config()->set('shop.entitlement_checker', SeatCheckerStub::class);

        $product = $this->productWithPrices([
            ['unit_amount' => 4500],
            ['unit_amount' => 800, 'meta' => ['requires' => ['role' => 'seat']]],
        ]);

        $this->assertSame(800.0, $product->resolvePriceFor('seat-holder')->unit_amount);
    }

    #[Test]
    public function resolve_price_falls_back_to_standalone_without_entitlement(): void
    {
        config()->set('shop.entitlement_checker', SeatCheckerStub::class);

        $product = $this->productWithPrices([
            ['unit_amount' => 4500],
            ['unit_amount' => 800, 'meta' => ['requires' => ['role' => 'seat']]],
        ]);

        $this->assertSame(4500.0, $product->resolvePriceFor('rando')->unit_amount);
        $this->assertSame(4500.0, $product->resolvePriceFor(null)->unit_amount);
    }

    #[Test]
    public function default_deny_checker_never_offers_conditional_price(): void
    {
        // No entitlement_checker configured -> DenyAllEntitlementChecker.
        $product = $this->productWithPrices([
            ['unit_amount' => 4500],
            ['unit_amount' => 800, 'meta' => ['requires' => ['role' => 'seat']]],
        ]);

        $this->assertSame(4500.0, $product->resolvePriceFor('seat-holder')->unit_amount);
    }

    #[Test]
    public function conditional_only_product_has_no_price_for_unqualified_buyer(): void
    {
        config()->set('shop.entitlement_checker', SeatCheckerStub::class);

        $product = $this->productWithPrices([
            ['unit_amount' => 800, 'meta' => ['requires' => ['role' => 'seat']]],
        ]);

        $this->assertSame(800.0, $product->resolvePriceFor('seat-holder')->unit_amount);
        $this->assertNull($product->resolvePriceFor('rando'));
    }

    #[Test]
    public function inactive_conditional_price_is_ignored(): void
    {
        config()->set('shop.entitlement_checker', SeatCheckerStub::class);

        $product = $this->productWithPrices([
            ['unit_amount' => 4500],
            ['unit_amount' => 800, 'active' => false, 'meta' => ['requires' => ['role' => 'seat']]],
        ]);

        $this->assertSame(4500.0, $product->resolvePriceFor('seat-holder')->unit_amount);
    }

    #[Test]
    public function percent_off_accessors(): void
    {
        $product = $this->productWithPrices([
            ['unit_amount' => 0, 'meta' => ['requires' => ['role' => 'seat'], 'percent_off' => 82]],
        ]);
        $price = $product->prices()->first();

        $this->assertSame(82.0, $price->percentOff());
        $this->assertTrue($price->isPercentage());
        $this->assertSame(900.0, $price->effectiveAmount(5000)); // 82% off 5000
        $this->assertNull($price->effectiveAmount(null));        // no base -> not offerable
    }

    #[Test]
    public function fixed_price_effective_amount_is_its_unit_amount(): void
    {
        $product = $this->productWithPrices([['unit_amount' => 800]]);

        $this->assertFalse($product->prices()->first()->isPercentage());
        $this->assertSame(800.0, $product->prices()->first()->effectiveAmount(5000));
    }

    #[Test]
    public function resolve_price_uses_percentage_against_cheapest_standalone(): void
    {
        config()->set('shop.entitlement_checker', SeatCheckerStub::class);

        $product = $this->productWithPrices([
            ['unit_amount' => 5000],
            ['unit_amount' => 0, 'meta' => ['requires' => ['role' => 'seat'], 'percent_off' => 90]],
        ]);

        $resolved = $product->resolvePriceFor('seat-holder');
        $this->assertTrue($resolved->isPercentage());
        $this->assertSame(500.0, $resolved->effectiveAmount(5000)); // 90% off 5000

        // Without the entitlement -> the standalone 5000.
        $this->assertSame(5000.0, $product->resolvePriceFor('rando')->unit_amount);
    }

    #[Test]
    public function resolve_price_picks_cheapest_across_fixed_and_percentage(): void
    {
        config()->set('shop.entitlement_checker', SeatCheckerStub::class);

        $product = $this->productWithPrices([
            ['unit_amount' => 5000],
            ['unit_amount' => 800, 'meta' => ['requires' => ['role' => 'seat']]],                       // fixed 800
            ['unit_amount' => 0, 'meta' => ['requires' => ['role' => 'seat'], 'percent_off' => 90]],     // 500
        ]);

        // 500 (90% off) beats the fixed 800 and the 5000 standalone.
        $this->assertTrue($product->resolvePriceFor('seat-holder')->isPercentage());
    }

    #[Test]
    public function percentage_price_ignored_when_product_has_no_standalone(): void
    {
        config()->set('shop.entitlement_checker', SeatCheckerStub::class);

        $product = $this->productWithPrices([
            ['unit_amount' => 0, 'meta' => ['requires' => ['role' => 'seat'], 'percent_off' => 90]],
        ]);

        // Nothing to discount against -> not offerable.
        $this->assertNull($product->resolvePriceFor('seat-holder'));
    }

    #[Test]
    public function set_conditional_pricing_authors_and_clears_meta(): void
    {
        $product = $this->productWithPrices([['unit_amount' => 0]]);
        $price = $product->prices()->first();

        $price->setConditionalPricing(['product' => 'full-seat'], 25.0, '25% with Full Seat')->save();
        $price->refresh();

        $this->assertSame(['product' => 'full-seat', 'label' => '25% with Full Seat'], $price->requires());
        $this->assertSame(25.0, $price->percentOff());
        $this->assertSame('25% with Full Seat', $price->requiresLabel());

        // Passing null for both clears the conditional pricing entirely.
        $price->setConditionalPricing(null, null)->save();
        $price->refresh();

        $this->assertNull($price->requires());
        $this->assertFalse($price->isPercentage());
    }
}
