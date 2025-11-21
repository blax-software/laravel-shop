<?php

namespace Blax\Shop\Console\Commands;

use Blax\Shop\Models\ProductStock;
use Illuminate\Console\Command;

class ReleaseExpiredStocks extends Command
{
    protected $signature = 'shop:release-expired-stocks';

    protected $description = 'Release expired stock reservations back to inventory';

    public function handle(): int
    {
        if (!config('shop.stock.auto_release_expired', true)) {
            $this->info('Auto-release is disabled in config.');
            return self::SUCCESS;
        }

        $this->info('Checking for expired stock reservations...');

        $count = ProductStock::releaseExpired();

        $this->info("Released {$count} expired stock reservation(s).");

        return self::SUCCESS;
    }
}
