<?php

declare(strict_types=1);

namespace Blax\Shop\Console\Commands;

use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Models\Product;
use Illuminate\Console\Command;

class ShopStocksCommand extends Command
{
    protected $signature = 'shop:stocks
                            {product? : Product ID, slug, SKU, or partial name. Omit to see a stock overview across all products.}
                            {--limit=20 : Maximum number of ledger entries to show in detail view}';

    protected $description = 'Show stock totals and the recent ledger for a product, or an overview across all products';

    public function handle(): int
    {
        $identifier = $this->argument('product');

        return $identifier
            ? $this->renderProductDetail((string) $identifier, max(1, (int) $this->option('limit')))
            : $this->renderOverview();
    }

    private function renderOverview(): int
    {
        $productModel = config('shop.models.product', Product::class);
        $products = $productModel::query()->orderBy('name')->get();

        if ($products->isEmpty()) {
            $this->info('No products found.');
            return self::SUCCESS;
        }

        $rows = $products->map(function (Product $product): array {
            $assigned = $product->manage_stock ? $this->assignedCapacity($product) : null;
            $used = $product->manage_stock ? $this->totalUsed($product) : null;
            $available = $product->getAvailableStock();
            $claimed = $product->getCurrentlyClaimedStock();
            $type = $product->type instanceof \BackedEnum ? $product->type->value : (string) ($product->type ?? '—');

            return [
                'id' => substr((string) $product->id, 0, 8).'…',
                'name' => $this->truncate((string) $product->name, 30),
                'type' => $type,
                'assigned' => $assigned === null ? '∞' : (string) $assigned,
                'used' => $used === null ? '—' : (string) $used,
                'available' => $available === PHP_INT_MAX ? '∞' : (string) $available,
                'claimed' => (string) $claimed,
            ];
        })->all();

        $this->newLine();
        $this->table(
            ['ID', 'Name', 'Type', 'Assigned', 'Used', 'Available', 'Claimed'],
            $rows,
        );
        $this->line('  <fg=gray>Total products: '.$products->count().'   '.
            'Run <fg=cyan>shop:stocks {product}</> for a detailed report.</>');
        $this->newLine();

        return self::SUCCESS;
    }

    private function renderProductDetail(string $identifier, int $limit): int
    {
        $product = $this->resolveProduct($identifier);
        if (! $product) {
            $this->error("No product matched '{$identifier}'.");
            return self::FAILURE;
        }

        $type = $product->type instanceof \BackedEnum ? $product->type->value : (string) ($product->type ?? '—');
        $sku = $product->sku ?: '—';

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>'.$product->name.'</>');
        $this->line('  <fg=gray>type:</> '.$type.'   <fg=gray>sku:</> '.$sku.'   <fg=gray>id:</> '.$product->id);
        $this->newLine();

        if (! $product->manage_stock) {
            $this->line('  <fg=green;options=bold>Stock management is OFF.</> <fg=gray>(unlimited availability)</>');
            $this->newLine();
            return self::SUCCESS;
        }

        $assigned = $this->assignedCapacity($product);
        $used = $this->totalUsed($product);
        $available = $product->getAvailableStock();
        $currentClaims = $product->getCurrentlyClaimedStock();
        $futureClaims = $product->getFutureClaimedStock();
        $activeAndPlanned = $product->getActiveAndPlannedClaimedStock();

        $this->renderTotalsBox([
            ['ASSIGNED', $assigned, 'cyan'],
            ['USED', $used, 'gray'],
            ['AVAILABLE', $available, $available > 0 ? 'green' : 'red'],
            ['CLAIMED NOW', $currentClaims, $currentClaims > 0 ? 'yellow' : 'gray'],
            ['CLAIMED LATER', $futureClaims, $futureClaims > 0 ? 'blue' : 'gray'],
            ['ACTIVE+PLANNED', $activeAndPlanned, 'magenta'],
        ]);

        $this->line('  <fg=gray>Recent stock ledger (newest first, capped at '.$limit.' entries):</>');
        $this->newLine();

        $ledger = $product->stocks()
            ->withoutGlobalScope('willExpire')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        if ($ledger->isEmpty()) {
            $this->line('  <fg=gray>(no ledger entries yet)</>');
            $this->newLine();
            return self::SUCCESS;
        }

        $this->table(
            ['When', 'Type', 'Status', 'Qty', 'Claim From', 'Expires', 'Note'],
            $ledger->map(fn ($s) => [
                $s->created_at?->format('Y-m-d H:i') ?? '—',
                $s->type instanceof \BackedEnum ? $s->type->value : (string) $s->type,
                $s->status instanceof \BackedEnum ? $s->status->value : (string) $s->status,
                (int) $s->quantity,
                $s->claimed_from?->format('Y-m-d H:i') ?? '—',
                $s->expires_at?->format('Y-m-d H:i') ?? '—',
                $this->truncate((string) ($s->note ?? ''), 30),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function totalUsed(Product $product): int
    {
        return (int) abs(
            (int) $product->stocks()
                ->withoutGlobalScope('willExpire')
                ->where('type', StockType::DECREASE->value)
                ->where('status', StockStatus::COMPLETED->value)
                ->sum('quantity')
        );
    }

    /**
     * Physical inventory the operator should see as "Assigned" — i.e. how many
     * copies the business actually owns.
     *
     * For non-loanable products this is just `getMaxStocksAttribute()` (sum of
     * INCREASE + RETURN entries). For loanable products that calc inflates
     * after every borrow→return cycle because the host's restock fires a
     * fresh INCREASE row; MayBeLoanableProduct's `total_quantity` accessor
     * sidesteps that by computing "available + active loans" instead. The
     * trait is mixed into Product unconditionally, so it's safe to consult
     * here for every product type.
     */
    private function assignedCapacity(Product $product): int
    {
        return (int) $product->total_quantity;
    }

    /**
     * @param  list<array{0: string, 1: int, 2: string}>  $boxes
     */
    private function renderTotalsBox(array $boxes): void
    {
        $boxWidth = 16;
        $rule = fn (string $l, string $j, string $r) => $l.implode($j, array_fill(0, count($boxes), str_repeat('─', $boxWidth))).$r;

        $labelLine = '│';
        $valueLine = '│';
        foreach ($boxes as [$label, $value, $color]) {
            $display = $value === PHP_INT_MAX ? '∞' : (string) $value;
            $labelLine .= "<fg=$color>".$this->pad($label, $boxWidth).'</>│';
            $valueLine .= "<fg=$color;options=bold>".$this->pad($display, $boxWidth).'</>│';
        }

        $this->line('  '.$rule('┌', '┬', '┐'));
        $this->line('  '.$labelLine);
        $this->line('  '.$valueLine);
        $this->line('  '.$rule('└', '┴', '┘'));
        $this->newLine();
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

    private function pad(string $value, int $width): string
    {
        $len = mb_strlen($value);
        if ($len >= $width) {
            return mb_substr($value, 0, $width);
        }
        $extra = $width - $len;
        $left = (int) floor($extra / 2);

        return str_repeat(' ', $left).$value.str_repeat(' ', $extra - $left);
    }

    private function truncate(string $value, int $max): string
    {
        return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1).'…' : $value;
    }
}
