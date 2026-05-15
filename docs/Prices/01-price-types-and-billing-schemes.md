# Prices — Types and Billing Schemes

Every monetary amount in `laravel-shop` lives on a [`ProductPrice`](../../src/Models/ProductPrice.php) row attached polymorphically to a product. A single product can carry many prices (different currencies, sale prices, tiered ladders, per-variant pricing). Two enums shape what a single price means:

- [`PriceType`](../../src/Enums/PriceType.php) — **when** money changes hands (`ONE_TIME` vs `RECURRING`)
- [`BillingScheme`](../../src/Enums/BillingScheme.php) — **how** the amount is computed (`PER_UNIT` vs `TIERED`)

This document is the reference for both, plus the supporting columns (`sale_unit_amount`, recurring fields, currency).

## ProductPrice anatomy

| Column | Purpose |
|---|---|
| `purchasable_type` / `purchasable_id` | Polymorphic — usually a `Product`, but any `Cartable` can carry prices |
| `name` | Human label (e.g. "EU pricing", "Annual plan", "Member rate") |
| `currency` | ISO 4217 code (`'EUR'`, `'USD'`, …) |
| `unit_amount` | Cents per unit |
| `sale_unit_amount` | Discounted unit_amount; reads via `getCurrentPrice(true)` |
| `sale_start` / `sale_end` | When the sale price is effective (host-app enforced) |
| `is_default` | Exactly one default per (product, currency) — what `getCurrentPrice()` returns |
| `active` | Soft-disable a price without deleting it |
| `type` | [`PriceType`](#price-types) enum cast |
| `billing_scheme` | [`BillingScheme`](#billing-schemes) enum cast |
| `interval` / `interval_count` | For `RECURRING` prices — `MONTH × 1`, `YEAR × 1`, etc. |
| `trial_period_days` | For `RECURRING` — free-trial length |
| `stripe_price_id` | Sync handle (see [Stripe integration](../02-stripe.md)) |
| `meta` | JSON for app-specific extension |

A `ProductPrice` can attach to **any** Cartable, not just Product — see the bottom of this doc.

---

## Price types

### `PriceType::ONE_TIME` — pay-once charges

The default. The buyer pays once. Used by every product type that's bought outright:

- Simple products
- Variation products (a single one-off variant of a Variable parent)
- Booking products (per-day rate, paid at checkout)
- Bundle / Grouped totals
- Loanable rentals (the cost accrues over time but is billed as a single one-time charge at return)

```php
ProductPrice::create([
    'purchasable_id' => $product->id,
    'purchasable_type' => Product::class,
    'type' => PriceType::ONE_TIME,
    'currency' => 'EUR',
    'unit_amount' => 2999,
    'is_default' => true,
]);
```

### `PriceType::RECURRING` — subscriptions

Used for billing that repeats on an interval. Combine with `interval`, `interval_count`, and optionally `trial_period_days`.

```php
ProductPrice::create([
    'purchasable_id' => $proPlan->id,
    'purchasable_type' => Product::class,
    'type' => PriceType::RECURRING,
    'interval' => RecurringInterval::MONTH,
    'interval_count' => 1,
    'trial_period_days' => 14,
    'currency' => 'EUR',
    'unit_amount' => 999,
    'is_default' => true,
]);
```

`RecurringInterval` cases: `DAY`, `WEEK`, `MONTH`, `YEAR`.

Recurring prices sync to Stripe as Prices with `recurring.interval` set; subscriptions live on the customer's Stripe object. See [Stripe integration](../02-stripe.md) for the full sync flow.

---

## Billing schemes

### `BillingScheme::PER_UNIT` — flat per-unit price

The simplest math: `total = unit_amount × quantity` (for Simple / Grouped / Variation), or `total = unit_amount × days × quantity` (for Booking).

```php
ProductPrice::create([
    'purchasable_id' => $book->id,
    'purchasable_type' => Product::class,
    'billing_scheme' => BillingScheme::PER_UNIT,
    'currency' => 'EUR',
    'unit_amount' => 1499,
    'is_default' => true,
]);

$price->calculateForUsage(3);  // 4497 cents = 3 × €14.99
```

### Tiered billing (`BillingScheme::TIERED`)

A `TIERED` price walks a ladder of `ProductPriceTier` rows. Each tier covers usage up to its `up_to` mark at `unit_amount` cents per unit, optionally adding a `flat_amount` on tier entry. The last tier (with `up_to = null`) extends to infinity.

**Storage**: tiers live in the `product_price_tiers` table, linked by `price_id`.

**Math**: `ProductPrice::calculateForUsage(float $usage): int` walks the ladder; see [`HasLoanLifecycle::calculateCost()`](../../src/Traits/HasLoanLifecycle.php) for the typical consumer.

**The library example** — free for 2 weeks, then €1/day, then €2/day after 2 months:

```php
$price = ProductPrice::create([
    'purchasable_id' => $book->id,
    'purchasable_type' => Product::class,
    'billing_scheme' => BillingScheme::TIERED,
    'currency' => 'EUR',
    'is_default' => true,
]);

ProductPriceTier::create(['price_id' => $price->id, 'up_to' => 14,   'unit_amount' => 0,   'sort_order' => 0]);
ProductPriceTier::create(['price_id' => $price->id, 'up_to' => 60,   'unit_amount' => 100, 'sort_order' => 1]);
ProductPriceTier::create(['price_id' => $price->id, 'up_to' => null, 'unit_amount' => 200, 'sort_order' => 2]);

$price->calculateForUsage(20);   // 600  — 14 free + 6 × 100
$price->calculateForUsage(75);   // 7600 — 14 free + 46 × 100 + 15 × 200
```

**Tier columns**:

| Column | Purpose |
|---|---|
| `price_id` | FK to the parent `ProductPrice` |
| `up_to` | Usage units this tier covers (null = unbounded) |
| `unit_amount` | Cents per unit consumed within this tier |
| `flat_amount` | Optional flat fee added once when the tier is entered |
| `sort_order` | Deterministic walk order |
| `meta` | App-specific extension |

**`flat_amount` use case** — a setup fee + tiered usage:

```php
ProductPriceTier::create(['price_id' => $price->id, 'up_to' => 14, 'unit_amount' => 0, 'flat_amount' => 500, 'sort_order' => 0]); // €5 setup
ProductPriceTier::create(['price_id' => $price->id, 'up_to' => null, 'unit_amount' => 100, 'sort_order' => 1]);

$price->calculateForUsage(20);  // 500 (setup) + 0 (free days) + 6 × 100 = 1100
```

The flat amount is charged once per tier *entered*, so a usage value that only touches the first tier still pays €5.

---

## Sale prices

Every `ProductPrice` can carry a `sale_unit_amount` alongside its `unit_amount`. The accessor picks based on the caller:

```php
$price->getCurrentPrice();       // unit_amount
$price->getCurrentPrice(true);   // sale_unit_amount (falls back to unit_amount if null)
```

`sale_start` and `sale_end` are stored but the package doesn't enforce them — your application layer decides whether to call `getCurrentPrice(true)` based on the current time.

Sale prices apply to **PER_UNIT** schemes naturally. For **TIERED** schemes, model the discount as alternate tiers on a separate `ProductPrice` row and switch which one is `is_default` for the promotional window — that keeps the math reproducible.

---

## Multiple prices per product

A product can have many `ProductPrice` rows simultaneously:

- One per currency (`EUR`, `USD`, `GBP`)
- A regular price plus a member-only price (tag them via `meta.tier` or `name`)
- A non-default override for specific borrowers (e.g. an institutional rate)

Exactly **one** price per (product, currency) should carry `is_default = true`. The `defaultPrice()` relation on `Product` filters by `is_default` (and active).

To attach a specific price to a purchase at checkout (rather than the default), set `ProductPurchase.price_id` when creating the row. Loanable lifecycle methods then bill against that price for the lifetime of the loan, even if the product's tier ladder changes later.

---

## Which products use which?

| Product type | Idiomatic billing scheme | Idiomatic price type | Sale price? | Notes |
|---|---|---|---|---|
| `SIMPLE` | `PER_UNIT` | `ONE_TIME` (or `RECURRING` for SaaS) | ✅ | The most flexible — supports anything |
| `VARIABLE` | — | — | — | No prices on the parent |
| `VARIATION` | `PER_UNIT` (or `TIERED`) | `ONE_TIME` or `RECURRING` | ✅ | Same flexibility as Simple |
| `GROUPED` | `PER_UNIT` if you set a bundle discount, otherwise none | `ONE_TIME` | ✅ | Children own most pricing |
| `EXTERNAL` | display-only `PER_UNIT` | display-only | display-only | Never charged |
| `BOOKING` | `PER_UNIT` (per-day) | `ONE_TIME` | ✅ | `unit_amount × days × quantity` |
| `POOL` | — | — | — | Derived via `PricingStrategy` from members |
| `LOANABLE` | `TIERED` (or `PER_UNIT` for flat-rate) | `ONE_TIME` | ✅ | Tiers express "free for N days, then €X/day…" |

See each [product type doc](../ProductTypes/00-overview.md) for the worked-out examples.

---

## Prices on non-Product Cartables

`ProductPrice.purchasable_*` is polymorphic. You can attach a price to anything that implements `Blax\Shop\Contracts\Cartable`:

```php
$subscription = SubscriptionPlan::create([...]); // implements Cartable

ProductPrice::create([
    'purchasable_id' => $subscription->id,
    'purchasable_type' => SubscriptionPlan::class,
    'type' => PriceType::RECURRING,
    'interval' => RecurringInterval::MONTH,
    'unit_amount' => 1999,
    'is_default' => true,
]);
```

`HasPrices` (used by Product) provides `prices()` and `defaultPrice()` relations — non-Product hosts can either include the trait or define the relation themselves.

## Related Documentation

- [Product types overview](../ProductTypes/00-overview.md)
- [Loanable products](../ProductTypes/08-loanable-products.md) — the canonical consumer of tiered pricing
- [Stripe integration](../02-stripe.md) — how prices sync to Stripe
- [Product Pool pricing strategies](../ProductTypes/02-pool-products.md) — `LOWEST` / `HIGHEST` / `AVERAGE` aggregation
