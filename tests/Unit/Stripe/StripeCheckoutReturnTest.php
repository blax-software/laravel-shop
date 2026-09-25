<?php

namespace Blax\Shop\Tests\Unit\Stripe;

use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Facades\Cart as CartFacade;
use Blax\Shop\Http\Controllers\StripeWebhookController;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Services\StripeCheckoutConfirmation;
use Blax\Shop\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;

/**
 * The buyer's round trip through Stripe Checkout: leaving and coming back
 * (cancel → edit cart → pay), the success page settling the session before
 * the webhook does, and Stripe delivering the same event more than once.
 */
class StripeCheckoutReturnTest extends TestCase
{
    use RefreshDatabase;

    private string $apiBase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shop.stripe.enabled' => true, 'services.stripe.secret' => 'sk_test_fake']);

        // Any real Stripe call fails at once instead of reaching the network.
        $this->apiBase = \Stripe\Stripe::$apiBase;
        \Stripe\Stripe::$apiBase = 'http://127.0.0.1:9';
    }

    protected function tearDown(): void
    {
        \Stripe\Stripe::$apiBase = $this->apiBase;

        parent::tearDown();
    }

    private function guestCartWith(Product $product, int $quantity): Cart
    {
        $cart = Cart::factory()->create(['session_id' => 'socket-A', 'customer_id' => null]);
        $cart->addToCart($product, $quantity);

        return $cart->fresh();
    }

    private function product(int $price = 1000): Product
    {
        return Product::factory()->withPrices(unit_amount: $price)->create(['manage_stock' => false]);
    }

    private function stripeSession(Cart $cart, array $overrides = []): object
    {
        return (object) array_merge([
            'id' => 'cs_test_'.uniqid(),
            'status' => 'complete',
            'payment_intent' => 'pi_test_'.uniqid(),
            'metadata' => (object) ['cart_id' => $cart->id],
            'client_reference_id' => $cart->id,
            'amount_total' => (int) $cart->getTotal(),
            'currency' => 'eur',
            'payment_status' => 'paid',
        ], $overrides);
    }

    private function completed(object $session): bool
    {
        $controller = new StripeWebhookController;
        $method = (new ReflectionClass($controller))->getMethod('handleCheckoutSessionCompleted');

        return $method->invoke($controller, $session);
    }

    /** Run checkoutSession() up to the Stripe call (which fails here by design). */
    private function startCheckout(Cart $cart): void
    {
        try {
            $cart->fresh()->checkoutSession();
        } catch (\Throwable) {
            // The session itself isn't under test, the purchases written before it are.
        }
    }

    private function confirmation(object $session): StripeCheckoutConfirmation
    {
        return new class($session) extends StripeCheckoutConfirmation
        {
            public function __construct(private object $session) {}

            protected function retrieve(string $sessionId): object
            {
                return $this->session;
            }
        };
    }

    #[Test]
    public function a_second_checkout_attempt_updates_pending_purchases_to_the_edited_cart()
    {
        $kept = $this->product(1000);
        $dropped = $this->product(500);

        $cart = $this->guestCartWith($kept, 1);
        $cart->addToCart($dropped, 1);

        $this->startCheckout($cart);
        $this->assertSame(2, ProductPurchase::where('cart_id', $cart->id)->count());

        // Back from Stripe without paying: one more of the first, the second removed.
        $cart = $cart->fresh();
        $cart->addToCart($kept, 2);
        $cart->removeFromCart($cart->items()->where('purchasable_id', $dropped->id)->first(), 1);

        $this->startCheckout($cart);

        $purchases = ProductPurchase::where('cart_id', $cart->id)->get();
        $this->assertCount(1, $purchases);
        $this->assertSame($kept->id, $purchases->first()->purchasable_id);
        $this->assertSame(3, $purchases->first()->quantity);
        $this->assertSame(3000, $purchases->first()->amount);
    }

    #[Test]
    public function a_redelivered_completed_event_records_the_payment_once()
    {
        $cart = $this->guestCartWith($this->product(1000), 2);
        $session = $this->stripeSession($cart);

        $this->assertTrue($this->completed($session));
        $this->assertTrue($this->completed($session));

        $order = $cart->fresh()->order;
        $this->assertSame(2000, $order->amount_paid);
        $this->assertNotNull($order->paid_at);
        $this->assertSame(OrderStatus::PROCESSING, $order->status);
        $this->assertSame(1, $order->notes()->where('content', 'like', 'Payment of%')->count());
    }

    #[Test]
    public function an_unpaid_completed_session_creates_the_order_but_no_payment()
    {
        $cart = $this->guestCartWith($this->product(1000), 1);
        $session = $this->stripeSession($cart, ['payment_status' => 'unpaid']);

        $this->completed($session);

        $order = $cart->fresh()->order;
        $this->assertNotNull($order);
        $this->assertSame(0, $order->amount_paid);
        $this->assertNull($order->paid_at);
        $this->assertSame(OrderStatus::PENDING, $order->status);

        // The async payment settles later: now it is recorded.
        $this->completed($this->stripeSession($cart, ['id' => $session->id, 'payment_intent' => $session->payment_intent]));

        $this->assertSame(1000, $order->fresh()->amount_paid);
        $this->assertNotNull($order->fresh()->paid_at);
    }

    #[Test]
    public function confirming_a_paid_session_creates_the_order_before_the_webhook()
    {
        $cart = $this->guestCartWith($this->product(1500), 1);
        $session = $this->stripeSession($cart);

        $result = $this->confirmation($session)->confirm($session->id);

        $this->assertSame(StripeCheckoutConfirmation::PAID, $result['status']);
        $this->assertNotNull($result['order']);
        $this->assertSame(1500, $result['order']->amount_paid);

        // The webhook arrives afterwards and finds the work done.
        $this->completed($session);

        $this->assertSame(1500, $result['order']->fresh()->amount_paid);
        $this->assertSame(1, Cart::find($cart->id)->order()->count());
    }

    #[Test]
    public function confirming_an_open_or_expired_session_leaves_the_cart_alone()
    {
        $cart = $this->guestCartWith($this->product(), 1);

        $open = $this->confirmation($this->stripeSession($cart, ['status' => 'open', 'payment_status' => 'unpaid']))->confirm('cs_x');
        $expired = $this->confirmation($this->stripeSession($cart, ['status' => 'expired', 'payment_status' => 'unpaid']))->confirm('cs_x');

        $this->assertSame(StripeCheckoutConfirmation::OPEN, $open['status']);
        $this->assertSame(StripeCheckoutConfirmation::EXPIRED, $expired['status']);
        $this->assertNull($cart->fresh()->order);
        $this->assertFalse($cart->fresh()->isConverted());
    }

    #[Test]
    public function confirming_a_session_of_an_unknown_cart_is_refused()
    {
        $cart = $this->guestCartWith($this->product(), 1);
        $session = $this->stripeSession($cart, ['metadata' => (object) ['cart_id' => 'nope'], 'client_reference_id' => null]);

        $this->expectException(\InvalidArgumentException::class);

        $this->confirmation($session)->confirm($session->id);
    }

    #[Test]
    public function the_socket_that_paid_gets_a_fresh_cart()
    {
        $cart = $this->guestCartWith($this->product(), 1);
        $this->completed($this->stripeSession($cart));

        $next = CartFacade::adopt($cart->id, 'socket-A');

        $this->assertFalse($next->is($cart));
        $this->assertFalse($next->isConverted());
        $this->assertSame('socket-A', $next->session_id);
        $this->assertSame(0, $next->items()->count());
        $this->assertTrue($cart->fresh()->isConverted());
    }

    #[Test]
    public function a_charge_event_after_checkout_does_not_pay_twice()
    {
        $cart = $this->guestCartWith($this->product(1000), 1);
        $session = $this->stripeSession($cart);
        $this->completed($session);

        $controller = new StripeWebhookController;
        $method = (new ReflectionClass($controller))->getMethod('handleChargeSucceeded');
        $method->invoke($controller, (object) [
            'id' => 'ch_test_1',
            'payment_intent' => $session->payment_intent,
            'amount' => 1000,
        ]);

        $order = $cart->fresh()->order;
        $this->assertSame(1000, $order->amount_paid);
        $this->assertSame('ch_test_1', $order->getMeta('stripe_charge_id'));
    }
}
