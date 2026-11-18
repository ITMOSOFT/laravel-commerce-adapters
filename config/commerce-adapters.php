<?php

return [
    'checkout_orders' => [
        /*
         * If set, this morph type is used for CheckoutSession::user_id when
         * creating the order's customer relation. Configure it to the same
         * morph alias/class your application uses.
         */
        'customer_type' => env('ITMOSOFT_CHECKOUT_ORDER_CUSTOMER_TYPE'),

        /*
         * Metadata key owned by this adapter. Existing checkout metadata is
         * preserved; this nested key stores the resulting order reference.
         */
        'metadata_key' => 'itmosoft_commerce_adapters',
    ],
];