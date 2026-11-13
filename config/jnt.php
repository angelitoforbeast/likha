<?php

return [
    'env' => env('JNT_ENV', 'sandbox'),

    'base_url' => env('JNT_BASE_URL', 'https://jtapi.jtexpress.ph/jts-phl-order-api'),

    'credentials' => [
        'eccompanyid' => env('JNT_ECCOMPANYID'),
        'customerid'  => env('JNT_CUSTOMERID'),
        'secret'      => env('JNT_SECRET'),
        'api_key'     => env('JNT_API_KEY'), // keep optional; you already confirmed NOT needed as header for demo
    ],

    'timeout' => env('JNT_TIMEOUT', 30),

    // Queues of the J&T jobs (spec 267). Incepxion: booking (CreateJntOrder) has its own workers
    // (deploy/supervisor/incepxion-queue-jnt.conf); bulk waybill print (BuildJntWaybillBulkPrint) is unused here
    // and stays on `default`, which has a worker.
    'queues' => [
        'book'  => env('JNT_BOOK_QUEUE', 'jnt-book'),
        'print' => env('JNT_PRINT_QUEUE', 'default'),
        // Spec 269: the label of each booked waybill is fetched right after booking (FetchJntLabel).
        'label' => env('JNT_LABEL_QUEUE', 'jnt-label'),
    ],

    // Waybill labels kept on the local disk (spec 269): printing stitches them instead of fetching each one again.
    // J&T label calls run `batch` at a time per worker, and never more than `max_concurrent` at once across all
    // workers (a shared cache limiter). Stored labels older than `keep_days` are pruned daily (jnt:prune-labels).
    // Incepxion does not print in-app and has no `jnt-label` worker: no label job after booking unless turned on
    // (JNT_LABEL_AFTER_BOOKING=true, with a jnt-label worker). A print run still fetches its missing labels itself.
    'labels' => [
        'after_booking'  => (bool) env('JNT_LABEL_AFTER_BOOKING', false),
        'batch'          => (int) env('JNT_LABEL_BATCH', 10),
        'max_concurrent' => (int) env('JNT_LABEL_MAX_CONCURRENT', 20),
        'keep_days'      => (int) env('JNT_LABEL_KEEP_DAYS', 14),
    ],

    'signing' => [
        'mode' => env('JNT_SIGN_MODE', 'md5_base64_urlencode'),
    ],

    'endpoints' => [
        'create'      => env('JNT_EP_CREATE', '/api/order/create'),
        'cancel'      => env('JNT_EP_CANCEL', '/api/order/cancel'),
        'queryOrder'  => env('JNT_EP_QUERY',  '/api/order/queryOrder'),
        'trackForJson'=> env('JNT_EP_TRACK',  '/api/track/trackForJson'),
        'queryInvoice'  => env('JNT_EP_INVOICE', '/api/invoice/queryInvoice'),
        'print' => env('JNT_EP_PRINT', '/api/order/print'),


    ],

    'msg_types' => [
        'create' => 'ORDERCREATE',
        'cancel' => 'ORDERCANCEL',
        'query'  => 'ORDERQUERY',
        'track'  => 'TRACKQUERY', // important: you already confirmed this works
    ],

    // Sender defaults (edit this to your real warehouse/pickup)
    'sender' => [
        'name'    => env('JNT_SENDER_NAME', ''),
        'phone'   => env('JNT_SENDER_PHONE', '09170000000'),
        'mobile'  => env('JNT_SENDER_MOBILE', '09170000000'),
        'prov'    => env('JNT_SENDER_PROV', 'METRO-MANILA'),
        'city'    => env('JNT_SENDER_CITY', 'TAGUIG'),
        'area'    => env('JNT_SENDER_AREA', 'BAGUMBAYAN'),
        'address' => env('JNT_SENDER_ADDRESS', '#20 1st AVE. STA. MARIA INDUSTRIAL BAGUMBAYAN TAGUIG CITY'),
    ],
];
