<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
    'key'   => env('OPENAI_API_KEY'),
    'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    // /astra chat: default model (verified accessible sa account) + optional allowed-list
    // override — ASTRA_MODELS="gpt-6-astra,gpt-5.5,o3" (comma-separated ids).
    'astra_default' => env('ASTRA_DEFAULT_MODEL', 'gpt-6-astra'),
    'astra_models'  => env('ASTRA_MODELS', ''),
    // /encoder/checker_1 AI checker: web_search sa address calls (PROV/CITY/BRGY/VERIFY).
    //   required = sapilitang mag-search kada call · auto = AI ang magpapasya · off = walang search (lumang gawi)
    'ai_checker_search' => env('AI_CHECKER_SEARCH', 'required'),
    // Hybrid escalation: mahirap na row lang (tinanggihan ng guard) → mas malalim na model, isang beses
    // kada row, sa huling pass lang. Blangko ang model = walang escalation (tao agad).
    'ai_checker_escalate_model'  => env('AI_CHECKER_ESCALATE_MODEL', 'gpt-6-astra'),
    'ai_checker_escalate_effort' => env('AI_CHECKER_ESCALATE_EFFORT', 'xhigh'),
    // ✨ Astra engine (AstraEncoder): isang malalim na call na may tools (web_search + jnt_address_search + Pancake).
    'astra_encoder_model'  => env('ASTRA_ENCODER_MODEL', 'gpt-6-astra'),
    'astra_encoder_effort' => env('ASTRA_ENCODER_EFFORT', 'high'),
    'astra_encoder_max_web' => (int) env('ASTRA_ENCODER_MAX_WEB', 4),   // cap sa web search calls kada row (0 = walang cap)
    // Aling buttons ang nakikita sa /encoder/checker_1: both | astra | classic
    'ai_checker_ui' => env('AI_CHECKER_UI', 'both'),
    // USD kada 1M tokens [input, output] + kada web search call — para sa cost_usd sa ai_checker_logs (estimate).
    'ai_checker_prices' => [
        'gpt-5.2'     => [1.75, 14.0],
        'gpt-6-astra' => [10.0, 50.0],
        'web_search'  => 0.01,
    ],
    // Model is now picked per-request via the UI dropdown sa /gpt-ad-generator.
    // Allowed list + default lives sa GPTAdGeneratorController::ALLOWED_MODELS
    // and ::DEFAULT_MODEL.
],

    'automation' => [
    'key' => env('AUTOMATION_KEY'),
],




];
