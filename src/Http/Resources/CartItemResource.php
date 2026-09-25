<?php

declare(strict_types=1);

namespace Blax\Shop\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single line in {@see CartResource}. Money is integer minor units (cents).
 *
 * `purchasable` is a compact summary (id/name/slug/type/image) so a cart drawer
 * can render without a second round-trip. The image lookup is best-effort: it
 * only resolves when the purchasable exposes a laravel-files `files()` relation,
 * and never throws if the file layer is absent.
 *
 * @mixin \Blax\Shop\Models\CartItem
 */
class CartItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $purchasable = $this->purchasable;

        return [
            'id' => $this->id,
            'quantity' => (int) $this->quantity,
            'price' => $this->price === null ? null : (int) $this->price,
            'regular_price' => $this->regular_price === null ? null : (int) $this->regular_price,
            'subtotal' => $this->subtotal === null ? null : (int) $this->subtotal,
            'currency' => $this->currency,
            // The chosen price (a product sold in several options) and what the buyer
            // attached to this line (e.g. a brief); both null/empty when unused.
            'price_id' => $this->price_id,
            'parameters' => (object) ((array) ($this->parameters ?? [])),
            'purchasable' => $purchasable ? [
                'id' => $purchasable->getKey(),
                'name' => $purchasable->name ?? null,
                'slug' => $purchasable->slug ?? null,
                'type' => $this->purchasableType($purchasable),
                'image' => $this->resolveImage($purchasable),
                // Virtual products (services, downloads) need no shipping.
                'virtual' => (bool) ($purchasable->virtual ?? false),
            ] : null,
        ];
    }

    /**
     * Stringify a product type enum (or pass through a scalar) without assuming
     * the purchasable is a Product.
     */
    private function purchasableType(mixed $purchasable): ?string
    {
        $type = $purchasable->type ?? null;

        if ($type === null) {
            return null;
        }

        return $type instanceof \BackedEnum ? (string) $type->value : (string) $type;
    }

    /**
     * Best-effort primary image URL via the laravel-files `files()` relation.
     * Prefers a single-slot image (thumbnail/cover/icon), then the first
     * gallery/any file. Returns null and swallows errors when files aren't wired.
     */
    private function resolveImage(mixed $purchasable): ?string
    {
        if (! is_object($purchasable) || ! method_exists($purchasable, 'files')) {
            return null;
        }

        try {
            $files = $purchasable->files()->get();

            if ($files->isEmpty()) {
                return null;
            }

            $preferred = $files->first(function ($file) {
                $as = $file->pivot->as ?? null;

                return in_array($as, ['thumbnail', 'cover', 'icon', 'banner', 'logo'], true);
            });

            return ($preferred ?? $files->first())->url ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
