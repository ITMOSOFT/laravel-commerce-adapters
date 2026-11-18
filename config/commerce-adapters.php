<?php

return [
    'checkout_orders' => [
        'customer_type' => env('ITMOSOFT_CHECKOUT_ORDER_CUSTOMER_TYPE'),
        'metadata_key' => 'itmosoft_commerce_adapters',
    ],

    'checkout_tax' => [
        // Tax class is supplied explicitly because Checkout snapshots do not
        // currently carry a canonical tax-class identifier per line.
        'destination' => env('ITMOSOFT_CHECKOUT_TAX_DESTINATION', 'shipping'),
        'include_shipping' => env('ITMOSOFT_CHECKOUT_TAX_INCLUDE_SHIPPING', false),
        'rounding_scale' => env('ITMOSOFT_CHECKOUT_TAX_ROUNDING_SCALE', 2),
    ],

    'checkout_shipping' => [
        // Shipping quotes are resolved on demand and revalidated when selected.
        'default_weight' => env('ITMOSOFT_CHECKOUT_SHIPPING_DEFAULT_WEIGHT', 0),
    ],

    'payments_orders' => [
        'enabled' => env('ITMOSOFT_PAYMENTS_ORDERS_ENABLED', true),
        'process_on_full_capture' => true,
        'refund_on_full_refund' => true,
    ],

    'cart_inventory' => [
        // Advisory availability checks only; carts do not reserve stock.
        'location_id' => env('ITMOSOFT_CART_INVENTORY_LOCATION_ID'),
    ],

    'inventory_orders' => [
        /*
         * Opt in after configuring a default stock location. Inventory
         * reservation failures abort the order status transition; when the
         * transition was initiated by a payment capture, the capture itself
         * remains recorded and the order remains pending for reconciliation.
         */
        'enabled' => env('ITMOSOFT_INVENTORY_ORDERS_ENABLED', false),
        'location_id' => env('ITMOSOFT_INVENTORY_LOCATION_ID'),
    ],
];
