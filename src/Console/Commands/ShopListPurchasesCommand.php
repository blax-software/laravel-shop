<?php

namespace Blax\Shop\Console\Commands;

use Illuminate\Console\Command;

class ShopListPurchasesCommand extends Command
{
    protected $signature = 'shop:list-purchases
                            {product? : Product ID to filter by}
                            {--user= : Filter by user ID}
                            {--status= : Filter by status}
                            {--limit=50 : Number of purchases to show}';

    protected $description = 'List product purchases';

    public function handle()
    {
        $purchaseModel = config('shop.models.product_purchase');
        $query = $purchaseModel::with(['product', 'user']);

        if ($productId = $this->argument('product')) {
            $query->where('product_id', $productId);
        }

        if ($userId = $this->option('user')) {
            $query->where('user_id', $userId);
        }

        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $limit = (int) $this->option('limit');
        $purchases = $query->latest()->limit($limit)->get();

        if ($purchases->isEmpty()) {
            $this->info('No purchases found.');
            return 0;
        }

        $headers = ['ID', 'Product', 'User', 'Price', 'Status', 'Date'];

        $rows = $purchases->map(function ($purchase) {
            return [
                $purchase->id,
                $purchase->product->name ?? "ID: {$purchase->product_id}",
                $purchase->user->name ?? "ID: {$purchase->user_id}",
                $purchase->price,
                $purchase->status ?? 'N/A',
                $purchase->created_at->format('Y-m-d H:i:s'),
            ];
        });

        $this->table($headers, $rows);
        $this->info("Showing {$purchases->count()} purchase(s)");

        return 0;
    }
}
