# External Products

## Overview

An External product (`ProductType::EXTERNAL`) is a catalogue entry that **doesn't transact inside the shop** — it points the buyer at a URL on a third-party site. Common pattern for affiliate listings, "view on Amazon" buttons, or referral programmes where the actual checkout happens elsewhere.

## Key Characteristics

### 1. **No internal checkout**
- The product cannot be added to a cart
- No `ProductPurchase` is ever created from this row
- Stock is meaningless and is forced off by the seeder (`manage_stock = false`)

### 2. **Holds a destination link**
- The external URL lives in `meta.external_url` (convention)
- Optional "button label" / affiliate tag also in `meta`

### 3. **Prices are display-only**
- You can attach a `ProductPrice` to show "from €X" for SEO / catalogue parity
- That price is never charged by this package

## How It Works

External products exist purely for catalogue presentation: they render alongside other products with the same images, description, categories, but the "Add to cart" button is replaced with a "View on partner site" link that uses `meta.external_url`.

```
Product(type=EXTERNAL, name='Hyperion paperback')
   meta = {
     "external_url": "https://amzn.example.com/dp/B00...?tag=mylib-20",
     "external_label": "Buy on Amazon"
   }
```

## Pricing

External products **don't charge money through the package**. Any `ProductPrice` attached is informational only — useful for showing parity prices in listings.

```php
ProductPrice::create([
    'purchasable_id' => $book->id,
    'purchasable_type' => Product::class,
    'unit_amount' => 1299,        // "Was €12.99 on Amazon" — display only
    'currency' => 'EUR',
    'is_default' => true,
    'active' => true,
]);
```

`sale_unit_amount` works the same way — informational, no checkout.

## Configuration

```php
$book = Product::create([
    'name' => 'Hyperion',
    'type' => ProductType::EXTERNAL,
    'slug' => 'hyperion-paperback',
    'manage_stock' => false,
    'meta' => [
        'external_url' => 'https://amzn.example.com/dp/B00...',
        'external_label' => 'Buy on Amazon',
    ],
]);
```

## Cart Integration

There is none — the cart will not process an External product. If you accidentally call `$cart->addToCart($externalProduct)`, the cartable contract still works but the resulting cart item points at a product that has no real price and no fulfilment path. Your UI should never offer the option.

In practice, your storefront renders this:

```blade
@if ($product->type === ProductType::EXTERNAL)
    <a href="{{ $product->meta->external_url }}" rel="sponsored" target="_blank">
        {{ $product->meta->external_label ?? 'View product' }}
    </a>
@else
    <button data-add-to-cart="{{ $product->id }}">Add to cart</button>
@endif
```

## Common Use Cases

- **Affiliate listings** with tracked URLs
- **"Out of print, available elsewhere"** library notes
- **Referral programs** to partner stores
- **Catalogue completeness** — book / album / film entries you want indexed in your catalogue but don't actually sell

## Best Practices

1. **Always set `manage_stock = false`.** External products don't have stock.
2. **Store the URL under `meta.external_url`** so any storefront / frontend understands the convention.
3. **Add `rel="sponsored"`** (or `nofollow` for non-affiliate links) to outbound links — SEO hygiene.
4. **Don't sync external products to Stripe.** They have no internal price you'd want to mirror.

## Troubleshooting

### Cart accidentally accepted an external product
Add a guard in your application layer before `$cart->addToCart()`:

```php
if ($product->type === ProductType::EXTERNAL) {
    abort(422, 'External products cannot be added to the cart.');
}
```

The package treats every `Cartable` uniformly — type-aware blocking is the host app's job.

### URL renders blank
The `meta` column is cast to `object`, so reading `meta->external_url` (or `meta['external_url']` after `(array)` casting) needs to match how you wrote it. Stick with one convention across writes and reads.

## Related Documentation

- [Simple products](./03-simple-products.md) — the same shape but transactable
- [Product Relations](../05-product-relations.md) — link related internal products to an external listing
