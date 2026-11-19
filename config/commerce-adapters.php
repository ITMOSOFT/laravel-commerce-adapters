<?php

return [
    'checkout_orders' => [
        'customer_type' => env('ITMOSOFT_CHECKOUT_ORDER_CUSTOMER_TYPE'),
        'metadata_key' => 'itmosoft_commerce_adapters',
    ],
    'checkout_tax' => [
        'destination' => env('ITMOSOFT_CHECKOUT_TAX_DESTINATION', 'shipping'),
        'include_shipping' => env('ITMOSOFT_CHECKOUT_TAX_INCLUDE_SHIPPING', false),
        'rounding_scale' => env('ITMOSOFT_CHECKOUT_TAX_ROUNDING_SCALE', 2),
    ],
    'checkout_shipping' => [
        'default_weight' => env('ITMOSOFT_CHECKOUT_SHIPPING_DEFAULT_WEIGHT', 0),
    ],
    'checkout_promotions' => [
        'metadata_key' => 'itmosoft_promotion',
    ],
    'payments_orders' => [
        'enabled' => env('ITMOSOFT_PAYMENTS_ORDERS_ENABLED', true),
        'process_on_full_capture' => true,
        'refund_on_full_refund' => true,
    ],
    'cart_inventory' => [
        'location_id' => env('ITMOSOFT_CART_INVENTORY_LOCATION_ID'),
    ],
    'inventory_orders' => [
        'enabled' => env('ITMOSOFT_INVENTORY_ORDERS_ENABLED', false),
        'location_id' => env('ITMOSOFT_INVENTORY_LOCATION_ID'),
    ],
    'product_catalog_variants' => [
        'enabled' => env('ITMOSOFT_PRODUCT_CATALOG_VARIANTS_ENABLED', true),
        // Set true only when other polymorphic product models also use the standalone variants table.
        'standalone_models_enabled' => env('ITMOSOFT_PRODUCT_VARIANTS_STANDALONE_MODELS_ENABLED', false),
    ],
];
