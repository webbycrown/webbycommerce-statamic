<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | The default currency used for all transactions.
    |
    */
    'currency' => env('WEBBYCOMMERCE_CURRENCY', env('WEBBYCOMMERCE_CURRENCY', 'USD')),
    'store_email' => env('WEBBYCOMMERCE_STORE_EMAIL', env('WEBBYCOMMERCE_STORE_EMAIL', null)),

    /*
    |--------------------------------------------------------------------------
    | Shipping Configuration
    |--------------------------------------------------------------------------
    |
    | Shipping methods are primarily managed via the "shipping_rates" collection
    | in the Statamic Control Panel. Each entry defines a shipping method with
    | zone-based location rules (country, state, city, ZIP).
    |
    | The fallback settings below are used when no zone-specific match is found.
    |
    | Legacy config-based methods are preserved below for backward compatibility.
    |
    */
    'shipping' => [
        'default_method' => 'standard',

        // Fallback when no shipping_rates entry matches the customer address
        'fallback_cost' => env('WEBBYCOMMERCE_SHIPPING_FALLBACK_COST', env('WEBBYCOMMERCE_SHIPPING_FALLBACK_COST', 0)),
        'fallback_name' => env('WEBBYCOMMERCE_SHIPPING_FALLBACK_NAME', env('WEBBYCOMMERCE_SHIPPING_FALLBACK_NAME', 'Standard Shipping')),
        'fallback_description' => env('WEBBYCOMMERCE_SHIPPING_FALLBACK_DESC', env('WEBBYCOMMERCE_SHIPPING_FALLBACK_DESC', 'Default shipping rate')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Product Configuration
    |-------------- ------------------------------------------------------------
    |
    | Product-related settings.
    |
    */
    'products' => [
        'collection' => 'products',
        'route' => 'products/{slug}',
    ],

    /*
    |--------------------------------------------------------------------------
    | Order Configuration
    |--------------------------------------------------------------------------
    |
    | Order-related settings.
    |
    */
    'orders' => [
        'collection' => 'orders',
        'number_prefix' => 'ORD',
        'status_flow' => ['pending', 'processing', 'shipped', 'delivered'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Customer Configuration
    |--------------------------------------------------------------------------
    |
    | Customer-related settings.
    |
    */
    'customers' => [
        'collection' => 'customers',
    ],

    /*
    |--------------------------------------------------------------------------
    | Coupon Configuration
    |--------------------------------------------------------------------------
    |
    | Coupon-related settings.
    |
    */
    'coupons' => [
        'collection' => 'coupons',
    ],

    /*
    |--------------------------------------------------------------------------
    | Cart Configuration
    |--------------------------------------------------------------------------
    |
    | Cart session and persistence settings.
    |
    */
    'cart' => [
        'session_key' => 'cart',
        'expires_after' => 60 * 24 * 7, // 7 days in minutes
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Configuration
    |--------------------------------------------------------------------------
    |
    | Payment gateway settings.
    |
    */
    'payment' => [
        'default_gateway' => env('WEBBYCOMMERCE_PAYMENT_GATEWAY', env('WEBBYCOMMERCE_PAYMENT_GATEWAY', 'stripe')),
        'redirect_url' => env('WEBBYCOMMERCE_PAYMENT_REDIRECT_URL', env('WEBBYCOMMERCE_PAYMENT_REDIRECT_URL', null)),
        'callback_url' => env('WEBBYCOMMERCE_PAYMENT_CALLBACK_URL', env('WEBBYCOMMERCE_PAYMENT_CALLBACK_URL', null)),
        'webhook_url' => env('WEBBYCOMMERCE_PAYMENT_WEBHOOK_URL', env('WEBBYCOMMERCE_PAYMENT_WEBHOOK_URL', null)),
        'gateways' => [
            'stripe' => [
                'secret_key' => env('STRIPE_SECRET_KEY'),
                'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
                'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
                'webhook_url' => env('STRIPE_WEBHOOK_URL', null),
                'redirect_url' => env('STRIPE_REDIRECT_URL', null),
                'callback_url' => env('STRIPE_CALLBACK_URL', null),
            ],
            'paypal' => [
                'client_id' => env('PAYPAL_CLIENT_ID'),
                'secret' => env('PAYPAL_SECRET'),
                'webhook_url' => env('PAYPAL_WEBHOOK_URL', null),
                'redirect_url' => env('PAYPAL_REDIRECT_URL', null),
                'callback_url' => env('PAYPAL_CALLBACK_URL', null),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Configuration
    |--------------------------------------------------------------------------
    |
    | Email notification settings.
    |
    */
    'emails' => [
        'order_confirmation' => [
            'enabled' => true,
            'to_customer' => true,
            'to_admin' => env('WEBBYCOMMERCE_ORDER_CONFIRMATION_TO_ADMIN', env('WEBBYCOMMERCE_ORDER_CONFIRMATION_TO_ADMIN', false)),
            'admin_email' => env('WEBBYCOMMERCE_ADMIN_EMAIL', env('WEBBYCOMMERCE_ADMIN_EMAIL')),
        ],
        'order_shipped' => [
            'enabled' => true,
            'to_customer' => true,
        ],
    ],
];
