<?php

namespace Blax\Shop\Http\Controllers;

use Blax\Shop\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Checkout\Session as StripeSession;

class StripeCheckoutController
{
    public function __construct()
    {
        if (config('shop.stripe.enabled')) {
            Stripe::setApiKey(config('services.stripe.secret'));
        }
    }

    /**
     * Create a Stripe Checkout Session for a cart
     */
    public function createCheckoutSession(Request $request, string $cartId)
    {
        if (!config('shop.stripe.enabled')) {
            return response()->json(['error' => 'Stripe is not enabled'], 400);
        }

        try {
            $cart = Cart::findOrFail($cartId);

            // Use the cart's checkoutSession method which handles syncing and session creation
            $session = $cart->checkoutSession([
                'success_url' => $request->input('success_url'),
                'cancel_url' => $request->input('cancel_url'),
                'metadata' => $request->input('metadata', []),
            ]);

            return response()->json([
                'session_id' => $session->id,
                'url' => $session->url,
            ]);
        } catch (\Exception $e) {
            Log::error('Stripe checkout session creation failed', [
                'cart_id' => $cartId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to create checkout session: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle successful payment
     */
    public function success(Request $request)
    {
        $sessionId = $request->get('session_id');
        $cartId = $request->get('cart_id');

        if (!$sessionId || !$cartId) {
            return response()->json(['error' => 'Missing session or cart ID'], 400);
        }

        try {
            $cart = Cart::findOrFail($cartId);

            // Verify the session
            $session = StripeSession::retrieve($sessionId);

            if ($session->payment_status === 'paid') {
                // Update cart status to converted
                $cart->update([
                    'status' => \Blax\Shop\Enums\CartStatus::CONVERTED,
                    'converted_at' => now(),
                ]);

                // Update product purchases with charge information
                $purchases = $cart->items()->with('purchase')->get()->pluck('purchase')->filter();
                foreach ($purchases as $purchase) {
                    if ($purchase && method_exists($purchase, 'update')) {
                        $updateData = [
                            'status' => \Blax\Shop\Enums\PurchaseStatus::COMPLETED,
                        ];

                        // Only update if column exists
                        if (in_array('charge_id', $purchase->getFillable())) {
                            $updateData['charge_id'] = $session->payment_intent;
                        }
                        if (in_array('amount_paid', $purchase->getFillable())) {
                            $updateData['amount_paid'] = $session->amount_total / 100; // Convert from cents
                        }

                        $purchase->update($updateData);
                    }
                }

                return response()->json([
                    'success' => true,
                    'message' => 'Payment successful',
                    'cart_id' => $cart->id,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => 'Payment not completed',
            ], 400);
        } catch (\Exception $e) {
            Log::error('Stripe success handler failed', [
                'session_id' => $sessionId,
                'cart_id' => $cartId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to process success: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle cancelled payment
     */
    public function cancel(Request $request)
    {
        $cartId = $request->get('cart_id');

        if (!$cartId) {
            return response()->json(['error' => 'Missing cart ID'], 400);
        }

        try {
            $cart = Cart::findOrFail($cartId);

            // Cart remains in active state, user can try again
            return response()->json([
                'success' => true,
                'message' => 'Payment cancelled',
                'cart_id' => $cart->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Stripe cancel handler failed', [
                'cart_id' => $cartId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => 'Failed to process cancellation: ' . $e->getMessage()
            ], 500);
        }
    }
}
