<?php

declare(strict_types=1);

namespace Blax\Shop\Services;

use Blax\Shop\Http\Controllers\StripeWebhookController;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Order;
use Stripe\StripeClient;

/**
 * What the buyer's return from Stripe Checkout means for the shop.
 *
 * The success page knows the Checkout Session id (Stripe appends it to the
 * success URL). confirm() asks Stripe for that session and, when it is paid,
 * fulfils it through the webhook's own code path
 * (StripeWebhookController::completeCheckoutSession, idempotent and locked),
 * so the order exists the moment the buyer arrives, even if the webhook is
 * slow or not configured. The session id is the credential: it is only known
 * to whoever went through that checkout.
 *
 * Statuses:
 *  - `paid`       payment settled, `order` is set
 *  - `processing` checkout finished, payment still settling (bank transfer, SEPA, …)
 *  - `open`       the session was never completed (buyer came back without paying)
 *  - `expired`    the session timed out; the cart can start a new checkout
 */
class StripeCheckoutConfirmation
{
    public const PAID = 'paid';

    public const PROCESSING = 'processing';

    public const OPEN = 'open';

    public const EXPIRED = 'expired';

    /**
     * @return array{status: string, order: Order|null, cart: Cart|null}
     *
     * @throws \InvalidArgumentException the session doesn't belong to a cart of this shop
     */
    public function confirm(string $sessionId): array
    {
        $session = $this->retrieve($sessionId);

        $cartId = data_get($session, 'metadata.cart_id') ?? data_get($session, 'client_reference_id');
        $cartModel = config('shop.models.cart', Cart::class);
        $cart = $cartId ? $cartModel::find($cartId) : null;

        if (! $cart) {
            throw new \InvalidArgumentException('This checkout session does not belong to a cart of this shop.');
        }

        $status = match (true) {
            data_get($session, 'status') === 'expired' => self::EXPIRED,
            data_get($session, 'status') !== 'complete' => self::OPEN,
            in_array(data_get($session, 'payment_status'), ['paid', 'no_payment_required'], true) => self::PAID,
            default => self::PROCESSING,
        };

        if ($status === self::PAID || $status === self::PROCESSING) {
            app(StripeWebhookController::class)->completeCheckoutSession($session);
        }

        $cart = $cart->fresh();

        return [
            'status' => $status,
            'order' => $cart?->order,
            'cart' => $cart,
        ];
    }

    /** The Checkout Session from Stripe (overridable for tests). */
    protected function retrieve(string $sessionId): object
    {
        return (new StripeClient((string) config('services.stripe.secret')))
            ->checkout->sessions->retrieve($sessionId);
    }
}
