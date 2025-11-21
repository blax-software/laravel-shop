<?php

namespace Blax\Shop\Console\Commands;

use Blax\Shop\Models\ProductAction;
use Illuminate\Console\Command;

class ShopTestActionCommand extends Command
{
    protected $signature = 'shop:test-action
                            {action-id : The ID of the action to test}
                            {--sync : Execute synchronously instead of queuing}';

    protected $description = 'Test execute a product action';

    public function handle()
    {
        $actionId = $this->argument('action-id');
        $action = ProductAction::with('product')->find($actionId);

        if (!$action) {
            $this->error("Action with ID {$actionId} not found.");
            return 1;
        }

        $this->info("Testing action: {$action->action_class}");
        $this->info("Product: {$action->product->name} (ID: {$action->product_id})");
        $this->info("Event: {$action->event}");

        if (!$this->confirm('Do you want to proceed?')) {
            $this->info('Test cancelled.');
            return 0;
        }

        try {
            if ($this->option('sync')) {
                $namespace = config('shop.actions.namespace', 'App\\Jobs\\ProductAction');
                $action_job = $namespace . '\\' . $action->action_class;

                $params = [
                    'product' => $action->product,
                    'productPurchase' => null,
                    'event' => $action->event,
                    ...($action->parameters ?? []),
                ];

                (new $action_job(...$params))->handle();
                $this->info('Action executed synchronously.');
            } else {
                $action->execute($action->product, null, []);
                $this->info('Action dispatched to queue.');
            }

            $this->info('✓ Action test completed successfully.');
            return 0;
        } catch (\Exception $e) {
            $this->error('✗ Action test failed: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }
}
