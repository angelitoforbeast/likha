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

    // Nexus (warehouse app): read-only API ng stock-in para sa /item (nexus:sync-stock). Walang token = hindi naka-configure:
    // walang tawag, at ang stock sa /item ay ang dating PO-received.
    'nexus' => [
        'url'     => env('NEXUS_API_URL') ?: 'https://srv1972783.hstgr.cloud',
        'token'   => env('NEXUS_API_TOKEN'),
        'timeout' => (int) (env('NEXUS_API_TIMEOUT') ?: 20),
        // Ilang segundo maghihintay ang cold /item/stock sa ibang request na kumukuwenta na ng parehong stock inputs.
        'cold_wait' => 15,
        // Mula kailan kinukuha ng nexus:sync-stock --history ang stock-in bago ang item_stock_start (Y-m-d).
        'history_from' => env('NEXUS_STOCK_HISTORY_FROM') ?: '2026-01-01',
    ],

    // Meta Ads (Marketing API): ang app ng "Connect Facebook" sa /ads_manager/report. Walang app id = "Meta app not set up yet".
    'meta_ads' => [
        'app_id'        => env('META_ADS_APP_ID'),
        'app_secret'    => env('META_ADS_APP_SECRET'),
        'config_id'     => env('META_ADS_CONFIG_ID'),
        'graph_version' => env('META_ADS_GRAPH_VERSION', 'v25.0'),
        // Oras (Manila, H:i) ng araw-araw na ads:data-health --record.
        'data_health_at' => env('META_ADS_DATA_HEALTH_AT') ?: '12:00',
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
    // Order encode (/order-encode, spec 130): sariling key; blangko = ang OPENAI_API_KEY. Hindi kailanman nilalabas o itinatala.
    'order_encode_key'     => env('ORDER_ENCODE_OPENAI_KEY') ?: env('OPENAI_API_KEY'),
    'astra_encoder_model'  => env('ASTRA_ENCODER_MODEL', 'gpt-6-astra'),
    'astra_encoder_effort' => env('ASTRA_ENCODER_EFFORT', 'high'),
    'astra_encoder_max_web' => (int) env('ASTRA_ENCODER_MAX_WEB', 4),   // cap sa web search calls kada row (0 = walang cap)
    'astra_encoder_max_web_first' => (int) env('ASTRA_ENCODER_MAX_WEB_FIRST', 5),   // ang parehong cap kapag "web search muna" ang mode ng address rules
    // Aling buttons ang nakikita sa /encoder/checker_1: both | astra | classic
    'ai_checker_ui' => env('AI_CHECKER_UI', 'both'),
    // Content check ng /chatbot/pages (item, presyo, larawan, ad video): murang model na may vision.
    'content_check_model' => env('CHATBOT_CONTENT_MODEL', 'gpt-4o-mini'),
    // Ang paste sa Edit ng isang page (field ng bawat cell): mas malakas na model, bihira ang tawag. Default = ang model ng
    // order-encode checker; blangko = ang content_check_model.
    'chatbot_paste_map_model' => env('CHATBOT_PASTE_MAP_MODEL', 'gpt-5.2'),
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
        // Ang setup check kada 10 minuto (botcake:setup-check --chatblast), oras ng Manila: from hanggang to, maliban sa
        // skip (ang umagang chatblast burst ang gumagamit ng 2 request kada segundo ng bawat page sa Botcake).
        'setup_check_window' => ['from' => '06:00', 'to' => '23:00', 'skip_from' => '08:45', 'skip_to' => '10:30'],
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
        // Habang may sagot ang bot sa page, ang chatblast ay hanggang ganitong bahagi lang ng rate_per_page (0.5 = 1 sa 2 kada segundo).
        'chatblast_share' => (float) env('BOTCAKE_CHATBLAST_SHARE', 0.5),
        // true = ang padala ng chatbot at chatblast ay ipinapasa sa botcake:send-pool (kapag buhay ito); false = naghihintay ang worker (ang dati).
        'async_sends' => (bool) env('BOTCAKE_ASYNC_SENDS', true),
        // Ilang request sa Botcake nang sabay sa botcake:send-pool (lahat ng page), tig-isang ganito ang sagot ng bot at ang chatblast.
        'send_concurrency' => (int) env('BOTCAKE_SEND_CONCURRENCY', 100),
        // Ilang ticket ng sequence nang sabay sa chatbot:sequence-pool (sariling proseso, hiwalay sa mga sagot at chatblast).
        'sequence_concurrency' => (int) env('BOTCAKE_SEQUENCE_CONCURRENCY', 100),
        // Ilang segundo hinihintay ang Botcake sa sagot ng chatbot (send_content); ang unang mensahe na may larawan ay inaabot nang 8-15 s.
        'send_timeout' => (int) env('BOTCAKE_SEND_TIMEOUT', 30),
    ],

    // Kusang J&T track-sync kada oras (jnt:track-sync-auto). Ang toggle ng CEO sa /jnt/track-sync ang nananaig kapag ginamit.
    'jnt' => [
        'track_sync_auto' => (bool) env('JNT_TRACK_SYNC_AUTO', false),
    ],

    // Sariling chatbot (/chatbot): ang On/Off ng bawat page (kasama ang flow) ang nagpapasya kung sumasagot. Ito ay emergency stop
    // lang: false = tumatakbo ang mga flow pero walang padala sa Botcake at walang sheet row (itinatala bilang skipped).
    'chatbot' => [
        'replies_enabled' => (bool) env('CHATBOT_REPLIES_ENABLED', true),
        // Ang customer na sinabi ng Meta na hindi maabot (#551, #10 ng window): walang padalang hindi sagot hanggang sumulat
        // siya (CustomerReach). false = walang bagong marka at walang laktaw, pang-rollback nang walang deploy.
        'stop_unreachable' => (bool) env('CHATBOT_STOP_UNREACHABLE', true),
        // AI step: true = hindi naghihintay ang worker (chatbot:ai-pool ang nagpapadala at nagtutuloy); false = naghihintay
        // ang takbo sa sagot (ang dati), pang-rollback nang walang deploy.
        'ai_async' => (bool) env('CHATBOT_AI_ASYNC', true),
        // Sequence step: true = ang padala ay sa chatbot:sequence-pool at hindi naghihintay ang worker; false = naghihintay ang
        // job sa padala (ang dati), pang-rollback nang walang deploy.
        'sequence_handoff' => (bool) env('CHATBOT_SEQUENCE_HANDOFF', true),
        // Ilang padala ng sequence nang sabay kada page sa gateway: walang rate ang Likha sa gateway (siya ang may pila), ito
        // ang kapalit ng bilang ng worker na dating naghihintay.
        'sequence_gateway_per_page' => (int) env('CHATBOT_SEQUENCE_GATEWAY_PER_PAGE', 30),
        // Ilang request sa OpenAI nang sabay sa chatbot:ai-pool (kahati ng Astra ang rate limit ng OpenAI).
        'ai_concurrency' => (int) env('CHATBOT_AI_CONCURRENCY', 100),
        // Read-only feed ng auto-switch events (/api/chatbot/provider-events, header X-CHATBOT-EVENTS-KEY); walang laman = 401.
        'events_key' => env('CHATBOT_EVENTS_KEY'),
        // Ang banner ng mga problema (ProblemsPanel). Mabagal: ang median ng unang sagot sa huling oras ay slow_factor beses
        // ng karaniwan ng page sa parehong oras (huling 7 araw), o slow_seconds pataas, na may slow_min_replies na sagot man
        // lang. Walang chat: karaniwang no_chats_per_hour pataas sa oras na ito, pero wala sa huling no_chats_minutes.
        'problems' => [
            'slow_factor' => (float) env('CHATBOT_SLOW_FACTOR', 3),
            'slow_seconds' => (int) env('CHATBOT_SLOW_SECONDS', 60),
            'slow_min_replies' => (int) env('CHATBOT_SLOW_MIN_REPLIES', 5),
            'no_chats_per_hour' => (int) env('CHATBOT_NO_CHATS_PER_HOUR', 20),
            'no_chats_minutes' => (int) env('CHATBOT_NO_CHATS_MINUTES', 30),
            // Ang AI step na walang sagot: pula sa ai_fail_count palya sa ai_fail_minutes, o naipit nang lampas ai_stuck_seconds;
            // isang linya para sa buong site kapag ai_site_min_pages page pataas (at lampas kalahati) ang pumalya sa ai_site_minutes.
            'ai_fail_count' => (int) env('CHATBOT_AI_FAIL_COUNT', 3),
            'ai_fail_minutes' => (int) env('CHATBOT_AI_FAIL_MINUTES', 15),
            'ai_stuck_seconds' => (int) env('CHATBOT_AI_STUCK_SECONDS', 120),
            'ai_site_minutes' => (int) env('CHATBOT_AI_SITE_MINUTES', 10),
            'ai_site_min_pages' => (int) env('CHATBOT_AI_SITE_MIN_PAGES', 5),
        ],
    ],

    // Aling AI ang sumasagot sa AI step (pinipili kada page sa /chatbot/pages; walang setting = OpenAI, gaya ng dati). Bawat
    // provider ay OpenAI-compatible (<url>/chat/completions); ang pangatlo ay isa pang entry dito. Ang URL at key lang ang
    // nasa env: ang model, timeout, cap at breaker ay nae-edit sa screen (ito ang default nila).
    'chatbot_ai' => [
        'providers' => [
            // pooled: ang mga key ay sa /chatbot/ai-keys (ang pool ng kumpanya, spec 210). Ang OpenAI na walang key sa pool ay ang
            // services.openai.key gaya ng dati. key_concurrency: ilang sabay kada key (null = walang hangganan, gaya ng dati).
            'openai' => ['label' => 'OpenAI', 'url' => 'https://api.openai.com/v1', 'key_config' => 'services.openai.key', 'needs_key' => true, 'pooled' => true,
                'key_concurrency' => env('CHATBOT_AI_OPENAI_KEY_CONCURRENCY') ?: null, 'test_model' => 'gpt-4o-mini'],
            // Claude at DeepSeek: ang sariling model at timeout (page, site, o ito); ang key ay sa pool lang.
            'anthropic' => ['label' => 'Claude', 'url' => 'https://api.anthropic.com/v1', 'needs_key' => true, 'pooled' => true, 'model' => 'claude-haiku-4-5', 'timeout' => 20,
                'key_concurrency' => env('CHATBOT_AI_ANTHROPIC_KEY_CONCURRENCY') ?: null],
            'deepseek' => ['label' => 'DeepSeek', 'url' => 'https://api.deepseek.com', 'needs_key' => true, 'pooled' => true, 'model' => 'deepseek-chat', 'timeout' => 20,
                'key_concurrency' => env('CHATBOT_AI_DEEPSEEK_KEY_CONCURRENCY') ?: null],
            // Ang Local ay sa localhost lang ng server (reverse SSH tunnel papunta sa office PC); ibang host = hindi naka-set up.
            'local' => [
                'label' => 'Local', 'url' => env('CHATBOT_AI_LOCAL_URL', ''), 'key' => env('CHATBOT_AI_LOCAL_KEY', ''), 'localhost_only' => true,
                'model' => '', 'timeout' => 8, 'concurrency' => 4, 'breaker_failures' => 3, 'breaker_seconds' => 30,
                // Ang qwen3 sa Ollama ay nag-iisip muna (13-14 s, lampas sa 8 s): "none" ang pumapatay nito sa OpenAI-compatible na endpoint.
                // none | low | medium | high, o '' = hindi ipinapadala. Hindi kailanman sa OpenAI.
                'reasoning_effort' => 'none',
            ],
        ],
        // Ang pahinga ng key ng pool (segundo) ayon sa dahilan; ang ulit na palya pagkatapos ng pahinga ay doble, hanggang max_rest.
        // Ang 401/403 (invalid) ay walang katapusan: hanggang pumasa ang Test. Ang failing (5xx, 529, timeout) ay pagkatapos lang ng
        // failing_after na palya sa loob ng failing_window segundo.
        'keys' => [
            'rest' => ['rate_limited' => (int) env('CHATBOT_AI_KEY_REST_RATE_LIMITED', 120), 'no_credit' => (int) env('CHATBOT_AI_KEY_REST_NO_CREDIT', 1800),
                'failing' => (int) env('CHATBOT_AI_KEY_REST_FAILING', 60)],
            'max_rest' => (int) env('CHATBOT_AI_KEY_MAX_REST', 21600),
            'failing_after' => (int) env('CHATBOT_AI_KEY_FAILING_AFTER', 3),
            'failing_window' => (int) env('CHATBOT_AI_KEY_FAILING_WINDOW', 300),
            'max' => 50, // ilang key sa pool
        ],
        // Ang presyo ng chatbot AI (USD kada 1M tokens: [input, output]) para sa cost_micros ng bawat sagot. Ang model na wala
        // rito = walang gastos (null, may marka), hindi 0. Ang petsa sa dulo ng pangalan (gpt-4o-mini-2024-07-18) ay inaalis.
        'prices' => [
            'gpt-4o-mini' => [0.15, 0.60],
            'gpt-4o' => [2.50, 10.00],
            'gpt-4.1-nano' => [0.10, 0.40],
            'gpt-4.1-mini' => [0.40, 1.60],
            'gpt-4.1' => [2.00, 8.00],
            'gpt-5-nano' => [0.05, 0.40],
            'gpt-5-mini' => [0.25, 2.00],
            'gpt-5' => [1.25, 10.00],
            'gpt-5.2' => [1.75, 14.00],
            'claude-haiku-4-5' => [1.00, 5.00],
            'claude-sonnet-4-5' => [3.00, 15.00],
            'deepseek-chat' => [0.28, 0.42],
            'deepseek-reasoner' => [0.28, 0.42],
        ],
        // Ang padala ng usage sa Forbeast (SendChatbotAiUsage) at ang tanong ng load (ChatbotAiLoad).
        'billing' => [
            'batches_per_run' => (int) env('CHATBOT_AI_USAGE_BATCHES', 20), // 100 bawat isa; ang Forbeast ay 120 request kada minuto
            'timeout' => (int) env('CHATBOT_AI_BILLING_TIMEOUT', 10),
            'load_timeout' => (int) env('CHATBOT_AI_LOAD_TIMEOUT', 3),
            'load_seconds' => 60,
        ],
    ],

    // Forbeast Gateway: ang sariling daan ng mensahe papunta at mula sa Messenger, pinipili kada page (chatbot.provider).
    // Walang url = hindi mapipili ang gateway at ang padala ay failed na may dahilan. Ang key at webhook_secret ay pang-lahat:
    // ang page na may sariling Forbeast token (forbeast.token at forbeast.webhook_secret) ay iyon ang gamit. Ang webhook_secret ang pumipirma sa
    // /api/gateway/events ng page na walang sariling secret; walang laman = 503 (walang tinatanggap).
    'gateway' => [
        'url' => rtrim((string) env('GATEWAY_URL', ''), '/'),
        // Ang iba pang address ng parehong gateway (parehong key at token ng page, ibang Meta app), pinipili kada page
        // (chatbot.gateway_app): "key=url key2=url2". Walang laman = ang url lang (forbeast), gaya ng dati.
        'urls' => (string) env('GATEWAY_URLS', ''),
        // Ang Meta app ng mga address na iyon (ChatEchoes: ang echo nito ay sagot ng Likha bot, hindi "Other app"):
        // "key=app_id key2=app_id2", parehong key ng GATEWAY_URLS. Walang laman = gaya ng dati.
        'app_ids' => (string) env('GATEWAY_APP_IDS', ''),
        // Ang mga address ng gateway na tumatanggap ng button at quick reply (Gateways::buttonsOn): "forbeast,incepxion,
        // incepxionbot" (ang route ng chatbot_replies). Walang laman = text lang sa lahat, gaya ng Botcake.
        'buttons' => (string) env('GATEWAY_BUTTONS', ''),
        // IPv4 lang sa mga tawag sa gateway (CURLOPT_IPRESOLVE), para masubok kung IPv6 lang ang nawawalang sagot.
        'force_ipv4' => (bool) env('GATEWAY_FORCE_IPV4', false),
        'key' => env('GATEWAY_KEY'),
        'webhook_secret' => env('GATEWAY_WEBHOOK_SECRET'),
        'timeout' => (int) env('GATEWAY_TIMEOUT', 10),
        // Ang "typing…" sa simula ng takbo para sa mensahe ng customer (GatewayTyping, Forbeast 028); false = walang tawag.
        'typing' => (bool) env('GATEWAY_TYPING', true),
        // Kusang lipat sa Botcake kapag pumapalya ang Forbeast (ForbeastWatch): ilang sunod na palya, at ang mga window.
        'auto_switch' => [
            'failed_sends' => (int) env('GATEWAY_SWITCH_FAILED_SENDS', 5),
            'no_answer' => (int) env('GATEWAY_SWITCH_NO_ANSWER', 3),
            'health_fails' => (int) env('GATEWAY_SWITCH_HEALTH_FAILS', 3),
            'inbound_chats' => (int) env('GATEWAY_SWITCH_INBOUND_CHATS', 1),
            'inbound_minutes' => (int) env('GATEWAY_SWITCH_INBOUND_MINUTES', 10),
            'standby_minutes' => (int) env('GATEWAY_SWITCH_STANDBY_MINUTES', 15),
            // Ang "inbound" na lipat: ilang customer na walang kopya sa Forbeast, at ilang segundo bago ito ebidensya.
            'inbound_customers' => (int) env('GATEWAY_SWITCH_INBOUND_CUSTOMERS', 3),
            'inbound_seconds' => (int) env('GATEWAY_SWITCH_INBOUND_SECONDS', 300),
            // Ang lag (segundo) na "mabagal" na: ang sa Likha (queue ng gateway events) at ang inbound_lag_seconds ng Forbeast.
            'lag_seconds' => (int) env('GATEWAY_SWITCH_LAG_SECONDS', 60),
            // Ang kusang balik sa Forbeast: ilang minutong sunod-sunod na maayos ang health, at isa lang kada ilang minuto.
            'back_minutes' => (int) env('GATEWAY_SWITCH_BACK_MINUTES', 30),
            'back_gap_minutes' => (int) env('GATEWAY_SWITCH_BACK_GAP_MINUTES', 60),
            // Ilang segundo pagkalipat sa Forbeast (kahit sino ang naglipat) na ang chat ng Botcake ay hindi ebidensya.
            'settle_seconds' => (int) env('GATEWAY_SWITCH_SETTLE_SECONDS', 180),
            // Ang walang sagot sa maraming page ng iisang gateway nang sabay (burst_pages na page sa loob ng burst_seconds) ay
            // problema ng daan, hindi ng page: hold muna nang hold_minutes at susuriin ulit bago ilipat. 0 page = walang hold.
            'hold_minutes' => (int) env('GATEWAY_SWITCH_HOLD_MINUTES', 2),
            'burst_pages' => (int) env('GATEWAY_SWITCH_BURST_PAGES', 5),
            'burst_seconds' => (int) env('GATEWAY_SWITCH_BURST_SECONDS', 60),
            // Ang hindi kilalang error ng Meta sa 10 minuto: ilang padala man lang, at ilang % na palya bago lipat (mga sagot)
            // o orange na linya (UPDATE). 0% = patay ang bahaging iyon.
            'unknown_min_sends' => (int) env('GATEWAY_SWITCH_UNKNOWN_MIN_SENDS', 10),
            'unknown_response_pct' => (int) env('GATEWAY_SWITCH_UNKNOWN_RESPONSE_PCT', 30),
            'unknown_update_pct' => (int) env('GATEWAY_SWITCH_UNKNOWN_UPDATE_PCT', 70),
        ],
        // Ang echo (ChatEchoes): ilang minuto bago "hindi nasagot" ang mensahe ng customer, ilang customer na hindi nasagot
        // sa huling oras bago pula ang page, at ilang segundo ang layo ng dalawang app na "dobleng sagot".
        'echo' => [
            'unanswered_minutes' => (int) env('GATEWAY_ECHO_UNANSWERED_MINUTES', 5),
            'unanswered_customers' => (int) env('GATEWAY_ECHO_UNANSWERED_CUSTOMERS', 3),
            'double_seconds' => (int) env('GATEWAY_ECHO_DOUBLE_SECONDS', 60),
        ],
    ],

    // Ang pangalawang gateway (incepxionbot.com: parehong Forbeast software, ibang Meta app). Kapareho ng gateway at ng
    // forbeast_site sa itaas; walang URL = hindi naka-set up. Ang mga bilang ng kusang lipat at echo ay ang sa gateway.
    // app_id: ang Meta app ng incepxionbot, para makilala ang echo nito (Bot Health). Ang mga event nito ay sa
    // /api/gateway/events/incepxionbot (ang webhook na inilalagay ng Likha kada page).
    'incepxionbot' => [
        'url' => rtrim((string) env('INCEPXIONBOT_URL', ''), '/'),
        'key' => env('INCEPXIONBOT_KEY'),
        'webhook_secret' => env('INCEPXIONBOT_WEBHOOK_SECRET'),
        'timeout' => (int) env('INCEPXIONBOT_TIMEOUT', 10),
        'typing' => (bool) env('INCEPXIONBOT_TYPING', true),
        'site_key' => env('INCEPXIONBOT_SITE_KEY'),
        'site_secret' => env('INCEPXIONBOT_SITE_SECRET'),
        'app_id' => env('INCEPXIONBOT_APP_ID'),
    ],

    // Ang site na ito bilang "site system" ng Forbeast (kung sino ang may-ari ng bawat page, at ang paglipat ng page sa
    // kabilang site): ang fbs_ key (Bearer sa /v1/site/...) at ang secret na pumipirma sa /api/gateway/site/...
    // Walang laman ang alinman = hindi naka-link: ang Owners screen ay sinasabi ito at ang mga endpoint ay 503.
    'forbeast_site' => [
        'key' => env('FORBEAST_SITE_KEY'),
        'secret' => env('FORBEAST_SITE_SECRET'),
    ],

    // Read-only RTS check feed (/api/owner/rts-check); walang laman = 401 sa lahat.
    'rts_check' => [
        'key' => env('RTS_CHECK_KEY'),
    ],

    // Sulatan ng analyst ng Mira RTS (/api/owner/analyst-rts); walang laman = 401 sa lahat.
    'analyst_rts' => [
        'key' => env('ANALYST_RTS_KEY'),
    ],

    // Read-only staff feed (/api/staff-status, /api/staff-events); walang laman = 503.
    'staff_feed' => [
        'key' => env('STAFF_FEED_KEY'),
        'bot_user_ids' => env('STAFF_FEED_BOT_USER_IDS', ''),
    ],




];
