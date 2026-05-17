<?php

declare(strict_types=1);

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

        // The ProductAction column is `active`, not `enabled` — the old code
        // wrote to a non-existent attribute, which silently no-op'd (or threw
        // a SQL error in strict mode). The user-facing verbs stay
        // "enabled"/"disabled" since that's what operators understand, but the
        // column we touch is `active`.
        if ($this->option('enable')) {
            $action->active = true;
            $status = 'enabled';
        } elseif ($this->option('disable')) {
            $action->active = false;
            $status = 'disabled';
        } else {
            $action->active = ! $action->active;
            $status = $action->active ? 'enabled' : 'disabled';
        }

        $action->save();

        $this->info("Action #{$action->id} ({$action->class}) has been {$status}.");

        return 0;
    }
}
