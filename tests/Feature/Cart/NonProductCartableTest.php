<?php

namespace Blax\Shop\Tests\Feature\Cart;

use Blax\Shop\Contracts\Cartable;
use Blax\Shop\Contracts\Purchasable;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\CartItem;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Tests\TestCase;
use Blax\Shop\Traits\IsSimplePurchasable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Models\User;

/**
 * Cart::addToCart() used to leave $is_pool / $is_booking undefined when the
 * cartable was not a Product / ProductPrice, which made non-Product Cartable
 * hosts (e.g. a library Book that doesn't extend Product) crash with
 * `Undefined variable $is_booking`. The fix initialises both flags to false.
 *
 * This integration test guards against that regression by exercising the
 * full add-to-cart flow with an in-line {@see IsSimplePurchasable} host.
 */
class NonProductCartableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('loanable_widgets', function ($table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });
    }

    #[Test]
    public function add_to_cart_accepts_a_non_product_cartable_without_undefined_variables(): void
    {
        $user = User::factory()->create();
        $widget = LoanableWidget::create(['name' => 'Hyperion']);

        $cart = Cart::factory()->create([
            'customer_id' => $user->id,
            'customer_type' => get_class($user),
        ]);

        $cartItem = $cart->addToCart($widget, 1);

        $this->assertInstanceOf(CartItem::class, $cartItem);
        $this->assertSame($widget->id, $cartItem->purchasable_id);
        $this->assertSame(LoanableWidget::class, $cartItem->purchasable_type);
        $this->assertSame(1, $cartItem->quantity);
        $this->assertFalse(
            (bool) $cartItem->is_booking,
            'A non-Product cartable should not flag the item as a booking by default'
        );
    }

    #[Test]
    public function is_simple_purchasable_provides_polymorphic_purchases_relation(): void
    {
        $widget = LoanableWidget::create(['name' => 'Hyperion']);
        $user = User::factory()->create();

        $purchase = $widget->purchases()->create([
            'purchaser_id' => $user->id,
            'purchaser_type' => User::class,
            'quantity' => 1,
            'amount' => 0,
            'amount_paid' => 0,
            'status' => 'pending',
        ]);

        $this->assertInstanceOf(ProductPurchase::class, $purchase);
        $this->assertSame(LoanableWidget::class, $purchase->purchasable_type);
        $this->assertCount(1, $widget->purchases()->get());
    }

    #[Test]
    public function purchasable_defaults_are_free_and_no_op_stock(): void
    {
        $widget = LoanableWidget::create(['name' => 'Hyperion']);

        $this->assertSame(0.0, $widget->getCurrentPrice());
        $this->assertSame(0.0, $widget->getPriceAttribute());
        $this->assertFalse($widget->isOnSale());
        $this->assertTrue($widget->increaseStock());
        $this->assertTrue($widget->decreaseStock());
    }
}

/**
 * In-line fixture: the smallest valid host for IsSimplePurchasable.
 * Lives in the test file because it's not useful anywhere else.
 */
class LoanableWidget extends Model implements Cartable, Purchasable
{
    use HasUuids;
    use IsSimplePurchasable;

    protected $table = 'loanable_widgets';

    protected $fillable = ['name'];
}
