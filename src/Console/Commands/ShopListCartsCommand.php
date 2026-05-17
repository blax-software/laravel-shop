<?php

declare(strict_types=1);

namespace Blax\Shop\Console\Commands;

use Illuminate\Console\Command;

class ShopListCartsCommand extends Command
{
    protected $signature = 'shop:list:carts
                            {--guest : Only show guest (session-based) carts}
                            {--with-items : Include item counts}
                            {--limit=50 : Maximum number of carts to display}';

    protected $description = 'List shopping carts (active or guest), optionally with item counts';

    public function handle(): int
    {
        $model = config('shop.models.cart');
        $query = $model::query()->latest();

        if ($this->option('guest')) {
            $query->whereNull('customer_id');
        }
        if ($this->option('with-items')) {
            $query->withCount('items');
        }

        $limit = max(1, (int) $this->option('limit'));
        $carts = $query->limit($limit)->get();

        if ($carts->isEmpty()) {
            $this->info('No carts found.');
            return self::SUCCESS;
        }

        $headers = ['ID', 'Customer', 'Session', 'Status', 'Last Activity'];
        if ($this->option('with-items')) {
            $headers[] = 'Items';
        }

        $rows = $carts->map(function ($cart) {
            $customer = $cart->customer_id
                ? class_basename((string) $cart->customer_type).'#'.substr((string) $cart->customer_id, 0, 8)
                : '<guest>';
            $status = $cart->status instanceof \BackedEnum ? $cart->status->value : (string) ($cart->status ?? '—');

            $row = [
                substr((string) $cart->id, 0, 8).'…',
                $customer,
                $cart->session_id ? substr((string) $cart->session_id, 0, 12).'…' : '—',
                $status,
                $cart->last_activity_at?->format('Y-m-d H:i') ?? '—',
            ];
            if ($this->option('with-items')) {
                $row[] = (int) ($cart->items_count ?? 0);
            }
            return $row;
        });

        $this->table($headers, $rows);
        $this->info("Showing {$carts->count()} cart(s)");

        return self::SUCCESS;
    }
}
