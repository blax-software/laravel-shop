<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\LicenseSeat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a {@see LicenseSeat} is reclaimed (returned to the pool) or
 * retired (removed from it). The former holder's grants have been revoked by
 * the time this event runs; `$previousAssignee` is who lost access (may be null
 * if the seat was already free).
 */
class SeatRevoked
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public LicenseSeat $seat,
        public ?Model $previousAssignee = null,
    ) {}
}
