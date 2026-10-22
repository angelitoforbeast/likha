# Plan 106: the page check also catches the wrong item, the wrong price and the wrong ad video

## What exists (read in code)

- **Values and versions:** `PageSettings::allWithIds($page, 'chatbot')` gives each botfield's plain value and its `page_settings` row id (the version). `ChatbotFields::kind()` marks PICTURE and Seq Img N as images.
- **Check column:** `ChatbotPageCheck::check()` (10 min cache, key from `prints()`), served by `GET/POST /chatbot/check` and cached rows in `/chatbot/data`. The view (`chatbot/index.blade.php`, `fillCheck`) shows ok / X with reasons / "—".
- **Orders:** `macro_output.PAGE`, `ITEM_NAME`, `COD` (string), `ts_date` (index `ts_date, PAGE`).
- **Ads:** `ads_manager_reports.page_name`, `day`, `item_name`, `campaign_name`. **Item settings:** `page_item_settings.page_name`, `item_name`, `mode_cod_int` (integer ₱), `effective_date` (the `price` column was dropped).
- **OpenAI:** `Http::withToken(config('services.openai.key'))->post('https://api.openai.com/v1/chat/completions')` with `response_format: json_object` (e.g. `CardStatement\StatementVision`). Cost: token usage × `config('services.openai.ai_checker_prices')[model]` (USD per 1M), stored with the row (`MacroChecker::recordUsage` → `ai_checker_logs.detail.cost_usd`). Video analyses convert USD to PHP at ×58.
- **Ad videos:** `gpt_video_analyses` has no ad id, creative id or page. `ads_manager_reports` and `ad_campaign_creatives` store no video id or file name. **No link from an ad to a video exists.** The only link that exists: `gpt_ad_generations.video_analysis_id` with `page_filter` (comma-separated pages the ad copy was written for, from that video, on `/gpt-ad-generator`). The video check uses that link only: "videos whose ad copy was written for this page". A page with no such generation gets no video reason.

## Design

- **Expected item and price** (`App\Services\Chatbot\PageExpectedItem`): mode of (ITEM_NAME, COD as integer ₱) over the page's orders with `ts_date` in the last 3 days (today and the 2 days before, Manila). Else the latest `ads_manager_reports.item_name` for the page (no price). Else the latest `page_item_settings` row with `effective_date <= today` (item + `mode_cod_int`). Returns `{item, price, source: orders|ads|item_settings, orders}` or null. Page names match by `LOWER(TRIM(..)) = pageKey` (BTRIM on pgsql).
- **AI client** (`App\Services\Chatbot\ContentAi`): one JSON chat call; model `services.openai.content_check_model` (env `CHATBOT_CONTENT_MODEL`, default `gpt-4o-mini`); `gpt-4o-mini` price added to `ai_checker_prices`. Returns the decoded answer plus model, tokens in/out and `cost_usd`; null on HTTP error, timeout or invalid JSON (counted as "not checked yet").
- **Content check** (`App\Services\Chatbot\PageContentCheck::run($page, bool $ask)`):
  - Text: Main Flow, Main Flow 2, Fill-Up Form, each non-empty, with `{{Botfield}}` filled from the page's botfields (so a price kept in another botfield is read). The AI returns `{found_item, same_product, confidence, prices:[{item_price, shipping, insurance, total}]}`.
  - Image: PICTURE then Seq Img 1..20, at most 5 with a value, http(s) only; vision with `detail: low`: `{found_item, same_product, confidence}`.
  - Video: each linked analysis (newest 3): its item_name + summary as text, same text prompt (prices ignored).
  - Verdict: `same_product` true → pass. False with confidence ≥ 70 → X; below 70 → warning (amber).
  - Price (no AI, from the text answer), only when the expected price is known and the item did not already fail: like with like. An option with `total` compares the total with the COD; an option with `item_price` compares item price + stated shipping + stated insurance with the COD (so item price = COD − shipping − insurance). Passes when any option matches within ₱1; else X, e.g. "Main Flow: price ₱199 ≠ orders ₱299 COD" or "Main Flow: price ₱199 + ₱50 shipping = ₱249 ≠ orders ₱299 COD".
  - Cache: table `chatbot_content_answers`, one row per answer, unique `cache_key = sha1(kind | ref | expected item)`: text ref = version id + hash of the filled text; image ref = hash of the URL; video ref = analysis id + its `updated_at`. Same key → no AI call. Rows keep model, tokens and `cost_usd` (the cost log).
  - `$ask = false` (every page view, `/chatbot/check`): reads answers only; targets without an answer count as `content_unchecked`. Never calls the AI.
- **Check column:** `ChatbotPageCheck::run` appends the content X reasons to `reasons`, adds `warnings` (amber) and `content: {expected, findings[], unchecked}` to the JSON. `status` stays ok | x | none. The fingerprint (`prints()`) also covers `chatbot_content_answers`, so a finished job shows on the next read. "Check again" (POST) also queues the content job for that page.
- **Job and command:** `App\Jobs\RunChatbotContentCheck` (queue `default`, unique per page or all, 1800 s timeout, stops at 1700 s and leaves the rest for the next run) calls `run($page, true)` for each chatbot page. `chatbot:content-check {--page=} {--sync} {--json}`: queues the job, or runs it in-process with `--sync`; `--json` prints `{pages:[{page, expected, findings, unchecked}]}` from the cached answers (after the run when `--sync`). Nightly at 02:40 Manila (`routes/console.php`).
- **View:** amber icon + warnings list (status ok with warnings), warnings under the red list when X, and "N content checks not done yet" in grey.
- **Migration:** `2026_11_02_106000_create_chatbot_content_answers_table.php`, guarded with `Schema::hasTable`. Its release needs the owner's yes.

Threat model: botfield text and image URLs are stored staff input (untrusted text): it goes to the AI only as data inside the prompt, and the answer is validated in PHP (types, lengths, confidence 0–100) and shown escaped (`textContent`). The image URL goes to OpenAI, never fetched by our server. Orders, ads and item settings are trusted app data. The OpenAI key comes from config and is never logged or returned.

## Tasks

| # | Task | Side | Tier | Cases |
|---|---|---|---|---|
| 1 | Migration, `PageExpectedItem` (orders → ads → item settings, source) | backend | medium | S-106.1, S-106.2 |
| 2 | `ContentAi` + `PageContentCheck`: text, image, video, verdict, price, cache and cost | backend | medium | S-106.3, S-106.4, S-106.5, S-106.6, S-106.7, S-106.10 |
| 3 | Check column JSON (`reasons`, `warnings`, `content`), fingerprint, Check again queues | backend | medium | S-106.7, S-106.9 |
| 4 | Job, `chatbot:content-check` (`--json`, `--sync`), nightly schedule | backend | medium | S-106.8, S-106.9 |
| 5 | Check cell: amber warnings, content not checked yet | frontend | medium | S-106.11 |
| 6 | Browser check 1366/390, result, stories, docs | both | low | S-106.11 |

Tasks share `ChatbotPageCheck` and the new service, so none are parallel-safe; they run in order.

## Stories

See `qa/stories.md`, S-106.
