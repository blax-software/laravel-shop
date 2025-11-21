<?php

namespace Blax\Shop\Console\Commands;

use Blax\Shop\Models\ProductAction;
use Illuminate\Console\Command;

class ShopToggleActionCommand extends Command
{
    protected $signature = 'shop:toggle-action
                            {action-id : The ID of the action to toggle}
                            {--enable : Enable the action}
                            {--disable : Disable the action}';

    protected $description = 'Enable or disable a product action';

    public function handle()
    {
        $actionId = $this->argument('action-id');
        $action = ProductAction::find($actionId);

        if (!$action) {
            $this->error("Action with ID {$actionId} not found.");
            return 1;
        }

        if ($this->option('enable')) {
            $action->enabled = true;
            $status = 'enabled';
        } elseif ($this->option('disable')) {
            $action->enabled = false;
            $status = 'disabled';
        } else {
            $action->enabled = !$action->enabled;
            $status = $action->enabled ? 'enabled' : 'disabled';
        }

        $action->save();

        $this->info("Action #{$action->id} ({$action->action_class}) has been {$status}.");

        return 0;
    }
}
