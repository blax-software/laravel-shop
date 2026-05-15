# Product Types — Overview

Every product in `laravel-shop` declares a `type` from the [`ProductType`](../../src/Enums/ProductType.php) enum. The type controls **how the product behaves** — whether it carries its own stock, how it's added to the cart, what kinds of prices apply, and what the resulting `ProductPurchase` row means.

## At a glance

| Type | What it is | Stock? | Typical price |
|---|---|---|---|
| [`SIMPLE`](./03-simple-products.md) | A stand-alone, single-SKU product | Optional | One-time, per-unit |
| [`VARIABLE`](./04-variable-products.md) | A parent of multiple variants (e.g. T-shirt → S/M/L) | No (variants do) | None on the parent |
| [`VARIATION`](./05-variation-products.md) | One specific variant of a Variable parent | Yes | Per-variation override |
| [`GROUPED`](./06-grouped-products.md) | A bundle / multi-pack of independent child products | No (children do) | Per child |
| [`EXTERNAL`](./07-external-products.md) | A listing that points at an external URL | No | None (no checkout) |
| [`BOOKING`](./01-booking-products.md) | Time-windowed reservation (`from` / `until`) | Yes (date-claimed) | One-time per-day |
| [`POOL`](./02-pool-products.md) | A pool of interchangeable Booking items | No (pool members do) | Aggregated (lowest/highest/avg) |
| [`LOANABLE`](./08-loanable-products.md) | Checked out → extended → returned (library / rental) | Yes (counter) | Tiered usage-priced |

## Which prices apply to which type?

A `ProductPrice` row attaches polymorphically (`purchasable_*`) to any product. Two enums control the price's shape:

- [`PriceType`](../../src/Enums/PriceType.php): `ONE_TIME` or `RECURRING` (subscription-style)
- [`BillingScheme`](../../src/Enums/BillingScheme.php): `PER_UNIT` or `TIERED`

Detailed reference: [Prices — types and billing schemes](../Prices/01-price-types-and-billing-schemes.md).

| Product type | `ONE_TIME / per_unit` | `RECURRING` | `TIERED` | Sale price | Notes |
|---|---|---|---|---|---|
| SIMPLE | ✅ default | ✅ for subscriptions | ✅ for usage-priced SKUs | ✅ | Most flexible — supports anything |
| VARIABLE | — | — | — | — | Variants own the prices; the parent has none |
| VARIATION | ✅ default | ✅ | ✅ | ✅ | Behaves like a Simple product attached to a parent |
| GROUPED | — | — | — | — | Each child carries its own price |
| EXTERNAL | display only | — | — | display only | No checkout — price is shown but never charged |
| BOOKING | ✅ per-day | rare | possible | ✅ | `unit_amount × days` math; tiers would mean tier-per-day |
| POOL | — | — | — | — | Derived from the pool members via `PricingStrategy` |
| LOANABLE | ✅ for flat-rate rentals | rare | ✅ recommended | ✅ | Tiered ladder gives "free for N days, then €X/day, then €Y/day" |

Legend: ✅ supported and idiomatic · "—" not applicable (the type owns no prices of its own) · "rare" technically possible but unusual.

## Pricing strategy vs. billing scheme

These two enums are easy to confuse:

- **`PricingStrategy`** (`LOWEST` / `HIGHEST` / `AVERAGE`) is for **POOL** products only — it tells the pool how to aggregate prices across its member items.
- **`BillingScheme`** (`PER_UNIT` / `TIERED`) is on each **ProductPrice** — it tells the math whether to multiply a flat rate or walk a tier ladder.

## Where to go next

- Browse the product-type docs in this directory ([Booking](./01-booking-products.md), [Pool](./02-pool-products.md), [Simple](./03-simple-products.md), [Variable](./04-variable-products.md), [Variation](./05-variation-products.md), [Grouped](./06-grouped-products.md), [External](./07-external-products.md), [Loanable](./08-loanable-products.md))
- For deep pricing details see [Prices — types and billing schemes](../Prices/01-price-types-and-billing-schemes.md).
- For cart / checkout / purchase mechanics see [Purchasing](../03-purchasing.md).
