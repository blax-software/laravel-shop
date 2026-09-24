<?php

return [

    /*
     * Whether the package should auto-run its migrations.
     *
     * Default: true — fresh installs work plug-and-play (composer require +
     * php artisan migrate). The package's own migrations live in vendor/ and
     * are auto-loaded.
     *
     * Set to false if you have already published migrations to your project's
     * database/migrations directory and want to manage the schema yourself.
     * If you publish *and* leave this true, Laravel's migrator will see the
     * same filenames in both locations and run each migration once — but
     * that requires the published filename to match the source filename. If
     * you've published with a different timestamp prefix, disable this flag
     * to avoid re-runs.
     */
    'run_migrations' => true,

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
        'product_price_tiers' => 'product_price_tiers',
        'products' => 'products',
        'cart_discounts' => 'cart_discounts',
        'subscriptions' => 'subscriptions',
        'subscription_items' => 'subscription_items',
        'license_seats' => 'license_seats',
        'stripe_transactions' => 'stripe_transactions',
    ],

    // Model classes (allow overriding in main instance)
    'models' => [
        'product' => \Blax\Shop\Models\Product::class,
        'product_price' => \Blax\Shop\Models\ProductPrice::class,
        'product_category' => \Blax\Shop\Models\ProductCategory::class,
        'product_stock' => \Blax\Shop\Models\ProductStock::class,
        'product_price_tier' => \Blax\Shop\Models\ProductPriceTier::class,
        'product_attribute' => \Blax\Shop\Models\ProductAttribute::class,
        'product_purchase' => \Blax\Shop\Models\ProductPurchase::class,
        'cart' => \Blax\Shop\Models\Cart::class,
        'cart_item' => \Blax\Shop\Models\CartItem::class,
        'order' => \Blax\Shop\Models\Order::class,
        'order_note' => \Blax\Shop\Models\OrderNote::class,
        'payment_provider_identity' => \Blax\Shop\Models\PaymentProviderIdentity::class,
        'payment_method' => \Blax\Shop\Models\PaymentMethod::class,
        'subscription' => \Blax\Shop\Models\Subscription::class,
        'subscription_item' => \Blax\Shop\Models\SubscriptionItem::class,
        'license_seat' => \Blax\Shop\Models\LicenseSeat::class,
        'stripe_transaction' => \Blax\Shop\Models\StripeTransaction::class,
    ],

    /*
     * Stripe BalanceTransaction ledger.
     *
     * `revenue_types` are the BalanceTransaction `type`s that count toward
     * "money the business earned, net of fees and refunds". Charges add, refunds
     * and disputes subtract; per-charge Stripe fees are already inside each row's
     * `net`. Bank movements (`payout`, `transfer`) are excluded — they move money
     * that was already counted, they are not revenue or cost.
     */
    'ledger' => [
        /*
         * Sync the charge's balance transactions into the ledger whenever a
         * charge, refund or dispute webhook arrives (one Stripe API call each).
         * Off by default: the ledger then only fills via shop:import-stripe-ledger.
         */
        'sync_on_webhook' => env('SHOP_LEDGER_SYNC_ON_WEBHOOK', false),

        'revenue_types' => [
            'charge',
            'payment',
            'refund',
            'payment_refund',
            'adjustment',
            'dispute',
            'dispute_reversal',
        ],
    ],

    /*
     * Conditional-price entitlement checker.
     *
     * A ProductPrice can carry `meta.requires` (e.g. {"role":"seat"}) so it is
     * only offered to buyers who already hold something else — a subscription,
     * role, or prior purchase (loyalty / bundle pricing). The package resolves
     * the cheapest *eligible* price but delegates "does this buyer satisfy the
     * requirement" to a host-provided \Blax\Shop\Contracts\EntitlementChecker,
     * because entitlement semantics are app-specific (laravel-roles, Cashier,
     * a custom table). Point this at your implementation's class-string.
     *
     * Left null, the package binds \Blax\Shop\Services\DenyAllEntitlementChecker
     * — conditional prices are never offered until a real checker is wired up
     * (fail-closed: a forgotten binding can't leak a discount to everyone).
     */
    'entitlement_checker' => null,

    /*
     * Subscriptions are Cashier-backed. The package binds its own
     * Cashier-extending Subscription / SubscriptionItem models (above) so it
     * can link a subscription to a product and run product actions on the
     * billing lifecycle. Set `subscriptions.register_cashier_models` to false
     * if the host app wants to point Cashier at its own models instead.
     */
    'subscriptions' => [
        'register_cashier_models' => env('SHOP_REGISTER_CASHIER_MODELS', true),
        // Stripe interval => the product-action event fired on a NEW subscription.
        'started_event' => 'subscription.started',
        'renewed_event' => 'subscription.renewed',
        'canceled_event' => 'subscription.canceled',
    ],

    /*
     * Assignable license seats.
     *
     * When a product is seat-based (Product::isSeatBased() — driven by
     * `meta.seat_based = true`), a purchase/subscription of `quantity = N`
     * provisions N assignable LicenseSeat rows instead of granting the buyer.
     * Assigning a seat fires the product's actions for `assigned_event` with an
     * explicit `grantee`; reclaiming/reassigning fires `revoked_event`. Host
     * action jobs should honor `grantee` (falling back to the buyer) so the
     * same jobs serve both personal purchases and seat assignments.
     */
    'seats' => [
        'enabled' => env('SHOP_SEATS_ENABLED', true),
        'assigned_event' => 'seat.assigned',
        'revoked_event' => 'seat.revoked',
    ],

    /*
     * Tax rates applied to taxable charges and subscriptions.
     *
     * TaxService::rates() is the single source of truth for the applied VAT
     * rate on every checkout path. Point `rates` at your Stripe tax-rate id(s)
     * (e.g. 'txr_...' for 19% German VAT) via SHOP_TAX_RATES, either one id or
     * a comma-separated list. The host app decides tax-exemption (reverse-charge
     * / zero-rated) and passes it to TaxService::rates($exempt); a repo may also
     * hand rates() an explicit list instead of reading this config.
     *
     * Set SHOP_TAX_REQUIRE=true in production once a rate is configured: a
     * non-exempt charge with no rate then throws instead of silently billing 0%.
     */
    'tax' => [
        'rates' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('SHOP_TAX_RATES', ''))),
            static fn ($id) => $id !== '',
        )),
        'require' => (bool) env('SHOP_TAX_REQUIRE', false),
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

    /*
    |--------------------------------------------------------------------------
    | Loan / rental defaults
    |--------------------------------------------------------------------------
    |
    | Consumed by ProductPurchase::extend() and ProductPurchase::canExtend()
    | when no overrides are passed. Lets a host app (library, equipment-rental
    | etc.) tune the lifecycle without subclassing the model.
    |
    */
    'loan' => [
        // Loan policy knobs (used by ProductPurchase lifecycle helpers).
        // Pricing lives on the ProductPrice itself (billing_scheme=tiered +
        // associated product_price_tiers rows), not here.
        'default_duration_weeks' => env('SHOP_LOAN_DURATION_WEEKS', 2),
        'extension_weeks' => env('SHOP_LOAN_EXTENSION_WEEKS', 1),
        'max_extensions' => env('SHOP_LOAN_MAX_EXTENSIONS', 2),
    ],

    /*
    |--------------------------------------------------------------------------
    | API pagination
    |--------------------------------------------------------------------------
    |
    | Consumed by the public API controllers (Http/Controllers/Api/*).
    | `per_page`     — default page size when the request doesn't specify one.
    | `max_per_page` — upper bound the controller will honour regardless of
    |                  what the client asks for, so a malicious or careless
    |                  caller can't request all rows in one request.
    |
    */
    'pagination' => [
        'per_page' => env('SHOP_API_PER_PAGE', 24),
        'max_per_page' => env('SHOP_API_MAX_PER_PAGE', 100),
    ],

];
