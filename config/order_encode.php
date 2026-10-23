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
];
