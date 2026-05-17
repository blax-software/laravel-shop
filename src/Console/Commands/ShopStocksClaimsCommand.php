<?php

declare(strict_types=1);

namespace Blax\Shop\Console\Commands;

use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductStock;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ShopStocksClaimsCommand extends Command
{
    protected $signature = 'shop:stocks:claims
                            {product? : Limit to one product (ID, slug, SKU, or partial name). Omit to list claims across the catalogue.}
                            {--active : Only show claims that are active right now}
                            {--limit=50 : Maximum number of claims to display}';

    protected $description = 'List pending stock claims (active or upcoming reservations) — useful for "why is this not available?" investigations';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $onlyActive = (bool) $this->option('active');
        $now = Carbon::now();

        $query = ProductStock::query()
            ->withoutGlobalScope('willExpire')
            ->where('type', StockType::CLAIMED->value)
            ->where('status', StockStatus::PENDING->value);

        if ($identifier = $this->argument('product')) {
            $product = $this->resolveProduct((string) $identifier);
            if (! $product) {
                $this->error("No product matched '{$identifier}'.");
                return self::FAILURE;
            }
            $query->where('product_id', $product->getKey());
            $this->newLine();
            $this->line('  <fg=cyan;options=bold>'.$product->name.'</> <fg=gray>('.($product->sku ?: $product->id).')</>');
        } else {
            $this->newLine();
            $this->line('  <fg=cyan;options=bold>All pending claims across the catalogue</>');
        }

        if ($onlyActive) {
            $query->where(function ($q) use ($now) {
                $q->whereNull('claimed_from')->orWhere('claimed_from', '<=', $now);
            })->where(function ($q) use ($now) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            });
            $this->line('  <fg=gray>Filter: currently active only</>');
        }

        $claims = $query
            ->orderBy('claimed_from')
            ->orderBy('expires_at')
            ->limit($limit)
            ->get();

        $this->newLine();

        if ($claims->isEmpty()) {
            $this->line('  <fg=gray>(no pending claims found)</>');
            $this->newLine();
            return self::SUCCESS;
        }

        $rows = $claims->map(function (ProductStock $stock) use ($now): array {
            $state = $this->classify($stock, $now);

            return [
                $stock->product?->name ? $this->truncate($stock->product->name, 22) : (string) $stock->product_id,
                (int) abs((int) $stock->quantity),
                $stock->claimed_from?->format('Y-m-d H:i') ?? 'immediate',
                $stock->expires_at?->format('Y-m-d H:i') ?? 'no expiry',
                $state,
                $stock->reference_type ? class_basename($stock->reference_type).'#'.substr((string) $stock->reference_id, 0, 8) : '—',
                $this->truncate((string) ($stock->note ?? ''), 28),
            ];
        })->all();

        $this->table(
            ['Product', 'Qty', 'Claim From', 'Expires', 'State', 'Reference', 'Note'],
            $rows,
        );

        $this->line('  <fg=gray>Showing '.$claims->count().' claim'.($claims->count() === 1 ? '' : 's').' (limit '.$limit.').</>');
        $this->newLine();

        return self::SUCCESS;
    }

    private function classify(ProductStock $stock, Carbon $now): string
    {
        if ($stock->claimed_from && $stock->claimed_from > $now) {
            return 'upcoming';
        }
        if ($stock->expires_at && $stock->expires_at <= $now) {
            return 'expired';
        }
        return 'active';
    }

    private function resolveProduct(string $identifier): ?Product
    {
        $model = config('shop.models.product', Product::class);

        return $model::query()
            ->where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->orWhere('sku', $identifier)
            ->orWhere('name', 'like', "%{$identifier}%")
            ->first();
    }

    private function truncate(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }
}
