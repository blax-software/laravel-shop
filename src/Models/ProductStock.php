<?php

namespace Blax\Shop\Models;

use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Models\Product;
use Blax\Workkit\Traits\HasExpiration;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

/**
 * ProductStock Model
 * 
 * Represents stock movements and claims for products. This model tracks:
 * - Stock increases/decreases (physical inventory changes)
 * - Stock claims (reservations/bookings for future use)
 * - Temporary vs permanent stock allocations
 * - Date-based stock availability for bookings/rentals
 * 
 * Stock Flow:
 * 1. INCREASE/DECREASE: Physical stock changes (COMPLETED status)
 *    - Positive quantity = stock added to inventory
 *    - Negative quantity = stock removed from inventory
 * 
 * 2. CLAIMED: Temporary allocation of stock (PENDING status)
 *    - Creates a DECREASE entry (negative quantity, COMPLETED)
 *    - Creates a CLAIMED entry (positive quantity, PENDING)
 *    - Net effect: stock is allocated but tracked separately
 *    - Can have claimed_from (when claim starts) and expires_at (when claim ends)
 *    - When released: CLAIMED status changes to COMPLETED
 * 
 * Key Concepts:
 * - Physical Stock: Sum of all COMPLETED status stocks (includes INCREASE/DECREASE)
 * - Available Stock: Physical stock minus currently PENDING claims
 * - Claimed Stock: Sum of PENDING claims (temporarily unavailable)
 * - Available on Date: Available stock considering only claims active on specific date
 */
class ProductStock extends Model
{
    use HasUuids, HasExpiration;

    protected $fillable = [
        'product_id',
        'quantity',
        'type',
        'status',
        'reference_type',
        'reference_id',
        'claimed_from',
        'expires_at',
        'note',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'type' => StockType::class,
        'status' => StockStatus::class,
        'claimed_from' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable(config('shop.tables.product_stocks', 'product_stocks'));
    }

    protected static function booted()
    {
        static::created(function ($model) {
            $model->logStockChange();
        });
    }

    /**
     * Get the product this stock entry belongs to
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(config('shop.models.product', Product::class));
    }

    /**
     * Get the related model (e.g., Order, User, Booking) that triggered this stock change
     * Used to track what caused the stock movement or claim
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope: Get stock entries that are still pending (claims not yet released)
     */
    public function scopePending($query)
    {
        return $query->where('status', StockStatus::PENDING->value);
    }

    /**
     * Scope: Get stock entries that have been released/completed
     */
    public function scopeReleased($query)
    {
        return $query->where('status', StockStatus::COMPLETED->value);
    }

    /**
     * Scope: Get temporary stock entries (with expiration date)
     */
    public function scopeTemporary($query)
    {
        return $query->whereNotNull('expires_at');
    }

    /**
     * Scope: Get permanent stock entries (no expiration date)
     */
    public function scopePermanent($query)
    {
        return $query->whereNull('expires_at');
    }

    /**
     * Backward compatibility accessor: Get when the stock was released
     * Returns updated_at if status is COMPLETED, otherwise null
     */
    public function getReleasedAtAttribute()
    {
        return $this->status === StockStatus::COMPLETED ? $this->updated_at : null;
    }

    /**
     * Backward compatibility accessor: Alias for expires_at
     */
    public function getUntilAtAttribute()
    {
        return $this->expires_at;
    }

    /**
     * Claim stock for a product (reservation/booking)
     * 
     * This creates a two-part entry:
     * 1. DECREASE entry (negative quantity, COMPLETED) - removes from physical stock
     * 2. CLAIMED entry (positive quantity, PENDING) - tracks the claim
     * 
     * @param Product $product The product to claim stock from
     * @param int $quantity Amount of stock to claim
     * @param mixed $reference Optional reference model (Order, Booking, etc.)
     * @param \DateTimeInterface|null $from When the claim starts (null = immediately)
     * @param \DateTimeInterface|null $until When the claim expires (null = permanent)
     * @param string|null $note Optional note about the claim
     * @return self|null The created claim entry, or null if insufficient stock
     */
    public static function claim(
        Product $product,
        int $quantity,
        $reference = null,
        ?\DateTimeInterface $from = null,
        ?\DateTimeInterface $until = null,
        ?string $note = null
    ): ?self {
        return DB::transaction(function () use ($product, $quantity, $reference, $from, $until, $note) {
            if (!$product->decreaseStock($quantity)) {
                return null;
            }

            return self::create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'type' => StockType::CLAIMED,
                'status' => StockStatus::PENDING,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->id,
                'claimed_from' => $from,
                'expires_at' => $until,
                'note' => $note,
            ]);
        });
    }

    /**
     * Release a claimed stock entry
     * 
     * Changes status from PENDING to COMPLETED, marking the claim as released.
     * Note: This does NOT add stock back - the stock remains decreased.
     * To return stock to inventory, use increaseStock() on the product.
     * 
     * @return bool True if released successfully, false if not pending
     */
    public function release(): bool
    {
        if ($this->status !== StockStatus::PENDING) {
            return false;
        }

        return DB::transaction(function () {
            $this->status = StockStatus::COMPLETED;
            $this->save();

            return true;
        });
    }

    /**
     * Check if this is a permanent stock entry (no expiration)
     */
    public function isPermanent(): bool
    {
        return is_null($this->expires_at);
    }

    /**
     * Check if this is a temporary stock entry (has expiration date)
     */
    public function isTemporary(): bool
    {
        return !is_null($this->expires_at);
    }

    /**
     * Check if this temporary claim has expired
     * Only applies to PENDING claims with past expiration dates
     */
    public function isExpired(): bool
    {
        return $this->isTemporary()
            && $this->status === StockStatus::PENDING
            && $this->expires_at->isPast();
    }

    /**
     * Check if this claim is currently active (PENDING status)
     */
    public function isActive(): bool
    {
        return $this->status === StockStatus::PENDING;
    }

    /**
     * Log stock changes to the product_stock_logs table
     * Provides audit trail of all stock movements
     */
    protected function logStockChange(): void
    {
        if (!config('shop.stock.log_changes', true)) {
            return;
        }

        DB::table('product_stock_logs')->insert([
            'product_id' => $this->product_id,
            'quantity_change' => -$this->quantity,
            'quantity_after' => $this->product->stock_quantity,
            'type' => $this->type,
            'note' => $this->note,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Release all expired stock claims
     * Used by scheduled command to automatically release expired claims
     * 
     * @return int Number of claims released
     */
    public static function releaseExpired(): int
    {
        $expired = self::expired()->get();
        $count = 0;

        foreach ($expired as $stock) {
            if ($stock->release()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Scope: Get completed/available stock entries
     * These are physical stock changes (INCREASE/DECREASE) that have been finalized
     */
    public static function scopeAvailable($query)
    {
        return $query->where('status', StockStatus::COMPLETED->value);
    }

    /**
     * Scope: Get active (pending) claimed stock entries
     * These represent stock currently claimed but not yet released
     */
    public static function scopeAvailableClaims($query)
    {
        return $query->where('type', StockType::CLAIMED->value)->where('status', StockStatus::PENDING->value);
    }

    /**
     * Get all active claims (alias for availableClaims)
     */
    public static function claims()
    {
        return self::availableClaims();
    }

    /**
     * Scope: Get stock claims that are active on a specific date
     * 
     * Used for date-based availability checking (bookings, rentals, etc.)
     * A claim is considered active on a date if:
     * - It has claimed_from <= date (or null = immediate) AND
     * - It has expires_at >= date (or null = permanent)
     * - Status is PENDING
     * 
     * Examples:
     * - Claim from day 5-10: Active on days 5,6,7,8,9,10
     * - Claim with no claimed_from, expires day 10: Active from creation until day 10
     * - Claim from day 5, no expires_at: Active from day 5 forever
     * 
     * @param \DateTimeInterface $date The date to check availability for
     */
    public static function scopeAvailableOnDate($query, \DateTimeInterface $date)
    {
        return $query->where('type', StockType::CLAIMED->value)
            ->where('status', StockStatus::PENDING->value)
            ->where(function ($q) use ($date) {
                $q->where(function ($subQuery) use ($date) {
                    // Claimed items with claimed_from set
                    $subQuery->whereNotNull('claimed_from')
                        ->where('claimed_from', '<=', $date)
                        ->where(function ($dateQuery) use ($date) {
                            $dateQuery->whereNull('expires_at')
                                ->orWhere('expires_at', '>=', $date);
                        });
                })->orWhere(function ($subQuery) use ($date) {
                    // Claimed items without claimed_from (immediately claimed)
                    $subQuery->whereNull('claimed_from')
                        ->where(function ($dateQuery) use ($date) {
                            $dateQuery->whereNull('expires_at')
                                ->orWhere('expires_at', '>=', $date);
                        });
                });
            });
    }
}
