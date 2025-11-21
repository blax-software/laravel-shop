<?php

namespace Blax\Shop\Console\Commands;

use Blax\Shop\Models\ProductAction;
use Illuminate\Console\Command;

class ShopAvailableActionsCommand extends Command
{
    protected $signature = 'shop:available-actions';

    protected $description = 'List all available action classes that can be used';

    public function handle()
    {
        $actions = ProductAction::getAvailableActions();

        if (empty($actions)) {
            $this->warn('No action classes found.');
            $this->info('Make sure auto_discover is enabled in config/shop.php');
            $this->info('Path: ' . config('shop.actions.path', app_path('Jobs/ProductAction')));
            return 0;
        }

        $this->info('Available Action Classes:');
        $this->newLine();

        foreach ($actions as $className => $parameters) {
            $this->line("• <fg=green>{$className}</>");

            if (!empty($parameters)) {
                $this->line('  Parameters:');
                foreach ($parameters as $param => $description) {
                    $this->line("    - {$param}: {$description}");
                }
            } else {
                $this->line('  No parameters');
            }

            $this->newLine();
        }

        $this->info("Total: " . count($actions) . " action class(es)");

        return 0;
    }
}
