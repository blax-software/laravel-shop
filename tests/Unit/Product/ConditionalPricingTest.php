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
}
