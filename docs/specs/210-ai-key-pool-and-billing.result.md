# Result: spec 210 AI key pool and billing

**Status:** done
**Date:** 2026-11-01
**Branch / PR:** `feat/210-ai-key-pool` (worktree `.claude/worktrees/ai-keys-210`), based on `8cdb1a7b` (the same tree as `vps2/main`), not pushed, no PR

## Summary

The chatbot's AI step now runs on a pool of the company's own keys, with failover between them.

- **Keys** (`/chatbot/ai-keys`, `ChatbotAiKeys`)
  - Stored as `page_settings` rows on the reserved page `_all`, provider `ai_keys`:
    - `k<id>.secret`, encrypted (`is_secret`)
    - `k<id>.info`, JSON: provider, label, last 4, HMAC hash, enabled, deleted
    - `k<id>.health`, the persisted down mark and the last Test
  - Add, Test (one 1-token call), Rename, Disable/Enable and Delete each write a history row with who and when.
  - Delete sets the encrypted value of every row of that key to null.
  - CEO only (amendment 210-1): everyone else gets 403 and no "AI keys" button. No new table.
- **Chain** (`ChatbotAiChain::steps`, used by the waiting path and by `chatbot:ai-pool`)
  - Each provider of the page's order becomes one step per usable key.
  - Keys take turns (round-robin).
  - A key fault (402/billing, 401/403, 429, or 5xx/529/timeout after 3 in 5 min) rests the key and moves to its next key in the same call.
  - A model or request error (404 model, 400) marks nothing and skips that provider's other keys.
  - Rests are 30 min for no credit, 2 min for a 429 and 1 min for failing. They double each time a recovering key fails again, capped at 6 h. An invalid key waits for a passing Test.
  - Each switch writes one event row. When every key has failed, the error is "All AI keys failed." and one alert row is written per hour.
  - With no OpenAI key in the pool, `services.openai.key` is used as before ("Server key"). The pool's 429 slow-down still applies to that key only.
- **Providers**
  - Claude and DeepSeek go through small subclasses of the Boardroom adapters (`Wire/ClaudeChat`, `Wire/DeepSeekChat`), which reuse their payload, parse and `classify()`.
  - OpenAI keeps the chat-completions body and only borrows the classification (`Wire/OpenAiChat`).
  - Error texts are fixed sentences ("Claude refused (HTTP 529).") and never the provider's text.
- **Cost**
  - New nullable columns on `chatbot_ai_requests`: key id, label, last 4, `cost_micros`, `cost_flag`, `usage_*`.
  - `cost_micros = ceil(in × price_in + out × price_out)`, using the price list in `services.chatbot_ai.prices` (USD per 1M tokens is micro-USD per token).
  - No usage block, or a model not in the list, gives a null cost with `no_usage` / `no_price`.
  - Only the answering attempt is costed. The prune never deletes a `pending` row.
- **Billing**
  - `SendChatbotAiUsage` runs every minute and is unique. Per gateway (`usage_gateway` = the page's `chatbot.gateway` at call time) it POSTs batches of 100, at most 20 batches per run, to `<gateway url>/v1/site/ai-usage` with the gateway's site key.
  - Results map as follows:
    - stored or duplicate → `sent`
    - stored with `needs_price: true` → `needs_price`
    - `unbilled` and `page_not_found` → those statuses
    - `invalid` → `invalid`
    - a 422 for the whole request → every row `invalid`
  - A 5xx, a 429, any other non-2xx or no answer stops that gateway and backs off 1, 2, 4 … 30 min.
  - A 401/403 pauses the gateway (a row the key screen shows) and probes every 15 min.
- **Zero load** (`ChatbotAiLoad`, in `FlowEngine::ai` before both paths)
  - The page's state comes from the cache (60 s).
  - On a miss it asks Forbeast `GET <gateway url>/v1/site/ai-load` for this page plus up to 99 other known pages of that gateway, one request at a time per gateway.
  - `chatbot:ai-load` refreshes every known page each minute, in batches of 100.
  - `stopped` → no provider call, failed port, error "No AI load: Forbeast stopped the AI for this page.", and a record row.
  - Anything else, any error, or no gateway → the AI runs. Errors are cached 60 s, so a down Forbeast is not asked per message.
  - The low/stopped note shows on the page row and in its AI dialog.
- **Settings**
  - The page and site AI dialog offers OpenAI, Claude, DeepSeek and Local.
  - Fallback is now a list, with a second "If that fails too".
  - New Claude model and DeepSeek model fields. Defaults: `claude-haiku-4-5` and `deepseek-chat`, timeout 20 s each.
  - New "AI keys" button.

A skeptic review subagent read the diff. It found 4 majors and 3 minors and no blocker; all 7 are fixed in `e708704e`:

- Usage: any non-422 4xx is retried instead of marked invalid.
- Load check: wrapped so an error never stops the AI, plus a per-gateway single-flight lock.
- Pool: the timer and rejection paths free their slot when settling throws.
- `succeeded()` keeps a mark that is still resting.
- "All AI keys failed" ignores busy/late skips.
- Keys are `#[\SensitiveParameter]`.

## Checks (command and output)

PHP is Herd's `php84`; tests run on sqlite `:memory:` (phpunit.xml).

| Check | Command | Output |
|---|---|---|
| Spec 210 tests (after amendment 210-1) | `php vendor/bin/phpunit tests/Feature/Chatblast --filter "AiKey\|AiUsage\|AiLoad"` | `Tests: 56, Assertions: 671, PHPUnit Deprecations: 52` (no failure; the deprecations are the suite's own) |
| Chatbot AI and neighbours (after amendment 210-1) | `php vendor/bin/phpunit tests/Feature/Chatblast --filter "AiKey\|AiUsage\|AiLoad\|ChatbotAi\|ChatbotAsyncAi\|AdGroup\|ChatbotPages\|ChatbotData"` | `Tests: 210, Assertions: 1683, Failures: 1`: only the base's `ChatbotDataSpeedTest::test_S_207_9…` |
| Chatbot AI neighbours | `php vendor/bin/phpunit tests/Feature/Chatblast --filter "AiKey\|AiUsage\|AiLoad\|ChatbotAi\|ChatbotAsyncAi"` | `Tests: 148, Assertions: 1144, PHPUnit Deprecations: 52` (no failure) |
| Full suite, base `8cdb1a7b` | `php -d memory_limit=2G vendor/bin/phpunit` | `Tests: 3928, Assertions: 72521, Failures: 3, PHPUnit Deprecations: 92, Skipped: 4` |
| Full suite, this branch (HEAD before this file) | same | `Tests: 3984, Assertions: 43168, Failures: 5, PHPUnit Deprecations: 92, Skipped: 4` — the 3 base failures plus 2 timing tests that also fail on the base when re-run alone (next row); the lower assertion count is those two loop tests stopping at their first failed assertion |
| The 2 timing tests, on the base code | `git checkout 8cdb1a7b -- app config routes resources`, each test alone, then `git checkout HEAD -- …` | `BigBroadcastTest::test_S_167_1…`: base `Failed asserting that 3604 is identical to 5000.` (branch 1839); `AstraAddressRulesTest::test_S_26_18…`: base `Failed asserting that 31.7957494 is less than 25.0.` (branch 45.36). Both are time limits on this machine; neither touches the chatbot AI code |
| Migration up/down/up | `AiKeyMigrationTest::test_the_key_and_cost_migration_runs_rolls_back_and_runs_again_keeping_rows` | passes: columns gone after `down()` (row kept), back after `up()`; both are no-ops when repeated |
| Secret mutation check | planted `Log::info('added ' . $key)` in `ChatbotAiKeys::add`, ran `--filter 7_1` | `Failed asserting that 'PageSettings: created …' does not contain …`; reverted |

The base suite already fails 3 tests. The branch fails the same 3 and no new one; the two timing tests above also fail on the base when re-run, depending on machine load:

- `ChatbotDataSpeedTest::test_S_207_9_chatbot_data_with_117_pages_makes_a_bounded_number_of_queries_and_the_same_json`
- `ExampleTest::test_the_application_returns_a_successful_response`
- `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`

## Red lines

Each was run before the code of its slice (watched red):

| Slice | Test | First failure |
|---|---|---|
| Key pool and screen (`7df84b1d`) | `AiKeyScreenTest::test_S_210_1_1_…` | `Expected response status code [200] but received 404.` (10 tests: 6 errors, 4 failures) |
| Failover, waiting path (`b6149b59`; chain change stashed for the run) | `AiKeyFailoverTest::test_S_210_3_1_…` | `Failed asserting that two arrays are identical.` (only key 1111 called); `test_S_210_3_5_…`: `ModelNotFoundException: No query results for model [App\Models\PageSetting].` (17 tests: 2 errors, 15 failures) |
| Failover, pool (`b6149b59`) | `AiKeyPoolPathTest::test_S_210_3_8_…` | `Failed asserting that 0 is identical to 1.` (no mark); `test_S_210_3_12_…`: `Failed asserting that two arrays are identical.` (the pool called the server key) |
| Cost and prune guard (`d7fd3e7e`) | `AiKeyCostTest::test_S_210_4_5_…` | `Failed asserting that 0 is identical to 1.`; `test_S_210_4_7_…`: `Failed asserting that two arrays are equal.` (6 failures) |
| Usage sender (`e2ff8762`) | `AiUsageSenderTest::test_S_210_5_1_…` | `Error: Class "App\Jobs\SendChatbotAiUsage" not found` (8 errors) |
| Zero load (`c457d3ee`) | `AiLoadTest::test_S_210_6_1_…` | `Failed asserting that two arrays are identical.` (the provider was called); `test_S_210_6_3_…`: `CommandNotFoundException: The command "chatbot:ai-load" does not exist.` (6.2 was already green: the AI ran before too) |
| Settings (`11cbe32e`) | `AiKeySettingsTest::test_S_210_2_1_…` | `Expected response status code [200] but received 422.` (fallback list refused) |
| Amendment 210-1, CEO-only key screen | `AiKeyScreenTest::test_S_210_1_5_anyone_but_the_ceo_gets_403…` on the /chatblast gate | `Expected response status code [403] but received 200.` |
| Review fixes (`e708704e`) | `AiUsageSenderTest::test_S_210_5_5_…` against the pre-fix sender | `after failure 3 — Failed asserting that two arrays are identical.` (a 404 marked rows invalid) |
| Secrets (`e3e6754c`, test only) | `AiKeySecretsTest::test_S_210_7_1…7_3` | Guards written after the code, green on their first run. The mutation check above shows 7.1 catches a leak. |

## Done-when checklist

- [x] Cases S-210.1.1 – S-210.7.4 pass, each with a test named after it (53 cases, 54 tests: S-210.2.1 has one in the chain and one in the settings).
- [x] A red line for every feat/fix slice, watched before the code (table above; the secrets slice is test-only).
- [x] The key screen, the page settings changes and the note are reachable locally. See "Trying it locally" under Deploy notes.
- [x] The full suite adds no new failures: the 3 base failures and 2 timing tests that also fail on the base are named. Migrations run and roll back.
- [x] `qa/stories.md` has the slice 210 stories, with "Last run" filled.
- [x] This file is filled in.

## Rulings

- Ruling: base is `8cdb1a7b`, as the worktree was made — `git diff HEAD vps2/main` is empty (vps2/main is release commits over the same tree) — none.
- Ruling: the key screen and all its routes are CEO only (amendment 210-1), using the app's one CEO check `RefuseRememberedLoginUnlessCeo::isCeo()` (trim, collapsed spaces, case-insensitive; the same check gates Horizon). The /order-encode/forbeast page of spec 211 is not on this base, so its check could not be read; this is the shared one the codebase already uses. The page/site AI dialog keeps the /chatblast gate — it chooses providers and models only and shows no key text — if spec 211 uses another CEO check, swap the one line in `ChatbotAiKeyController::allowed()`.
- Ruling: Add does not run a Test; a new key is "Not tested" and usable — a bad key is marked down on its first real call (401 → invalid) — if wrong, one customer call may hit a bad key before failover.
- Ruling: a Test that fails with a model or request error does not mark the key down (the test model, not the key, was refused) — if wrong, a key whose account lacks `gpt-4o-mini` shows OK.
- Ruling: Local answers are not billed (not costed, `usage_status` null). Only the pooled providers are billed, and the legacy server key counts as one — Local uses no paid key — if wrong, Local AI is given away free. Confirmed by amendment 210-1.
- Ruling: Test runs and sequences are billed like live calls — they use the same AI step and real tokens — if wrong, staff tests are charged to the page's account. Confirmed by amendment 210-1.
- Ruling: cost is rounded up to the whole micro-USD (`ceil`, after rounding the float to 6 decimals) — "never rounded to 0" with an integer contract field — if wrong, up to +1 micro-USD per call.
- Ruling: a record with no usage block sends `tokens_in`/`tokens_out` 0 and `cost_micros: null` — the contract requires integers for tokens — Forbeast flags it `needs_price` either way.
- Ruling: Forbeast URLs are `<gateway url>/v1/site/...`, like the existing `ForbeastSite` calls (the configured gateway URL ends in `/api`) — if a gateway URL lacks `/api`, the calls 404; they are retried with backoff and nothing is lost.
- Ruling: a whole-request 422 marks the batch `invalid` for good; every other non-2xx is retried — a 422 means our request is wrong, and repeating it would loop forever — rows marked invalid need a manual resend.
- Ruling: the usage gateway is fixed at call time (`usage_gateway`) — the page answered on that gateway then — if the page moves gateways later, its old rows still go to the first one.
- Ruling: the load state is fresh for 60 s per page (per spec) and fetched with a per-gateway lock. A message that finds the lock taken uses the last answer up to 120 s old, or runs the AI — this avoids a request storm at each cache expiry — if wrong, a top-up can take up to 2 min in rare overlap.
- Ruling: a 429 from a pooled OpenAI key marks that key and moves on; the pool's halve-and-requeue stays for the server key only — the pool's slow-down was built for a single key — if wrong, the other keys are not slowed by one key's limit.
- Ruling: per-key concurrency caps (`key_concurrency`) default to none, as OpenAI is today; a call that finds the next key full is "busy" and moves on — keeps today's throughput — set `CHATBOT_AI_*_KEY_CONCURRENCY` to cap.
- Ruling: default models are Claude `claude-haiku-4-5` and DeepSeek `deepseek-chat`, timeout 20 s each. Default prices (USD/1M in/out): gpt-4o-mini 0.15/0.60, gpt-4o 2.50/10, gpt-4.1-nano 0.10/0.40, gpt-4.1-mini 0.40/1.60, gpt-4.1 2/8, gpt-5-nano 0.05/0.40, gpt-5-mini 0.25/2, gpt-5 1.25/10, gpt-5.2 1.75/14, claude-haiku-4-5 1/5, claude-sonnet-4-5 3/15, deepseek-chat and deepseek-reasoner 0.28/0.42 — from memory, not checked online (no network) — if a price is off, the cost is off; edit `services.chatbot_ai.prices`. Kept by amendment 210-1 (config; the owner can change them).
- Ruling: Claude chat messages: system messages are joined into `system`; consecutive same-role turns are merged; a leading assistant turn is dropped (Claude needs the user first) — if wrong, a greeting sent before the customer's first message is not seen by Claude.
- Ruling: on the page AI dialog a blank model or timeout is sent as `default` — the server turns `''` into null, so blank never cleared before (this also fixes Local model and timeout) — none.

## Story changes

- S-210.1.5 (amendment 210-1): "any user who may not change the site-wide chatbot AI settings" became "anyone but the CEO". The test is now `test_S_210_1_5_anyone_but_the_ceo_gets_403_on_the_key_screen_sees_no_link_and_nothing_changes`. A Marketing user with /chatblast access gets 403 on every key route and sees no "AI keys" button, but still changes the AI default.
- S-210.7.2: provider error texts are never stored or logged at all (fixed sentences only), which is stronger than "scrubbed".
- Spec 192's `AdGroupAllValuesTest::test_S_192_3…`: the ad-group AI options now list Claude and DeepSeek, and the refusal reads "Pick OpenAI, Claude, DeepSeek or Local." The expected values were updated.

## Deferred minors

- The key list is not paginated (at most 50 keys, `services.chatbot_ai.keys.max`); the events list is paginated (25 per page).
- "Last used" comes from `chatbot_ai_requests` (90 days), not from a live counter.
- The Forbeast note is shown as text in the row and the dialog; there is no separate banner across /chatbot.
- `ChatbotAiCompare` groups by provider only, not by key.

## Deploy notes

- One migration: `2026_11_15_100000_add_key_and_cost_to_chatbot_ai_requests` (nullable columns and two indexes; reversible).
- New schedule entries, so the scheduler must run as today:
  - `SendChatbotAiUsage` (a job on the `chatbot` queue), every minute
  - `chatbot:ai-load`, every minute, in the background
- Nothing changes for billing until a gateway has a URL and a site key (`GATEWAY_URL` + `FORBEAST_SITE_KEY`, `INCEPXIONBOT_URL` + `INCEPXIONBOT_SITE_KEY`). Without them, rows are `no_gateway` and the load check is skipped. Forbeast spec 042 must be deployed before billing starts.
- Check the price list against the providers' pages before turning billing on.
- Optional env: `CHATBOT_AI_KEY_REST_*`, `CHATBOT_AI_KEY_MAX_REST`, `CHATBOT_AI_KEY_FAILING_AFTER`, `CHATBOT_AI_KEY_FAILING_WINDOW`, `CHATBOT_AI_*_KEY_CONCURRENCY`, `CHATBOT_AI_USAGE_BATCHES`, `CHATBOT_AI_BILLING_TIMEOUT`, `CHATBOT_AI_LOAD_TIMEOUT`.
- Existing behaviour on deploy: with no OpenAI key in the pool, `OPENAI_API_KEY` answers as before (S-210.1.10). Its answers are now costed and, once a gateway is linked, billed.
- **Merge danger:** two-way door.
  - Blast radius: every chatbot AI step (waiting and pool) and the /chatbot/pages AI dialog.
  - Revert: revert the branch and roll back the one migration. Pool keys stay as inert `page_settings` rows and can be deleted on the screen first.
  - Usage already sent to Forbeast stays there.

### Trying it locally

1. `php artisan migrate`, then `php artisan chatbot:ai-demo "<a page on /chatbot/pages>"` (local or testing only; refuses elsewhere). It adds fake keys:
   - one down for no credit
   - one disabled
   - a switch event and the alert
   - usage rows in every status
   - a "low" note for that page

   Open `/chatbot/ai-keys` and `/chatbot/pages` (AI keys button; the row and its AI dialog show the note).

   The note needs that page to have a gateway with `GATEWAY_URL` and `FORBEAST_SITE_KEY`. Forbeast's DemoSeeder site key is `fbs_demo_likha_key_not_real_000000000000`.
2. For live billing against a local Forbeast: run its `DemoSeeder` (Forbeast `docs/ai-load.md`) and point `GATEWAY_URL` at its `/api`. Then `php artisan chatbot:ai-load` and the usage job run against it.
3. Do not press Test on a demo key: Test makes a real provider call.
4. Every case is covered with `Http::fake` in `tests/Feature/Chatblast/Ai*Test.php`.

## Open questions

None. The three questions were answered by amendment 210-1: keys are CEO only, the billing rulings stand, and the default models and prices stay in config.

## Proposed tasks

- A per-key breakdown in Compare AI (requests, failures, cost per key).
- A "Resend" action on the key screen for rows marked `invalid` once their cause is fixed.

210: done — key pool with failover, micro-USD cost and Forbeast billing/load built and tested; key screen CEO only per amendment 210-1
