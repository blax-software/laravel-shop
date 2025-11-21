<?php

return [
    // Table names (customizable for multi-tenancy)
    'tables' => [
        'products' => 'products',
        'product_prices' => 'product_prices',
        'product_categories' => 'product_categories',
        'product_images' => 'product_images',
        'product_attributes' => 'product_attributes',
        'product_purchases' => 'product_purchases',
        'product_stocks' => 'product_stocks',
        'carts' => 'carts',
        'cart_items' => 'cart_items',
    ],

    // Model classes (allow overriding in main instance)
    'models' => [
        'product' => \Blax\Shop\Models\Product::class,
        'product_price' => \Blax\Shop\Models\ProductPrice::class,
        'product_category' => \Blax\Shop\Models\ProductCategory::class,
        'product_stock' => \Blax\Shop\Models\ProductStock::class,
        'product_attribute' => \Blax\Shop\Models\ProductAttribute::class,
        'cart' => \Blax\Shop\Models\Cart::class,
        'cart_item' => \Blax\Shop\Models\CartItem::class,
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
    ],

    // Cache configuration
    'cache' => [
        'enabled' => env('SHOP_CACHE_ENABLED', true),
        'ttl' => 3600,
        'prefix' => 'shop:',
    ],

    // Pagination
    'pagination' => [
        'per_page' => 20,
        'max_per_page' => 100,
    ],

    // Cart configuration
    'cart' => [
        'expire_after_days' => 30,
        'auto_cleanup' => true,
        'merge_on_login' => true,
    ],

    // API Response format
    'api' => [
        'include_meta' => true,
        'wrap_response' => true,
        'response_key' => 'data',
    ],
];
