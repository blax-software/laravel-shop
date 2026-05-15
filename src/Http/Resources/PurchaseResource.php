<?php

declare(strict_types=1);

namespace Blax\Shop\Http\Resources;

use Blax\Shop\Models\ProductPurchase;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Domain-vocabulary translation for ProductPurchase rows.
 *
 * The underlying model carries e-commerce naming (`from`, `until`,
 * `amount_paid`, polymorphic `purchasable_*` / `purchaser_*`). API consumers
 * generally want a flatter, loan/booking-flavoured payload. Extend this in
 * app code if you need extra fields:
 *
 *     class LoanResource extends PurchaseResource
 *     {
 *         protected function purchasableResource(): ?string
 *         {
 *             return BookResource::class;
 *         }
 *     }
 *
 * `status` is the **domain status** (active|overdue|returned) — not the raw
 * PurchaseStatus enum, which is exposed separately as `lifecycle_status`.
 *
 * @mixin ProductPurchase
 */
class PurchaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $meta = (array) ($this->meta ?? []);

        return [
            'id' => $this->id,
            'item' => $this->resolveItem(),
            'loaned_at' => optional($this->from)->toIso8601String(),
            'due_at' => optional($this->until)->toIso8601String(),
            'returned_at' => $meta['returned_at'] ?? null,
            'status' => $this->getDomainStatus(),
            'lifecycle_status' => $this->status?->value ?? (string) $this->status,
            'extensions_used' => (int) ($meta['extensions_used'] ?? 0),
            'quantity' => $this->quantity,
            // Accrued cost in cents per the configured tier ladder. Only set
            // when the purchase has a `from` timestamp (i.e. is a loan/booking);
            // a plain e-commerce purchase reports null.
            'accrued_cost' => $this->from !== null ? $this->accruedCost() : null,
        ];
    }

    /**
     * Override to point at the resource that should serialise the purchasable.
     * Returning null falls through to the raw purchasable model (or null when
     * not loaded).
     */
    protected function purchasableResource(): ?string
    {
        return null;
    }

    private function resolveItem(): mixed
    {
        $resource = $this->purchasableResource();
        $purchasable = $this->whenLoaded('purchasable', fn () => $this->purchasable);

        if ($purchasable === null || $resource === null) {
            return $purchasable;
        }

        return $resource::make($purchasable);
    }
}
