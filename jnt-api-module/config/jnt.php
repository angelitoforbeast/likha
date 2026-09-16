<?php

/**
 * J&T Express PH — API config (portable module).
 *
 * Copy this file to the target Laravel app's config/jnt.php, then set the
 * matching keys sa .env. Sandbox muna bago prod.
 */
return [
    // 'sandbox' | 'production' — para lang sa iyong sariling logging/guards.
    'env' => env('JNT_ENV', 'sandbox'),

    // Base URL ng J&T PH order API. Palitan kung ibang endpoint ang bibigay sa iyo.
    'base_url' => env('JNT_BASE_URL', 'https://jtapi.jtexpress.ph/jts-phl-order-api'),

    'credentials' => [
        'eccompanyid' => env('JNT_ECCOMPANYID'),
        'customerid'  => env('JNT_CUSTOMERID'),
        'secret'      => env('JNT_SECRET'),   // signing key ("key" sa J&T docs)
        'api_key'     => env('JNT_API_KEY'),  // optional header key; kadalasan hindi kailangan
    ],

    'timeout' => (int) env('JNT_TIMEOUT', 30),

    /**
     * Signing mode. VERIFIED working (create / query / print / status-sync):
     *   md5_hex_base64  =>  base64( md5_hex(logistics_interface + secret) )
     *
     * Kung may endpoint na tumanggi (minsan track/cancel sa ibang gateway),
     * subukan ang: md5_base64_urlencode  =>  urlencode( base64( md5_raw(...) ) )
     */
    'signing' => [
        'mode' => env('JNT_SIGN_MODE', 'md5_hex_base64'),
    ],

    'endpoints' => [
        'create'     => env('JNT_EP_CREATE',  '/api/order/create'),
        'cancel'     => env('JNT_EP_CANCEL',  '/api/order/cancel'),
        'queryOrder' => env('JNT_EP_QUERY',   '/api/order/queryOrder'),
        'track'      => env('JNT_EP_TRACK',   '/api/track/trackForJson'),
        'print'      => env('JNT_EP_PRINT',   '/api/order/print'),
    ],

    'msg_types' => [
        'create' => 'ORDERCREATE',
        'cancel' => 'ORDERCANCEL',
        'query'  => 'ORDERQUERY',
        'track'  => 'TRACKQUERY',
        'print'  => 'BILLQUERY',
    ],

    // Default sender / warehouse — gamitin ng payload builder kapag create order.
    'sender' => [
        'name'    => env('JNT_SENDER_NAME', ''),
        'phone'   => env('JNT_SENDER_PHONE', '09170000000'),
        'mobile'  => env('JNT_SENDER_MOBILE', '09170000000'),
        'prov'    => env('JNT_SENDER_PROV', 'METRO-MANILA'),
        'city'    => env('JNT_SENDER_CITY', 'TAGUIG'),
        'area'    => env('JNT_SENDER_AREA', 'BAGUMBAYAN'),
        'address' => env('JNT_SENDER_ADDRESS', ''),
    ],
];
