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

    // /ads_manager/payment/reconcile: vision model na bumabasa ng screenshot ng card statement (OPENAI_API_KEY),
    // at ang pdftotext binary para sa PDF (smalot/pdfparser kung wala).
    'card_statement' => [
        'model'     => env('OPENAI_STATEMENT_MODEL', 'gpt-4o'),
        'pdftotext' => env('POPPLER_PDFTOTEXT', 'pdftotext'),
    ],

    'openai' => [
    'key'   => env('OPENAI_API_KEY'),
    // Chatbot AI step (chatbot:ai-pool): ilang segundo bago ituring na walang sagot (failed port).
    'timeout' => (int) env('OPENAI_TIMEOUT', 60),
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
    // Hiwalay na API key para sa Astra engine (para hiwalay ang billing/limits). Blangko = gamitin ang OPENAI_API_KEY.
    'astra_encoder_key'    => env('ASTRA_ENCODER_API_KEY') ?: env('OPENAI_API_KEY'),
    'astra_encoder_model'  => env('ASTRA_ENCODER_MODEL', 'gpt-6-astra'),
    'astra_encoder_effort' => env('ASTRA_ENCODER_EFFORT', 'high'),
    'astra_encoder_max_web' => (int) env('ASTRA_ENCODER_MAX_WEB', 4),   // cap sa web search calls kada row (0 = walang cap)
    'astra_encoder_max_web_first' => (int) env('ASTRA_ENCODER_MAX_WEB_FIRST', 5),   // ang parehong cap kapag "web search muna" ang mode ng address rules
    // Aling buttons ang nakikita sa /encoder/checker_1: both | astra | classic
    'ai_checker_ui' => env('AI_CHECKER_UI', 'both'),
    // Content check ng /chatbot/pages (item, presyo, larawan, ad video): murang model na may vision.
    'content_check_model' => env('CHATBOT_CONTENT_MODEL', 'gpt-4o-mini'),
    // USD kada 1M tokens [input, output] + kada web search call — para sa cost_usd sa ai_checker_logs (estimate).
    'ai_checker_prices' => [
        'gpt-5.2'     => [1.75, 14.0],
        'gpt-6-astra' => [10.0, 50.0],
        'gpt-6-luna'  => [0.10, 0.50],
        'gpt-4o-mini' => [0.15, 0.60],
        'web_search'  => 0.01,
    ],
    // Model is now picked per-request via the UI dropdown sa /gpt-ad-generator.
    // Allowed list + default lives sa GPTAdGeneratorController::ALLOWED_MODELS
    // and ::DEFAULT_MODEL.
],

    'automation' => [
    'key' => env('AUTOMATION_KEY'),
],

    // Botcake customer sync (botcake:sync-customers sa schedule). Naka-off kapag walang laman.
    'botcake' => [
        'sync_enabled' => (bool) env('BOTCAKE_SYNC_ENABLED', false),
        // Max na batch ng lumang incremental read; hindi na ginagamit mula nang palitan ito ng by date read (stop date ang hangganan).
        'sync_max_batches' => (int) env('BOTCAKE_SYNC_MAX_BATCHES', 300),
        // Hanggang ilang araw pabalik ang binabasa (date_subscribed_at); humihinto ang sync sa unang mas lumang customer.
        'sync_max_age_days' => (int) env('BOTCAKE_SYNC_MAX_AGE_DAYS', 45),
        // /page-settings: rows = mga page na may order sa huling ilang araw (ts_date), saka ang mga may settings.
        'grid_order_days' => (int) env('BOTCAKE_GRID_ORDER_DAYS', 30),
        // Sync lahat: nilalaktawan ang page na by date lang ang kailangan at may matagumpay na sync sa huling ilang oras.
        'sync_all_fresh_hours' => (float) env('BOTCAKE_SYNC_ALL_FRESH_HOURS', 6),
        // /chatblast: hanggang ilang totoong mensahe kada page kada araw (Manila).
        'chatblast_daily_cap' => (int) env('BOTCAKE_CHATBLAST_DAILY_CAP', 3000),
        // /chatblast: ilang segundo pagkatapos ng send_flow bago ang susunod na mensahe sa parehong customer (page + PSID).
        'chatblast_customer_gap' => (int) env('BOTCAKE_CHATBLAST_CUSTOMER_GAP', 20),
        // /chatblast "Open in Botcake": ang live chat ng page sa Botcake. {page_id} = botcake.page_id; {psid} = ang
        // customer (kapag may kumpirmadong anyo ng URL na may customer). Hindi pa kumpirmado ang path: ayusin dito.
        'inbox_url' => env('BOTCAKE_INBOX_URL', 'https://botcake.io/pages/{page_id}/live_chat'),
        // /api/botcake/chat (JSON API block ng Botcake): ang shared key (header X-Chat-Key o param key); walang laman = 401
        // sa lahat. chat_rate: ilang request kada minuto kada IP (iilang IP ng Botcake para sa ~170 page).
        'chat_key'  => env('BOTCAKE_CHAT_KEY'),
        // Ang lumang shared key ay tinatanggap lang habang true (habang wala pang sariling botcake.chat.key ang lahat ng page).
        'chat_shared_key_ok' => (bool) env('BOTCAKE_CHAT_SHARED_KEY_OK', true),
        // Mga IP/CIDR ng Botcake (kuwit ang hati). log = tinatanggap pero itinatala at binibilang ang iba; enforce = 403.
        'chat_ips'     => array_values(array_filter(array_map('trim', explode(',', (string) env('BOTCAKE_CHAT_IPS', '203.171.22.6'))))),
        'chat_ip_mode' => env('BOTCAKE_CHAT_IP_MODE', 'log'),
        // Hanggang ilang bagong PSID kada minuto kada page ang sinusuri sa Botcake (token ng page, kahati ng chatblast).
        'chat_verify_per_minute' => (int) env('BOTCAKE_CHAT_VERIFY_PER_MINUTE', 60),
        'chat_rate' => (int) env('BOTCAKE_CHAT_RATE', 1200),
        // Ilang request kada segundo kada page (token) sa Botcake: ang pool (botcake:send-pool) ay eksakto, ang iba ay sumusunod.
        'rate_per_page' => (float) env('BOTCAKE_RATE_PER_PAGE', 2),
        // true = ang padala ng chatbot at chatblast ay ipinapasa sa botcake:send-pool (kapag buhay ito); false = naghihintay ang worker (ang dati).
        'async_sends' => (bool) env('BOTCAKE_ASYNC_SENDS', true),
        // Ilang request sa Botcake nang sabay sa botcake:send-pool (lahat ng page).
        'send_concurrency' => (int) env('BOTCAKE_SEND_CONCURRENCY', 100),
    ],

    // Kusang J&T track-sync kada oras (jnt:track-sync-auto). Ang toggle ng CEO sa /jnt/track-sync ang nananaig kapag ginamit.
    'jnt' => [
        'track_sync_auto' => (bool) env('JNT_TRACK_SYNC_AUTO', false),
    ],

    // Sariling chatbot (/chatbot): ang On/Off ng bawat page (kasama ang flow) ang nagpapasya kung sumasagot. Ito ay emergency stop
    // lang: false = tumatakbo ang mga flow pero walang padala sa Botcake at walang sheet row (itinatala bilang skipped).
    'chatbot' => [
        'replies_enabled' => (bool) env('CHATBOT_REPLIES_ENABLED', true),
        // AI step: true = hindi naghihintay ang worker (chatbot:ai-pool ang nagpapadala at nagtutuloy); false = naghihintay
        // ang takbo sa sagot (ang dati), pang-rollback nang walang deploy.
        'ai_async' => (bool) env('CHATBOT_AI_ASYNC', true),
        // Ilang request sa OpenAI nang sabay sa chatbot:ai-pool (kahati ng Astra ang rate limit ng OpenAI).
        'ai_concurrency' => (int) env('CHATBOT_AI_CONCURRENCY', 50),
    ],

    // Read-only staff feed (/api/staff-status, /api/staff-events); walang laman = 503.
    'staff_feed' => [
        'key' => env('STAFF_FEED_KEY'),
        'bot_user_ids' => env('STAFF_FEED_BOT_USER_IDS', ''),
    ],




];
