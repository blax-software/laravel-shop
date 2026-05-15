<?php

namespace Blax\Shop\Traits;

use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Events\LoanCreated;
use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Models\ProductPurchase;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Drop on a {@see \Blax\Shop\Models\Product} subclass to declare it a
 * loanable item. Provides:
 *
 *  - Sensible defaults on `creating` (type=LOANABLE, manage_stock=true,
 *    status=PUBLISHED, is_visible=true) so callers can omit the e-commerce
 *    columns and just give the product its domain attributes.
 *
 *  - A virtual `total_quantity` setter that's translated into a stock
 *    INCREASE entry the moment the row is saved — so a single
 *    `Book::create(['title' => …, 'total_quantity' => 3])` produces a
 *    book with three copies in stock.
 *
 *  - `checkOutTo($borrower, $weeks, $price)` — atomic decrement + purchase
 *    creation + LoanCreated event dispatch, all in one call. Replaces the
 *    DB::transaction + decreaseStock + purchases()->create + event boilerplate
 *    every host controller would otherwise repeat.
 *
 * Pair with {@see HasLoanLifecycle} on ProductPurchase (already mixed in via
 * the package's default ProductPurchase model) to get the full borrow →
 * extend → return state machine for free.
 *
 * Example host model:
 *
 *     class Book extends \Blax\Shop\Models\Product
 *     {
 *         use \Blax\Shop\Traits\IsLoanableProduct;
 *
 *         public function getTitleAttribute(): ?string { return $this->name; }
 *         public function setTitleAttribute(?string $v): void { $this->attributes['name'] = $v; }
 *         public function getIsbnAttribute(): ?string { return $this->sku; }
 *         public function setIsbnAttribute(?string $v): void { $this->attributes['sku'] = $v; }
 *     }
 */
trait IsLoanableProduct
{
    /** Captured by setTotalQuantityAttribute; consumed in created(). */
    protected int $initialLoanableQuantity = 0;

    /**
     * Treat title / isbn / total_quantity as fillable virtual attributes by
     * default. Hosts that don't need them can override getFillable().
     */
    public function getFillable(): array
    {
        return array_merge(parent::getFillable(), ['title', 'isbn', 'total_quantity']);
    }

    public function getTotalQuantityAttribute(): int
    {
        if (! $this->exists) {
            return $this->initialLoanableQuantity;
        }

        return (int) $this->getMaxStocksAttribute();
    }

    public function setTotalQuantityAttribute(int $value): void
    {
        $this->initialLoanableQuantity = max(0, $value);
        $this->attributes['manage_stock'] = true;
    }

    public function getAvailableQuantityAttribute(): int
    {
        return $this->getAvailableStock();
    }

    public static function bootIsLoanableProduct(): void
    {
        static::creating(function ($product): void {
            $product->type ??= ProductType::LOANABLE;
            $product->status ??= ProductStatus::PUBLISHED;
            $product->is_visible ??= true;
            $product->manage_stock = $product->manage_stock ?? true;
        });

        static::created(function ($product): void {
            if ($product->initialLoanableQuantity > 0) {
                $product->increaseStock($product->initialLoanableQuantity);
            }
        });
    }

    /**
     * Atomically check out one unit of this product to a borrower.
     *
     * Wraps three operations in a single transaction so a failure anywhere
     * rolls back the lot:
     *   1. decreaseStock(1) — throws NotEnoughStockException if no copy
     *      is available
     *   2. ProductPurchase row created (purchasable=this, purchaser=$borrower)
     *   3. LoanCreated event dispatched
     *
     * @param  Model  $borrower  The model recording who's holding the item
     * @param  int|null  $weeks  Loan duration; defaults to shop.loan.default_duration_weeks
     * @param  ProductPrice|null  $price  Override price; defaults to product's defaultPrice
     *
     * @throws NotEnoughStockException When no copies are available
     */
    public function checkOutTo(
        Model $borrower,
        ?int $weeks = null,
        ?ProductPrice $price = null,
    ): ProductPurchase {
        $weeks ??= (int) config('shop.loan.default_duration_weeks', 2);
        $now = Carbon::now();
        $price ??= $this->defaultPrice()->first();

        $purchase = DB::transaction(function () use ($borrower, $weeks, $price, $now): ProductPurchase {
            $this->decreaseStock(1);

            return $this->purchases()->create([
                'purchaser_id' => $borrower->getKey(),
                'purchaser_type' => $borrower::class,
                'price_id' => $price?->id,
                'quantity' => 1,
                'amount' => 0,
                'amount_paid' => 0,
                'status' => PurchaseStatus::PENDING,
                'from' => $now,
                'until' => $now->copy()->addWeeks($weeks),
                'meta' => ['extensions_used' => 0],
            ]);
        });

        event(new LoanCreated($purchase));

        return $purchase;
    }
}
