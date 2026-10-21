# Result 098: page settings from the cache, one Botcake rate per page, and the real reason of a failed send

Branch `fix/098-load-fixes`, cut from `main`. Not pushed.

## Commits

| Commit | What |
|---|---|
| `efb68489` | docs: the spec, verbatim |
| `bd828e2a` | fix: cache page settings per page with forget on every write |
| `f86e2a0e` | fix: one atomic Botcake rate per page for the pool and every blocking call |
| `3c049f15` | fix: real Botcake failure reasons, raw failed body and `chatbot:failed-reasons` |
| `d8e15db8` | fix: the send pool books its slot ahead like blocking sends, so a sequence backlog cannot starve it (review fix) |
| (this commit) | docs: result, stories, TODO |

## What changed

**Page settings cache** (`app/Services/PageSettings.php`, `app/Models/PageSetting.php`, `app/Providers/AppServiceProvider.php`)
- `get()` reads a per-page map of all active settings: `provider.key => [value as stored, is_secret]`. It is kept in the cache store (Redis on the server) for 10 minutes under `page-settings:<sha1 of the page key>`.
- Secrets stay encrypted in the cache. The map holds the raw column, and the token is decrypted only in memory, on each read. Nothing new is logged.
- A short memo in the `PageSettings` instance (2 seconds) keeps one run from reading the cache over and over. `PageSettings` is now bound `scoped`, so the memo ends with each request or queue job.
- The cache is forgotten on every write, both right away and after the commit:
  - through the `PageSetting` model's `saved` and `deleted` events, which cover `set()`, `deactivate()`, the cell, the bulk save and paste (`/chatbot/cells`), the setup check, and any other model write;
  - explicitly after the stress-page cleanup, which deletes with the query builder.
- New `PageSettings::all($page, $provider)`. `ChatbotPage::botfields()` uses it, so the botfields come from the same cached map.

**One rate per page** (`app/Services/BotcakeClient.php`, `app/Services/Chatbot/BotcakeSendPool.php`)
- `BotcakeClient::slot($rateKey, $now, $wait)` books the next free time for a page token. It runs under `Cache::lock` on the same `botcake-rate:<sha1 token>` key as before, so no two processes get the same time.
- Every `throttle()` now books a slot before it waits. Clients without `shared()` (customer sync, chat block) use `sha1(token)`, so every Botcake call for a page shares the one rate: sends, customer info, chatblast, setup and token checks.
- The rate is the same `services.botcake.rate_per_page` as in 089.
- The send pool books in the same chain. When its booked time arrives it sends; when the call goes out later than the slot, `sentAt()` moves the slot forward. A booking more than 20 ms past is dropped and booked again.
- **Sequence step sends stay blocking.** `ChatbotSequences` → `FlowEngine` → `BotcakeOutbox::send` now takes its slot from this shared limiter; it doesn't go through the pool.

**Real failure reasons** (`BotcakeClient::messageText`, `app/Services/Chatbot/BotcakeOutbox.php`, `ChatbotInboxController`, `ChatbotTabsController`)
- `messageText()` looks for the reason in this order:
  1. `message` when it is a non-empty string;
  2. `error.message`, `error_user_msg`, `error.error_user_msg`, `message.message`, `error_description`, `title`, `detail`, in the body and then inside `message`;
  3. the first string not under `fbtrace_id`, `trace_id`, `request_id` or `x_fb_trace_id`;
  4. compact JSON.

  A trace ID alone gives `{"fbtrace_id":"…"}`, never the bare ID. The chat block's error text and the customer-info check use the same helper.
- A failed send keeps the raw Botcake answer: compact JSON, at most 1,000 characters, with the token replaced by `[token]`.
  - It is stored in a new nullable column, **`chatbot_replies.raw`** (migration `2026_10_30_098000_add_raw_to_chatbot_replies`).
  - Writes are guarded by `ChatbotReply::hasRaw()`, so nothing breaks before the migration runs.
  - It is stored on all three paths: blocking sends (sequence steps), pool sends (also in the ticket's `result` JSON), and Inbox manual sends.
  - `chatbot_replies.error` stays short, as before.
  - `/chatbot/replies/{id}` returns it as `raw`.
- The Inbox manual send now builds its result with `BotcakeOutbox::outcome()` instead of a copy of the same logic, so it gets the raw body too.

**Look back:** `php artisan chatbot:failed-reasons --since="-7 days"` (read-only)
- It groups failed `chatbot_replies` since `--since` by the real reason, with count, last time (Manila) and the error as it was shown.
- A "Botcake said …" failure with no stored answer reads **"unknown (before 098)"**.
- Failures that never had an answer (no token, switched off, Botcake did not answer) read as their error.

## Case table

| Case | Test | Result |
|---|---|---|
| S-098.1 | `PageSettingsCacheTest::test_S_098_1_a_miss_reads_mysql_once_then_every_read_is_a_hit_from_the_memo_or_the_cache` | Pass |
| S-098.2 | `PageSettingsCacheTest::test_S_098_2_the_cache_holds_the_secret_encrypted_never_the_plain_token` | Pass |
| S-098.3 | `PageSettingsCacheTest::test_S_098_3_every_write_forgets_the_cached_page_so_the_edit_applies_at_once` (cell, bulk save and paste, deactivate, setup check, model write) | Pass |
| S-098.4 | `BotcakeAsyncSendTest::test_S_098_4_the_pool_and_the_blocking_sends_share_one_rate_per_page_even_when_they_arrive_at_once` | Pass |
| S-098.5 | `BotcakeFailedReasonsTest::test_S_098_5_message_text_is_the_error_text_never_the_trace_id` (9 data sets, each as array and as object) | Pass |
| S-098.6 | `BotcakeFailedReasonsTest::test_S_098_6_a_failed_send_keeps_the_raw_answer_capped_and_without_the_token` | Pass |
| S-098.7 | `BotcakeFailedReasonsTest::test_S_098_7_failed_reasons_groups_recent_failures_by_the_real_reason` | Pass |
| S-098.8 | `BotcakeAsyncSendTest::test_S_098_8_a_steady_stream_of_blocking_sends_on_a_page_does_not_starve_the_pool` | Pass |

Red runs before each fix:
- S-098.1: `one query for the whole page — Failed asserting that 3 is identical to 1.`
- S-098.2: `Call to undefined method App\Services\PageSettings::cacheKey()` (the cache didn't exist yet).
- S-098.4: `at most 2 per second on one page, across paths: [1000,1000.5,1000.5,1001]`.
- S-098.5, S-098.6, S-098.7: 8 data sets failed (trace ID or `''` instead of the text, including every object body); the raw assertion failed; the command didn't exist.
- S-098.8: `the pool sent the chat reply — Failed asserting that actual size 0 matches expected size 1.`

The S-098.3 data sets passed before the cache existed, because nothing was cached then. They are guards: each fails if its write path stops forgetting the cache.

## Verification

- `php -l` on every changed PHP file: no syntax errors.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: **OK, 777 tests, 5244 assertions**, 1 skipped (S-088.4's Redis data set, no Redis on Windows), 49 PHPUnit deprecations (47 existed before).
- Full suite (`vendor/bin/phpunit`): **2132 tests, 1 error, 1 failure**, the same two as `main`:
  - `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`
  - `ExampleTest::test_the_application_returns_a_successful_response`

  `main` in a clean worktree: 2112 tests with the same two. **No new failures.**

## Local load check (my machine, not the server)

**Setup**
- In the Ubuntu WSL: PHP 8.5, a throwaway PostgreSQL 18 instance and a Redis 8 (own ports, run as my user, stopped afterwards).
- Horizon (chatbot queue) and `botcake:send-pool`, on a copy of the branch. The config came only from process environment variables (no env file).
- Migrations ran one file at a time. 7 old MySQL-only migrations (J&T, checklist, ads) fail on PostgreSQL; every chatbot table migrated.
- 20 mock pages via `botcake:stress-page` (mock Botcake at 400 ms, mock AI at 500 ms).
- The flow: intro send, then an AI step that sends its answer (2 pool sends per chat).

**Load:** 1,200 chats at 20/s for 60 s, while separate PHP processes ran for 70 s on the same pages:
- **sequence steps**, run exactly as `ChatbotSequences` runs one: a `FlowRun` with `startedBy`, through `FlowEngine` and `BotcakeOutbox`, so the send blocks;
- **Inbox sends**: the controller's own `shared()->once()->sendContent()` call, plus `customerInfo()` on every third call.

| Code | Sequence / Inbox processes | Max calls/s (one page) | misrouted | Failed sends | Chat to first / last reply (avg, p95) |
|---|---|---|---|---|---|
| `main` | 4 / 4 | **5** | 0 | 0 | 9.4 s, 18 s / 19.7 s, 33 s |
| branch before the review fix | 4 / 4 | **2** | 0 | 0 | 10.6 s, 19 s / 22.3 s, 33 s |
| branch, final | 8 / 4 | **2** | 0 | 0 | 11.5 s, 20 s / 24.1 s, 35 s |

- `main` shows the live finding (5); the branch holds 2 in both runs.
- All 1,200 mock AI replies reached their own customer.
- Every run drained within about 25 s.
- The longer reply times on the branch are the rate doing its job: 20 pages × 2/s are shared between chats, sequence steps and Inbox sends. On `main` the extra calls burst past the rate.

**Query count, one chat** (query log on a warm second chat of a page, intro send + set field):

| Code | All queries | On `page_settings` |
|---|---|---|
| `main` | 16 | 5 |
| branch | 12 | 1 |

The one left is `ChatbotFields`' distinct-keys read, not a per-page `get()`. It is in TODO.

## Review

`skeptic-reviewer`, standard depth (medium risk).
- **Major, fixed in `d8e15db8`:** a steady stream of blocking sends booked ahead, so the pool (which took a slot only when it was free right then) could starve on that page. S-098.8 pins the fix; the re-check confirmed it.
- **Minor, fixed:** an empty-string `message` hid `error.message` (new data set in S-098.5).
- Accepted minors, with reasons in `TODO.md` (098 section): the 20 ms rebook rule under slow ticks, no test for the stale-booking drop, `hasRaw()` checked once per process, the 2 s memo in other processes and the miss stampede, raw-SQL writes skipping the forget, a spent slot when a ticket doesn't send.

## Deploy steps (for the reviewer; nothing here was run on a server)

1. Deploy as usual, then `php artisan migrate --force`. Its only migration adds the nullable `chatbot_replies.raw`.
2. Run `php artisan horizon:terminate` and `sudo supervisorctl restart likha-botcake-send likha-chatbot-ai` **after** the migration, so every worker sees the new column and the new code.
3. To answer "anong pumalya": `php artisan chatbot:failed-reasons --since="-3 days"`. Failures before this deploy read "unknown (before 098)"; new ones show the real reason.
4. With the mock pages: `botcake:stress-page --stats --all` should read "Max calls/s (one page)" = 2.

## Story changes

- Added S-098 (8 cases) to `qa/stories.md`. The spec brought no stories. Its "Done when" tests became S-098.1–7.
- S-098.8 was added after the review, for the starvation case.

## Conflicts and deviations

- **The raw body is stored in a new column, not in the reply's `content` JSON.** `content` is the message list that the Inbox, Replies and the AI history read item by item, so an extra key would show as a broken message. The blocking sequence path has no send ticket either. The pool still keeps the raw body in the ticket's `result` as well.
- The Inbox and Replies screens don't show the raw body yet. The Replies detail JSON returns it; showing it is in `TODO.md`.
- Process: the brief said to start now, so the plan wasn't sent for a "go" first. `story-writer` wasn't run; the stories come from the spec's cases. No browser check was run (no screen changed). Nothing was pushed.
- Conflicts between the spec and CLAUDE.local.md: none.
