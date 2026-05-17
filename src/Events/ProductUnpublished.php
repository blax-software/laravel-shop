<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a product moves away from PUBLISHED (to DRAFT, ARCHIVED,
 * etc.). Listeners commonly use this to retract the product from sales
 * surfaces or freeze ongoing operations referencing it.
 */
class ProductUnpublished
{
    use Dispatchable, SerializesModels;

    public function __construct(public Product $product) {}
}
