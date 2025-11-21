<?php

namespace Blax\Shop\Console\Commands;

use Blax\Shop\Models\ProductAction;
use Illuminate\Console\Command;

class ShopListActionsCommand extends Command
{
    protected $signature = 'shop:list-actions
                            {product? : Product ID to filter by}
                            {--event= : Filter by event type}
                            {--enabled : Only show enabled actions}
                            {--disabled : Only show disabled actions}';

    protected $description = 'List all product actions';

    public function handle()
    {
        $query = ProductAction::with('product');

        if ($productId = $this->argument('product')) {
            $query->where('product_id', $productId);
        }

        if ($event = $this->option('event')) {
            $query->where('event', $event);
        }

        if ($this->option('enabled')) {
            $query->where('enabled', true);
        } elseif ($this->option('disabled')) {
            $query->where('enabled', false);
        }

        $actions = $query->orderBy('product_id')->orderBy('priority')->get();

        if ($actions->isEmpty()) {
            $this->info('No actions found.');
            return 0;
        }

        $headers = ['ID', 'Product', 'Event', 'Action Class', 'Priority', 'Enabled', 'Parameters'];

        $rows = $actions->map(function ($action) {
            return [
                $action->id,
                $action->product->name ?? "ID: {$action->product_id}",
                $action->event,
                $action->action_class,
                $action->priority,
                $action->enabled ? '✓' : '✗',
                json_encode($action->parameters),
            ];
        });

        $this->table($headers, $rows);
        $this->info("Total actions: {$actions->count()}");

        return 0;
    }
}
