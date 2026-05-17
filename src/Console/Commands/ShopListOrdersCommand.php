<?php

declare(strict_types=1);

namespace Blax\Shop\Console\Commands;

use Illuminate\Console\Command;

class ShopListOrdersCommand extends Command
{
    protected $signature = 'shop:list:orders
                            {--customer= : Filter by customer ID (polymorphic)}
                            {--status= : Filter by order status}
                            {--limit=50 : Maximum number of orders to display}';

    protected $description = 'List orders, optionally filtered by customer or status';

    public function handle(): int
    {
        $model = config('shop.models.order');
        $query = $model::query()->latest();

        if ($customer = $this->option('customer')) {
            $query->where('customer_id', $customer);
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
            (string) ($order->order_number ?? '—'),
            $order->customer_id
                ? class_basename((string) $order->customer_type).'#'.substr((string) $order->customer_id, 0, 8)
                : '—',
            $order->status instanceof \BackedEnum ? $order->status->value : (string) ($order->status ?? '—'),
            number_format(((int) $order->amount_total) / 100, 2),
            (string) ($order->currency ?? '—'),
            $order->created_at?->format('Y-m-d H:i') ?? '—',
        ]);

        $this->table(['ID', 'Number', 'Customer', 'Status', 'Total', 'Currency', 'Created'], $rows);
        $this->info("Showing {$orders->count()} order(s)");

        return self::SUCCESS;
    }
}
