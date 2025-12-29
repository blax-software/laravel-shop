<?php

namespace Blax\Shop\Http\Controllers;

use Blax\Shop\Enums\CartStatus;
use Blax\Shop\Enums\OrderStatus;
use Blax\Shop\Enums\PurchaseStatus;
use Blax\Shop\Models\Cart;
use Blax\Shop\Models\Order;
use Blax\Shop\Models\OrderNote;
use Blax\Shop\Models\ProductPurchase;
use Illuminate\Http\Request;
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
        if (!config('shop.stripe.enabled')) {
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
     * Handle checkout.session.completed event
     */
    protected function handleCheckoutSessionCompleted($session): bool
    {
        $cartId = $session->metadata->cart_id ?? $session->client_reference_id;

        if (!$cartId) {
            Log::warning('Stripe checkout session completed without cart ID', ['session_id' => $session->id]);
            return false;
        }

        $cart = Cart::find($cartId);
        if (!$cart) {
            Log::warning('Stripe checkout session for non-existent cart', ['cart_id' => $cartId]);
            return false;
        }

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

        // Record payment on the associated order
        $order = $cart->order;
        if ($order) {
            $amountPaid = (int) (($session->amount_total ?? 0) / 100);
            $currency = strtoupper($session->currency ?? $order->currency ?? 'USD');

            // recordPayment(int $amount, ?string $reference, ?string $method, ?string $provider)
            $order->recordPayment($amountPaid, $session->payment_intent, 'stripe', 'stripe');

            // Add a detailed note
            $order->addNote(
                "Payment of " . Order::formatMoney($amountPaid, $currency) . " received via Stripe checkout (Session: {$session->id})",
                OrderNote::TYPE_PAYMENT
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
        }

        return true;
    }

    /**
     * Handle checkout.session failed event
     */
    protected function handleCheckoutSessionFailed($session): bool
    {
        $cartId = $session->metadata->cart_id ?? $session->client_reference_id;

        if (!$cartId) {
            Log::warning('Stripe checkout session failed without cart ID', ['session_id' => $session->id]);
            return false;
        }

        $cart = Cart::find($cartId);
        if ($cart) {
            // Mark order as failed if it exists
            $order = $cart->order;
            if ($order && $order->status->canTransitionTo(OrderStatus::FAILED)) {
                $order->update(['status' => OrderStatus::FAILED]);
                // addNote(string $content, string $type, bool $isCustomerNote, ?string $authorType, ?string $authorId)
                $order->addNote(
                    "Payment failed via Stripe checkout (Session: {$session->id})",
                    OrderNote::TYPE_PAYMENT
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
                    $order->addNote(
                        "Stripe checkout session expired (Session: {$session->id})",
                        OrderNote::TYPE_SYSTEM
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
                    $updateData['amount_paid'] = $charge->amount / 100;
                }

                $purchase->update($updateData);

                // Claim stock if not already claimed
                $this->claimStockForPurchase($purchase);
            }
        }

        // Try to find related order via payment_intent
        $order = $this->findOrderByPaymentIntent($charge->payment_intent);
        if ($order && !$order->is_fully_paid) {
            $amountPaid = (int) ($charge->amount / 100);
            // recordPayment(int $amount, ?string $reference, ?string $method, ?string $provider)
            $order->recordPayment($amountPaid, $charge->id, 'stripe', 'stripe');
        }

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
                'Stripe charge failed: ' . ($charge->failure_message ?? 'Unknown error') .
                    ' (Charge: ' . $charge->id . ', Code: ' . ($charge->failure_code ?? 'none') . ')',
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

        // Find order and record refund
        $order = $this->findOrderByPaymentIntent($charge->payment_intent);
        if ($order) {
            $refundAmount = (int) ($charge->amount_refunded / 100);

            // Only record refund if the amount changed
            if ($refundAmount > 0 && $order->amount_refunded < $refundAmount) {
                $newRefundAmount = $refundAmount - $order->amount_refunded;
                // recordRefund(int $amount, ?string $reason)
                $order->recordRefund($newRefundAmount, "Refund processed via Stripe (Charge: {$charge->id})");
            }
        }

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

        // Try to find order via the charge
        $order = $this->findOrderByChargeId($dispute->charge);
        if ($order) {
            $order->update(['status' => OrderStatus::ON_HOLD]);
            $disputeAmount = ($dispute->amount ?? 0) / 100;
            $order->addNote(
                'Payment dispute opened: ' . ($dispute->reason ?? 'Unknown reason') .
                    " (Dispute: {$dispute->id}, Amount: " . Order::formatMoney($disputeAmount, $order->currency) . ')',
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
                    $updateData['amount_paid'] = $paymentIntent->amount / 100;
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

        $order = $this->findOrderByChargeId($refund->charge);
        if ($order) {
            $refundAmount = (int) ($refund->amount / 100);
            // recordRefund(int $amount, ?string $reason)
            $order->recordRefund($refundAmount, ($refund->reason ?? 'Refund created') . " (Refund: {$refund->id})");
        }

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

        $order = $this->findOrderByChargeId($refund->charge);
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
            $order = Order::find($invoice->metadata->order_id);
            if ($order) {
                $amountPaid = ($invoice->amount_paid ?? 0) / 100;
                $order->addNote(
                    "Subscription invoice paid: " . Order::formatMoney($amountPaid, $order->currency) . " (Invoice: {$invoice->id})",
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
            $order = Order::find($invoice->metadata->order_id);
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
     * Find an order by payment intent ID
     */
    protected function findOrderByPaymentIntent(?string $paymentIntentId): ?Order
    {
        if (!$paymentIntentId) {
            return null;
        }

        // First try to find via order's payment_reference
        $order = Order::where('payment_reference', $paymentIntentId)->first();
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
        if (!$chargeId) {
            return null;
        }

        // Try to find order where payment_reference contains the charge
        $order = Order::where('payment_reference', $chargeId)->first();
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
            if (!$purchase) {
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
        if (!($product instanceof \Blax\Shop\Models\Product)) {
            return;
        }

        // Skip if product doesn't manage stock
        if (!$product->manage_stock && !$product->isPool()) {
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
