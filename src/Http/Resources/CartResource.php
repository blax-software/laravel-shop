<?php

declare(strict_types=1);

namespace Blax\Shop\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stable storefront wire shape for a Cart.
 *
 * This is the payload the cart-driving controllers (WebSocket or HTTP) hand a
 * frontend after every mutation. All money is integer MINOR units (cents) plus
 * an explicit ISO-4217 `currency` — never inferred, never pre-divided — so the
 * client owns formatting. Kept deliberately flat and presentation-agnostic;
 * apps that need extra fields extend this resource.
 *
 * @mixin \Blax\Shop\Models\Cart
 */
class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->items()->with('purchasable')->get();

        return [
            'id' => $this->id,
            'currency' => $this->currency ?? config('shop.currency'),
            // Sum of line subtotals in minor units (cents).
            'total' => (int) $items->sum('subtotal'),
            'item_count' => (int) $items->sum('quantity'),
            'is_converted' => $this->isConverted(),
            'is_ready_to_checkout' => $this->is_ready_to_checkout,
            'items' => CartItemResource::collection($items),
        ];
    }
}
