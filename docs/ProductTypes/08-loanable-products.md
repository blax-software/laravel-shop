# Loanable Products

## Overview

A Loanable product (`ProductType::LOANABLE`) is **checked out, possibly extended, and returned** — the rental / library pattern. Unlike a Booking product (which reserves a fixed date window upfront), a Loanable's end date is open-ended: the borrower picks up an item now, has a due date that can be pushed back via extensions, and the loan ends only when the item is returned.

Loanables are the natural fit for:
- Library books
- Equipment / tool rental
- Vehicle hire by the day with no fixed return date
- Time-priced metered usage (free for N days, then €X/day, then €Y/day after some threshold)

## Key Characteristics

### 1. **`from` is the checkout time, `until` is the (mutable) due date**
- `from` is set when the loan is created
- `until` is the current due date; `extend()` moves it forward
- `meta.returned_at` is stamped when `markReturned()` is called

### 2. **Manage stock as a plain counter**
- A Loanable Product has `manage_stock = true`
- Each loan calls `$product->decreaseStock(1)` at checkout and `$product->increaseStock(1)` on return
- The package's stock subsystem keeps the audit log automatically

### 3. **Tiered usage pricing**
- A Loanable typically carries a `ProductPrice` with `billing_scheme = TIERED`
- The tiers describe "free for the first N days, then €X/day, then €Y/day after some threshold"
- The cost accrues over time and is computed by `ProductPurchase::accruedCost()` (delegating to `ProductPrice::calculateForUsage($days)`)

### 4. **Open-ended lifecycle**
- The loan stays `pending` until the borrower marks it returned
- Cost is computed against `now()` while active, and frozen at `meta.returned_at` after return

## How It Works

### Lifecycle

```
checkout
   │   $product->decreaseStock(1)
   │   ProductPurchase(from=now, until=now+2w, meta.extensions_used=0, status=pending)
   ▼
[ active — borrower has the item ]
   │
   │   Borrower wants more time?
   │   $purchase->extend()           // bumps `until` forward by config('shop.loan.extension_weeks')
   │
   │   Due date passes without return?
   │   $purchase->isOverdue() === true
   │   $purchase->accruedCost() keeps growing per the tier ladder
   ▼
return
       $purchase->markReturned()    // meta.returned_at = now, status = completed
       $product->increaseStock(1)
```

### Loan policy knobs

| Config key | Default | What it controls |
|---|---|---|
| `shop.loan.default_duration_weeks` | 2 | Initial `until` offset from `from` |
| `shop.loan.extension_weeks` | 1 | How far `extend()` pushes `until` per call |
| `shop.loan.max_extensions` | 2 | Cap enforced by `canExtend()` |

These are policy / UI knobs — the lifecycle methods accept overrides per-call, so host apps can layer additional rules without touching config.

## Pricing

Loanable products are priced by **usage** (days), and tiered pricing is the idiomatic shape. The price ladder lives on `ProductPriceTier` rows attached to the `ProductPrice`.

### The library scenario: free for 2 weeks, then €1/day, then €2/day after 2 months

```php
$book = Product::create([
    'name' => 'Hyperion',
    'type' => ProductType::LOANABLE,
    'manage_stock' => true,
]);
$book->increaseStock(3);  // three copies available

$price = ProductPrice::create([
    'purchasable_id' => $book->id,
    'purchasable_type' => Product::class,
    'currency' => 'EUR',
    'billing_scheme' => BillingScheme::TIERED,
    'is_default' => true,
]);

ProductPriceTier::create(['price_id' => $price->id, 'up_to' => 14,   'unit_amount' => 0,   'sort_order' => 0]);
ProductPriceTier::create(['price_id' => $price->id, 'up_to' => 60,   'unit_amount' => 100, 'sort_order' => 1]);
ProductPriceTier::create(['price_id' => $price->id, 'up_to' => null, 'unit_amount' => 200, 'sort_order' => 2]);
```

Day-by-day cost from this ladder:

| Days out | Cost (cents) | Breakdown |
|---|---|---|
| 0–14 | 0 | Free grace period |
| 15 | 100 | 14 free + 1 day × €1 |
| 30 | 1 600 | 14 free + 16 × €1 |
| 60 | 4 600 | 14 free + 46 × €1 |
| 61 | 4 800 | + 1 day × €2 |
| 90 | 10 600 | 14 free + 46 × €1 + 30 × €2 |

See [Prices — tiered billing](../Prices/01-price-types-and-billing-schemes.md#tiered-billing-billingschemetiered) for the full tier mechanic.

### Other pricing options

| Scheme | When to use |
|---|---|
| `TIERED` | The default for libraries / rentals. Use a single all-free tier if loans are entirely free. |
| `PER_UNIT` (one-time) | Simple flat-rate rentals — `unit_amount` × days. |
| `RECURRING` | Unusual; for subscription-style "unlimited borrowing for €X/month" — use a Simple subscription product, not a Loanable, and gate the loan API on subscription status in your app. |
| `sale_unit_amount` | For promotional "free this week" overrides without rewriting the tier ladder. |

## Configuration

### Cart and checkout

The package's cart accepts a Loanable product like any Cartable. In a typical library API you'd bypass the cart and create the `ProductPurchase` directly:

```php
DB::transaction(function () use ($book, $user) {
    $book->decreaseStock(1);  // throws NotEnoughStockException if no copy available

    return $book->purchases()->create([
        'purchaser_id' => $user->id,
        'purchaser_type' => User::class,
        'price_id' => $book->defaultPrice()->first()?->id,
        'quantity' => 1,
        'amount' => 0,
        'amount_paid' => 0,
        'status' => PurchaseStatus::PENDING,
        'from' => now(),
        'until' => now()->addWeeks(config('shop.loan.default_duration_weeks')),
        'meta' => ['extensions_used' => 0],
    ]);
});
```

### Extending

```php
if ($loan->canExtend()) {       // respects config('shop.loan.max_extensions')
    $loan->extend();             // shifts `until` by config('shop.loan.extension_weeks')
}
```

`canExtend()` returns false if the loan is already returned **or** overdue. `extend()` itself is permissive — guard your endpoint with `canExtend()`.

### Returning

```php
$loan->markReturned();           // meta.returned_at = now(), status = completed
$loan->purchasable->increaseStock(1);
```

After this, `accruedCost()` stays frozen at the cost-as-of-`returned_at` value.

## Lifecycle helpers

Provided by [`HasLoanLifecycle`](../../src/Traits/HasLoanLifecycle.php) on `ProductPurchase`:

| Method | Returns | Purpose |
|---|---|---|
| `isReturned()` | `bool` | `meta.returned_at` is set |
| `isOverdue()` | `bool` | Active and `until < now()` |
| `returnedAt()` | `?string` (ISO) | The return timestamp, if any |
| `extensionsUsed()` | `int` | How many times `extend()` has been called |
| `canExtend(?int $max)` | `bool` | Honours the max cap, refuses overdue or returned loans |
| `extend(?int $weeks)` | `self` | Bumps `until`, increments meta counter, saves |
| `markReturned(?DateTimeInterface)` | `self` | Sets `meta.returned_at`, flips status to `completed`, saves |
| `getDomainStatus()` | `string` | `'active'`, `'overdue'`, or `'returned'` |
| `accruedCost()` | `int` (cents) | Cost as of now (or `returned_at` if returned) |
| `calculateCost(?$asOf, ?ProductPrice)` | `int` (cents) | Cost at an arbitrary moment, optionally against an override price |

### Scopes

```php
ProductPurchase::activeLoans();   // status=pending, not yet returned
ProductPurchase::returned();       // meta.returned_at not null
ProductPurchase::overdue();        // active + past due date
```

## Common Use Cases

- **Public / private libraries** — the canonical case
- **Tool libraries / equipment rental**
- **Internal device fleet** — laptops, AV gear, lab kit
- **Car / bike sharing** with day-based pricing
- **Conference loaner kits**

## Best Practices

1. **Use the package's stock subsystem.** Don't keep a parallel counter on the host model — `increase/decreaseStock` already does the audit log right.
2. **Wrap `decreaseStock` + `purchases()->create()` in a transaction.** If the purchase insert fails, the stock movement rolls back too.
3. **Always check `canExtend()` before `extend()`.** `extend()` is intentionally permissive so custom policies can compose; the check protects the standard flow.
4. **Surface `accruedCost` through your resource layer.** [`PurchaseResource`](../../src/Http/Resources/PurchaseResource.php) already includes it.
5. **Configure tiers per-product.** Different products (a brand-new bestseller vs. a 1990s paperback) can have entirely different ladders by attaching different `ProductPrice` rows.
6. **Use `price_id` on the purchase** to lock in pricing at checkout — if you later change the product's tier ladder, existing loans still bill against the price they started under.

## Troubleshooting

### `accruedCost()` returns 0 even though days have passed
Either:
- No `ProductPrice` is attached to the product (`purchasable->defaultPrice()` returns null)
- The price's `billing_scheme` is `tiered` but `tiers` is empty
- Tiers are present but all carry `unit_amount = 0`

### Overdue loans don't fall into the `overdue` scope
The scope is `activeLoans + until < now`. If `markReturned()` was already called, the loan is no longer active and won't appear — that's correct behaviour.

### Stock doesn't restore after return
`markReturned()` only updates the purchase row. You must call `$book->increaseStock(1)` (or equivalent) yourself — the package keeps the two operations separate so your business logic can decide whether a damaged-on-return item should restock or not.

### Returning an already-returned loan double-counts stock
Guard your endpoint:

```php
if ($loan->isReturned()) {
    abort(422, 'Already returned');
}
```

## Related Documentation

- [Booking products](./01-booking-products.md) — when the loan window is fixed at checkout
- [Simple products](./03-simple-products.md) — for outright sales rather than loans
- [Prices — tiered billing](../Prices/01-price-types-and-billing-schemes.md#tiered-billing-billingschemetiered)
- [Purchasing](../03-purchasing.md)
