<?php

namespace Blax\Shop\Http\Controllers\Api;

use Blax\Shop\Models\PaymentMethod;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PaymentMethodController extends Controller
{
    // List all payment methods for the authenticated user
    public function index(Request $request)
    {
        $user = $request->user();
        $methods = PaymentMethod::whereHas('paymentProviderIdentity', function ($q) use ($user) {
            $q->where('customer_id', $user->getKey())
                ->where('customer_type', get_class($user));
        })->active()->get();
        return response()->json($methods);
    }

    // Store a new payment method (provider-agnostic)
    public function store(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'provider' => 'required|string',
            'provider_payment_method_id' => 'required|string',
            'type' => 'nullable|string',
            'name' => 'nullable|string',
            'last_digits' => 'nullable|string',
            'brand' => 'nullable|string',
            'exp_month' => 'nullable|integer',
            'exp_year' => 'nullable|integer',
            'is_default' => 'boolean',
            'meta' => 'array',
        ]);
        // Find or create PaymentProviderIdentity for user/provider
        $providerIdentity = \Blax\Shop\Models\PaymentProviderIdentity::firstOrCreate([
            'customer_id' => $user->getKey(),
            'customer_type' => get_class($user),
            'provider_name' => $data['provider'],
        ]);
        $method = PaymentMethod::create(array_merge($data, [
            'payment_provider_identity_id' => $providerIdentity->id,
        ]));
        return response()->json($method, 201);
    }

    // Show a specific payment method
    public function show($id, Request $request)
    {
        $user = $request->user();
        $method = PaymentMethod::where('id', $id)
            ->whereHas('paymentProviderIdentity', function ($q) use ($user) {
                $q->where('customer_id', $user->getKey())
                    ->where('customer_type', get_class($user));
            })->firstOrFail();
        return response()->json($method);
    }

    // Update a payment method
    public function update($id, Request $request)
    {
        $user = $request->user();
        $method = PaymentMethod::where('id', $id)
            ->whereHas('paymentProviderIdentity', function ($q) use ($user) {
                $q->where('customer_id', $user->getKey())
                    ->where('customer_type', get_class($user));
            })->firstOrFail();
        $data = $request->validate([
            'name' => 'nullable|string',
            'is_default' => 'boolean',
            'meta' => 'array',
        ]);
        $method->update($data);
        return response()->json($method);
    }

    // Delete a payment method
    public function destroy($id, Request $request)
    {
        $user = $request->user();
        $method = PaymentMethod::where('id', $id)
            ->whereHas('paymentProviderIdentity', function ($q) use ($user) {
                $q->where('customer_id', $user->getKey())
                    ->where('customer_type', get_class($user));
            })->firstOrFail();
        $method->delete();
        return response()->json(['deleted' => true]);
    }
}
