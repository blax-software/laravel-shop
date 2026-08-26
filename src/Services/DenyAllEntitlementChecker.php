<?php

declare(strict_types=1);

namespace Blax\Shop\Services;

use Blax\Shop\Contracts\EntitlementChecker;

/**
 * Safe default {@see EntitlementChecker}: nothing ever satisfies a requirement.
 *
 * Bound automatically when `config('shop.entitlement_checker')` is unset, so a
 * conditional price stays hidden/refused until the host app provides a real
 * checker. This makes "forgot to wire it up" fail closed (no discount) rather
 * than open (discount for everyone).
 */
class DenyAllEntitlementChecker implements EntitlementChecker
{
    public function satisfies(mixed $buyer, array $requirement): bool
    {
        return false;
    }
}
