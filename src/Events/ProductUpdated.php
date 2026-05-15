<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched automatically by {@see Product} via the model's
 * `$dispatchesEvents` map whenever an existing product row is saved
 * (after the `updated` model event fires).
 *
 * Pairs with {@see ProductCreated} for any sink that needs to react to
 * the full product write surface — search reindex, cache invalidation,
 * Stripe price sync, etc.
 */
class ProductUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(public Product $product) {}
}
