<?php

declare(strict_types=1);

namespace Blax\Shop\Enums;

/**
 * Lifecycle of a single {@see \Blax\Shop\Models\LicenseSeat}.
 *
 *  - UNASSIGNED: a free seat in the pool, ready to be handed to a user.
 *  - ASSIGNED:   currently held by a user, who has the product's grants.
 *  - REVOKED:    the seat itself was retired (pool shrank / subscription
 *                canceled) and can no longer be assigned. Distinct from
 *                *reclaiming* a seat, which returns it to UNASSIGNED so it can
 *                be handed to someone else.
 */
enum SeatStatus: string
{
    case UNASSIGNED = 'unassigned';
    case ASSIGNED = 'assigned';
    case REVOKED = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::UNASSIGNED => 'Unassigned',
            self::ASSIGNED => 'Assigned',
            self::REVOKED => 'Revoked',
        };
    }
}
