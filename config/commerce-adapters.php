<?php

return [
    'checkout_orders' => [
        /*
         * If set, this morph type is used for CheckoutSession::user_id when
         * creating the order's customer relation.
         */
        'customer_type' => env('ITMOSOFT_CHECKOUT_ORDER_CUSTOMER_TYPE'),
        'metadata_key' => 'itmosoft_commerce_adapters',
    ],

    'payments_orders' => [
        /*
         * Automatically connect Payment events to Order status transitions.
         * Set false if the application wants to invoke PaymentOrderAdapter
         * explicitly or provide its own event orchestration.
         */
        'enabled' => env('ITMOSOFT_PAYMENTS_ORDERS_ENABLED', true),

        /*
         * An order moves pending -> processing only when aggregate captured
         * payments for that order meet or exceed the order grand total.
         */
        'process_on_full_capture' => true,

        /*
         * An order moves processing/completed -> refunded only when all
         * captured payments linked to it have been fully refunded.
         */
        'refund_on_full_refund' => true,
    ],
];