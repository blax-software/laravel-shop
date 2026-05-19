<?php

declare(strict_types=1);

namespace Blax\Shop\Tests\Feature\Cart;

use Blax\Shop\Contracts\Cartable;
use Blax\Shop\Contracts\Purchasable;
use Blax\Shop\Enums\ProductRelationType;
use Blax\Shop\Enums\ProductType;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Exceptions\ExceedsMaxPerCartException;
use Blax\Shop\Exceptions\ExceedsMaxPerUserException;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPrice;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Blax\Shop\Traits\IsSimplePurchasable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Covers the `max_per_cart` and `max_per_user` purchase caps configured on
 * {@see Product} and enforced in {@see Cart::addToCart()}.
 *
 * What we care about:
 *   - NULL on both columns keeps the historical behaviour (no cap).
 *   - max_per_cart counts total in-cart quantity, not cart items.
 *   - max_per_cart respects existing items when a second add bumps over.
 *   - max_per_user only applies to identified customers (not guest carts).
 *   - max_per_user counts already-placed purchases AND current cart, but
 *     skips CART / FAILED rows so the cap can't be bypassed by accumulating
 *     dead rows.
 *   - Pool products go through the recursive single-unit path; the cap must
 *     still fire even when the request is split into per-unit adds.
 *   - Different customers under the same product see independent counters.
 */
class CartPurchaseLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('limit_widgets')) {
            Schema::create('limit_widgets', function ($table) {
                $table->uuid('id')->primary();
                $table->string('name');
                $table->timestamps();
            });
        }
    }

    private function productWithCaps(?int $maxPerCart = null, ?int $maxPerUser = null, int $price = 1000): Product
    {
        $product = Product::factory()->create([
            'name' => 'Capped Product',
            'manage_stock' => false,
            'max_per_cart' => $maxPerCart,
            'max_per_user' => $maxPerUser,
        ]);

        ProductPrice::factory()->create([
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'unit_amount' => $price,
            'currency' => 'EUR',
            'is_default' => true,
        ]);

        return $product->fresh();
    }

    private function userCart(): array
    {
        $user = User::factory()->create();
        $cart = Cart::create([
            'customer_type' => get_class($user),
            'customer_id' => $user->id,
        ]);
        return [$user, $cart];
    }

    // ------------------------------------------------------------------
    // max_per_cart
    // ------------------------------------------------------------------

    #[Test]
    public function null_caps_keep_unlimited_behaviour(): void
    {
        $product = $this->productWithCaps(maxPerCart: null, maxPerUser: null);
        $cart = Cart::create();

        $cart->addToCart($product, quantity: 50);

        $this->assertSame(50, (int) $cart->fresh()->items->sum('quantity'));
    }

    #[Test]
    public function it_blocks_a_single_add_that_exceeds_max_per_cart(): void
    {
        $product = $this->productWithCaps(maxPerCart: 3);
        $cart = Cart::create();

        $this->expectException(ExceedsMaxPerCartException::class);

        $cart->addToCart($product, quantity: 4);
    }

    #[Test]
    public function it_allows_adding_up_to_the_max_per_cart(): void
    {
        $product = $this->productWithCaps(maxPerCart: 3);
        $cart = Cart::create();

        $cart->addToCart($product, quantity: 3);

        $this->assertSame(3, (int) $cart->fresh()->items->sum('quantity'));
    }

    #[Test]
    public function it_blocks_a_second_add_that_would_exceed_max_per_cart(): void
    {
        $product = $this->productWithCaps(maxPerCart: 3);
        $cart = Cart::create();

        $cart->addToCart($product, quantity: 2);

        $this->expectException(ExceedsMaxPerCartException::class);
        $cart->addToCart($product, quantity: 2); // 2 + 2 = 4 > 3
    }

    #[Test]
    public function exception_message_reports_the_correct_remaining_quantity(): void
    {
        $product = $this->productWithCaps(maxPerCart: 5);
        $cart = Cart::create();
        $cart->addToCart($product, quantity: 4);

        try {
            $cart->addToCart($product, quantity: 3);
            $this->fail('Expected ExceedsMaxPerCartException was not thrown');
        } catch (ExceedsMaxPerCartException $e) {
            $this->assertStringContainsString('maximum of 5 per cart', $e->getMessage());
            $this->assertStringContainsString('already have 4', $e->getMessage());
            $this->assertStringContainsString('up to 1 more', $e->getMessage());
        }
    }

    #[Test]
    public function caps_are_per_product_not_global(): void
    {
        $productA = $this->productWithCaps(maxPerCart: 2);
        $productB = $this->productWithCaps(maxPerCart: 2);
        $cart = Cart::create();

        $cart->addToCart($productA, quantity: 2);
        $cart->addToCart($productB, quantity: 2);

        $this->assertSame(4, (int) $cart->fresh()->items->sum('quantity'));
    }

    #[Test]
    public function adding_via_product_price_still_enforces_the_cap(): void
    {
        // The Cartable can be a ProductPrice — make sure we resolve through
        // .purchasable so a price-driven add hits the same enforcement path
        // as a product-driven one.
        $product = $this->productWithCaps(maxPerCart: 2);
        $price = $product->defaultPrice()->first();
        $cart = Cart::create();

        $this->expectException(ExceedsMaxPerCartException::class);
        $cart->addToCart($price, quantity: 3);
    }

    // ------------------------------------------------------------------
    // max_per_user
    // ------------------------------------------------------------------

    #[Test]
    public function max_per_user_is_skipped_for_guest_carts(): void
    {
        // Guest cart has no customer_id, so there's no identity to count
        // against — the cap is intentionally bypassed in this case.
        $product = $this->productWithCaps(maxPerUser: 1);
        $guestCart = Cart::create();

        $guestCart->addToCart($product, quantity: 5);

        $this->assertSame(5, (int) $guestCart->fresh()->items->sum('quantity'));
    }

    #[Test]
    public function max_per_user_blocks_when_in_cart_alone_would_exceed(): void
    {
        $product = $this->productWithCaps(maxPerUser: 2);
        [$user, $cart] = $this->userCart();

        $this->expectException(ExceedsMaxPerUserException::class);
        $cart->addToCart($product, quantity: 3);
    }

    #[Test]
    public function max_per_user_blocks_when_existing_purchases_plus_cart_exceed(): void
    {
        $product = $this->productWithCaps(maxPerUser: 3);
        [$user, $cart] = $this->userCart();

        ProductPurchase::create([
            'status' => PurchaseStatus::COMPLETED,
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'quantity' => 2,
            'amount' => 2000,
            'amount_paid' => 2000,
        ]);

        // Already bought 2/3 — adding 2 more would land on 4 > 3.
        $this->expectException(ExceedsMaxPerUserException::class);
        $cart->addToCart($product, quantity: 2);
    }

    #[Test]
    public function max_per_user_allows_the_exact_remaining_quantity(): void
    {
        $product = $this->productWithCaps(maxPerUser: 3);
        [$user, $cart] = $this->userCart();

        ProductPurchase::create([
            'status' => PurchaseStatus::COMPLETED,
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'quantity' => 2,
            'amount' => 2000,
            'amount_paid' => 2000,
        ]);

        $cart->addToCart($product, quantity: 1); // 2 + 1 = 3 == cap

        $this->assertSame(1, (int) $cart->fresh()->items->sum('quantity'));
    }

    #[Test]
    public function pending_and_unpaid_purchases_still_count(): void
    {
        // Critical: the cap can't be bypassed by accumulating unpaid orders.
        $product = $this->productWithCaps(maxPerUser: 2);
        [$user, $cart] = $this->userCart();

        ProductPurchase::create([
            'status' => PurchaseStatus::PENDING,
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'quantity' => 1,
            'amount' => 1000,
        ]);
        ProductPurchase::create([
            'status' => PurchaseStatus::UNPAID,
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'quantity' => 1,
            'amount' => 1000,
        ]);

        $this->expectException(ExceedsMaxPerUserException::class);
        $cart->addToCart($product, quantity: 1);
    }

    #[Test]
    public function failed_and_cart_status_purchases_are_not_counted(): void
    {
        // Cart rows are not committed purchases; failed rows shouldn't lock
        // the customer out of trying again with a different payment method.
        $product = $this->productWithCaps(maxPerUser: 2);
        [$user, $cart] = $this->userCart();

        ProductPurchase::create([
            'status' => PurchaseStatus::CART,
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'quantity' => 5,
            'amount' => 5000,
        ]);
        ProductPurchase::create([
            'status' => PurchaseStatus::FAILED,
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'quantity' => 5,
            'amount' => 5000,
        ]);

        // None of the rows above count, so the customer can still buy 2.
        $cart->addToCart($product, quantity: 2);

        $this->assertSame(2, (int) $cart->fresh()->items->sum('quantity'));
    }

    #[Test]
    public function caps_are_per_customer_not_global(): void
    {
        $product = $this->productWithCaps(maxPerUser: 2);

        [$userA, $cartA] = $this->userCart();
        [$userB, $cartB] = $this->userCart();

        ProductPurchase::create([
            'status' => PurchaseStatus::COMPLETED,
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'purchaser_id' => $userA->id,
            'purchaser_type' => get_class($userA),
            'quantity' => 2,
            'amount' => 2000,
            'amount_paid' => 2000,
        ]);

        // User A is capped, user B is untouched.
        $cartB->addToCart($product, quantity: 2);
        $this->assertSame(2, (int) $cartB->fresh()->items->sum('quantity'));

        $this->expectException(ExceedsMaxPerUserException::class);
        $cartA->addToCart($product, quantity: 1);
    }

    #[Test]
    public function existing_in_cart_combined_with_purchases_is_summed_correctly(): void
    {
        $product = $this->productWithCaps(maxPerUser: 5);
        [$user, $cart] = $this->userCart();

        ProductPurchase::create([
            'status' => PurchaseStatus::COMPLETED,
            'purchasable_id' => $product->id,
            'purchasable_type' => Product::class,
            'purchaser_id' => $user->id,
            'purchaser_type' => get_class($user),
            'quantity' => 2,
            'amount' => 2000,
            'amount_paid' => 2000,
        ]);

        $cart->addToCart($product, quantity: 2); // total: 4
        $cart->addToCart($product, quantity: 1); // total: 5 (cap)

        $this->assertSame(3, (int) $cart->fresh()->items->sum('quantity'));

        $this->expectException(ExceedsMaxPerUserException::class);
        $cart->addToCart($product, quantity: 1);
    }

    // ------------------------------------------------------------------
    // Both caps together
    // ------------------------------------------------------------------

    #[Test]
    public function the_lower_cap_wins_when_both_are_configured(): void
    {
        // max_per_user=10 is loose, max_per_cart=2 is the binding constraint.
        $product = $this->productWithCaps(maxPerCart: 2, maxPerUser: 10);
        [$user, $cart] = $this->userCart();

        $this->expectException(ExceedsMaxPerCartException::class);
        $cart->addToCart($product, quantity: 3);
    }

    #[Test]
    public function per_user_can_be_tighter_than_per_cart(): void
    {
        $product = $this->productWithCaps(maxPerCart: 100, maxPerUser: 1);
        [$user, $cart] = $this->userCart();

        $this->expectException(ExceedsMaxPerUserException::class);
        $cart->addToCart($product, quantity: 2);
    }

    // ------------------------------------------------------------------
    // Pool product interaction (recursive per-unit add path)
    // ------------------------------------------------------------------

    #[Test]
    public function pool_products_respect_max_per_cart(): void
    {
        // Pool products with quantity > 1 go through addToCart() recursively
        // (one unit at a time). The cap must fire on the *initial* call
        // before the recursion expands, otherwise the request would partly
        // succeed and partly fail — a non-atomic add we want to avoid.
        $pool = Product::factory()->create([
            'name' => 'Capped Pool',
            'type' => ProductType::POOL,
            'manage_stock' => false,
            'max_per_cart' => 2,
        ]);

        $single1 = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single1->increaseStock(5);
        $single2 = Product::factory()->create([
            'type' => ProductType::BOOKING,
            'manage_stock' => true,
        ]);
        $single2->increaseStock(5);

        ProductPrice::factory()->create([
            'purchasable_id' => $single1->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1000,
            'currency' => 'EUR',
            'is_default' => true,
        ]);
        ProductPrice::factory()->create([
            'purchasable_id' => $single2->id,
            'purchasable_type' => Product::class,
            'unit_amount' => 1000,
            'currency' => 'EUR',
            'is_default' => true,
        ]);

        $pool->productRelations()->attach($single1->id, ['type' => ProductRelationType::SINGLE->value]);
        $pool->productRelations()->attach($single2->id, ['type' => ProductRelationType::SINGLE->value]);

        $cart = Cart::create();

        $this->expectException(ExceedsMaxPerCartException::class);
        $cart->addToCart($pool, quantity: 3, parameters: [], from: Carbon::tomorrow(), until: Carbon::tomorrow()->addDays(2));
    }

    // ------------------------------------------------------------------
    // Non-Product cartables
    // ------------------------------------------------------------------

    #[Test]
    public function caps_are_silently_skipped_for_non_product_cartables(): void
    {
        // Host-app models using IsSimplePurchasable don't own these columns,
        // so the enforcement should be a no-op rather than a crash.
        $cart = Cart::create();
        $widget = LimitWidget::create(['name' => 'Hyperion']);

        $cart->addToCart($widget, quantity: 99);

        $this->assertSame(99, (int) $cart->fresh()->items->sum('quantity'));
    }
}

/**
 * In-line fixture: smallest valid IsSimplePurchasable host. Used to confirm
 * the cap enforcement is a no-op for non-Product cartables (they don't own
 * the max_per_* columns and shouldn't crash by trying to read them).
 */
class LimitWidget extends Model implements Cartable, Purchasable
{
    use HasUuids;
    use IsSimplePurchasable;

    protected $table = 'limit_widgets';

    protected $fillable = ['name'];
}
