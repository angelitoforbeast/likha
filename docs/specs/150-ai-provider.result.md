# Result 150: chatbot AI provider (OpenAI / Local), fallback, per-request record

## Plan

Base: `feat/149-comment-replies` at 52974859. Base run before any change: `tests/Feature/Chatblast` = 882 tests, OK, 1 skipped (38 PHPUnit deprecations). The AI pool tests (`ChatbotAsyncAiTest`) are inside that folder.

### Design (details the brief leaves open)

**Providers (config only).** `config/services.php` `chatbot_ai.providers`: `openai` (label OpenAI, url `https://api.openai.com/v1`, key read from the existing `services.openai.key`) and `local` (label Local, url `CHATBOT_AI_LOCAL_URL`, key `CHATBOT_AI_LOCAL_KEY`, `localhost_only`, defaults model '', timeout 8, concurrency 4, breaker 3 / 30 s). A third OpenAI-compatible provider is one more entry there (no UI). `ChatbotAiProviders` reads them: ready or "not set up", the request URL (`<base>/v1/chat/completions`, one slash), the key (no header when empty), the localhost check (http on 127.0.0.1 or localhost, any port, no user:pass; anything else = not set up, never called), and the parse of an answer (usage tokens; `<think>` strip for non-OpenAI only).

**Chain.** `ChatbotAiChain::forPage`: provider = page `chatbot.ai_provider` ?? site default ?? `openai`; fallback = page `chatbot.ai_fallback` ?? site ?? (Local → `openai`, OpenAI → none). OpenAI uses the node's model; Local uses its own model and timeout (page ?? site ?? config). The waiting path (CHATBOT_AI_ASYNC=false, Test tab, sequences, no live pool) runs the chain inside today's 30 s; the pool runs it inside the row's `deadline_at`. An OpenAI-only chain sends today's exact request (URL, headers, body, timeout, connect_timeout) and keeps today's error texts. Local: cap = cache-lock slots shared by workers and the pool (they expire on their own); circuit breaker = cache counter, both site-editable. Skipped steps (not set up, busy, paused) are recorded with a reason.

**Pool.** The pool resolves the chain at claim time (no `chain` column: settings are read per claim, which also covers rows in flight at deploy). A failed attempt with time left starts the next provider at once; the row stays `sending` under the same owner and only the final outcome calls the conditional `finish()`. Each attempt has a pool-wide unique token; Local attempts also get a pool timer at their timeout, so the pool moves on even if the connection does not give up; any late callback is ignored. OpenAI 429 keeps spec 087's rule (requeue, halve); the failed attempts are carried, so the retry goes on from OpenAI. Local 429 falls back at once. The sweep's late text names the chain's last provider ("OpenAI did not answer in time." for OpenAI-only rows, as today).

**Record.** New columns on `chatbot_ai_requests` (migration `2026_11_10_100000_add_provider_record_to_chatbot_ai_requests`): `provider`, `model_used`, `fell_back`, `fallback_reason`, `ai_attempts` (JSON list of {provider, model, ms, ok, why, skipped?}; no text), `latency_ms` (sum of the attempts = the customer's wait for the AI), `prompt_tokens`, `completion_tokens`, `path` (async | sync); `messages` and `context` become nullable; index (page_name, created_at). Waiting and Test-tab calls write one row with status `sync`, without messages, answer or context. Retention: `resumed`/`sync` rows lose messages, answer and context after 2 days and are deleted after 90; stuck `answered`/`failed` rows are deleted after 2 days as before. Every write checks `Schema::hasColumn`, so before the migration the AI step works as today.

**Settings.** Per page (page_settings, provider `chatbot`, history kept): `ai_provider`, `ai_fallback`, `ai_local_model`, `ai_local_timeout`. Site default: app_settings `chatbot.ai.*` (provider, fallback, local_model, local_timeout, local_concurrency, breaker_failures, breaker_seconds) is what the reply path reads (cached 60 s, forgotten on save); every site change also writes its history line as a page_settings row on the reserved page `_all`, shown in Changes as "Site default". The `ai_*` keys are in `ChatbotFields::RESERVED` and `PageSettings::SYSTEM_KEYS`; chatbot keys ending in `key` / `api_key` are refused by the generic endpoints. Endpoints (access `/chatblast`): `POST /chatbot/ai` (page_names[] ≤ 200, all or none), `POST /chatbot/ai/site`, `GET /chatbot/ai/compare` (page, from, to; Manila days, up to 90).

### Tasks

| # | Task | Side | Tier | Cases |
|---|---|---|---|---|
| 1 | Provider layer + chain in the waiting path | backend | high | C.1, C.2, C.4, C.5, C.7–C.10, C.13 (sync), C.13b, C.15–C.18, E.1, E.2, E.5–E.10, F.1, F.2, F.4, F.5, F.10 |
| 2 | Pool chain + finish / sweep / resume | backend | high | C.3, C.6, C.11, C.12, C.13 (pool), C.14, F.3, F.6, F.7, F.9 |
| 3 | Record columns, migration, retention | backend | medium | D.1, D.2, D.5–D.8, D.11–D.13 |
| 4 | Settings endpoints + Changes | backend | high | A.1–A.10, B.1–B.7, E.3, E.4, F.8 |
| 5 | /chatbot/pages AI control + site default | frontend | medium | A.1 (screen), A.11 |
| 6 | Comparison endpoint + dialog | backend + frontend | medium | D.3, D.4, D.9, D.10, D.14 |
| 7 | Full suite, key grep, reviews, result | — | — | all |

## Progress

- Stage 0: vendor copied from rel-137 (same composer.lock); base run 882 OK; spec and plan committed.
- Stage 1: provider layer and the chain in the waiting AI step (28 tests).
- Stages 2–3: pool chain, finish/sweep, record, migration and retention (7 + 9 tests).
- Review 1 (adversarial, tasks 1–3): 1 major (stale Local timer could end a re-claimed request) fixed test-first; 3 minors fixed (OpenAI keeps its last-second send, stuck rows pruned as before, sync rows out of Bot Health); 5 minors accepted in TODO.md.
- Stages 4–6: settings endpoints, Changes, comparison and the UI (20 + 7 tests).
- Review 2 (adversarial, tasks 4–6): no blocker or major; minors on staff input fixed in one wave (compare `page[]` 500, impossible dates, skipped attempts counted as calls, D.4 test, existing `ai_` staff columns locked, site dialog saving env defaults, UTC date default, stuck "Loading…"); the rest accepted in TODO.md.
- Stage 7: full Chatblast suite, PageSettings and OrderEncode suites, key grep (see Evidence).
- Browser check: not run. This worktree has no running app; A.11 and D.14 at 390 x 844 are checked in markup only (the dialogs are single-column below `sm`, the comparison table scrolls in its own box). Owner checks on a real phone stay open.

## Files

- `config/services.php`: `chatbot_ai.providers` (OpenAI, Local).
- `app/Services/Chatbot/ChatbotAiProviders.php` (new): provider config, URL guard, keys, parse, fixed error texts.
- `app/Services/Chatbot/ChatbotAiChain.php` (new): chain per page, site settings cache, Local slots and breaker, waiting-path run, outcome/record.
- `app/Services/Chatbot/ChatbotAi.php`: `answer()` runs the chain and writes the sync record (the old single-call `complete()` is gone; payload/parse unchanged).
- `app/Services/Chatbot/FlowEngine.php`: one line (calls `answer()` with the node id).
- `app/Services/Chatbot/ChatbotAiPool.php`: chain per row, tokens, Local timer, 429 carry.
- `app/Services/Chatbot/ChatbotAiQueue.php`: `path` on submit, record on finish, `record()` for sync calls, sweep text and retention.
- `app/Services/Chatbot/BotHealth.php`: AI calls skip `sync` rows.
- `app/Services/Chatbot/ChatbotAiSettings.php` (new), `ChatbotAiCompare.php` (new), `app/Http/Controllers/ChatbotAiController.php` (new), `routes/web.php` (3 routes).
- `app/Http/Controllers/ChatbotController.php`: `data()` rows get `ai`, response gets `ai_site`; `changes()` includes the AI keys and the site default, with labels and shown values.
- `app/Services/PageSettings.php`: AI system keys, `keyLike()` refusal. `app/Services/ChatbotFields.php`: AI keys reserved, key-like column names refused.
- `resources/views/chatbot/index.blade.php`: "AI: …" button per row, "AI default" and "Compare AI" dialogs (inline script; no Vite asset changed, so no `npm run build`).
- `database/migrations/2026_11_10_100000_add_provider_record_to_chatbot_ai_requests.php` (new).
- Tests (new): `tests/Feature/Chatblast/ChatbotAiProviderTestCase.php`, `ChatbotAiProviderTest.php`, `ChatbotAiProviderPoolTest.php`, `ChatbotAiRecordTest.php`, `ChatbotAiSettingsTest.php`, `ChatbotAiCompareTest.php`.
- `qa/stories.md` (S-150), `TODO.md` (accepted findings), this result and the spec.

## Story changes

- Added **S-150D.3b**: skipped attempts (cap full, breaker open, not set up) are counted as `skipped`, not as calls, failures or latency, and bad compare input (array page, impossible or reversed dates, range over 90 days) is refused with 422. Reason: review 2 showed Local's failure rate looked worse than its real calls, and `page[]` gave a 500.
- **S-150E.10**: the request always goes to `…/v1/chat/completions` (`/v1` is added when the base lacks it), because Ollama serves only `/v1`. The case text said `<base>/chat/completions`; see ruling 2.
- **S-150F.6, F.7, F.9** are proven by the unchanged `ChatbotAsyncAiTest` (S-087.7 for the 429 rule, the whole file for F.7, S-087.10 for the mock bypass) instead of new tests, per the "no second test for a proven behaviour" rule.

## Evidence per case

Final run (stage 7): see "Final runs" below. Red lines are the first failure seen before the code of that slice.

| Slice | Red run (first failure line) |
|---|---|
| 1 (28 tests, `ChatbotAiProviderTest`) | `Error: Class "App\Services\Chatbot\ChatbotAiChain" not found` (all 28; F.1, F.2, F.4, E.7, E.8 pin today's behaviour, so their only red was the missing class) |
| 2 (`ChatbotAiProviderPoolTest`) | C.3 `Failed asserting that actual size 0 matches expected size 1` (no Local call); C.13 `… size 0 matches expected size 4`; C.6, C.11, C.12, C.14 `Undefined array key 0`; F.3 passed on the base (characterisation) |
| 3 (`ChatbotAiRecordTest`) | all 9: `FileNotFoundException: File does not exist at path …/2026_11_10_100000_add_provider_record_to_chatbot_ai_requests.php` |
| Review 1 fixes | C.14 `Failed asserting that two arrays are identical` (stale timer failed the retried request); F.3 `actual size 1 matches expected size 2` (no last-second send); D.8 stuck `answered` row not deleted; D.11 `Failed asserting that 2 is identical to 0` (sync rows in Bot Health) |
| 4 (`ChatbotAiSettingsTest`) | 14× `Expected response status code [200] but received 404`; `Undefined array key "ai"`; E.4 `received 302` (fixed in the test: that endpoint refuses by redirect with errors) |
| 5–6 (UI, compare) | A.11 and D.14 `Failed asserting that '<!DOCTYPE html>…' contains "data-ai-url=…"`; the first `ChatbotAiCompareTest` cases (D.3, D.6–D.10) were written before `ChatbotAiCompare` but run only after it existed, so no separate red line was captured for them (the A.4 / E.3 runs before it errored on the missing class) |
| Review 2 fixes | D.3b `Undefined array key "skipped"`; E.4 (ai_prompt) `Failures: 1` (existing staff column refused) |

| Case | Test | Result |
|---|---|---|
| S-150A.1 | `ChatbotAiSettingsTest::test_S_150_A1…` | pass |
| S-150A.2 | `…test_S_150_A2…` | pass |
| S-150A.3 | `…test_S_150_A3…` | pass |
| S-150A.4 | `…test_S_150_A4…` | pass |
| S-150A.6 | `…test_S_150_A6…` (7 bad posts) | pass |
| S-150A.7 | `…test_S_150_A7…` | pass |
| S-150A.8 | `…test_S_150_A8…` | pass |
| S-150A.9 | `…test_S_150_A9…` (sequential; see TODO) | pass |
| S-150A.10 | `…test_S_150_A10…` (4 good, 5 bad names) | pass |
| S-150A.11 | `…test_S_150_A11…` (server + markup; browser not run) | pass (server) |
| S-150B.1–B.7 | `…test_S_150_B1…` to `…test_S_150_B7…` | pass |
| S-150C.1, C.2, C.4, C.5, C.7–C.10, C.13 (sync), C.13b, C.15–C.18 | `ChatbotAiProviderTest::test_S_150_C…` | pass |
| S-150C.3, C.6, C.11, C.12, C.13 (pool), C.14 | `ChatbotAiProviderPoolTest::test_S_150_C…` (C.3 also sync in `ChatbotAiProviderTest`) | pass |
| S-150D.1, D.2, D.5–D.8, D.11–D.13 | `ChatbotAiRecordTest::test_S_150_D…` (D.6 also in `ChatbotAiCompareTest`) | pass |
| S-150D.3, D.3b, D.4, D.7, D.8, D.9, D.10, D.14 | `ChatbotAiCompareTest::test_S_150_D…` | pass (D.14 markup; browser not run) |
| S-150E.1, E.2, E.5–E.10 | `ChatbotAiProviderTest::test_S_150_E…` | pass |
| S-150E.3, E.4 | `ChatbotAiSettingsTest::test_S_150_E3…`, `…E4…` | pass |
| S-150F.1, F.2, F.4, F.5, F.10 | `ChatbotAiProviderTest::test_S_150_F…` | pass |
| S-150F.3 | `ChatbotAiProviderPoolTest::test_S_150_F3…` | pass |
| S-150F.6, F.7, F.9 | `ChatbotAsyncAiTest` S-087.7, whole file, S-087.10 (unchanged) | pass |
| S-150F.8 | `ChatbotAiSettingsTest::test_S_150_F8…` | pass |

Migration: D.12 runs the migration's `down()` then `up()` inside the test on the sqlite test database (both clean), and the AI step (sync and pool) works while the columns are missing. MySQL not run.
Key grep: `git grep -n -i -P "sk-[a-z0-9]{10,}"` finds only 3 lines that are on the base already (`tests/Feature/NightRun/CheckerLogCleanupTest.php:21`, `tests/Feature/OrderEncode/EncodeOrderTest.php:229,233`); nothing new. Test keys are `sk-test-not-real` and `local-test-key`.

### Final runs

- `tests/Feature/Chatblast` (includes the AI pool tests): **955 tests, OK**, 6471 assertions, 1 skipped (the same skip as the base), 38 PHPUnit deprecations (same as the base). Base was 882; 73 new tests (this spec's 71 plus D.3b and D.4 from review 2). No test fails.
- `tests/Feature/PageSettings` + `tests/Feature/OrderEncode` (they touch `PageSettings::validate` and the AI queue): **555 tests, OK**.
- One earlier full run had `ChatbotTagsTest::test_S_100_2…` red once (a sequence-timing test, passes alone and in the later full runs) and one had this spec's F.3 red once: a whole-second timing assumption in the test, fixed by pinning the clock (C.11 had the same assumption, also pinned); the pool test file then passed 3 runs after the F.3 pin, 2 runs after the C.11 pin, and the final full run.

## Rulings

1. Site default "in app_settings with its own history line": app_settings holds the value the reply path reads; the history line is a page_settings row on the reserved page `_all`, written in the same transaction, so Changes and history work unchanged.
2. E.10: the request always goes to `…/v1/chat/completions`: `/v1` is added when the base lacks it (Ollama serves only `/v1`).
3. The `<think>` strip applies to non-OpenAI providers only, so OpenAI parsing stays byte-for-byte today's.
4. Waiting-path rows use status `sync`, so the pool, the sweep and the resume never touch them; Bot Health skips them (no wait to measure).
5. No `chain` column: the pool reads the chain at claim time (simpler, and rows in flight at deploy get it too).
6. Choosing Local also needs a Local model name (page, site or config); otherwise every request would skip Local. Refused with "Set the Local model name first."
7. E.7: a missing OpenAI key is recorded as "OpenAI is not set up." in the attempts, while the step's error keeps today's "No OpenAI key is set on the server." (F: OpenAI-only pages fail exactly as today).
8. `latency_ms` is the sum of all attempts (the customer's wait for the AI); each attempt keeps its own ms for the per-provider comparison.
9. E.4 refuses chatbot keys ending in `key`/`api_key` only; the AI settings themselves are system keys. Existing staff columns starting with `ai_` stay editable.

## Merge danger

- **Builds on spec 149** (unreleased): merge after 149.
- **Migration on `chatbot_ai_requests`** (column change + 9 columns + index). The table is small today (rows deleted after 2 days); from now on resumed/sync rows stay 90 days (without customer text), so it grows to about 90 days of AI requests. Run by hand: `php artisan migrate --force`. Before the migration the AI step works as today (no record).
- **Restart after deploy**: `php artisan config:cache`, restart `chatbot:ai-pool` (supervisor) and the queue workers (`php artisan queue:restart`), because the pool code changed.
- **Generic settings refusal**: chatbot keys ending in `key` can no longer be written from the generic endpoints. Before deploy, check prod `page_settings` (provider chatbot) and app_settings `chatbot.columns` for such keys (TODO.md).
- **Switch everything back to OpenAI**: on /chatbot/pages set each overridden page's AI button to "Default", and "AI default" to OpenAI; or unset `CHATBOT_AI_LOCAL_URL` and run `php artisan config:cache`, so Local is "not set up" and is skipped. Note: a page whose fallback is "None" with Local as its only provider then fails (failed port) instead of answering; set its fallback to OpenAI or its provider to Default first.
- **Set Tech Flask Ph to Local after release**: set the env lines below, `config:cache`, restart the pool; open /chatbot/pages, click "AI: OpenAI (default)" on the Tech Flask Ph row, pick AI provider Local, type the model name (as Ollama lists it, e.g. `qwen3:14b`), leave "If it fails, then" on Default (Local → OpenAI), Save. Compare with "Compare AI".
- **Env lines the server needs** (only for Local; Incepxion needs none):
  - `CHATBOT_AI_LOCAL_URL=http://127.0.0.1:11435/v1` (the reverse SSH tunnel's port on the server's localhost)
  - `CHATBOT_AI_LOCAL_KEY=` (optional; leave empty for Ollama without a key)

## Commits

- 5a1f4fb7 docs: spec and plan for the chatbot AI provider (spec 150)
- 9398049b feat: chatbot AI provider chain in the waiting AI step, Local with cap and breaker (spec 150)
- 56bdf719 feat: chatbot AI pool runs the provider chain in the row deadline, per-request record and 90-day retention (spec 150)
- 1c937d59 fix: a stale Local timer never ends a re-claimed request, OpenAI keeps its last-second send, stuck rows pruned as before, sync records out of Bot Health (spec 150)
- 0b1d01ee feat: choose the chatbot AI per page and site-wide on /chatbot/pages, AI changes in Changes, compare AI per page (spec 150)
- fafce8dd fix: compare AI refuses bad dates and counts only real calls, staff ai_ columns stay editable, the site AI dialog saves only changed fields (spec 150)
- (last) docs: result, stories and stable pool test timing (spec 150)

## Open points

- Owner checks: stop the Local tunnel and ask Tech Flask a question (one reply from OpenAI); judge Local's answers against OpenAI; change the setting on a real phone (also covers A.11 and D.14 at 390 x 844, which no browser checked here).
- The production cache store must give cross-process locks for the Local cap (the default `database` store does; `array` would not).
