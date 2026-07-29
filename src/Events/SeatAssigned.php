<?php

declare(strict_types=1);

namespace Blax\Shop\Events;

use Blax\Shop\Models\LicenseSeat;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched when a previously free {@see LicenseSeat} is handed to a user.
 * The product's grant actions have already been fired against the assignee by
 * the time this event runs.
 */
class SeatAssigned
{
    use Dispatchable, SerializesModels;

    public function __construct(public LicenseSeat $seat) {}
}
