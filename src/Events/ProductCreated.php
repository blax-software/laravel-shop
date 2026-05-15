<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched automatically by {@see Product} via the model's
 * `$dispatchesEvents` map after a new product row is inserted.
 *
 * Listeners typically use this for downstream sync (push to Stripe, build a
 * search index entry, warm a cache). The model is passed already persisted
 * — listeners can read `$event->product->id`.
 */
class ProductCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Product $product) {}
}
