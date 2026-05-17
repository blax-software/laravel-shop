<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched automatically by {@see Product} when a row is deleted (or
 * soft-deleted, if the host enables it). Useful for search-index cleanup,
 * Stripe archival, or cache invalidation.
 */
class ProductDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public Product $product) {}
}
