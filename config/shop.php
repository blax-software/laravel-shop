<?php

return [
    // Table names (customizable for multi-tenancy)
    'tables' => [
        'cart_items' => 'cart_items',
        'carts' => 'carts',
        'orders' => 'orders',
        'order_notes' => 'order_notes',
        'payment_methods' => 'payment_methods',
        'payment_provider_identities' => 'payment_provider_identities',
        'product_action_runs' => 'product_action_runs',
        'product_attributes' => 'product_attributes',
        'product_categories' => 'product_categories',
        'product_relations' => 'product_relations',
        'product_prices' => 'product_prices',
        'product_purchases' => 'product_purchases',
        'product_actions' => 'product_actions',
        'product_stocks' => 'product_stocks',
        'products' => 'products',
        'cart_discounts' => 'cart_discounts',
    ],

    // Model classes (allow overriding in main instance)
    'models' => [
        'product' => \Blax\Shop\Models\Product::class,
        'product_price' => \Blax\Shop\Models\ProductPrice::class,
        'product_category' => \Blax\Shop\Models\ProductCategory::class,
        'product_stock' => \Blax\Shop\Models\ProductStock::class,
        'product_attribute' => \Blax\Shop\Models\ProductAttribute::class,
        'product_purchase' => \Blax\Shop\Models\ProductPurchase::class,
        'cart' => \Blax\Shop\Models\Cart::class,
        'cart_item' => \Blax\Shop\Models\CartItem::class,
        'order' => \Blax\Shop\Models\Order::class,
        'order_note' => \Blax\Shop\Models\OrderNote::class,
        'payment_provider_identity' => \Blax\Shop\Models\PaymentProviderIdentity::class,
        'payment_method' => \Blax\Shop\Models\PaymentMethod::class,
    ],

    // API Routes configuration
    'routes' => [
        'enabled' => true,
        'prefix' => 'api/shop',
        'middleware' => ['api'],
        'name_prefix' => 'shop.',
    ],

    // Stock management
    'stock' => [
        'track_inventory' => true,
        'allow_backorders' => false,
        'low_stock_threshold' => 5,
        'log_changes' => true,
        'auto_release_expired' => true,
    ],

    // Product actions (extensible by main instance)
    'actions' => [
        'path' => app_path('Jobs/ProductAction'),
        'namespace' => 'App\\Jobs\\ProductAction',
        'auto_discover' => true,
    ],

    // Stripe integration (optional)
    'stripe' => [
        'enabled' => env('SHOP_STRIPE_ENABLED', false),
        'sync_prices' => true,
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        // Webhook events that the shop package listens for
        // You can customize this list to add/remove events as needed
        'webhook_events' => [
            // Checkout Session Events
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded',
            'checkout.session.async_payment_failed',
            'checkout.session.expired',

            // Charge Events
            'charge.succeeded',
            'charge.failed',
            'charge.refunded',
            'charge.dispute.created',
            'charge.dispute.closed',

            // Payment Intent Events
            'payment_intent.succeeded',
            'payment_intent.payment_failed',
            'payment_intent.canceled',

            // Refund Events
            'refund.created',
            'refund.updated',

            // Invoice Events (for subscriptions)
            'invoice.payment_succeeded',
            'invoice.payment_failed',
        ],
    ],

    // Currency configuration
    'currency' => env('SHOP_CURRENCY', 'usd'),

    // Cache configuration
    'cache' => [
        'enabled' => env('SHOP_CACHE_ENABLED', true),
        'ttl' => 3600,
        'prefix' => 'shop:',
    ],

    // Cart configuration
    'cart' => [
        'expire_after_days' => 30,
        'auto_cleanup' => true,
        'merge_on_login' => true,

        // Cart expiration: mark carts as expired after this many minutes of inactivity
        'expiration_minutes' => env('SHOP_CART_EXPIRATION_MINUTES', 60),

        // Cart deletion: delete unused carts after this many hours of inactivity
        'deletion_hours' => env('SHOP_CART_DELETION_HOURS', 24),
    ],

    // Order configuration
    'orders' => [
        'number_prefix' => env('SHOP_ORDER_PREFIX', 'ORD-'),
        'auto_complete_virtual' => true, // Auto-complete orders with only virtual products
        'auto_complete_paid' => false, // Auto-complete orders when fully paid
    ],

    // API Response format
    'api' => [
        'include_meta' => true,
        'wrap_response' => true,
        'response_key' => 'data',
    ],

];
