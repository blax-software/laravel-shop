<?php

declare(strict_types=1);

namespace Blax\Shop\Console\Commands;

use Illuminate\Console\Command;

class ShopListOrdersCommand extends Command
{
    protected $signature = 'shop:list:orders
                            {--user= : Filter by user ID}
                            {--status= : Filter by order status}
                            {--limit=50 : Maximum number of orders to display}';

    protected $description = 'List orders, optionally filtered by user or status';

    public function handle(): int
    {
        $model = config('shop.models.order');
        $query = $model::query()->latest();

        if ($user = $this->option('user')) {
            $query->where('user_id', $user);
        }
        if ($status = $this->option('status')) {
            $query->where('status', $status);
        }

        $limit = max(1, (int) $this->option('limit'));
        $orders = $query->limit($limit)->get();

        if ($orders->isEmpty()) {
            $this->info('No orders found.');
            return self::SUCCESS;
        }

        $rows = $orders->map(fn ($order) => [
            substr((string) $order->id, 0, 8).'…',
            $order->user_id ? substr((string) $order->user_id, 0, 8).'…' : '—',
            $order->status instanceof \BackedEnum ? $order->status->value : (string) ($order->status ?? '—'),
            $order->total ?? '—',
            $order->currency ?? '—',
            $order->created_at?->format('Y-m-d H:i') ?? '—',
        ]);

        $this->table(['ID', 'User', 'Status', 'Total', 'Currency', 'Created'], $rows);
        $this->info("Showing {$orders->count()} order(s)");

        return self::SUCCESS;
    }
}
