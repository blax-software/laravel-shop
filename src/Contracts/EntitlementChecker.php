<?php

declare(strict_types=1);

namespace Blax\Shop\Contracts;

/**
 * Decides whether a buyer satisfies a conditional-price requirement.
 *
 * Conditional pricing lets a {@see \Blax\Shop\Models\ProductPrice} apply only
 * to buyers who already hold something else — a subscription, a role, a prior
 * purchase. The package models the requirement (`ProductPrice.meta.requires`,
 * e.g. `{"role":"seat"}`) and resolves the cheapest *eligible* price, but it
 * has no opinion on what "the buyer holds the `seat` role" means — that is
 * host-specific (laravel-roles, Cashier subscriptions, a custom entitlements
 * table). The host binds an implementation of this contract; the package calls
 * it from {@see \Blax\Shop\Traits\HasPrices::resolvePriceFor()}.
 *
 * Bind via `config('shop.entitlement_checker')` (a class-string resolved from
 * the container). When unbound the package falls back to
 * {@see \Blax\Shop\Services\DenyAllEntitlementChecker} — a conditional price is
 * never offered until a host wires this up, so a forgotten binding can never
 * leak a discount to everyone.
 */
interface EntitlementChecker
{
    /**
     * Does `$buyer` satisfy `$requirement`?
     *
     * @param  mixed  $buyer  The buyer (usually a User model); may be null for
     *                        a guest — guests satisfy nothing.
     * @param  array<string,mixed>  $requirement  The `requires` map from the
     *                        price meta, e.g. `['role' => 'seat']` or
     *                        `['product' => 'full-seat']`. The reserved key
     *                        `label` is display-only and never a condition.
     */
    public function satisfies(mixed $buyer, array $requirement): bool;
}
