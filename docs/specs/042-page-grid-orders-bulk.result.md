# Result 042: `/page-settings` lists the pages that sell, and tests or syncs them all at once

## What shipped

Commits on `feat/042-page-grid-orders-bulk`:

- `docs: brief for spec 042 page grid from orders and bulk test and sync`
- `feat: /page-settings rows are the pages with orders in the last 30 days, busiest first, then pages with settings only, greyed (spec 042)`
- `feat: Test lahat ng token tests each page with Botcake in grid order, one JSON request per page with one call and no retry, and updates each row's token status (spec 042)`
- `feat: Sync lahat queues an incremental sync of every page with Botcake in grid order, one page per job, waiting on a held lock instead of skipping, one run at a time, with progress in the poll (spec 042)`
- `fix: the per-page token test has its own rate-limit counter so testing every page leaves the other buttons usable, and a no-retry error no longer says 0 retry (spec 042)`
- `docs: accepted review findings for spec 042 in TODO`
- `docs: result for spec 042`

There is no migration and storage is unchanged. One new config key: `services.botcake.grid_order_days` (env `BOTCAKE_GRID_ORDER_DAYS`, default 30), added to `config/services.php` and `.env.example`.

### Rows (`PageSettingsGrid::build`)

- **Which pages are rows:**
  - Every page with an active setting (as in 041).
  - Every page with at least one `macro_output` order where `ts_date >= today (Manila) − N days`. This is the same boundary as the matcher's 30 days: an order exactly 30 days old counts, one 31 days old does not.
  - Pages are merged by `PageSettings::pageKey()` (trim, collapse inner spaces, lower case).
  - `PAGE` values that are NULL, empty or only spaces are ignored.
- **Name shown:** the setting's spelling when the page has settings. Otherwise the most common spelling in `macro_output.PAGE`, with spaces collapsed.
- **A page with orders but no settings:** a row with empty cells, the same as "+ Page". Nothing is stored until a value is typed.
- **Order of rows:** most orders first, ties by name (natural, case-insensitive). Then the pages with 0 orders in the window, by name.
  - Rows with 0 orders are greyed: grey text and a grey page cell. They can still be edited.
  - No page is ever dropped: every page with a setting stays.
- **New read-only column "Orders (30 araw)":** placed before Customers. Its label follows N (`order_days` in the grid JSON). The browser sorts the same way.
- **Order counts:**
  - One query: `SELECT PAGE, COUNT(*) FROM macro_output WHERE ts_date >= ? AND PAGE IS NOT NULL GROUP BY PAGE`, served by the `(ts_date, PAGE)` index.
  - Spellings are merged in PHP.
  - The result is cached for 10 minutes (`page-settings-grid:orders:<N>`).
  - `changes()` (the 5 s poll) never runs this query. The poll keeps each row's count from the page load.

### Test lahat ng token

- **The button** sits above the grid. It goes through every row that has both a Botcake ID and a token, in grid order, one request at a time. Each request is `POST /page-settings/test-token/one` with `{page_name}` and is CEO only.
- **The endpoint** makes one `custom_fields` call through `BotcakeClient::once()`: no retry, 20 s timeout. One request therefore takes at most about 20 s plus Laravel's own time.
  - It records `botcake.token_status` exactly like the row button: `ok` or `invalid`, always written.
  - A Botcake error (5xx, connection) is not recorded, as with the row button.
  - It returns `{status: ok|invalid|error|skipped, cell, error?}`. `cell` is the token-status cell (`ok 2026-…`), never the token.
  - A page without an ID or a token returns `skipped` without calling Botcake.
- **In the browser:**
  - Each row's Token status cell shows "Testing…", then the new value with an ok / invalid mark, or "!" with the error.
  - The status line reads "Test token: 12 / 45 · <page>".
  - At the end it shows the summary: ok, invalid, error, and the number of pages skipped for having no Botcake ID or token.
- **Rate limit:** 120 a minute, on its own counter (`throttle:120,1,page-settings-test-one`). Testing 45 pages does not use up the shared per-user counter that Sync now (10), Sync lahat (10), Test token (20) and page-check (30) use.

### Sync lahat

- **The button** sends `POST /page-settings/sync-all` (CEO only, 10 a minute) after a confirm. The reply is `{status: started|running, sync_all: progress}`.
- **Pages:** every page with an active `botcake.page_id` and `botcake.token` whose `botcake.token_status` does not start with `invalid`. They run in grid order, so the busiest page comes first. The list is fixed when the run starts.
- **State:** `App\Services\BotcakeSyncAll` keeps the run in the cache under key `botcake-sync-all`: run id, pages, done / ok / failed, current page, waiting, started and finished.
  - Only one run at a time. `start()` checks and sets under a 10 s cache lock (`botcake-sync-all:start`).
  - A press while a run is unfinished starts nothing and answers `running`, with the progress.
  - The state's TTL is 2 h 10 min and is refreshed at every step, so a broken chain expires on its own. A finished run stays readable for a day.
- **Jobs:** `App\Jobs\SyncAllBotcakeCustomersJob(runId, index)`, one job per page, on the default queue.
  - The job syncs its page through the existing `BotcakeCustomerSync::withLock(syncPage($page))`: incremental, the 45-day window and the same stop rules as every sync.
  - Only after that does it queue the job for the next page. Pages therefore run strictly one at a time.
  - A page that fails (Botcake error, invalid token, or any exception) is counted as failed, and the next page still runs.
  - When the job is killed (timeout), `failed()` counts the page as failed and queues the next. A guard (`done === index`) keeps a page from being counted twice.
  - A job from an older or finished run does nothing.
- **Lock:** when another sync holds the Botcake lock (a row's Sync now, the command or the schedule), the page is not skipped. The job marks the run "waiting" and queues itself again for the same page 30 s later, repeating until the lock is free.
- **Progress:** `sync_all` is in the grid JSON and in every poll, read from the cache with no query. The page shows "Sync lahat: 7 / 40, ngayon: <page>", plus "(naghihintay matapos ang ibang sync)" while waiting, and at the end "Sync lahat tapos (time): N ok, M failed sa T page." The button stays disabled while a run is going. Each row's Huling sync updates through the existing poll.
- **Time limits:**
  - The job has `$timeout = 1800` and `$tries = 1`. That is below the database queue's `retry_after` of 1900 s (`config/queue.php`), so a running page is never handed to a second worker.
  - One incremental page is capped at 300 batches, about 150 s at 2 requests a second, plus at most 3 retries per request (2 + 4 + 8 s waits, 30 s HTTP timeout each).
  - **What the setup must provide:** a worker on the `default` queue whose own `--timeout` does not cut the job earlier. A job's `$timeout` overrides the worker flag on the database driver, and pcntl is needed for the timeout to fire at all. The cache store must also be shared by the web and the worker, as the existing sync lock already needs.
- **Unchanged:** the schedule is still gated by `BOTCAKE_SYNC_ENABLED`. Both buttons work regardless.

### Query counts (auth and session queries not counted)

| Request | Queries |
|---|---|
| Grid page or grid JSON, order counts not cached | 5 (settings, user names, customers grouped, last run per page, order counts) |
| Grid, order counts cached (within 10 min) | 4 |
| Poll with a run changed | 5 (no order count) |
| Poll with nothing changed | 2 |
| Test lahat ng token, per page | a few (settings reads, token_status write), one Botcake call |

The test measures 5 pages and 60 pages: 120 rows (60 with settings, 60 with only orders), each with customers, runs and orders. The counts are the same, `[5, 5, 5]`, with the order count not cached.

## Tests

| Case | Test | Result |
|---|---|---|
| Orders-only page appears with empty cells; settings-only page greyed at the bottom (0 orders); sorted by count, ties by name; case and space variants merge; exactly 30 days counts, 31 does not; blank, space-only and NULL PAGE ignored; label "Orders (30 araw)" | `PageGridOrdersTest::test_pages_with_orders_come_first_by_count_then_pages_with_settings_only_by_name` | pass |
| The window follows `grid_order_days` | `PageGridOrdersTest::test_the_window_follows_the_config` | pass |
| One order-count query per grid build, none from the cache within 10 min, none in the poll, fresh after 11 min | `PageGridOrdersTest::test_the_order_counts_are_one_query_cached_for_10_minutes_and_never_run_by_the_poll` | pass |
| Query count flat from 5 to 60 pages (updated from 50; now with orders-only pages) | `PageSettingsGridTest::test_the_query_count_stays_flat_from_5_to_60_pages` | pass |
| Per-page test: ok / success:false / 401 recorded as `token_status` like the row button; 500 → `error`, nothing recorded, error text without a retry count; one call, no sleep; no token in the JSON (4 rows) | `BulkTokenTestTest::test_one_page_is_tested_with_one_call_and_recorded_like_the_row_button` | pass |
| No ID or no token → `skipped`, no call | `BulkTokenTestTest::test_a_page_without_id_or_token_is_skipped_without_a_call` | pass |
| 45 per-page tests leave Sync lahat usable (own rate-limit counter) | `BulkTokenTestTest::test_testing_45_pages_does_not_use_up_the_limit_of_the_other_buttons` | pass |
| CEO only: 404, no call, nothing recorded | `BulkTokenTestTest::test_only_the_ceo_reaches_it` | pass |
| Sync lahat: pages one at a time in grid order; a page failing with 500 does not stop the next; pages without a token or with an invalid token left out; final progress 3 / 3, 2 ok, 1 failed | `SyncAllTest::test_pages_run_one_at_a_time_in_grid_order_and_a_failing_page_does_not_stop_the_next` | pass |
| A held lock makes the page wait (same page queued again with a delay, no call), then it runs; the poll shows current, waiting and done; a killed job (`failed()`) counts as failed and the next page is queued | `SyncAllTest::test_a_held_lock_makes_the_page_wait_then_run_and_the_poll_shows_the_progress` | pass |
| A second press during a run starts nothing (`running`, one job queued) | `SyncAllTest::test_a_second_press_during_a_run_starts_nothing` | pass |
| CEO only; no token in the reply | `SyncAllTest::test_only_the_ceo_reaches_it_and_no_token_is_sent` | pass |

Red runs:
- **Rows:** the three `PageGridOrdersTest` tests failed on "Failed asserting that two arrays are identical" (twice) and "Failed asserting that 0 is identical to 1" (no order query).
- **Bulk test:** "Expected response status code [200] but received 404" (no route).
- **Sync lahat:** "received 404", and "The expected [SyncAllBotcakeCustomersJob] job was pushed 0 times instead of 1".
- **Fix loop:** the limit test failed on "Failed asserting that 429 is identical to 200". The error text failed on "two strings are identical" ("… pagkatapos ng 0 retry").

Updated existing tests:
- **`PageSettingsGridTest::test_a_new_page_and_a_new_column_exist_once_a_value_is_entered`:** expects `['Page Uno', 'Page Wala']`. Page Uno has an order, so it is now a row.
- **The query-count test:** now 60 pages with orders. It clears only the order-count cache key before each measured request.

Full suite (`vendor/bin/phpunit`), before the fix commit: `Tests: 1313, Assertions: 16701, Errors: 1, Failures: 1, PHPUnit Deprecations: 5, Skipped: 3`. The only reds are the known `ExampleTest::test_the_application_returns_a_successful_response` and, in this fresh worktree, `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`. After the fix commit: `Tests: 1314, Assertions: 16748, Errors: 1, Failures: 1, PHPUnit Deprecations: 5, Skipped: 3`, with the same two known reds and nothing else.

Other checks:
- `php -l` passes on every changed PHP file.
- `node --check` passes on the page's script.
- `artisan view:cache` compiles.

## Review

- **One standard-depth review:** no blockers, no majors.
- **Fixed:**
  - The shared per-user rate-limit counter: testing every page used to leave Sync now, Sync lahat and Test token answering 429 for a minute.
  - The "0 retry" error text.
- **Accepted in `TODO.md` under 042, with reasons:**
  - On MySQL, case-only spelling variants group by collation.
  - A timed-out page leaves the lock held until its 2 h TTL.
  - A failed dispatch stops the chain until the state expires.
  - An array `page_name` gives a 500.
  - Missing tests for a simultaneous double press and for zero pages.
  - The schedule's "Skipped" when Sync lahat holds the lock.
  - The existing per-row job's 3600 s timeout above `retry_after`.

## Browser check

Not done. The app needs the server database (`.env`, not read), and this session has no running app. The JavaScript is checked by syntax and by reading only. The steps to check are in `TODO.md` under 042.

## After release (server)

1. Nothing to migrate.
2. Optional: set `BOTCAKE_GRID_ORDER_DAYS` (default 30), then `php artisan config:cache` if the config is cached.
3. Restart the queue workers (`php artisan queue:restart`) so they load the new job class.
4. `npm run build` is not needed: the page uses the Tailwind CDN.

## What the brief got wrong, and decisions it left open

- **"No single HTTP request may run longer than about 30 seconds":** the existing client retries up to 3 times with a 30 s timeout per try, which is about 2 minutes. The bulk endpoint therefore uses a new `BotcakeClient::once()` (no retry, 20 s). The row's Test token button is unchanged and still retries.
- **Window boundary:** "within the last N days" is taken as `ts_date >= today − N` (Manila), the same as the matcher's 30 days. That is N + 1 calendar days including today.
- **"Most common spelling":** spellings that differ in inner spaces are collapsed before counting, so the shown name never has double spaces.
- **Page list fixed at the start:** Sync lahat takes its list and order when it starts. A token marked invalid during the run still gets its turn, and that page just fails fast.
- **Waiting on the lock** repeats every 30 s with no limit of its own. A lock left by a killed run frees itself within 2 h (041 amendment 1), and the run's state is refreshed at every wait.
- **The poll does not refresh the Orders column.** Counts come with the page load, cached 10 minutes, because the brief keeps the count query out of `changes()`.
- **Conflict with `CLAUDE.local.md`:** the kit asks for a plan and a "go" before code. The brief came with every decision made and asked for commits per part within 75 minutes, so I worked straight from it and send the result instead.

## Story changes

None. The brief brought no `qa/stories.md` stories. The cases above come from its Tests list.

## Amendment 1

### Amendment changes (kept in the handoff folder, not committed)

1. Matching also fills the ad id of orders that already have a PSID but no ad id, from the page's API customer with that PSID. It is counted in the match result (`ad_filled`), and the sync and import logs show the count.

Commit: `feat: matching fills the ad id of recent orders that already have a PSID but no ad id from the page's API customer, once, in one update per page, and the sync and import logs count it (spec 042 amendment 1)`

### What changed (`BotcakeCustomerMatcher::matchPage`)

- **One UPDATE per page.** It runs after the PSID matching, with no query per order:
  - **Orders it touches:** the page's orders (`LOWER(TRIM(PAGE))` = page key) with `ts_date` within the matcher's `DAYS` (30). `botcake_psid` must be set, and `botcake_ad_id` must be NULL or `''`.
  - **What it writes:** `botcake_ad_id` = the `ad_id` of a `botcake_psids` row with `source = 'api'`, the same page key and the same PSID, whose `ad_id` is non-empty. If there are several, the newest row (highest `id`) wins.
  - **Customer status:** customers marked inactive count too. Their ad still brought the order.
  - **Orders left as they are:** orders whose PSID has no such row (`EXISTS` guard). This includes a customer of another page.
- **Never overwritten.** The "empty ad id" condition is checked in the UPDATE itself. The PSID is never written.
- **Counted.** `matchPage()` and `matchPagesWithTokens()` return `ad_filled`.
  - The sync's run message now ends with `…, N ad id`.
  - The hourly sheet import's "Botcake customer match" log line is also written when only ad ids were filled.
- **Every caller gets it:** the sync after each page, the per-row Sync now, Sync lahat, and the hourly sheet import. Old sheet PSIDs therefore get their ad id on the next run, as long as their customer row has one.

### Tests (amendment 1)

| Case | Test | Result |
|---|---|---|
| A sheet PSID without an ad id gets the ad id (NULL and `''`); an existing ad id is kept; another page's customer is not used; a customer without an ad id and a PSID with no API row leave the order as it is; the PSID never changes; an order 31 days old is untouched; `ad_filled` = 2, then 0 on a second run; `matchPagesWithTokens` returns `ad_filled` | `BotcakeCustomerMatchTest::test_an_order_with_a_psid_but_no_ad_id_gets_the_ad_id_of_its_page_customer` | pass |
| The sync's run message shows the count (`…, 1 ad id`) | `BotcakeCustomerSyncTest::test_incremental_stops_at_the_first_batch_whose_psids_are_all_known` (extended) | pass |

Red runs:
- The matcher test failed on "Failed asserting that two arrays are identical": the ad id was still NULL.
- The sync message failed on "… 0 ambiguous, 0 wala' ends with ', 1 ad id'".
- That sync test was then reworked. Its first fixture gave the ad id to a customer that the sync re-reads, and the payload has no ad id. It now uses a stored customer outside the read batches.

Full suite after the amendment: `Tests: 1315, Assertions: 16759, Errors: 1, Failures: 1, PHPUnit Deprecations: 5, Skipped: 3`. The only reds are the known `ExampleTest` and, in this fresh worktree, `ImportStartTest`. `php -l` passes on the three changed PHP files.

### Notes

- **Each sync rewrites the ad id from Botcake's answer.** When the sync re-reads a customer, it stores that answer's `ad_id`, and the answer can be empty. The fill then uses whatever ad id the row holds at matching time. This was already the case before the amendment; it is noted because the fill depends on it.
- **The query scans the page's recent orders,** like the existing matching select: `LOWER(TRIM(PAGE))` cannot use the `(ts_date, PAGE)` index beyond `ts_date`. The subquery uses the existing `botcake_psids.psid` index.
- **Review:** no separate review for this amendment. It is one bounded UPDATE on a trusted path, with tests on every case the amendment lists.

## Amendment 2

### Amendment changes (kept in the handoff folder, not committed)

1. The ad-id fill (amendment 1) selects the order ids first and updates them by id, in chunks of up to 1000, instead of one UPDATE over the page's 30 days.
2. A Sync lahat job whose run state is gone from the cache logs a warning.
3. Test lahat ng token waits 500 ms between pages, and retries a page once after a rate limit.
4. Sync lahat, Sync now and Test token each get their own rate-limit counter.

Commits:
- `fix: the ad id fill selects the order ids first and updates them by id in chunks of 1000, so it no longer locks every recent order of the page (spec 042 amendment 2)`
- `fix: a Sync lahat job whose run state is gone from the cache logs a warning with the run id and index instead of stopping silently (spec 042 amendment 2)`
- `fix: Sync lahat, Sync now and Test token each have their own rate-limit counter, so editing cells no longer uses up their budget (spec 042 amendment 2)`
- `fix: Test lahat ng token waits 500 ms between pages and, on a rate limit from Likha or Botcake, waits (Retry-After or 5 s) and retries the same page once before marking it error (spec 042 amendment 2)`

### What changed

- **Ad-id fill (`BotcakeCustomerMatcher::matchPage`).** This replaces the "one UPDATE per page" of amendment 1.
  - **Read first, without locks:** a plain SELECT of `id`, chunked by id. It uses the same filters (page key, `ts_date` within 30 days, PSID set, ad id NULL or `''`) plus `EXISTS` for an API customer row of the same page and PSID that has an ad id.
  - **Then update by id:** for each chunk of up to 1000 ids, `UPDATE macro_output SET botcake_ad_id = (newest such row's ad_id) WHERE id IN (…) AND (botcake_ad_id IS NULL OR botcake_ad_id = '') AND EXISTS (…)`. The empty-ad-id check stays inside the UPDATE.
  - **Unchanged:** the results and the `ad_filled` count. The PSID is never written.
- **Missing run state (`SyncAllBotcakeCustomersJob`).** When the cache has no `botcake-sync-all` state at all, the job logs `Sync lahat: wala na ang state ng run sa cache; tumigil ang run` with `{run_id, index}`, then stops. A job from another run or from a finished run still stops silently, because a state exists then.
- **Test lahat ng token (browser).**
  - It waits 500 ms between pages.
  - On HTTP 429 from Likha, it waits for the `Retry-After` header, or 5 s if there is none. On a `reason: "rate_limited"` answer from the endpoint (Botcake's 429), it waits 5 s.
  - It then retries the same page once. If that also fails, the page is counted as error.
  - The status line shows the wait. The endpoint's error JSON now carries `reason` (`rate_limited` or `error`).
- **Rate limits (`routes/web.php`).** Each now has its own counter, through the throttle's third argument (a key prefix):
  - `/sync-all` (10 a minute, `page-settings-sync-all`)
  - `/sync` (10, `page-settings-sync`)
  - `/test-token` (20, `page-settings-test-token`)

  Cell edits (120) and page-check (30) keep sharing the per-user counter with each other only.

### Tests (amendment 2)

| Case | Test | Result |
|---|---|---|
| Every ad-id UPDATE is by `id IN (…)`; another page's order with the same PSID is not written; all amendment 1 cases unchanged | `BotcakeCustomerMatchTest::test_an_order_with_a_psid_but_no_ad_id_gets_the_ad_id_of_its_page_customer` (extended) | pass |
| A job whose run state is gone logs one warning with run id and index; no Botcake call and no next job | `SyncAllTest::test_a_job_whose_run_state_is_gone_logs_a_warning_and_stops` | pass |
| 25 cell edits, then Sync lahat, Sync now and Test token all go through (no 429) | `SyncAllTest::test_editing_cells_does_not_use_up_the_limit_of_the_sync_and_test_buttons` | pass |
| Botcake 429 → `error` with `reason: rate_limited`; 500 → `reason: error`; one call, no sleep | `BulkTokenTestTest::test_one_page_is_tested_with_one_call_and_recorded_like_the_row_button` (new 429 row) | pass |

Red runs:
- **Ad-id fill:** the UPDATE without `id IN` failed the regex. The other-page order passed before and after, because the old UPDATE was already filtered by page.
- **Warning:** the test failed on "Method warning(…) should be called at least 1 times but called 0 times".
- **Rate limits:** Sync lahat after 25 edits failed on "Expected response status code [200] but received 429".
- **Reason:** the 429 and 500 rows failed on "Failed asserting that null is identical to 'rate_limited'" and "… to 'error'".

The browser-side wait and retry has no automated test (no JS test setup). `node --check` passes and `artisan view:cache` compiles.

Full suite after amendment 2: `Tests: 1318, Assertions: 16805, Errors: 1, Failures: 1, PHPUnit Deprecations: 5, Skipped: 3`. The only reds are the known `ExampleTest` and, in this fresh worktree, `ImportStartTest`.

### Notes

- **Named limiter.** The amendment says "named throttle". I used the throttle's prefix argument (`throttle:10,1,page-settings-sync-all`), which gives each route its own counter without a `RateLimiter::for` in a service provider. It is the same mechanism as `/test-token/one`.
- **The SELECT still reads the page's recent orders with `LOWER(TRIM(PAGE))`,** as the PSID matching select does. It is a plain read and takes no row locks under InnoDB's consistent read. Only the chosen ids are locked, and only for their chunk's UPDATE.
- **The `TODO.md` item on the shared throttle counter is now resolved** for these routes. Cell edits and page-check still share one counter, which is fine at 120 and 30 a minute.
