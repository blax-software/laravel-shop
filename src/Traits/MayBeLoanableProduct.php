<?php

declare(strict_types=1);

namespace Blax\Shop\Traits;

use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Events\LoanCreated;
use Blax\Shop\Exceptions\NotEnoughStockException;
use Blax\Shop\Exceptions\NotLoanableProductException;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Models\ProductPurchase;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Mixed into {@see \Blax\Shop\Models\Product} so every product can be asked
 * "are you loanable?" and, if so, expose the loan-specific helpers below.
 * Mirrors the shape of {@see MayBePoolProduct}: the helpers early-out for
 * products whose `type` is not LOANABLE.
 *
 * Plug-n-pray for host apps: declare the constant and you're done.
 *
 *     class Book extends \Blax\Shop\Models\Product
 *     {
 *         public const DEFAULT_TYPE = ProductType::LOANABLE;
 *     }
 *
 * The boot hook reads `DEFAULT_TYPE` on the concrete class and, when it's
 * LOANABLE, applies sensible defaults on `creating` (type, status=PUBLISHED,
 * is_visible=true, manage_stock=true) so callers can omit e-commerce columns
 * entirely. Hosts that want a loanable Product *instance* without declaring
 * the constant can still set `type` explicitly at create time.
 *
 * The `total_quantity` virtual fillable translates into a stock INCREASE
 * entry on `created` when (and only when) the product is loanable:
 *
 *     Book::create(['name' => …, 'total_quantity' => 3])
 *
 * `checkOutTo($borrower, $weeks, $price)` wraps decrement + purchase
 * creation + LoanCreated dispatch in a single transaction; throws
 * NotLoanableProductException when called on a non-loanable product.
 *
 * Pair with {@see HasLoanLifecycle} on ProductPurchase (already mixed in via
 * the package's default ProductPurchase model) to get the full borrow →
 * extend → return state machine for free.
 *
 * Host models use the inherited `name` and `sku` columns directly. Anything
 * domain-specific (author, page count, language…) belongs in
 * {@see \Blax\Shop\Models\ProductAttribute}, and the public API can rename
 * columns at the Resource layer.
 */
trait MayBeLoanableProduct
{
    /** Captured by setTotalQuantityAttribute; consumed in created(). */
    protected int $initialLoanableQuantity = 0;

    public function isLoanable(): bool
    {
        return $this->type === ProductType::LOANABLE;
    }

    /**
     * `total_quantity` stays universally fillable — setting it on a
     * non-loanable product is a no-op (the created() hook below only fires
     * the stock INCREASE when isLoanable()), so there's no need to gate
     * the fillable list per type.
     */
    public function getFillable(): array
    {
        return array_merge(parent::getFillable(), ['total_quantity']);
    }

    /**
     * Physical inventory = copies on the shelf + copies currently loaned out.
     * We can't use getMaxStocksAttribute() here because it sums every
     * INCREASE entry in product_stocks — including the increaseStock() call
     * a return fires — which inflates the displayed total after each loan
     * cycle even though no new copies were ever acquired.
     */
    public function getTotalQuantityAttribute(): int
    {
        if (! $this->isLoanable()) {
            return (int) $this->getMaxStocksAttribute();
        }

        if (! $this->exists) {
            return $this->initialLoanableQuantity;
        }

        if ($this->manage_stock === false) {
            return PHP_INT_MAX;
        }

        return $this->getAvailableStock() + $this->purchases()->activeLoans()->count();
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

    public static function bootMayBeLoanableProduct(): void
    {
        static::creating(function ($product): void {
            if (self::resolveDefaultProductType($product) !== ProductType::LOANABLE) {
                return;
            }
            $product->type ??= ProductType::LOANABLE;
            $product->status ??= ProductStatus::PUBLISHED;
            $product->is_visible ??= true;
            $product->manage_stock = $product->manage_stock ?? true;
        });

        static::created(function ($product): void {
            if ($product->isLoanable() && $product->initialLoanableQuantity > 0) {
                $product->increaseStock($product->initialLoanableQuantity);
            }
        });
    }

    private static function resolveDefaultProductType(object $product): ?ProductType
    {
        $constant = $product::class . '::DEFAULT_TYPE';

        return defined($constant) ? constant($constant) : null;
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
     * @throws NotLoanableProductException When called on a non-loanable product
     * @throws NotEnoughStockException When no copies are available
     */
    public function checkOutTo(
        Model $borrower,
        ?int $weeks = null,
        ?ProductPrice $price = null,
    ): ProductPurchase {
        if (! $this->isLoanable()) {
            throw new NotLoanableProductException();
        }

        $weeks ??= (int) config('shop.loan.default_duration_weeks', 2);
        $now = Carbon::now();
        $until = $now->copy()->addWeeks($weeks);
        $price ??= $this->defaultPrice()->first();

        $purchase = DB::transaction(function () use ($borrower, $price, $now, $until): ProductPurchase {
            $purchase = $this->purchases()->create([
                'purchaser_id' => $borrower->getKey(),
                'purchaser_type' => $borrower::class,
                'price_id' => $price?->id,
                'quantity' => 1,
                'amount' => 0,
                'amount_paid' => 0,
                'status' => PurchaseStatus::PENDING,
                'from' => $now,
                'until' => $until,
                'meta' => ['extensions_used' => 0],
            ]);

            // Loan model = booking-style claim that doesn't auto-release.
            // The PHYSICALLY_CLAIMED row drives availability/calendar and
            // carries the due date in `expires_at` for overdue tracking,
            // while ProductStock::releaseExpired() pointedly skips this
            // type — the borrower physically has the item until they bring
            // it back, regardless of how overdue they get. markReturned()
            // releases the claim, which creates the offsetting RETURN entry.
            $this->claimStock(
                quantity: 1,
                reference: $purchase,
                from: $now,
                until: $until,
                note: 'Loan to ' . class_basename($borrower::class) . ' #' . substr((string) $borrower->getKey(), 0, 8),
                type: \Blax\Shop\Enums\StockType::PHYSICALLY_CLAIMED,
            );

            return $purchase;
        });

        event(new LoanCreated($purchase));

        return $purchase;
    }
}
