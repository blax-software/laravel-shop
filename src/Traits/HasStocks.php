<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Enums\StockStatus;
use Blax\Shop\Enums\StockType;
use Blax\Shop\Exceptions\NotEnoughStockException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

trait HasStocks
{
    public function stocks(): HasMany
    {
        return $this->hasMany(config('shop.models.product_stock', 'Blax\Shop\Models\ProductStock'));
    }

    public function getAvailableStocksAttribute(): int
    {
        return $this->stocks()
            ->available()
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->sum('quantity') ?? 0;
    }

    public function isInStock(): bool
    {
        if (!$this->manage_stock) {
            return true;
        }

        return $this->getAvailableStock() > 0;
    }

    public function decreaseStock(int $quantity = 1, Carbon|null $until = null): bool
    {
        if (!$this->manage_stock) {
            return true;
        }

        if ($this->AvailableStocks < $quantity) {
            return throw new NotEnoughStockException("Not enough stock available for product ID {$this->id}");
        }

        $this->stocks()->create([
            'quantity' => -$quantity,
            'type' => StockType::DECREASE,
            'status' => StockStatus::COMPLETED,
            'expires_at' => $until,
        ]);

        $this->logStockChange(-$quantity, 'decrease');

        $this->save();

        return true;
    }

    public function increaseStock(int $quantity = 1): bool
    {
        if (!$this->manage_stock) {
            return false;
        }

        $this->stocks()->create([
            'quantity' => $quantity,
            'type' => StockType::INCREASE,
            'status' => StockStatus::COMPLETED,
        ]);

        $this->logStockChange($quantity, 'increase');

        $this->save();

        return true;
    }

    public function adjustStock(
        StockType $type,
        int $quantity,
        \DateTimeInterface|null $until = null,
        ?StockStatus $status = null,
    ) {
        if (!$this->manage_stock) {
            return false;
        }

        $this->stocks()->create([
            'quantity' => $type === StockType::INCREASE ? $quantity : -$quantity,
            'type' => $type,
            'status' => $status ?? StockStatus::COMPLETED,
            'expires_at' => $until,
        ]);

        $this->logStockChange($type === StockType::INCREASE ? $quantity : -$quantity, 'adjust');

        $this->save();

        return true;
    }

    public function reserveStock(
        int $quantity,
        $reference = null,
        ?\DateTimeInterface $until = null,
        ?string $note = null
    ): ?\Blax\Shop\Models\ProductStock {

        if (!$this->manage_stock) {
            return null;
        }

        $stockModel = config('shop.models.product_stock', 'Blax\Shop\Models\ProductStock');

        return $stockModel::reserve(
            $this,
            $quantity,
            $reference,
            $until,
            $note
        );
    }

    public function getAvailableStock(): int
    {
        if (!$this->manage_stock) {
            return PHP_INT_MAX;
        }

        return max(0, $this->AvailableStocks);
    }

    public function getReservedStock(): int
    {
        return $this->activeStocks()->sum('quantity');
    }

    protected function logStockChange(int $quantityChange, string $type): void
    {
        DB::table('product_stock_logs')->insert([
            'product_id' => $this->id,
            'quantity_change' => $quantityChange,
            'quantity_after' => $this->stock_quantity,
            'type' => $type,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function scopeInStock($query)
    {
        return $query->where(function ($q) {
            $q->where('manage_stock', false)
                ->orWhere(function ($q2) {
                    $q2->where('manage_stock', true)
                        ->whereRaw("(SELECT SUM(quantity) FROM " . config('shop.tables.product_stocks', 'product_stocks') . " WHERE product_id = " . config('shop.tables.products', 'products') . ".id) > 0");
                });
        });
    }

    public function scopeLowStock($query)
    {
        $stockTable = config('shop.tables.product_stocks', 'product_stocks');
        $productTable = config('shop.tables.products', 'products');

        return $query->where('manage_stock', true)
            ->whereNotNull('low_stock_threshold')
            ->whereRaw("(SELECT COALESCE(SUM(quantity), 0) FROM {$stockTable} WHERE {$stockTable}.product_id = {$productTable}.id AND {$stockTable}.status IN ('completed', 'pending') AND ({$stockTable}.expires_at IS NULL OR {$stockTable}.expires_at > ?)) <= {$productTable}.low_stock_threshold", [
                now()
            ]);
    }

    public function isLowStock(): bool
    {
        if (!$this->manage_stock || !$this->low_stock_threshold) {
            return false;
        }

        return $this->getAvailableStock() <= $this->low_stock_threshold;
    }

    public function reservations()
    {
        $stockModel = config('shop.models.product_stock', 'Blax\Shop\Models\ProductStock');

        return $stockModel::reservations()
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where('product_id', $this->id);
    }
}
