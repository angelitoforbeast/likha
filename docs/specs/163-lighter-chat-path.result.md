# Result 163: each chat costs Likha less work

Status: done. Branch `feat/163-lighter-chat-path`, cut from `839431d2`.

## What shipped

- **Batched send_status (decision 1).** `/api/gateway/events` accepts `{"kind": "batch", "events": [...]}` per the contract; single events work as before (`GatewayEventController`).
  - **Signature:** checked with the secret of the batch's page, read from the first event, through the same lookup as a single event: the page's own `forbeast.webhook_secret`, else the global `GATEWAY_WEBHOOK_SECRET`. An unknown page gets 401.
  - **Validation:** after the signature, every event must be a `send_status` of that same page (`1001` and `"1001"` count as the same). Mixed pages, another event type, no events, `events` that is not a list, an element that is not an object, or more than 200 events get 422. Nothing is queued in any of these cases.
  - **Processing:** each event is dispatched as its own `HandleGatewayEvent` job, the same job a single event uses. So `confirmed_at` comes from the event's own `sent_at`, and a repeat changes nothing (`SendStatus::receive` updates only a row whose `confirmed_status` is still null). The answer is `{"ok": true, "accepted": n}`.
  - **Size:** the body limit is 64 KB as before. A batch sent with `X-Gateway-Batch: 1` may be up to 512 KB. A body over 64 KB that is not a batch still gets 413, with or without the header.
- **Config cache (decision 2).** New `Chatbot\BotConfigCache` keeps the flow row (by id) and a sequence with its steps for 60 s.
  - **Readers:** `ChatbotPage::flow` (gateway job and every run), `ChatbotSequences::subscribe`, the sequence tick (`runStep`: the sequence, its steps, the step's flow) and `stepFrom`, which now uses the cached steps with the same rule.
  - **Forgets:** every write clears the cache now and again after the commit. This covers the model `saved` / `deleted` events of `ChatbotFlow`, `ChatbotSequence` and `ChatbotSequenceStep`. It also covers `ChatbotFlowController::write()`, the single UPDATE behind save draft, Publish, discard and restore, which fires no model event.
  - **Page settings:** they were already cached by `PageSettings` (600 s, cleared on every `PageSetting` save or delete). The screens, the 162 values API, paste and imports all write through it. It is unchanged.
  - **Schema check:** `Schema::hasTable('chatbot_flows')` now runs only on a cache miss. It used to run twice per chat.
- **One run row (decision 3).**
  - **What's stored:** a flow run now stores one `run` event at its end in place of `run_started` + `run_ended`. Its detail holds `started_at`, `ended_at`, `start` (the start reason), `reason` (the end reason), `flow`, `path`, and `events` (how many of the run's events precede it in the same insert).
  - **Failures:** a run that throws outside a step stores the row with `failed: <class>` (`FlowEngine::run` catches, records and rethrows). `ChatbotRunner` stores the events in a `finally`, so a failed run's events are no longer lost.
  - **Inbox:** `Inbox::merge` renders a `run` row as the same two lines as before. The `run_started` line sits at `started_at`, before the run's events; the `run_ended` line sits at the row. Both keep the same `type` values, so the icons are unchanged.
  - **Other readers:** `Inbox::runs` (the panel's last runs) reads `run` too. The stress stats count `run_started` + `run`. Old rows render as before, and nothing is migrated.
- **Sequence events (decision 4): kept.** The reply row can't carry them safely:
  - A step whose flow sends nothing, fails before sending, or sends twice has 0 or 2+ reply rows for one "Sequence X step N ran" line.
  - The other ops (subscribed, already, refused, unsubscribed, held, paused, skipped, failed, stopped, completed) have no reply row at all.
  - `Inbox::errors` reads the failed ones.

  A safe follow-up is in `TODO.md`: fold `ran` into the run row.
- **Decisions 5 and 6:** the field, waiting, ai, ai_pending, condition, order and encode/upload events are untouched. The sequence logic and timings are unchanged; only their reads are cached.

## Readers of chatbot_events (before the change, by type)

| Reader | Types read | After this change |
|---|---|---|
| `Inbox::thread` / `merge` (timeline) | all, rendered by `ChatbotEvents::text` | `run` renders as the two old lines |
| `Inbox::runs` (panel, last runs) | `run_ended`, `skipped` | + `run` (same `reason` / `path` keys) |
| `Inbox::errors` (panel) | `ai`, `sequence` (failed) | unchanged |
| `Inbox` statuses, `InboxFilters` (ordered filter) | `order` | unchanged |
| `BotHealth` | `ignored`, `order` | unchanged |
| `ChatbotResults` | `order` | unchanged |
| `ChatbotTags::order` (dedupe) | `order` | unchanged |
| `BotcakeStressPage` stats | `run_started` ("Flow runs"), `ai` | "Flow runs" = `run_started` + `run` |
| `resources/views/chatbot/inbox.blade.php` (icons) | item `type` run_started / run_ended / … | unchanged: the items keep those types |
| `ChatbotEvents::prune`, `PruneBotcakeChats`, `BotcakeStressPage` reset | all (delete by age / page) | unchanged |

Writers: `ChatbotEvents::store / skipped / write`, `ChatAttachments` (ignored), `ChatbotTags::order`, `OrderEncode\Runner` (encode_order), `BotcakeSendPool` (send_mismatch), `ChatbotSequences` (sequence).

## Numbers

**Queries per chat on a cached page** (`ChatConfigCacheTest`, one live run with a send and a sequence subscribe, fresh services, warm cache):

| | Bot configuration reads | All queries of the run |
|---|---|---|
| Before (839431d2) | 4 (`hasTable chatbot_flows`, the flow, the sequence, the step) | 17 |
| After | 0 | 13 |

The gateway job on the same chat used 2 more config reads before (`hasTable` + the flow) and 0 now.

- **Reading of the ≤ 8 target:** the test reads "≤ 8" as configuration reads per chat. Those are now 0. The total of 13 is customer data and writes that this spec doesn't touch: chats, customer fields, replies and tags, events and the subscription.
- **Cache store:** on production a cache hit is a Redis read (`CACHE_STORE=redis` since spec 088), not SQL. See the deploy notes.

**Local benchmark (P2, .6).** 1,000 synthetic gateway chats from 200 customers through `POST /api/gateway/events` with sync jobs. The flow is condition → set field → subscribe a sequence → send with wait and save; sends are faked. Then every reply's send_status was posted. Each version ran twice; sqlite in memory, array cache, one PHP process, so there is no per-request app boot.

| | Before (839431d2) | After |
|---|---|---|
| Chats/s | 205.3, 208.3 | 224.0, 224.7 (+8%) |
| Queries per chat (whole path) | 28.8 | 22.8 (−21%) |
| chatbot_events rows | 6,800 (run_started 1,000 + run_ended 1,000) | 5,800 (run 1,000), −15% |
| send_status POSTs for 1,000 replies | 1,000 | 20 (batches of 50) |

- **Event rows:** on the production mix from the brief (447,117 rows a day, of which run_started + run_ended are 253,170), one row per run removes about 126,600 rows a day, or 28%.
- **Requests:** the benchmark can't show the app-boot saving of batching, because a test request doesn't boot the app. On production each saved POST is one full boot, which was about two thirds of the incoming gateway requests in the stress test.

## Case table

| Case | Test | Result |
|---|---|---|
| S-163.1 | `SendStatusBatchTest::test_S_163_1_a_batch_confirms_each_reply_with_its_own_sent_at_and_a_repeat_changes_nothing` | pass |
| S-163.1b | `SendStatusBatchTest::test_S_163_1b_a_refused_batch_confirms_nothing` (11 rows: 401 ×3, 422 ×6, 413 ×3) | pass |
| S-163.1c | `SendStatusBatchTest::test_S_163_1c_a_valid_batch_variant_is_accepted_and_confirmed` (3 rows) | pass |
| S-163.2 | `ChatConfigCacheTest::test_S_163_2_a_chat_on_a_cached_page_reads_no_bot_configuration_from_the_database` | pass (red before: 4 config reads, listed) |
| S-163.2b | `ChatConfigCacheTest::test_S_163_2b_a_save_on_any_path_is_used_by_the_next_message` (values API, page settings service, flow Publish, sequence edit) | pass |
| S-163.3 | `RunEventRowTest::test_S_163_3_a_run_writes_one_run_row_and_the_inbox_shows_the_same_two_lines` | pass (the expected lines were captured on the old code) |
| S-163.3b | `RunEventRowTest::test_S_163_3b_old_run_started_and_run_ended_rows_still_show` | pass |
| S-163.3c | `RunEventRowTest::test_S_163_3c_bot_health_and_results_are_the_same_with_old_and_new_run_rows` | pass |
| S-163.3d | `RunEventRowTest::test_S_163_3d_a_run_that_throws_still_writes_its_run_row_as_failed` | pass |
| S-163.4 | `RunEventRowTest::test_S_163_4_a_sequence_step_still_shows_its_lines` | pass |
| S-163.5 | `phpunit tests/Feature/Chatblast tests/Feature/Botcake tests/Feature/PageSettings tests/Feature/OrderEncode` | pass: see below |
| S-163.6 | local benchmark (above; the script is not committed) | done |

Suite: 839431d2 gave `Tests: 1725, Assertions: 10178, Skipped: 1`, 0 failures. This branch gives `Tests: 1751, Assertions: 10260, Skipped: 1`, 0 failures, with no new failures. The skip is the same one as before.

Red runs, one per slice:
- **Batch:** `test_S_163_1…` failed with "Expected response status code [200] but received 401" (the body had no top-level page).
- **Cache:** `test_S_163_2…` failed with "basa ng ayos sa isang chat (lahat: 17)", listing the 4 config queries.
- **Run row:** `test_S_163_3…` failed with "Failed asserting that two arrays are identical" (the old run_started/run_ended rows); `test_S_163_3d…` failed with RecordsNotFoundException (no run row was written).
- **Mutation checks:** removing the forget in `ChatbotFlowController::write()` fails the "flow publish" row. Removing the 64 KB guard for non-batch bodies fails the "single event over 64 KB with the batch header" row.

## Story changes

- Added S-163.1c (accepted batch variants) and widened S-163.1b with the reviewer's auth and limit cases.
- S-163.2: the test reads "≤ 8" as configuration reads, which are now 0, and also pins the run's total at ≤ 13 (17 before).
- S-163.4: the lines come from the kept `sequence` events (decision 4's "otherwise keep them").

## Existing tests changed (storage assertions only; the behaviour is the same)

- `ChatbotInboxActionsTest` S-074 E1: the stored types are now `[…, 'run']` in place of `run_started` … `run_ended`. Its timeline assertions (texts and order between bubbles, with AI and sheet time gaps) pass unchanged.
- `BotcakeAsyncSendTest` S-089.9 and S-089.11, and `EncodeMacroTest::lastRun()`: these read the end reason and path, or the "resumed after send" start, from the `run` row.
- `ChatbotTagsTest` S-100.4 simulated Publish with raw SQL. It now also clears the flow cache, as the real Publish does. Its sibling S-100.4b uses the real routes, unchanged.

## Review

`skeptic-reviewer`: adversarial on the batch endpoint, standard on the rest. No blockers.

**Majors fixed:**
- The query test was vacuous; it now asserts the run's total.
- Missing batch tests: the global-secret fallback, an unknown page, mixed number/string page ids, and the size and count limits.

**Minors:**
- **Fixed** (untrusted path): the 512 KB limit now applies to batches only.
- **Accepted with reasons in `TODO.md`:**
  - the Test-tab sequence name read;
  - `draft_graph` in the cache;
  - the millisecond cache race on a save;
  - the existing 2 s PageSettings memo;
  - three untested paths (a steps-only sequence edit, two runs in one second, the `finally` store in skip/resume).

## Deploy notes

- **No migration.** The `run` type fits the existing `chatbot_events.type` column, and old rows stay as they are. Pruning still deletes them after 30 days.
- **Cache:** `BotConfigCache` uses the default cache store. Check the server's `CACHE_STORE` (spec 088 moved it to Redis). On `database` a hit becomes a query on `cache`, which is still cheaper than the old flow row, but the saving is smaller. No cache clear is needed at deploy: the keys are new (`chatbot-config:v1:*`).
- **Order: this goes live before Forbeast's batching (spec 023).** Turn on `FORBEAST_SEND_STATUS_BATCH` only after this is live on Likha and Incepxion. A batch sent to an old Likha gets 401, because the body has no top-level `page_id`.
- **During the deploy:** a worker still on the old code writes run_started/run_ended rows; both kinds render.

## Conflicts

None between the spec and CLAUDE.local.md.

163: done — batched send_status accepted, flow/sequence config cached with forget on every write, one run row per flow run with identical Inbox lines; sequence events kept (reply rows can't carry them safely).
