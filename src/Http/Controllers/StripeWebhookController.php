<?php

declare(strict_types=1);

namespace Blax\Shop\Http\Controllers;

use Blax\Shop\Enums\CartStatus;
use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Order;
use Blax\Shop\Models\OrderNote;
use Blax\Shop\Models\Product;
use Blax\Shop\Models\ProductPurchase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Stripe;
use Stripe\Webhook;

class StripeWebhookController
{
    public function __construct()
    {
        if (config('shop.stripe.enabled')) {
            Stripe::setApiKey(config('services.stripe.secret'));
        }
    }

    /**
     * Handle Stripe webhook events
     */
    public function handleWebhook(Request $request)
    {
        if (! config('shop.stripe.enabled')) {
            return response()->json(['error' => 'Stripe is not enabled'], 400);
        }

        $payload = $request->getContent();
        $sigHeader = $request->header('Stripe-Signature');
        $webhookSecret = config('shop.stripe.webhook_secret') ?? config('services.stripe.webhook_secret');

        try {
            // Verify webhook signature
            if ($webhookSecret) {
                $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
            } else {
                // If no webhook secret, parse the event directly (not recommended for production)
                $event = json_decode($payload);
                Log::warning('Stripe webhook received without signature verification - not recommended for production');
            }
        } catch (\UnexpectedValueException $e) {
            Log::error('Stripe webhook invalid payload', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid payload'], 400);
        } catch (SignatureVerificationException $e) {
            Log::error('Stripe webhook signature verification failed', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Invalid signature'], 400);
        }

        // Handle the event
        try {
            $handled = match ($event->type) {
                // Checkout Session Events
                'checkout.session.completed' => $this->handleCheckoutSessionCompleted($event->data->object),
                'checkout.session.async_payment_succeeded' => $this->handleCheckoutSessionCompleted($event->data->object),
                'checkout.session.async_payment_failed' => $this->handleCheckoutSessionFailed($event->data->object),
                'checkout.session.expired' => $this->handleCheckoutSessionExpired($event->data->object),

                // Charge Events
                'charge.succeeded' => $this->handleChargeSucceeded($event->data->object),
                'charge.failed' => $this->handleChargeFailed($event->data->object),
                'charge.refunded' => $this->handleChargeRefunded($event->data->object),
                'charge.dispute.created' => $this->handleChargeDisputeCreated($event->data->object),
                'charge.dispute.closed' => $this->handleChargeDisputeClosed($event->data->object),

                // Payment Intent Events
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->data->object),
                'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event->data->object),
                'payment_intent.canceled' => $this->handlePaymentIntentCanceled($event->data->object),

                // Refund Events
                'refund.created' => $this->handleRefundCreated($event->data->object),
                'refund.updated' => $this->handleRefundUpdated($event->data->object),

                // Invoice Events (for subscriptions)
                'invoice.payment_succeeded' => $this->handleInvoicePaymentSucceeded($event->data->object),
                'invoice.payment_failed' => $this->handleInvoicePaymentFailed($event->data->object),

                default => $this->handleUnknownEvent($event->type),
            };

            return response()->json(['success' => true, 'handled' => $handled]);
        } catch (\Exception $e) {
            Log::error('Stripe webhook handler failed', [
                'type' => $event->type,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Webhook handler failed'], 500);
        }
    }

    /**
     * Handle unknown/unhandled event types
     */
    protected function handleUnknownEvent(string $type): bool
    {
        Log::info('Stripe webhook unhandled event type', ['type' => $type]);

        return false;
    }

    /**
     * Fulfil a completed Checkout Session outside the webhook, e.g. when the
     * buyer lands on the success page before Stripe's event does. Same code
     * path (and lock) as `checkout.session.completed`, so whichever comes
     * first creates the order and the other finds it done.
     */
    public function completeCheckoutSession($session): bool
    {
        return $this->handleCheckoutSessionCompleted($session);
    }

    /**
     * Handle checkout.session.completed event
     *
     * Idempotent: Stripe delivers events at least once, and the success page
     * may fulfil the same session (completeCheckoutSession). Runs under a lock
     * per payment, records the payment once, and only when Stripe reports the
     * session paid (async methods complete `unpaid` first and settle later via
     * checkout.session.async_payment_succeeded).
     */
    protected function handleCheckoutSessionCompleted($session): bool
    {
        $cartId = $session->metadata->cart_id ?? $session->client_reference_id;

        if (! $cartId) {
            Log::warning('Stripe checkout session completed without cart ID', ['session_id' => $session->id]);

            return false;
        }

        if (! Cart::find($cartId)) {
            Log::warning('Stripe checkout session for non-existent cart', ['cart_id' => $cartId]);

            return false;
        }

        return $this->withPaymentLock($session->payment_intent ?? "cart:{$cartId}", function () use ($cartId, $session) {
            return $this->fulfilCheckoutSession(Cart::find($cartId), $session);
        });
    }

    /**
     * Run $callback while holding the lock for one payment, so concurrent
     * deliveries (checkout.session.completed, charge.succeeded, the success
     * page) never both create an order or record the same payment twice.
     * A lock that can't be had in time throws, and Stripe retries the event.
     */
    protected function withPaymentLock(string $key, callable $callback): mixed
    {
        return Cache::lock("shop:stripe-payment:{$key}", 60)->block(20, $callback);
    }

    /** checkout.session.completed for a cart that exists; see handleCheckoutSessionCompleted(). */
    protected function fulfilCheckoutSession(Cart $cart, $session): bool
    {
        // Only update if not already converted
        if ($cart->status !== CartStatus::CONVERTED) {
            $cart->update([
                'status' => CartStatus::CONVERTED,
                'converted_at' => now(),
            ]);

            // Update associated purchases and claim stocks
            $this->updatePurchasesForSession($cart, $session);

            Log::info('Cart converted via Stripe checkout', [
                'cart_id' => $cart->id,
                'session_id' => $session->id,
            ]);
        }

        // Get or create order from the cart
        $order = $cart->order;
        if (! $order) {
            // Create order from the converted cart
            $order = $this->orderModel()::createFromCart($cart);

            Log::info('Order created from Stripe checkout session', [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'cart_id' => $cart->id,
                'session_id' => $session->id,
            ]);
        }

        // Persist the addresses Stripe collected during checkout so the
        // order carries a buyer snapshot (invoicing, shipping labels, ...).
        $this->persistSessionAddresses($order, $session);

        // Shipping, discounts and tax chosen/applied on the Checkout page are only
        // known to Stripe; take the order's totals from the session so what was
        // charged and what the order says agree (and the payment below settles it).
        $this->applySessionTotals($order, $session);

        // Record payment on the order
        // Stripe provides amounts in cents, which matches our storage format
        $amountPaid = (int) ($session->amount_total ?? 0);
        $currency = strtoupper($session->currency ?? $order->currency ?? 'USD');

        $order->refresh();
        $settled = in_array($session->payment_status ?? 'paid', ['paid', 'no_payment_required'], true);
        $recorded = $order->paid_at
            || ($session->payment_intent && $order->payment_reference === $session->payment_intent && $order->amount_paid > 0);

        if (! $settled || $recorded) {
            Log::info('Stripe checkout session: no payment to record', [
                'order_id' => $order->id,
                'session_id' => $session->id,
                'payment_status' => $session->payment_status ?? null,
                'already_recorded' => (bool) $recorded,
            ]);

            return true;
        }

        // recordPayment(int $amount, ?string $reference, ?string $method, ?string $provider)
        $order->recordPayment($amountPaid, $session->payment_intent, 'stripe', 'stripe');

        // Add a detailed note (customer-visible)
        $order->addNote(
            'Payment of '.Order::formatMoney($amountPaid, $currency).' received',
            OrderNote::TYPE_PAYMENT,
            true
        );

        // Mark order as processing if payment is successful
        if ($session->payment_status === 'paid' && $order->status === OrderStatus::PENDING) {
            $order->markAsProcessing('Payment received via Stripe checkout');
        }

        Log::info('Order payment recorded via Stripe checkout', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'amount' => $amountPaid,
            'currency' => $currency,
        ]);

        return true;
    }

    /**
     * Copy the Checkout Session's customer_details / shipping_details onto the
     * order as address snapshots. Only empty columns are filled so an address
     * the host application set beforehand is never overwritten. Null-safe:
     * a session without either block leaves the order untouched.
     *
     * Snapshot shape (both columns): name, email, phone, line1, line2,
     * postal_code, city, state, country, uid.
     */
    protected function persistSessionAddresses(Order $order, $session): void
    {
        $customer = data_get($session, 'customer_details');
        // Stripe API ≥ 2025-03-31 moved shipping_details under collected_information.
        $shipping = data_get($session, 'shipping_details') ?? data_get($session, 'collected_information.shipping_details');

        $billing = $this->addressSnapshotFromStripe($customer);
        $shipped = $this->addressSnapshotFromStripe($shipping, $customer) ?? $billing;

        $updates = [];

        if ($billing && empty((array) $order->billing_address)) {
            $updates['billing_address'] = $billing;
        }

        if ($shipped && empty((array) $order->shipping_address)) {
            $updates['shipping_address'] = $shipped;
        }

        // Optional customer columns (not part of the shipped schema, but a host
        // may add them): fill only when present and still empty.
        $email = data_get($customer, 'email');
        $name = data_get($customer, 'name');
        foreach (['customer_email' => $email, 'customer_name' => $name] as $column => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (! $this->orderHasColumn($order, $column)) {
                continue;
            }
            if (empty($order->getAttribute($column))) {
                $updates[$column] = $value;
            }
        }

        if ($updates === []) {
            return;
        }

        $order->forceFill($updates)->save();
    }

    /**
     * Build the JSON address snapshot from a Stripe details block
     * (customer_details or shipping_details). Contact fields missing on the
     * block (shipping_details carries no email/phone) are taken from
     * $contactFallback. Returns null when the block is empty.
     */
    protected function addressSnapshotFromStripe($details, $contactFallback = null): ?array
    {
        if (! $details) {
            return null;
        }

        $address = data_get($details, 'address');
        $taxIds = data_get($details, 'tax_ids');
        $uid = null;
        if (is_iterable($taxIds)) {
            foreach ($taxIds as $taxId) {
                $uid = data_get($taxId, 'value');
                if ($uid) {
                    break;
                }
            }
        }

        $snapshot = [
            'name' => data_get($details, 'name'),
            'email' => data_get($details, 'email') ?? data_get($contactFallback, 'email'),
            'phone' => data_get($details, 'phone') ?? data_get($contactFallback, 'phone'),
            'line1' => data_get($address, 'line1'),
            'line2' => data_get($address, 'line2'),
            'postal_code' => data_get($address, 'postal_code'),
            'city' => data_get($address, 'city'),
            'state' => data_get($address, 'state'),
            'country' => data_get($address, 'country'),
            'uid' => $uid,
        ];

        // A block with no usable data at all is treated as absent.
        $hasData = array_filter($snapshot, fn ($v) => $v !== null && $v !== '');

        return $hasData === [] ? null : $snapshot;
    }

    /**
     * Whether the orders table has an (optional, host-added) column.
     */
    protected function orderHasColumn(Order $order, string $column): bool
    {
        try {
            return $order->getConnection()->getSchemaBuilder()->hasColumn($order->getTable(), $column);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Handle checkout.session failed event
     */
    protected function handleCheckoutSessionFailed($session): bool
    {
        $cartId = $session->metadata->cart_id ?? $session->client_reference_id;

        if (! $cartId) {
            Log::warning('Stripe checkout session failed without cart ID', ['session_id' => $session->id]);

            return false;
        }

        $cart = Cart::find($cartId);
        if ($cart) {
            // Mark order as failed if it exists
            $order = $cart->order;
            if ($order && $order->status->canTransitionTo(OrderStatus::FAILED)) {
                $order->update(['status' => OrderStatus::FAILED]);
                // Internal note - payment failure details should not be shown to customer
                $order->addNote(
                    "Payment failed via Stripe checkout (Session: {$session->id})",
                    OrderNote::TYPE_PAYMENT,
                    false
                );
            }
        }

        Log::info('Stripe checkout session failed', [
            'cart_id' => $cartId,
            'session_id' => $session->id,
        ]);

        return true;
    }

    /**
     * Handle checkout.session.expired event
     */
    protected function handleCheckoutSessionExpired($session): bool
    {
        $cartId = $session->metadata->cart_id ?? $session->client_reference_id;

        if ($cartId) {
            $cart = Cart::find($cartId);
            if ($cart) {
                // Add note to order if it exists
                $order = $cart->order;
                if ($order) {
                    // Internal note - session expiry is a technical detail
                    $order->addNote(
                        "Stripe checkout session expired (Session: {$session->id})",
                        OrderNote::TYPE_SYSTEM,
                        false
                    );
                }
            }
        }

        Log::info('Stripe checkout session expired', [
            'cart_id' => $cartId,
            'session_id' => $session->id,
        ]);

        return true;
    }

    /**
     * Handle charge.succeeded event
     */
    protected function handleChargeSucceeded($charge): bool
    {
        Log::info('Stripe charge succeeded', [
            'charge_id' => $charge->id,
            'amount' => $charge->amount,
        ]);

        // Update purchases with this charge ID if they exist
        $purchases = ProductPurchase::where('charge_id', $charge->id)->get();
        foreach ($purchases as $purchase) {
            if ($purchase->status !== PurchaseStatus::COMPLETED) {
                $updateData = [
                    'status' => PurchaseStatus::COMPLETED,
                ];

                if (in_array('amount_paid', $purchase->getFillable())) {
                    // Cents, like `amount`. The charge covers the whole cart, so each
                    // purchase is paid its own line amount.
                    $updateData['amount_paid'] = $purchase->amount;
                }

                $purchase->update($updateData);

                // Claim stock if not already claimed
                $this->claimStockForPurchase($purchase);
            }
        }

        // Try to find related order via payment_intent. Under the payment lock: a
        // checkout.session.completed for the same payment may be recording it right now.
        $this->withPaymentLock($charge->payment_intent ?? "charge:{$charge->id}", function () use ($charge) {
            $order = $this->findOrderByPaymentIntent($charge->payment_intent);
            if (! $order) {
                return;
            }

            // Remember the charge so ledger rows (source_id = ch_…) can be matched to the order.
            if ($order->getMeta('stripe_charge_id') !== $charge->id) {
                $order->updateMetaKey('stripe_charge_id', $charge->id);
            }

            if (! $order->is_fully_paid) {
                // Stripe amounts are cents, like every amount_* column.
                $order->recordPayment((int) $charge->amount, $charge->id, 'stripe', 'stripe');
            }
        });

        $this->syncLedger($charge->id);

        return true;
    }

    /**
     * Handle charge.failed event
     */
    protected function handleChargeFailed($charge): bool
    {
        Log::warning('Stripe charge failed', [
            'charge_id' => $charge->id,
            'failure_message' => $charge->failure_message ?? 'Unknown error',
        ]);

        // Update purchases with this charge ID
        $purchases = ProductPurchase::where('charge_id', $charge->id)->get();
        foreach ($purchases as $purchase) {
            $purchase->update([
                'status' => PurchaseStatus::FAILED,
            ]);
        }

        // Try to find related order and add note
        $order = $this->findOrderByPaymentIntent($charge->payment_intent);
        if ($order) {
            $order->addNote(
                'Stripe charge failed: '.($charge->failure_message ?? 'Unknown error').
                    ' (Charge: '.$charge->id.', Code: '.($charge->failure_code ?? 'none').')',
                OrderNote::TYPE_PAYMENT
            );
        }

        return true;
    }

    /**
     * Handle charge.refunded event
     */
    protected function handleChargeRefunded($charge): bool
    {
        Log::info('Stripe charge refunded', [
            'charge_id' => $charge->id,
            'amount_refunded' => $charge->amount_refunded,
        ]);

        // Find order and record refund. `amount_refunded` is the charge's running total
        // in cents; only the part the order doesn't know yet is recorded.
        $order = $this->findOrderByPaymentIntent($charge->payment_intent)
            ?? $this->findOrderByChargeId($charge->id);
        if ($order) {
            $order->applyStripeRefundedTotal(
                (int) ($charge->amount_refunded ?? 0),
                "Refund processed via Stripe (Charge: {$charge->id})"
            );
        }

        $this->syncLedger($charge->id);

        return true;
    }

    /**
     * Handle charge.dispute.created event
     */
    protected function handleChargeDisputeCreated($dispute): bool
    {
        Log::warning('Stripe dispute created', [
            'dispute_id' => $dispute->id,
            'charge_id' => $dispute->charge,
            'amount' => $dispute->amount,
            'reason' => $dispute->reason,
        ]);

        $this->syncLedger($dispute->charge ?? null);

        // Try to find order via the charge
        $order = $this->findOrderByChargeId($dispute->charge);
        if ($order) {
            $order->update(['status' => OrderStatus::ON_HOLD]);
            $disputeAmount = (int) ($dispute->amount ?? 0);
            $order->addNote(
                'Payment dispute opened: '.($dispute->reason ?? 'Unknown reason').
                    " (Dispute: {$dispute->id}, Amount: ".Order::formatMoney($disputeAmount, $order->currency).')',
                OrderNote::TYPE_PAYMENT
            );
        }

        return true;
    }

    /**
     * Handle charge.dispute.closed event
     */
    protected function handleChargeDisputeClosed($dispute): bool
    {
        Log::info('Stripe dispute closed', [
            'dispute_id' => $dispute->id,
            'status' => $dispute->status,
        ]);

        $this->syncLedger($dispute->charge ?? null);

        $order = $this->findOrderByChargeId($dispute->charge);
        if ($order) {
            $outcome = $dispute->status === 'won' ? 'in your favor' : 'against you';
            $order->addNote(
                "Payment dispute closed {$outcome} (Dispute: {$dispute->id})",
                OrderNote::TYPE_PAYMENT
            );

            // If dispute was lost, mark as refunded
            if ($dispute->status === 'lost' && $order->status === OrderStatus::ON_HOLD) {
                $order->update(['status' => OrderStatus::REFUNDED]);
            } elseif ($dispute->status === 'won' && $order->status === OrderStatus::ON_HOLD) {
                // Restore to processing if dispute was won
                $order->update(['status' => OrderStatus::PROCESSING]);
            }
        }

        return true;
    }

    /**
     * Handle payment_intent.succeeded event
     */
    protected function handlePaymentIntentSucceeded($paymentIntent): bool
    {
        Log::info('Stripe payment intent succeeded', [
            'payment_intent_id' => $paymentIntent->id,
            'amount' => $paymentIntent->amount,
        ]);

        // Update purchases with this payment intent
        $purchases = ProductPurchase::where('charge_id', $paymentIntent->id)->get();
        foreach ($purchases as $purchase) {
            if ($purchase->status !== PurchaseStatus::COMPLETED) {
                $updateData = [
                    'status' => PurchaseStatus::COMPLETED,
                ];

                if (in_array('amount_paid', $purchase->getFillable())) {
                    // Cents, like `amount`: each purchase is paid its own line amount.
                    $updateData['amount_paid'] = $purchase->amount;
                }

                $purchase->update($updateData);

                // Claim stock if not already claimed
                $this->claimStockForPurchase($purchase);
            }
        }

        return true;
    }

    /**
     * Handle payment_intent.payment_failed event
     */
    protected function handlePaymentIntentFailed($paymentIntent): bool
    {
        Log::warning('Stripe payment intent failed', [
            'payment_intent_id' => $paymentIntent->id,
            'last_payment_error' => $paymentIntent->last_payment_error->message ?? 'Unknown error',
        ]);

        $purchases = ProductPurchase::where('charge_id', $paymentIntent->id)->get();
        foreach ($purchases as $purchase) {
            $purchase->update([
                'status' => PurchaseStatus::FAILED,
            ]);
        }

        return true;
    }

    /**
     * Handle payment_intent.canceled event
     */
    protected function handlePaymentIntentCanceled($paymentIntent): bool
    {
        Log::info('Stripe payment intent canceled', [
            'payment_intent_id' => $paymentIntent->id,
        ]);

        $order = $this->findOrderByPaymentIntent($paymentIntent->id);
        if ($order) {
            $order->addNote(
                "Payment intent was canceled (Intent: {$paymentIntent->id})",
                OrderNote::TYPE_PAYMENT
            );
        }

        return true;
    }

    /**
     * Handle refund.created event
     */
    protected function handleRefundCreated($refund): bool
    {
        Log::info('Stripe refund created', [
            'refund_id' => $refund->id,
            'charge_id' => $refund->charge,
            'amount' => $refund->amount,
        ]);

        // Checkout orders keep the payment intent as payment_reference, so try that first.
        $order = $this->findOrderByPaymentIntent($refund->payment_intent ?? null)
            ?? $this->findOrderByChargeId($refund->charge ?? null);
        if ($order) {
            // Cents; idempotent per refund id (see Order::applyStripeRefund()).
            $order->applyStripeRefund(
                (string) $refund->id,
                (int) ($refund->amount ?? 0),
                ($refund->reason ?? 'Refund created')." (Refund: {$refund->id})"
            );
        }

        $this->syncLedger($refund->charge ?? null);

        return true;
    }

    /**
     * Handle refund.updated event
     */
    protected function handleRefundUpdated($refund): bool
    {
        Log::info('Stripe refund updated', [
            'refund_id' => $refund->id,
            'status' => $refund->status,
        ]);

        $order = $this->findOrderByPaymentIntent($refund->payment_intent ?? null)
            ?? $this->findOrderByChargeId($refund->charge ?? null);
        if ($order) {
            $order->addNote(
                "Refund status updated to: {$refund->status} (Refund: {$refund->id})",
                OrderNote::TYPE_REFUND
            );
        }

        return true;
    }

    /**
     * Handle invoice.payment_succeeded event (for subscriptions)
     */
    protected function handleInvoicePaymentSucceeded($invoice): bool
    {
        Log::info('Stripe invoice payment succeeded', [
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription ?? null,
            'amount_paid' => $invoice->amount_paid,
        ]);

        // Invoice events are typically for subscriptions
        // Add order note if we can find the related order
        if ($invoice->metadata->order_id ?? null) {
            $order = $this->orderModel()::find($invoice->metadata->order_id);
            if ($order) {
                $amountPaid = (int) ($invoice->amount_paid ?? 0);
                $order->addNote(
                    'Subscription invoice paid: '.Order::formatMoney($amountPaid, $order->currency)." (Invoice: {$invoice->id})",
                    OrderNote::TYPE_PAYMENT
                );
            }
        }

        return true;
    }

    /**
     * Handle invoice.payment_failed event (for subscriptions)
     */
    protected function handleInvoicePaymentFailed($invoice): bool
    {
        Log::warning('Stripe invoice payment failed', [
            'invoice_id' => $invoice->id,
            'subscription_id' => $invoice->subscription ?? null,
        ]);

        if ($invoice->metadata->order_id ?? null) {
            $order = $this->orderModel()::find($invoice->metadata->order_id);
            if ($order) {
                $order->addNote(
                    "Subscription invoice payment failed (Invoice: {$invoice->id})",
                    OrderNote::TYPE_PAYMENT
                );
            }
        }

        return true;
    }

    /**
     * The order model class configured by the host app (`shop.models.order`).
     *
     * Every lookup/creation in this controller goes through it — not through
     * `Order::` statically — so a host subclass with its own boot hooks, casts
     * or scopes is what the real webhook path builds and returns.
     *
     * @return class-string<Order>
     */
    protected function orderModel(): string
    {
        return config('shop.models.order', Order::class);
    }

    /**
     * Copy the Checkout Session's amounts onto a not-yet-paid order: subtotal,
     * shipping (`total_details.amount_shipping`), discount, tax and total, all in
     * cents. The chosen shipping rate id goes to `meta.stripe_shipping_rate`.
     * An order that is already paid, or a session without amounts, is left alone.
     */
    protected function applySessionTotals(Order $order, $session): void
    {
        if ($order->paid_at || ! isset($session->amount_total)) {
            return;
        }

        $details = data_get($session, 'total_details');
        $updates = array_filter([
            'amount_subtotal' => isset($session->amount_subtotal) ? (int) $session->amount_subtotal : null,
            'amount_shipping' => data_get($details, 'amount_shipping') !== null ? (int) data_get($details, 'amount_shipping') : null,
            'amount_discount' => data_get($details, 'amount_discount') !== null ? (int) data_get($details, 'amount_discount') : null,
            'amount_tax' => data_get($details, 'amount_tax') !== null ? (int) data_get($details, 'amount_tax') : null,
            'amount_total' => (int) $session->amount_total,
        ], fn ($v) => $v !== null);

        $rate = data_get($session, 'shipping_cost.shipping_rate');
        if (is_object($rate)) {
            $rate = $rate->id ?? null;
        }

        $order->fill($updates);
        if ($rate) {
            $meta = (array) ($order->meta ?? []);
            $meta['stripe_shipping_rate'] = $rate;
            $order->meta = (object) $meta;
        }
        if ($order->isDirty()) {
            $order->save();
        }
    }

    /**
     * Upsert the charge's balance transactions into the ledger when
     * `shop.ledger.sync_on_webhook` is on. Never throws (see ShopService::syncLedgerForCharge()).
     */
    protected function syncLedger(?string $chargeId): void
    {
        if (! config('shop.ledger.sync_on_webhook') || ! $chargeId) {
            return;
        }

        try {
            app(\Blax\Shop\Services\ShopService::class)->syncLedgerForCharge($chargeId);
        } catch (\Throwable $e) {
            Log::warning('[shop:ledger] webhook ledger sync failed', ['charge' => $chargeId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Find an order by payment intent ID
     */
    protected function findOrderByPaymentIntent(?string $paymentIntentId): ?Order
    {
        if (! $paymentIntentId) {
            return null;
        }

        // First try to find via order's payment_reference
        $order = $this->orderModel()::where('payment_reference', $paymentIntentId)->first();
        if ($order) {
            return $order;
        }

        // Try to find via cart's stripe session meta
        $cart = Cart::whereJsonContains('meta->stripe_payment_intent', $paymentIntentId)->first();
        if ($cart) {
            return $cart->order;
        }

        // Try to find via purchase charge_id
        $purchase = ProductPurchase::where('charge_id', $paymentIntentId)->first();
        if ($purchase && $purchase->cart) {
            return $purchase->cart->order;
        }

        return null;
    }

    /**
     * Find an order by charge ID
     */
    protected function findOrderByChargeId(?string $chargeId): ?Order
    {
        if (! $chargeId) {
            return null;
        }

        // Try to find order where payment_reference contains the charge
        $order = $this->orderModel()::where('payment_reference', $chargeId)->first();
        if ($order) {
            return $order;
        }

        // Try to find via purchase charge_id
        $purchase = ProductPurchase::where('charge_id', $chargeId)->first();
        if ($purchase && $purchase->cart) {
            return $purchase->cart->order;
        }

        return null;
    }

    /**
     * Update product purchases for a checkout session
     */
    protected function updatePurchasesForSession(Cart $cart, $session)
    {
        // Get all purchases for this cart
        $purchases = ProductPurchase::where('cart_id', $cart->id)->get();

        foreach ($purchases as $purchase) {
            if (! $purchase) {
                continue;
            }

            $updateData = [
                'status' => PurchaseStatus::COMPLETED,
            ];

            // Update charge_id if it exists in fillable
            if (in_array('charge_id', $purchase->getFillable())) {
                $updateData['charge_id'] = $session->payment_intent;
            }

            // Update amount_paid if it exists in fillable
            if (in_array('amount_paid', $purchase->getFillable())) {
                // Use the purchase's amount since it was already set correctly
                $updateData['amount_paid'] = $purchase->amount;
            }

            $purchase->update($updateData);

            // Claim stock after successful payment
            $this->claimStockForPurchase($purchase);
        }
    }

    /**
     * Claim stock for a purchase (used after successful payment)
     */
    protected function claimStockForPurchase(ProductPurchase $purchase)
    {
        $product = $purchase->purchasable;
        if (! ($product instanceof Product)) {
            return;
        }

        // Skip if product doesn't manage stock
        if (! $product->manage_stock && ! $product->isPool()) {
            return;
        }

        // Determine if we need to claim stock with timespan (from/until)
        $hasTimespan = $purchase->from && $purchase->until;

        try {
            if ($product->isPool()) {
                // For pool products: claim from single items (they manage their own stock)
                // Only claim if there's a timespan (booking dates)
                if ($hasTimespan) {
                    $product->claimPoolStock(
                        $purchase->quantity,
                        $purchase,
                        $purchase->from,
                        $purchase->until,
                        "Purchase #{$purchase->id} completed"
                    );
                }
                // If no timespan, pool products don't claim stock
                // (single items would be simple products that don't need claiming)
            } elseif ($product->isBooking()) {
                // For booking products: claim stock for the timespan
                if ($hasTimespan) {
                    $product->claimStock(
                        $purchase->quantity,
                        $purchase,
                        $purchase->from,
                        $purchase->until,
                        "Purchase #{$purchase->id} completed"
                    );
                } else {
                    Log::warning('Booking product without timespan', [
                        'purchase_id' => $purchase->id,
                        'product_id' => $product->id,
                    ]);
                }
            } else {
                // For simple/consumable products (like shampoo bottle):
                // Decrease stock immediately (no timespan needed)
                if ($product->manage_stock) {
                    $product->decreaseStock($purchase->quantity);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to claim/decrease stock for purchase', [
                'purchase_id' => $purchase->id,
                'product_id' => $product->id,
                'product_type' => $product->type->value ?? 'unknown',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
