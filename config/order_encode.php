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
    // Live (spec 136): ilang segundo hinihintay ng encode ang naunang encode ng parehong customer bago ulitin ng queue ang job.
    'customer_lock_wait' => 300,
    // Ilang segundo hinihintay ng encode ang Astra (o ang night run) na may hawak ng row bago ito isulat.
    'row_lock_wait' => 300,
    // Ang night run: ilang segundo hinihintay ang Astra on the spot sa row bago ito laktawan ("Row busy").
    'night_row_lock_wait' => 200,
];
