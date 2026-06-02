<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\ProductPurchase;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched the moment a {@see ProductPurchase} becomes COMPLETED — both when
 * a row is created already-completed and when an existing row transitions into
 * COMPLETED (it does NOT re-fire on later, unrelated saves of an
 * already-completed purchase).
 *
 * This is the package-agnostic fulfillment seam: host applications listen here
 * to grant access, send receipts, provision licences, etc., without coupling
 * to the package's own {@see \Blax\Shop\Models\ProductAction} table or to a
 * specific purchasable model. The purchase carries everything needed to fan
 * out — `purchasable` (the product/price/host model that was sold), `price_id`,
 * `quantity`, `cart_id`, and `meta`.
 *
 * Contrast with {@see PurchaseCreated}, which fires for every new purchase row
 * regardless of status (including CART/PENDING); listen to PurchaseCompleted
 * when you only care about paid/fulfillable purchases.
 */
class PurchaseCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public ProductPurchase $purchase) {}
}
