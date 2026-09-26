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
    // Model is now picked per-request via the UI dropdown sa /gpt-ad-generator.
    // Allowed list + default lives sa GPTAdGeneratorController::ALLOWED_MODELS
    // and ::DEFAULT_MODEL.
],

    'automation' => [
    'key' => env('AUTOMATION_KEY'),
],




];
