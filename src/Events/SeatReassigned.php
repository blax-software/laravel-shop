<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\LicenseSeat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when an already-held {@see LicenseSeat} is moved from one user to
 * another in a single operation. The previous holder's grants have been revoked
 * and the new holder's granted by the time this event runs.
 */
class SeatReassigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public LicenseSeat $seat,
        public ?Model $previousAssignee = null,
    ) {}
}
