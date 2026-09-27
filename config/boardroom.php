<?php

// AI Boardroom (/boardroom) — CEO only. Walang API key dito: bawat role ay may sariling
// credential na inilalagay sa /boardroom/agents (encrypted sa DB, hindi bumabalik sa browser).

return [

    // Opsyonal na HIWALAY na encryption key para sa mga naka-save na API key (base64, 32 bytes;
    // gawin gamit ang `php artisan boardroom:key`). Blangko = APP_KEY ang gagamitin.
    // Alinman ang gamitin, nasa .env ito — hiwalay sa database.
    'encryption_key' => env('BOARDROOM_ENCRYPTION_KEY'),

    // Queue na pagtatakbuhan ng bawat turn (isang job = isang model call).
    'queue' => env('BOARDROOM_QUEUE', 'default'),

    'limits' => [
        'max_cycles'          => (int) env('BOARDROOM_MAX_CYCLES', 3),    // review/revision cycles kada meeting
        'max_calls'           => (int) env('BOARDROOM_MAX_CALLS', 16),    // LAHAT ng model call (routing, review, repair, final)
        'max_asks_per_cycle'  => (int) env('BOARDROOM_MAX_ASKS', 4),      // targeted questions ng CEO kada cycle
        'max_output_tokens'   => (int) env('BOARDROOM_MAX_OUTPUT_TOKENS', 16000),   // default kada role
        'timeout_s'           => (int) env('BOARDROOM_TIMEOUT_S', 240),   // default request timeout kada role
        'message_chars'       => 12000,   // pinakamahabang bahagi ng isang message na isinasama sa context
        'test_output_tokens'  => 64,      // Test Connection: maliit na totoong request
    ],

    // Bounded retries — para LANG sa retryable failures (429, 5xx, overloaded, timeout).
    'retry' => [
        'max_retries' => (int) env('BOARDROOM_MAX_RETRIES', 2),
        'backoff_ms'  => [2000, 6000],
    ],

    // Turn na "generating" pa rin lampas sa (timeout + ito) = patay na worker → markahang failed (retryable ng user).
    'stale_grace_s' => 90,

    'endpoints' => [
        'openai'    => env('BOARDROOM_OPENAI_BASE', 'https://api.openai.com/v1'),
        'anthropic' => env('BOARDROOM_ANTHROPIC_BASE', 'https://api.anthropic.com/v1'),
        'deepseek'  => env('BOARDROOM_DEEPSEEK_BASE', 'https://api.deepseek.com'),
    ],

    'anthropic_version' => '2023-06-01',

    'providers' => [
        'openai'    => 'OpenAI',
        'anthropic' => 'Anthropic',
        'deepseek'  => 'DeepSeek',
    ],
];
