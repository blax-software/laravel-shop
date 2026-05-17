<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a product's status transitions to PUBLISHED (or it is created
 * directly in the published state). Listeners typically push to the public
 * sales surface, kick off launch notifications, or warm caches.
 */
class ProductPublished
{
    use Dispatchable, SerializesModels;

    public function __construct(public Product $product) {}
}
