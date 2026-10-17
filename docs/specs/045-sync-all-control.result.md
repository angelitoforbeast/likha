# Result 045: Sync lahat that knows what each page needs, can pause and resume, and shows what it did

## What shipped

Commits on `feat/045-sync-all-control` (cut from `develop` at 64f1867):

- `docs: brief for spec 045 Sync lahat control`
- `feat: the grid shows each page's next sync (window, continue from batch N, incremental, skipped as fresh, no ID or token, token invalid) with a one-line plan of Sync lahat, and Huling sync shows the run's counts (spec 045)`
- `feat: Sync lahat keeps its run in the database with Pause, Resume and Stop, skips pages that only need an incremental and synced in the last 6 hours, and adopts a run left in the cache by the old code (spec 045)`
- `feat: the page shows the Sync lahat run panel with Pause, Resume and Stop, its totals and the last 10 runs, updated by the poll (spec 045)`
- `fix: an adopted old Sync lahat run is closed when the old code finishes it, adoption runs under a lock so a poll and a job never make two rows, and a job that meets a Resume while releasing its page keeps it (spec 045)`
- `docs: accepted review findings for spec 045 in TODO`
- `docs: result for spec 045`

### The plan and freshness rules (`BotcakeSyncAll::next`)

For every grid row, in this order:

1. No active `botcake.page_id` and `botcake.token`: **`walang ID/token`**.
2. `botcake.token_status` starts with `invalid`: **`token invalid`**.
3. Otherwise, `BotcakeCustomerSync::plan()` from spec 044 decides, now computed for all pages at once by `plans()`: one query with `ROW_NUMBER() OVER (PARTITION BY LOWER(TRIM(page_name)) ORDER BY id DESC)`, keeping the two newest deciding runs per page and merging spellings with `pageKey()` in PHP.
   - A window read from batch 1: **`window (45 araw, buo)`**. The 45 follows `sync_max_age_days`.
   - A window read from batch N: **`tuloy mula batch N`**.
   - Incremental: **`incremental`**, unless the page's last successful (`done`) api run ended less than `services.botcake.sync_all_fresh_hours` ago. Then it shows **`laktaw (sariwa, 2 oras na)`**, or `35 min na` when under an hour.
     - The hours come from `BOTCAKE_SYNC_ALL_FRESH_HOURS`, default 6, added to `config/services.php` and `.env.example`.
     - "Ended" is the run's `updated_at`, read for all pages by one grouped query (`lastDoneAt()`).

Where it shows:
- **On the grid:** a new read-only column "Susunod na sync".
- **Above the grid:** a one-line plan, for example `1 window, 2 tuloy, 1 incremental, 1 laktaw (sariwa), 1 walang ID/token, 1 token invalid`. Kinds with 0 are left out.
- **After a sync:** the poll refreshes the column and the line for the pages that synced.

What uses the rule:
- **Sync lahat** takes the pages in grid order whose kind is `window`, `continue` or `incremental`. It counts the `fresh` ones as skipped, and leaves out `missing` and `invalid`.
- **Window reads and continues are never skipped.**
- **The row's Sync now** is unchanged and ignores the freshness rule.

### Where the run state lives

- **The run row:** each Sync lahat run is one row in `botcake_psid_import_runs` with `source = sync_all`. This reuses the existing table: every existing reader filters on `source` (`api`, `sheet`) or looks a run up by id, so the new rows show up nowhere else.
- **Reused columns:** the totals use `customers_new`, `customers_updated`, `matched_by_name`, `matched_by_phone` and `batches_read`, plus the new `ad_filled`.
- **Migration `2026_10_18_200000_add_sync_all_columns_to_botcake_psid_import_runs`** adds nullable columns, each guarded with `hasColumn`, on that small table only:
  - **`ad_filled`:** also written by every api run now, so "Huling sync" can show it.
  - **`run_key`** (indexed): the id the jobs carry.
  - **`sync_state`** (JSON): page list, ok, failed, skipped, waiting.
  - **`page_index`:** the page that is next or running.
  - **`current_page`:** set while a job owns that page.
  - **`started_by`** and **`finished_at`**.
- **Rollback:** `down()` drops each column only if it exists.
- **No cache:** nothing about the run is in the cache any more, so a cache clear or a deploy cannot stop it or lose it.

### Pause, Resume, Stop

Routes: `POST /page-settings/sync-all/{pause|resume|stop}`, throttled with their own counter. They use the same access check as the rest of `/page-settings`: the CEO, or a role checked for `/page-settings` in nav settings (NavAccess). Everyone else gets 404. The replies hold the panel only, never a token.

**Who may queue a job.** `current_page` is set while a job owns the page at `page_index`, queued or running. A job is queued only by whoever wins one guarded `UPDATE … SET current_page = ? WHERE status = 'running' AND page_index = ? AND current_page IS NULL`. Finishing a page is also one guarded `UPDATE … WHERE page_index = ?`. A page is therefore counted once, and only one job runs per run, even when Resume and the end of a page happen at the same moment.

- **Pause:** sets `paused`. The running page finishes and is counted, and the job queues nothing. A job that arrives while paused releases its page.
- **Resume:** sets `running` again and continues from `page_index`, the next page that has not run. If a job still owns that page, Resume queues nothing and that job carries on. A job that meets a Resume while releasing its page keeps it.
- **Stop:** sets `stopped` and `finished_at`. The running page finishes, and nothing more is queued. A new press starts a new run.
- **Pause and Stop always win.** The job never writes the status, except closing a run as `done` after its last page, which is guarded on running or paused.
- **One run at a time.** Pressing Sync lahat while a run is running or paused starts nothing, and the answer is `running`.
- **Unchanged from 042:** a page whose lock is held waits 30 s and retries; a failing page or a job killed by the worker (`failed()`, now also marked `killed` per 044) counts as failed, and the run goes on.

### The run panel

Above the grid, updated by the 5 s poll:
- **Status:** running, paused, stopped or done, shown as `tumatakbo`, `naka-pause`, `itinigil` or `tapos`.
- **Started:** by whom (user name) and when, and when it finished.
- **Pages:** done / total, failed, skipped (fresh), remaining.
- **The current page:** its kind and batches read so far, from that page's running api run, and whether it is waiting for another sync.
- **Totals:** new customers, updated customers, orders matched (by name plus by phone), ad ids filled.
- **Duration.**
- **History:** a "Huling 10 Sync lahat" table with the same counts per run.
- **Buttons:** Sync lahat is disabled while a run is active. Pause shows while running, Resume while paused, Stop while either.

Each row's "Huling sync" now also shows the last run's counts, for example `2026-10-18 09:00 · window · age · +9,985 bago · 1,279 na-match`.

### The old run at deploy: adopted

I chose to **adopt** the old run.

- **Import:** the old cache state in `botcake-sync-all` (id, pages, done, ok, failed, current, waiting, started_at) becomes a `sync_all` row with `run_key` set to the old id. `page_index` = `done`. `current_page` = the page at `done`, because the old chain already has a job queued or running for it. The new code therefore sends no job of its own and never starts from the first page.
- **When:** on first sight. That is the first poll or panel view, a press, or the first job the new code runs.
- **The old chain's jobs run under the new code:** same class, same `runId` and `index` properties. When one runs, the row first catches up from the cache if the old code got further (`done > page_index`). The new code then owns the run and deletes the cache key.
- **An old run finished by the old code** (its cache has `finished_at`) closes the row as `done` with its counts.
- **One row only:** adoption runs under a cache lock, so a poll and a job cannot create two rows.
- **Pause and Stop on an adopted run** work from the next job that the new code runs.

## Query counts

Auth, session and NavAccess queries are not counted.

| Request | Queries |
|---|---|
| Grid, order counts not cached | 8: settings, user names, customers, last run per page, orders, plans (`ROW_NUMBER`), last successful run, Sync lahat runs; +2 when a Sync lahat run exists (starter's name, the current page's api run) |
| Poll, nothing changed | 3: changes, api runs since, Sync lahat runs; +2 with a run |
| Poll, an api run changed | 9: adds customers, last run, Botcake settings of those pages, plans, last successful run |
| Start (press) | one grid build plus a few writes |

These counts stay flat from 5 to 60 pages (`PageSettingsGridTest`). With the `database` cache store, each request also reads the old cache key once, for the adoption.

## Tests

| Case | Test | Result |
|---|---|---|
| "Susunod na sync" per kind: window, tuloy mula batch 15, incremental, laktaw (sariwa, 2 oras na), a fresh page that needs a continue is not skipped, token invalid, walang ID/token; the summary line; the hours follow the config | `SyncPlanTest::test_each_page_shows_what_its_next_sync_would_do_and_the_summary_counts_them` | pass |
| Huling sync carries new, updated, matched (name + phone) and ad ids filled | `SyncPlanTest::test_the_last_sync_shows_its_counts` | pass |
| Query count flat for 5 and 60 pages (now at most 9) | `PageSettingsGridTest::test_the_query_count_stays_flat_from_5_to_60_pages` | pass |
| Sync lahat: grid order, one page at a time, the fresh incremental page skipped (counted), pages without a token or with an invalid token left out, a failing page does not stop the next; panel status, counts, starter and source | `SyncAllTest::test_pages_run_one_at_a_time_in_grid_order_skip_fresh_ones_and_a_failing_page_does_not_stop_the_next` | pass |
| The row's Sync now ignores freshness | `SyncAllTest::test_the_row_sync_now_ignores_the_freshness_rule` | pass |
| Panel totals (3 new, 3 matched) and the last 10 runs, newest first | `SyncAllTest::test_the_panel_sums_the_pages_and_keeps_the_last_10_runs` | pass |
| A held lock makes the page wait, then run; the poll shows current and waiting; a killed job counts as failed and the next is queued | `SyncAllTest::test_a_held_lock_makes_the_page_wait_then_run_and_the_poll_shows_it` | pass |
| Pause pressed during a page: that page finishes, nothing is queued, it survives `Cache::flush()`, a press then starts nothing, Resume queues the next page (index 1), a stale job of the same run does nothing | `SyncAllTest::test_pause_lets_the_running_page_finish_survives_a_cache_flush_and_resume_continues_from_the_next_page` | pass |
| Stop ends the run; its queued job syncs nothing; the next press starts a new run | `SyncAllTest::test_stop_ends_the_run_and_the_next_press_starts_a_new_one` | pass |
| A second press during a run starts nothing | `SyncAllTest::test_a_second_press_during_a_run_starts_nothing` | pass |
| Adoption: an old cache run mid-page is adopted (same key, from its page, no new job, Resume sends none); the old chain's next job runs only the last page; the cache key is gone; one row | `SyncAllTest::test_an_old_run_in_the_cache_is_adopted_and_continued_from_where_it_is` | pass |
| An adopted old run that the old code finishes is closed as done, and a new press starts | `SyncAllTest::test_an_adopted_old_run_that_the_old_code_finishes_is_closed` | pass |
| A job that arrives while paused releases its page, and Resume queues it again | `SyncAllTest::test_a_job_that_arrives_while_paused_lets_resume_queue_the_page_again` | pass |
| Stop while a page waits on the lock: no sync, stopped, released | `SyncAllTest::test_stop_while_a_page_waits_on_the_lock_ends_the_run_without_syncing_it` | pass |
| A job of an unknown run logs a warning | `SyncAllTest::test_a_job_of_an_unknown_run_logs_a_warning_and_stops` | pass |
| 25 cell edits leave Sync lahat, Sync now and Test token usable | `SyncAllTest::test_editing_cells_does_not_use_up_the_limit_of_the_sync_and_test_buttons` | pass |
| CEO and a NavAccess role can press, Pause, Resume and Stop; another role gets 404 on all four, and nothing changes; no token in the reply | `SyncAllTest::test_the_ceo_and_a_role_checked_in_nav_settings_may_use_the_controls_others_get_404_and_no_token_is_sent` | pass |

Red runs:
- **Plan:** `Undefined array key "next"` and `Undefined array key "new"`.
- **Sync lahat** before the rewrite:
  - `Undefined constant BotcakeSyncAll::LEGACY_STATE`
  - `Undefined array key "status"`
  - "3 is identical to 2" (no freshness skip)
  - "walang susunod na page na naka-queue" (no Pause)
  - 404 on the control routes
- **Two test mistakes, corrected:** a name mismatch in the totals fixture (2 matched against 3 expected), and the held-lock test expecting no current page where the next page is now already claimed.
- **Fix loop:** the finished-old-run test failed on "two arrays are identical" (the row stayed running). The paused-arrival, stop-while-waiting and NavAccess-Stop cases passed before the fix; they pin behaviour that already held.

`SyncAllTest` was rewritten for the database state. Its 042 cases (order, failing page, lock wait, killed job, second press, missing run warning, rate-limit counters, access) are all kept.

Full suite (`vendor/bin/phpunit`) before the fix commit: `Tests: 1380, Assertions: 16826, Errors: 1, Failures: 1, PHPUnit Deprecations: 6, Skipped: 3`. The only reds are the known `ExampleTest` and, in this fresh worktree, `ImportStartTest`. On the final tree: `Tests: 1383, Assertions: 16851, Errors: 1, Failures: 1, PHPUnit Deprecations: 6, Skipped: 3`, with the same two known reds and nothing else.

Other checks:
- `php -l` passes on every changed PHP file.
- `node --check` passes on the page's script.
- `artisan view:cache` compiles.

## Review

- **One adversarial review:** one major, seven minors.
- **The major (fixed):** an adopted old run could stay "running" forever. If the old code finished its last page after the adoption, the row never closed, and every press answered "running". The row is now closed from the finished cache state, and a test covers it.
- **Minors fixed:**
  - Pause and Resume racing with the job's release of its page could leave a running run with no job. The release is now guarded on the status, and the job keeps the page when a Resume came in between.
  - A poll and a job adopting at the same moment could create two rows. Adoption now runs under a lock.
- **Missing tests added:** an arrival while paused, then Resume; Stop while waiting on the lock; a NavAccess role pressing Stop.
- **Accepted in `TODO.md` under 045, with reasons:**
  - An old run whose chain was already broken before the deploy is adopted as a running run that nothing moves. Stop clears it.
  - A job lost without `failed()` leaves its page claimed. Stop clears it.
  - The poll reads the old cache key on every request.
  - The duplicated "has an ID and token" rule and summary labels.
  - The 2 h lock left by a timed-out page.
- **Deploy order:** covered in the next section.

## Browser check

Not done. The app needs the server database (`.env`, not read), and this session has no running app. The page's script is checked by syntax and by reading only. To check on the server:
- Before pressing: "Susunod na sync" and the plan line.
- Press Sync lahat, then Pause during a page. The panel shows `naka-pause` after that page.
- Resume. The next page starts.
- Stop.
- The "Huling 10 Sync lahat" table.

## After release (server): order matters

1. **`php artisan migrate --force` first.** The new code reads `run_key` and writes `ad_filled`. Before the migration, /page-settings would answer 500 and every sync would fail.
2. **Then `php artisan queue:restart`.** This is required:
   - Until the worker restarts, it runs the old code. The old code ignores Pause and Stop, and drops the jobs of a run started by the new code.
   - The restart waits for the current job, so an old Sync lahat page in progress finishes first. Its next job then runs under the new code and is adopted.
3. **Optional:** set `BOTCAKE_SYNC_ALL_FRESH_HOURS` (default 6), then `php artisan config:cache` if the config is cached.
4. **No `npm run build`:** the page uses the Tailwind CDN.
5. **Incepxion:** it uses the same steps. Its MySQL must support `ROW_NUMBER()` (MySQL 8 or later).

## What the brief got wrong, and decisions it left open

- **Stopping still lets the running page finish.** The old code wrote its state back from the running job, so removing the cache key during a page did not stop it. Pause and Stop now win from the next page on, but the page that is running still finishes, because cutting a Botcake read halfway would only make it continue next time.
- **Incepxion's `--timeout=120`.** A job's own `$timeout` (1800 s for Sync lahat) wins over the worker flag, as the brief says. But `retry_after` on Incepxion's queue must also exceed it, or a long window read is handed to the worker a second time. I could not check that here.
- **"Started by" for an adopted old run** is empty: the old code did not record who pressed.
- **Skipped counts** are fixed when a run starts. A page that becomes fresh during a long run is still synced when its turn comes. Its own `plan()` makes that an incremental, which is cheap.
- **"Current page"** on the panel is the page a job owns: running, waiting on the lock, or queued to start next.
- **Conflict with `CLAUDE.local.md`:** the kit asks for a plan and a "go" before code. The brief came with every decision made, so I worked straight from it.

## Story changes

None. The brief brought no `qa/stories.md` stories. The cases above come from its Tests list.

## Amendment 1

### Amendment changes (kept in the handoff folder, not committed)

1. Before `migrate` has run, a missing column means "not yet": no 500 on /page-settings or the poll, and a job in that window keeps the chain.
2. `start()` waits briefly for an adoption in progress, so a press cannot start a second run next to the adopted one.
3. An adopted old run that is still running is closed when the old cache key is gone and no job owns a page.

Commit: `fix: before the migration the page, the poll and a press answer without error and a Sync lahat job waits instead of losing the chain, a press waits for an adoption in progress, and an adopted old run with no cache key and no job is closed (spec 045 amendment 1)`

### What changed

- **Before the migration.** The deploy is push, then migrate. `optimize:clear` and a php-fpm restart run between them, but the queue worker does not restart.
  - **The poll and the grid:** `BotcakeSyncAll::panel()` turns a `QueryException` into "no panel" (null). Adoption catches it too and leaves the cache key for later. /page-settings and the poll answer 200, and the other grid reads did not need the new columns.
  - **The Sync lahat press:** `start()` checks `ready()` (does `run_key` exist?) and answers `{status: not_ready}` without creating anything. The page then says "Hindi pa handa … Subukan ulit mamaya".
  - **A Sync lahat job:** it checks `ready()` first. If the columns are missing, it queues itself again for the same page 60 s later. The chain is never lost, and the old cache state is not consumed. `failed()` does nothing before the migration.
  - **`syncPage()`:** it writes `ad_filled` only when the column exists, so a row's Sync now running on a restarted worker before the migration still records its run.
- **Press during an adoption.** `start()` now takes the adopt lock with a blocking wait (`block(10)`, `adoptWait`). If the wait times out, it answers `running` and starts nothing. Once the lock is free, the next press finds the adopted run.
- **Orphaned adopted run.** This runs on every poll, and on a press. It applies when the newest Sync lahat row is an adopted old run that is still running, the old cache key is gone, and the `jobs` table has no queued or reserved job carrying its `run_key`. It is checked only on the database queue; on other drivers nothing changes.
  - **On its last page:** closed as `done`, with `page_index` = total and `finished_at` set.
  - **Earlier:** closed as `stopped` with the error "Naputol ang lumang run: walang job at walang state sa cache". This goes beyond the amendment: calling a run cut in the middle `done` would misreport it.
  - **Paused:** its page is released, so Resume can queue it.
  - A job of the old chain that is running or queued is still in `jobs`, so a live run is never closed.

### Tests (amendment 1)

| Case | Test | Result |
|---|---|---|
| New columns dropped, an old run in the cache: /page-settings 200; poll 200 with no panel; press answers `not_ready` and queues nothing; the old chain's job queues itself again for the same page with a delay, calls nothing, and leaves the cache key; a row Sync now still records its run | `SyncAllTest::test_before_the_migration_the_page_the_poll_the_press_and_the_jobs_do_not_fail` | pass |
| A press while a poll holds the adopt lock answers `running` and creates no run; after the lock frees, a press adopts and still starts nothing new (one row, the old key) | `SyncAllTest::test_a_press_waits_for_an_adoption_in_progress_and_never_starts_a_second_run` | pass |
| Adopted run on its last page, cache key gone: stays running while a job with its key is in `jobs`; closed as done (3 / 3, no current page, finished) once the job is gone; a run cut in the middle is closed as stopped; a press then starts a new run | `SyncAllTest::test_an_adopted_old_run_with_no_cache_key_and_no_job_is_closed` | pass |

Red runs:
- "Expected response status code [200] but received 500" (no columns).
- "two strings are identical" (the press started a second run).
- "tapos na ang huling page, walang job: isinara … two arrays are identical" (the row stayed running).

Full suite after amendment 1: `Tests: 1386, Assertions: 16884, Errors: 1, Failures: 1, PHPUnit Deprecations: 6, Skipped: 3`. The only reds are the known `ExampleTest` and, in this fresh worktree, `ImportStartTest`. `php -l`, `node --check` and `artisan view:cache` pass.

### Deploy, updated

The order is push, migrate, `queue:restart`, and it is now safe at every step.
- **Between push and migrate:** the page and the poll work, and Sync lahat answers "not ready". The worker still runs the old code. If it restarts early, a Sync lahat job waits for the migration.
- **`optimize:clear`:** it removes the old cache key, but an old job in the middle of a page writes it back when it finishes, and its next job is adopted. If no old job was running or queued, the adopted row is closed as described above.
- **Not covered:** an old job that was only waiting on the lock (delayed by 30 s) when the cache was cleared still meets an empty state under the old code, and that chain stops. This is the old code's behaviour. The page's next sync continues its window read as planned (044).

`TODO.md`: the broken-chain item is updated. A broken old chain is now closed as stopped once its cache key expires.
