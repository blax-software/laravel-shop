<?php

declare(strict_types=1);

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

        $this->info("Testing action: {$action->class}");
        $this->info("Product: {$action->product->name} (ID: {$action->product_id})");
        $this->info("Event: {$action->event}");

        if (!$this->confirm('Do you want to proceed?')) {
            $this->info('Test cancelled.');
            return 0;
        }

        try {
            if ($this->option('sync')) {
                // Resolve the action class. If a fully-qualified name is set
                // on `class`, use it as-is; otherwise prefix the configured
                // actions namespace so short names still resolve.
                $actionClass = $action->class;
                if (! str_contains($actionClass, '\\')) {
                    $namespace = config('shop.actions.namespace', 'App\\Jobs\\ProductAction');
                    $actionClass = $namespace . '\\' . $actionClass;
                }

                $params = [
                    'product' => $action->product,
                    'productPurchase' => null,
                    'event' => $action->event,
                    ...($action->parameters ?? []),
                ];

                $instance = new $actionClass(...$params);
                if (method_exists($instance, 'handle')) {
                    $instance->handle();
                } elseif (is_callable($instance)) {
                    $instance();
                } else {
                    throw new \RuntimeException("Action class {$actionClass} is neither callable nor has a handle() method.");
                }
                $this->info('Action executed synchronously.');
            } else {
                // Run the action through the package's normal runner — which
                // honours defer/method/parameters and writes a ProductActionRun
                // row. ProductAction::callForProduct() looks up matching
                // actions by event; passing the action's own first event
                // guarantees this single action fires.
                ProductAction::callForProduct($action->product, $action->event, null, []);
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
