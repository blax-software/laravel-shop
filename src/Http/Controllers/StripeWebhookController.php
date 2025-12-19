<?php

namespace Blax\Shop\Http\Controllers;

use Blax\Shop\Models\Cart;
use Blax\Shop\Models\ProductPurchase;
use Blax\Shop\Enums\CartStatus;
use Blax\Shop\Enums\PurchaseStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Webhook;
use Stripe\Exception\SignatureVerificationException;

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
        $webhookSecret = config('services.stripe.webhook_secret');

        try {
            // Verify webhook signature
            if ($webhookSecret) {
                $event = Webhook::constructEvent($payload, $sigHeader, $webhookSecret);
            } else {
                // If no webhook secret, parse the event directly (not recommended for production)
                $event = json_decode($payload);
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
            switch ($event->type) {
                case 'checkout.session.completed':
                    $this->handleCheckoutSessionCompleted($event->data->object);
                    break;

                case 'checkout.session.async_payment_succeeded':
                    $this->handleCheckoutSessionCompleted($event->data->object);
                    break;

                case 'checkout.session.async_payment_failed':
                    $this->handleCheckoutSessionFailed($event->data->object);
                    break;

                case 'charge.succeeded':
                    $this->handleChargeSucceeded($event->data->object);
                    break;

                case 'charge.failed':
                    $this->handleChargeFailed($event->data->object);
                    break;

                case 'payment_intent.succeeded':
                    $this->handlePaymentIntentSucceeded($event->data->object);
                    break;

                case 'payment_intent.payment_failed':
                    $this->handlePaymentIntentFailed($event->data->object);
                    break;

                default:
                    Log::info('Stripe webhook unhandled event type', ['type' => $event->type]);
            }

            return response()->json(['success' => true]);
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
     * Handle checkout.session.completed event
     */
    protected function handleCheckoutSessionCompleted($session)
    {
        $cartId = $session->metadata->cart_id ?? $session->client_reference_id;

        if (!$cartId) {
            Log::warning('Stripe checkout session completed without cart ID', ['session_id' => $session->id]);
            return;
        }

        $cart = Cart::find($cartId);
        if (!$cart) {
            Log::warning('Stripe checkout session for non-existent cart', ['cart_id' => $cartId]);
            return;
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
    }

    /**
     * Handle checkout.session failed event
     */
    protected function handleCheckoutSessionFailed($session)
    {
        $cartId = $session->metadata->cart_id ?? $session->client_reference_id;

        if (!$cartId) {
            Log::warning('Stripe checkout session failed without cart ID', ['session_id' => $session->id]);
            return;
        }

        Log::info('Stripe checkout session failed', [
            'cart_id' => $cartId,
            'session_id' => $session->id,
        ]);

        // Cart remains in active state for retry
    }

    /**
     * Handle charge.succeeded event
     */
    protected function handleChargeSucceeded($charge)
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
    }

    /**
     * Handle charge.failed event
     */
    protected function handleChargeFailed($charge)
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
    }

    /**
     * Handle payment_intent.succeeded event
     */
    protected function handlePaymentIntentSucceeded($paymentIntent)
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
    }

    /**
     * Handle payment_intent.payment_failed event
     */
    protected function handlePaymentIntentFailed($paymentIntent)
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
