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
        'expires_at',
        'note',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'type' => StockType::class,
        'status' => StockStatus::class,
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

    public function product(): BelongsTo
    {
        return $this->belongsTo(config('shop.models.product', Product::class));
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopePending($query)
    {
        return $query->where('status', StockStatus::PENDING->value);
    }

    public function scopeReleased($query)
    {
        return $query->where('status', StockStatus::COMPLETED->value);
    }

    public function scopeTemporary($query)
    {
        return $query->whereNotNull('expires_at');
    }

    public function scopePermanent($query)
    {
        return $query->whereNull('expires_at');
    }

    // Backward compatibility accessors
    public function getReleasedAtAttribute()
    {
        return $this->status === StockStatus::COMPLETED ? $this->updated_at : null;
    }

    public function getUntilAtAttribute()
    {
        return $this->expires_at;
    }

    public static function reserve(
        Product $product,
        int $quantity,
        $reference = null,
        ?\DateTimeInterface $until = null,
        ?string $note = null
    ): ?self {
        return DB::transaction(function () use ($product, $quantity, $reference, $until, $note) {
            if (!$product->decreaseStock($quantity)) {
                return null;
            }

            return self::create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'type' => StockType::RESERVATION,
                'status' => StockStatus::PENDING,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->id,
                'expires_at' => $until,
                'note' => $note,
            ]);
        });
    }

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

    public function isPermanent(): bool
    {
        return is_null($this->expires_at);
    }

    public function isTemporary(): bool
    {
        return !is_null($this->expires_at);
    }

    public function isExpired(): bool
    {
        return $this->isTemporary()
            && $this->status === StockStatus::PENDING
            && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return $this->status === StockStatus::PENDING;
    }

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

    public static function scopeAvailable($query)
    {
        return $query->where('status', StockStatus::COMPLETED->value);
    }

    public static function scopeAvailableReservations($query)
    {
        return $query->where('type', StockType::RESERVATION->value)->where('status', StockStatus::PENDING->value);
    }

    public static function reservations()
    {
        return self::availableReservations();
    }
}
