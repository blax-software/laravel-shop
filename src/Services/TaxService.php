<?php

declare(strict_types=1);

namespace Blax\Shop\Services;

use Blax\Shop\Exceptions\TaxRateNotConfiguredException;

/**
 * Single source of truth for the tax rate(s) applied to taxable charges and
 * subscriptions across every checkout path (Cashier subscriptions, raw Stripe
 * Checkout Sessions, invoice items).
 *
 * The host app owns two decisions and passes them in; this service owns the
 * policy that turns them into the array Stripe expects.
 *   - WHICH rate(s) apply: `config('shop.tax.rates')`, or an explicit list.
 *   - WHETHER this customer is exempt (reverse-charge / zero-rated): `$exempt`.
 *
 * Keeping the policy in one place stops a repo applying the rate twice (Stripe
 * rejects duplicates with "You cannot attach more than one of the same tax
 * rate") or silently billing 0% VAT. When a rate is required but none is
 * configured for a non-exempt customer, this throws.
 */
class TaxService
{
    /**
     * Resolve the Stripe tax-rate ids to apply.
     *
     * @param  bool  $exempt  True for reverse-charge / zero-rated customers → no rate.
     * @param  array<int, string|null>|null  $rates  Explicit rate ids; defaults to config('shop.tax.rates').
     * @return array<int, string>  Stripe tax-rate ids (e.g. ['txr_...']), or [] when exempt.
     *
     * @throws TaxRateNotConfiguredException  When non-exempt, no rate resolved, and config('shop.tax.require') is true.
     */
    public static function rates(bool $exempt = false, ?array $rates = null): array
    {
        if ($exempt) {
            return [];
        }

        $resolved = array_values(array_filter(
            $rates ?? (array) config('shop.tax.rates', []),
            static fn ($id): bool => is_string($id) && $id !== '',
        ));

        if ($resolved === [] && config('shop.tax.require', false)) {
            throw new TaxRateNotConfiguredException();
        }

        return $resolved;
    }
}
