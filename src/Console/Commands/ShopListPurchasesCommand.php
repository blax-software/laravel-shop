<?php

declare(strict_types=1);

namespace Blax\Shop\Console\Commands;

use Illuminate\Console\Command;

class ShopListPurchasesCommand extends Command
{
    protected $signature = 'shop:list:purchases
                            {product? : Filter by purchasable (Product) ID}
                            {--purchaser= : Filter by purchaser ID (any polymorphic type)}
                            {--status= : Filter by purchase status}
                            {--limit=50 : Number of purchases to show}';

    protected $description = 'List product purchases (loans, bookings, sales, …)';

    public function handle(): int
    {
        $purchaseModel = config('shop.models.product_purchase');
        $query = $purchaseModel::with(['purchasable', 'purchaser', 'price']);

        if ($productId = $this->argument('product')) {
            $query->where('purchasable_id', $productId);
        }
        if ($purchaserId = $this->option('purchaser')) {
            $query->where('purchaser_id', $purchaserId);
        }
        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $limit = max(1, (int) $this->option('limit'));
        $purchases = $query->latest()->limit($limit)->get();

        if ($purchases->isEmpty()) {
            $this->info('No purchases found.');
            return self::SUCCESS;
        }

        $rows = $purchases->map(fn ($purchase) => [
            substr((string) $purchase->id, 0, 8).'…',
            $this->describePurchasable($purchase),
            $this->describePurchaser($purchase),
            $this->money((int) $purchase->amount),
            $this->money((int) $purchase->amount_paid),
            $this->enumValue($purchase->status),
            $purchase->created_at?->format('Y-m-d H:i') ?? '—',
        ]);

        $this->table(
            ['ID', 'Item', 'Purchaser', 'Amount', 'Paid', 'Status', 'Created'],
            $rows,
        );
        $this->info("Showing {$purchases->count()} purchase(s)");

        return self::SUCCESS;
    }

    private function describePurchasable($purchase): string
    {
        $item = $purchase->purchasable;
        if ($item === null) {
            return 'ID: '.substr((string) $purchase->purchasable_id, 0, 8).'…';
        }
        $name = $item->name ?? class_basename($item::class);
        return $name;
    }

    private function describePurchaser($purchase): string
    {
        $by = $purchase->purchaser;
        if ($by === null) {
            return 'ID: '.substr((string) $purchase->purchaser_id, 0, 8).'…';
        }
        $label = $by->name ?? $by->email ?? class_basename($by::class);
        return $label;
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2);
    }

    private function enumValue(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }
        return $value === null ? '—' : (string) $value;
    }
}
