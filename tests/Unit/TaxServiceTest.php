<?php

declare(strict_types=1);

namespace Blax\Shop\Tests\Unit;

use Blax\Shop\Exceptions\TaxRateNotConfiguredException;
use Blax\Shop\Models\Product;
use Blax\Shop\Services\TaxService;
use Blax\Shop\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TaxServiceTest extends TestCase
{
    #[Test]
    public function it_returns_the_configured_rates_for_a_non_exempt_customer(): void
    {
        config(['shop.tax.rates' => ['txr_19']]);

        $this->assertSame(['txr_19'], TaxService::rates(false));
    }

    #[Test]
    public function an_explicit_rate_list_overrides_config(): void
    {
        config(['shop.tax.rates' => ['txr_from_config']]);

        $this->assertSame(['txr_explicit'], TaxService::rates(false, ['txr_explicit']));
    }

    #[Test]
    public function an_exempt_customer_gets_no_rate_even_when_one_is_configured(): void
    {
        config(['shop.tax.rates' => ['txr_19'], 'shop.tax.require' => true]);

        // Reverse charge / zero-rated short-circuits before the require guard.
        $this->assertSame([], TaxService::rates(true));
        $this->assertSame([], TaxService::rates(true, ['txr_19']));
    }

    #[Test]
    public function empty_rates_return_empty_when_not_required(): void
    {
        config(['shop.tax.rates' => [], 'shop.tax.require' => false]);

        $this->assertSame([], TaxService::rates(false));
    }

    #[Test]
    public function empty_rates_throw_when_required(): void
    {
        config(['shop.tax.rates' => [], 'shop.tax.require' => true]);

        $this->expectException(TaxRateNotConfiguredException::class);

        TaxService::rates(false);
    }

    #[Test]
    public function it_filters_blanks_and_non_strings_and_reindexes(): void
    {
        // null / '' / non-string entries are dropped; keys are reindexed so the
        // result is a clean list Stripe accepts (a gappy array would 400).
        $this->assertSame(
            ['txr_a', 'txr_b'],
            TaxService::rates(false, ['txr_a', '', null, 0, false, 'txr_b']),
        );
    }

    #[Test]
    public function a_multi_purpose_voucher_is_sold_without_a_rate(): void
    {
        // Its sale is not a taxable supply; VAT is charged when it is redeemed.
        config(['shop.tax.rates' => ['txr_19'], 'shop.tax.require' => true]);
        $voucher = new Product(['tax_class' => Product::TAX_CLASS_MULTI_PURPOSE_VOUCHER]);

        $this->assertTrue($voucher->isMultiPurposeVoucher());
        $this->assertTrue(TaxService::untaxedAtSale($voucher));
        $this->assertSame([], TaxService::ratesFor($voucher));
        $this->assertSame([], TaxService::ratesFor($voucher, false, ['txr_19']));
    }

    #[Test]
    public function a_taxable_product_gets_the_same_rates_as_rates(): void
    {
        config(['shop.tax.rates' => ['txr_19']]);
        $course = new Product(['tax_class' => 'standard']);

        $this->assertFalse($course->isMultiPurposeVoucher());
        $this->assertSame(['txr_19'], TaxService::ratesFor($course));
        $this->assertSame(['txr_x'], TaxService::ratesFor($course, false, ['txr_x']));
        $this->assertSame(['txr_19'], TaxService::ratesFor(new Product()));
        $this->assertSame(['txr_19'], TaxService::ratesFor(null));
    }

    #[Test]
    public function an_exempt_customer_stays_exempt_for_a_taxable_product(): void
    {
        config(['shop.tax.rates' => ['txr_19']]);

        $this->assertSame([], TaxService::ratesFor(new Product(['tax_class' => 'standard']), true));
    }

    #[Test]
    public function the_untaxed_classes_come_from_config(): void
    {
        config(['shop.tax.rates' => ['txr_19'], 'shop.tax.untaxed_classes' => ['gift-card']]);

        $this->assertSame([], TaxService::ratesFor(new Product(['tax_class' => 'gift-card'])));
        $this->assertSame(['txr_19'], TaxService::ratesFor(new Product(['tax_class' => Product::TAX_CLASS_MULTI_PURPOSE_VOUCHER])));
    }
}
