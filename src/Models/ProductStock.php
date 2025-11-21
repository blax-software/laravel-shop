<?php

namespace Blax\Shop\Models;

use Blax\Shop\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

class ProductStock extends Model
{
    use HasUuids;

    protected $fillable = [
        'product_id',
        'quantity',
        'type',
        'status',
        'reference_type',
        'reference_id',
        'expires_at',
        'note',
        'meta',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'expires_at' => 'datetime',
        'meta' => 'object',
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

        static::updated(function ($model) {
            if ($model->wasChanged('status') && $model->status === 'completed') {
                $model->releaseStock();
            }
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
        return $query->where('status', 'pending');
    }

    public function scopeReleased($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeExpired($query)
    {
        return $query->where('status', 'expired')
            ->orWhere(function ($q) {
                $q->where('status', 'pending')
                    ->whereNotNull('expires_at')
                    ->where('expires_at', '<=', now());
            });
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
        return $this->status === 'completed' ? $this->updated_at : null;
    }

    public function getUntilAtAttribute()
    {
        return $this->expires_at;
    }

    public static function reserve(
        Product $product,
        int $quantity,
        ?string $type = 'reservation',
        $reference = null,
        ?\DateTimeInterface $until = null,
        ?string $note = null
    ): ?self {
        return DB::transaction(function () use ($product, $quantity, $type, $reference, $until, $note) {
            if (!$product->decreaseStock($quantity)) {
                return null;
            }

            return self::create([
                'product_id' => $product->id,
                'quantity' => $quantity,
                'type' => $type,
                'status' => 'pending',
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->id,
                'expires_at' => $until,
                'note' => $note,
            ]);
        });
    }

    public function release(): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        return DB::transaction(function () {
            $this->product->increaseStock($this->quantity);

            $this->status = 'completed';
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
            && $this->status === 'pending'
            && $this->expires_at->isPast();
    }

    public function isActive(): bool
    {
        return $this->status === 'pending';
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

    protected function releaseStock(): void
    {
        if (!config('shop.stock.log_changes', true)) {
            return;
        }

        DB::table('product_stock_logs')->insert([
            'product_id' => $this->product_id,
            'quantity_change' => $this->quantity,
            'quantity_after' => $this->product->stock_quantity,
            'type' => 'release',
            'note' => 'Stock released from reservation',
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
}
