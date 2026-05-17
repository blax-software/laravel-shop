<?php

declare(strict_types=1);

namespace Blax\Shop\Enums;

/**
 * StockType — the kind of movement a {@see \Blax\Shop\Models\ProductStock}
 * row represents.
 *
 * Two-axis classification:
 *
 *   1. Sign of the stock movement
 *      INCREASE / RETURN  → positive (stock added)
 *      DECREASE           → negative (stock removed)
 *      CLAIMED            → positive quantity but stored as a PENDING
 *                          reservation that nets to negative against
 *                          available stock until the claim is released
 *      PHYSICALLY_CLAIMED → same as CLAIMED, but never auto-released by
 *                          {@see \Blax\Shop\Models\ProductStock::releaseExpired()}.
 *                          Used for loans: the borrower physically has the
 *                          item until they return it — the expires_at column
 *                          carries the due date for overdue tracking, not a
 *                          release deadline.
 *
 *   2. Release model
 *      INCREASE / DECREASE / RETURN  → COMPLETED at write time, permanent
 *      CLAIMED                       → PENDING, auto-released at expires_at
 *      PHYSICALLY_CLAIMED            → PENDING, manual release only
 *
 * The two claim types share availability semantics — both subtract from
 * `available` and both contribute to `currently_claimed` — so most queries
 * filter by {@see self::claimTypeValues()} rather than a single case.
 */
enum StockType: string
{
    case CLAIMED = 'claimed';
    case PHYSICALLY_CLAIMED = 'physically_claimed';
    case RETURN = 'return';
    case INCREASE = 'increase';
    case DECREASE = 'decrease';

    public function label(): string
    {
        return match ($this) {
            self::CLAIMED => 'Claimed',
            self::PHYSICALLY_CLAIMED => 'Physically claimed',
            self::RETURN => 'Return',
            self::INCREASE => 'Increase',
            self::DECREASE => 'Decrease',
        };
    }

    /**
     * The claim-style types — both reserve stock against availability and
     * keep a PENDING row in the ledger until released. CLAIMED auto-releases
     * at `expires_at`; PHYSICALLY_CLAIMED is manual-release only.
     *
     * @return array<int, self>
     */
    public static function claimTypes(): array
    {
        return [self::CLAIMED, self::PHYSICALLY_CLAIMED];
    }

    /**
     * Same as {@see self::claimTypes()} but as string values, for use in
     * `whereIn(...)` SQL clauses.
     *
     * @return array<int, string>
     */
    public static function claimTypeValues(): array
    {
        return [self::CLAIMED->value, self::PHYSICALLY_CLAIMED->value];
    }

    public function isClaim(): bool
    {
        return in_array($this, self::claimTypes(), strict: true);
    }
}
