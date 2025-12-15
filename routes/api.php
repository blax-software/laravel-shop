<?php

use Blax\Shop\Http\Controllers\Api\CategoryController;
use Blax\Shop\Http\Controllers\Api\ProductController;
use Blax\Shop\Http\Controllers\StripeCheckoutController;
use Blax\Shop\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

$config = config('shop.routes');

Route::prefix($config['prefix'])
    ->middleware($config['middleware'])
    ->name($config['name_prefix'])
    ->group(function () {

        // Categories
        Route::get('categories', [CategoryController::class, 'index'])
            ->name('categories.index');

        Route::get('categories/tree', [CategoryController::class, 'tree'])
            ->name('categories.tree');

        Route::get('categories/{slug}', [CategoryController::class, 'show'])
            ->name('categories.show');

        Route::get('categories/{slug}/products', [CategoryController::class, 'products'])
            ->name('categories.products');

        // Products
        Route::get('products', [ProductController::class, 'index'])
            ->name('products.index');

        Route::get('products/{slug}', [ProductController::class, 'show'])
            ->name('products.show');
    });

// Stripe routes - only if enabled and not already defined by the Laravel instance
if (config('shop.stripe.enabled', false) && !Route::has('shop.stripe.checkout')) {
    Route::prefix($config['prefix'])
        ->middleware($config['middleware'])
        ->name($config['name_prefix'])
        ->group(function () {
            // Stripe Checkout
            Route::post('stripe/checkout/{cartId}', [StripeCheckoutController::class, 'createCheckoutSession'])
                ->name('stripe.checkout');

            Route::get('stripe/success', [StripeCheckoutController::class, 'success'])
                ->name('stripe.success');

            Route::get('stripe/cancel', [StripeCheckoutController::class, 'cancel'])
                ->name('stripe.cancel');

            // Stripe Webhook (no auth middleware)
            Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
                ->withoutMiddleware($config['middleware'])
                ->name('stripe.webhook');
        });
}
