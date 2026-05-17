<?php

namespace Blax\Shop\Tests\Feature;

use Blax\Shop\Enums\ProductStatus;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Events\CartCreated;
use Blax\Shop\Events\OrderCreated;
use Blax\Shop\Events\ProductDeleted;
use Blax\Shop\Events\ProductPublished;
use Blax\Shop\Events\ProductUnpublished;
use Blax\Shop\Events\PurchaseCreated;
use Blax\Shop\Events\StockBecameLow;
use Blax\Shop\Events\StockClaimed;
use Blax\Shop\Events\StockClaimExpired;
use Blax\Shop\Events\StockDecreased;
use Blax\Shop\Events\StockDepleted;
use Blax\Shop\Events\StockIncreased;
use Blax\Shop\Events\StockReleased;
use Blax\Shop\Events\StockReplenished;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Order;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Models\ProductStock;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

class EventsWiredUpTest extends TestCase
{
    use RefreshDatabase;

    private function newProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Test Product',
            'sku' => 'EV-'.uniqid(),
            'type' => ProductType::SIMPLE,
            'status' => ProductStatus::PUBLISHED,
            'manage_stock' => true,
            'is_visible' => true,
        ], $overrides));
    }

    // ─── Stock-level transitions ──────────────────────────────────────────

    #[Test]
    public function increase_stock_dispatches_stock_increased(): void
    {
        Event::fake([StockIncreased::class]);

        $product = $this->newProduct();
        $product->increaseStock(3);

        Event::assertDispatched(StockIncreased::class, fn (StockIncreased $e) =>
            $e->product->is($product) && $e->availableAfter === 3
        );
    }

    #[Test]
    public function decrease_stock_dispatches_stock_decreased(): void
    {
        $product = $this->newProduct();
        $product->increaseStock(3);

        Event::fake([StockDecreased::class]);

        $product->decreaseStock(2);

        Event::assertDispatched(StockDecreased::class, fn (StockDecreased $e) =>
            $e->product->is($product) && $e->availableAfter === 1
        );
    }

    #[Test]
    public function depleting_the_last_unit_dispatches_stock_depleted(): void
    {
        $product = $this->newProduct();
        $product->increaseStock(1);

        Event::fake([StockDepleted::class]);

        $product->decreaseStock(1);

        Event::assertDispatched(StockDepleted::class, fn (StockDepleted $e) => $e->product->is($product));
    }

    #[Test]
    public function restocking_a_depleted_product_dispatches_stock_replenished(): void
    {
        $product = $this->newProduct();
        $product->increaseStock(1);
        $product->decreaseStock(1);

        Event::fake([StockReplenished::class]);

        $product->increaseStock(2);

        Event::assertDispatched(StockReplenished::class, fn (StockReplenished $e) =>
            $e->product->is($product) && $e->availableAfter === 2
        );
    }

    #[Test]
    public function crossing_below_the_low_stock_threshold_dispatches_stock_became_low(): void
    {
        $product = $this->newProduct(['low_stock_threshold' => 2]);
        $product->increaseStock(5);

        Event::fake([StockBecameLow::class]);

        // 5 → 4 stays above threshold (2); 4 → 2 crosses it.
        $product->decreaseStock(3);

        Event::assertDispatched(StockBecameLow::class, fn (StockBecameLow $e) =>
            $e->product->is($product) && $e->availableAfter === 2 && $e->threshold === 2
        );
    }

    #[Test]
    public function low_stock_threshold_does_not_fire_for_zero_after(): void
    {
        $product = $this->newProduct(['low_stock_threshold' => 2]);
        $product->increaseStock(3);

        Event::fake([StockBecameLow::class, StockDepleted::class]);

        // Going from 3 → 0 should fire Depleted, not BecameLow.
        $product->decreaseStock(3);

        Event::assertNotDispatched(StockBecameLow::class);
        Event::assertDispatched(StockDepleted::class);
    }

    #[Test]
    public function claim_stock_dispatches_stock_claimed(): void
    {
        $product = $this->newProduct();
        $product->increaseStock(2);

        Event::fake([StockClaimed::class]);

        $claim = $product->claimStock(1);

        $this->assertNotNull($claim);
        Event::assertDispatched(StockClaimed::class, fn (StockClaimed $e) =>
            $e->product->is($product) && $e->entry->is($claim)
        );
    }

    #[Test]
    public function releasing_a_claim_manually_dispatches_stock_released(): void
    {
        $product = $this->newProduct();
        $product->increaseStock(2);
        $claim = $product->claimStock(1);

        Event::fake([StockReleased::class, StockClaimExpired::class]);

        $claim->release();

        Event::assertDispatched(StockReleased::class);
        Event::assertNotDispatched(StockClaimExpired::class);
    }

    #[Test]
    public function release_expired_dispatches_stock_claim_expired_not_stock_released(): void
    {
        $product = $this->newProduct();
        $product->increaseStock(2);

        Carbon::setTestNow(Carbon::parse('2026-05-14 10:00:00'));
        $product->claimStock(
            1,
            null,
            Carbon::parse('2026-05-14 10:00:00'),
            Carbon::parse('2026-05-14 11:00:00'),
            'short claim'
        );

        Carbon::setTestNow(Carbon::parse('2026-05-14 12:00:00')); // past expiry

        Event::fake([StockReleased::class, StockClaimExpired::class]);

        ProductStock::releaseExpired();

        Event::assertDispatched(StockClaimExpired::class);
        Event::assertNotDispatched(StockReleased::class);
    }

    // ─── Model-level events ───────────────────────────────────────────────

    #[Test]
    public function publishing_a_new_product_dispatches_product_published(): void
    {
        Event::fake([ProductPublished::class]);

        $product = $this->newProduct(); // created already PUBLISHED

        Event::assertDispatched(ProductPublished::class, fn (ProductPublished $e) => $e->product->is($product));
    }

    #[Test]
    public function moving_a_product_away_from_published_dispatches_product_unpublished(): void
    {
        $product = $this->newProduct();

        Event::fake([ProductUnpublished::class]);

        $product->status = ProductStatus::DRAFT;
        $product->save();

        Event::assertDispatched(ProductUnpublished::class, fn (ProductUnpublished $e) => $e->product->is($product));
    }

    #[Test]
    public function deleting_a_product_dispatches_product_deleted(): void
    {
        $product = $this->newProduct();

        Event::fake([ProductDeleted::class]);

        $product->delete();

        Event::assertDispatched(ProductDeleted::class, fn (ProductDeleted $e) => $e->product->is($product));
    }

    #[Test]
    public function creating_a_cart_dispatches_cart_created(): void
    {
        Event::fake([CartCreated::class]);

        $cart = Cart::create(['session_id' => 'sess-evt-1']);

        Event::assertDispatched(CartCreated::class, fn (CartCreated $e) => $e->cart->is($cart));
    }

    #[Test]
    public function creating_an_order_dispatches_order_created(): void
    {
        Event::fake([OrderCreated::class]);

        $order = Order::create(['currency' => 'EUR']);

        Event::assertDispatched(OrderCreated::class, fn (OrderCreated $e) => $e->order->is($order));
    }

    #[Test]
    public function creating_a_purchase_dispatches_purchase_created(): void
    {
        $product = $this->newProduct();
        $product->increaseStock(1);

        Event::fake([PurchaseCreated::class]);

        $purchase = ProductPurchase::create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'purchaser_id' => 'user-x',
            'purchaser_type' => 'App\\Models\\User',
            'quantity' => 1,
            'amount' => 0,
            'amount_paid' => 0,
        ]);

        Event::assertDispatched(PurchaseCreated::class, fn (PurchaseCreated $e) => $e->purchase->is($purchase));
    }
}
