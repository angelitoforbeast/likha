<?php

return [
    'env' => env('JNT_ENV', 'sandbox'),

    'base_url' => env('JNT_BASE_URL', 'https://demostandard.jtexpress.ph/jts-phl-order-api'),

    'credentials' => [
        'eccompanyid' => env('JNT_ECCOMPANYID'),
        'customerid'  => env('JNT_CUSTOMERID'),
        'secret'      => env('JNT_SECRET'),
        'api_key'     => env('JNT_API_KEY'), // keep optional; you already confirmed NOT needed as header for demo
    ],

    'timeout' => env('JNT_TIMEOUT', 30),

    'signing' => [
        'mode' => env('JNT_SIGN_MODE', 'md5_base64_urlencode'),
    ],

    'endpoints' => [
        'create'      => env('JNT_EP_CREATE', '/api/order/create'),
        'cancel'      => env('JNT_EP_CANCEL', '/api/order/cancel'),
        'queryOrder'  => env('JNT_EP_QUERY',  '/api/order/queryOrder'),
        'trackForJson'=> env('JNT_EP_TRACK',  '/api/track/trackForJson'),
        'queryInvoice'  => env('JNT_EP_INVOICE', '/api/invoice/queryInvoice'),
    ],

    'msg_types' => [
        'create' => 'ORDERCREATE',
        'cancel' => 'ORDERCANCEL',
        'query'  => 'ORDERQUERY',
        'track'  => 'TRACKQUERY', // important: you already confirmed this works
    ],

    // Sender defaults (edit this to your real warehouse/pickup)
    'sender' => [
        'name'    => env('JNT_SENDER_NAME', 'INCEPXION INC'),
        'phone'   => env('JNT_SENDER_PHONE', '09170000000'),
        'mobile'  => env('JNT_SENDER_MOBILE', '09170000000'),
        'prov'    => env('JNT_SENDER_PROV', 'METRO-MANILA'),
        'city'    => env('JNT_SENDER_CITY', 'TAGUIG'),
        'area'    => env('JNT_SENDER_AREA', 'BAGUMBAYAN'),
        'address' => env('JNT_SENDER_ADDRESS', '#20 1st AVE. STA. MARIA INDUSTRIAL BAGUMBAYAN TAGUIG CITY'),
    ],
];
