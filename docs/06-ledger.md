# Money ledger (Stripe + PayPal accounts)

The `stripe_transactions` table is a user-independent ledger of every money movement, one row per transaction in the account where it happened. Amounts are signed cents: `amount` is the gross, `fee` the provider's fee, `net = amount - fee`. Revenue figures (`Shop::ledgerTotals`, `revenueLedgerByDay`, `customerLedgerTotals`, `ledgerAmountByCustomer`) sum the rows whose `source_type` is in `shop.ledger.revenue_types`.

Besides charges, refunds and disputes, the default revenue types include bounced direct debits (`payment_failure_refund`, `refund_failure`) and Stripe's account-level fees (`stripe_fee`, `stripe_fx_fee`, `tax_fee`). Stripe reports those fees as a negative amount; the ledger stores them as `fee` with amount 0, so they lower net without showing up as refunds.

`provider` says which system a row comes from (`stripe`, `paypal`) and `account` which account of it (the PayPal merchant id; null for Stripe). `Shop::ledgerTotalsByAccount($from, $until)` splits the totals per account.

## Filling it

- Stripe: `shop:import-stripe-ledger` pages all BalanceTransactions; `Shop::syncLedgerForCharge()` writes a charge in real time from the webhook.
- PayPal: `shop:import-paypal-ledger` pages PayPal's Transaction Search API for every configured account. Read-only. Without `--since` it resumes 14 days before the account's newest row; the first run reaches back three years. Schedule it hourly or daily, after the Stripe import.

## PayPal setup

Create a REST app in the PayPal developer dashboard (live) with the "Transaction search" permission, then set:

```dotenv
PAYPAL_CLIENT_ID=...
PAYPAL_SECRET=...
```

More accounts go into `shop.paypal.accounts` (publish the config), one entry each with `name`, `client_id`, `secret` and an optional `base_url` (`https://api-m.sandbox.paypal.com` for sandbox).

## How PayPal rows count

PayPal checkouts that run through Stripe settle into the PayPal account. Stripe's row books the buyer's gross (Stripe reports 0 for these, the importer reads the charge amount) and Stripe's fee. PayPal takes its own fee inside the PayPal account, so the PayPal import adds one row per payment or refund:

| PayPal transaction | `source_type` | amount | fee | counts as revenue |
| --- | --- | --- | --- | --- |
| Stripe payment that settled in PayPal | `paypal_fee` | 0 | PayPal fee | yes (lowers net) |
| Stripe refund that settled in PayPal | `paypal_fee` | 0 | fee PayPal gave back (negative) | yes |
| Stripe payment swept on to Stripe | `paypal_fee_passthrough` | 0 | PayPal fee | no, Stripe's fee already holds it |
| Direct PayPal sale / refund / chargeback | `payment` / `payment_refund` / `dispute` | gross | PayPal fee | yes |
| PayPal account fee (T01xx) | `paypal_fee` | 0 | fee | yes |
| Withdrawal to bank (T04xx) | `payout` | signed | | no |
| Money sent (T00xx outgoing) | `payment_sent` | signed | | no |
| Holds, releases, sweeps, conversions | `transfer` | signed | | no |
| Not settled yet (status ≠ S) | `pending` | signed | | no |

A payment counts as swept when PayPal shows a T20xx transfer on the same transaction. Stripe's `custom_field` (`acct_…:pi_…`) links PayPal rows to the Stripe payment; the buyer is copied from the Stripe row with that payment intent.

If you override `shop.ledger.revenue_types`, add `paypal_fee`, or PayPal's fees will not lower net revenue.
