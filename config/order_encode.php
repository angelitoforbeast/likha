<?php

return [
    // Tantiya ng presyo kada 1M token (USD), para sa gastos kada order sa /order-encode. Palitan dito kapag nagbago ang presyo ng OpenAI.
    'prices' => [
        'gpt-4o-mini' => ['in' => 0.15, 'out' => 0.60],
        'gpt-5.2'     => ['in' => 1.75, 'out' => 14.00],
        'default'     => ['in' => 1.75, 'out' => 14.00],
    ],
    // Hanggang kailan (minuto) bago hintayin ng compare ang checker ng sheet, at ilang oras bago sabihing "sheet not done".
    'compare_after_minutes' => 15,
    'compare_give_up_hours' => 48,
    // Ilang oras bago sabihing "sheet never finished" sa paghahambing sa final na order (macro_output).
    'final_give_up_hours' => 72,
    // Live: ilang segundo hinihintay ng encode ang naunang encode ng parehong customer bago ulitin ng queue ang job.
    'customer_lock_wait' => 240,
    // Ilang segundo hinihintay ng encode ang Astra (o ang night run) na may hawak ng row bago ito isulat.
    'row_lock_wait' => 180,
    // Ang night run: ilang segundo hinihintay ang Astra on the spot sa row bago ito laktawan ("Row busy").
    'night_row_lock_wait' => 200,
    // Ang mga queue. Ang "live" ay ang express lane: ang encode at ang Astra ng customer na naghihintay (live na order, Encode
    // order step), na laging inuuna ng mga worker (--queue=encode-live,encode at --queue=astra-live,astra). Ang shadow, import at
    // night run ay sa encode / astra.
    'queues' => [
        'encode' => 'encode',
        'encode_live' => 'encode-live',
        'astra' => 'astra',
        'astra_live' => 'astra-live',
    ],
    // Ang order na naisulat sa macro_output ay ipinapadala rin sa Forbeast ng gateway ng page (spec 211).
    'forbeast' => [
        // Ang go-live (ISO 8601, hal. 2026-11-02T00:00:00+08:00): ang mga row na nagawa mula noon lang ang ipinapadala
        // (walang backfill). Walang laman = nakapatay.
        'since' => env('FORBEAST_ORDERS_SINCE'),
        // Ang pangalan ng site sa ref (<site>-mo-<id>); walang laman = ayon sa app.url (likha / incepxion).
        'site' => env('FORBEAST_ORDERS_SITE'),
        // Ilang oras inuulit ang padala na hindi dumarating (Forbeast down, 5xx, 429) bago ito failed.
        'retry_hours' => (int) env('FORBEAST_ORDERS_RETRY_HOURS', 24),
        // Ilang minuto walang tawag sa gateway pagkatapos ng 401/403 (site key), bago ang probe ng bantay.
        'pause_minutes' => (int) env('FORBEAST_ORDERS_PAUSE_MINUTES', 15),
        // Ang queue ng padala: sa database queue na "default" (may sariling Supervisor group), hindi sa encode.
        'queue' => env('FORBEAST_ORDERS_QUEUE', 'default'),
    ],
];
