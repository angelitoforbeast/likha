# 262: read every Botcake customer of every page, all time, several pages at once — result

Branch `feat/262-psid-backfill-parallel`, from 7de7176f (live code).

## What changed

| File | What |
|---|---|
| app/Services/BotcakeCustomerSync.php:80–145 | Lock per page (decision 3): `lockKey($page)` = `botcake-sync-customers:<pageKey>`, `withLock($page, $fn)`, `forceRelease($page)`, `lockedSince(?$page)`. Same 2 h TTL per page. With no page, `lockedSince()` gives the earliest holder of any page from a small cache list of held keys (`botcake-sync-customers:held`, written under a 5 s cache lock; each key's own `:since` with TTL is the truth, so a killed process drops out). No database query, so the grid's query count is unchanged. |
| app/Services/BotcakeCustomerSync.php:44–52, 64 | Constants `ALL_TIME = 'all_time'`, `ALL_TIME_SECONDS = 3600`; `$maxBatches` (= MAX_BATCHES 20000) as a property so a test can lower the cap of the other modes. |
| app/Services/BotcakeCustomerSync.php:159, 177, 241 | `plan()` and `plans()` ignore all-time runs (`notAllTime`), so a backfill never changes what the nightly reads next (decision 6). `clean()` and `lastDoneAt()` already only count by_date / window / full. |
| app/Services/BotcakeCustomerSync.php:247 | `allTimeFinished($page)`: the last all-time run of the page is done / end. |
| app/Services/BotcakeCustomerSync.php:259 | `planAllTime($page)`: unfinished chain → from the last run's reached batch minus RESUME_OVERLAP (no batch read → the same start; never batch 1). Finished chain (only reached with `--again`) → batch 1. No all-time run yet → continues the latest `full` run if it started at batch 1, read > 0 batches and did not finish (not done end / age): from its batches_read minus 5. Otherwise batch 1. |
| app/Services/BotcakeCustomerSync.php:285 | `allTimeChain()`: the chain's first run (since the previous done / end all-time run) and when it started. |
| app/Services/BotcakeCustomerSync.php:371–445 | `syncPage($page, $full, $allTime, $sleepMs)`. All time: first marks the page's runs still "running" as killed (it holds the page lock, so they are dead), then reads with no age stop and no cap, stops with reason `time` after an hour (so the lock is always held well under its 2 h TTL). Same `store()` upsert, same `matchPage()` after each run. Inactive only when the run reaches `end` and the chain started at batch 1: available api rows of the page not seen since the chain started and not in 30 days (any subscribed date, also undated, because everything was read). `--sleep-ms` sleeps between batches (all modes; default 0). The 429 / 5xx backoff is the client's, unchanged. |
| app/Services/BotcakeCustomerSync.php:351 | `kindLabel`: "all time" / "all time, resumed from batch N" (grid and run message). |
| app/Console/Commands/SyncBotcakeCustomers.php | New options `--all-time`, `--shard=K/N`, `--again`, `--sleep-ms=`. Shard = pages whose index in `pages()` satisfies `index % N == K-1` (checked: 1 ≤ K ≤ N); `--page` filters inside the shard. All time loops per page: a `time` stop continues at once with a new run (new lock); finished pages are skipped (checked inside the lock) unless `--again`; a locked page is skipped; a failed page is left for the next start. One summary line at the end. `--all-time` refuses `--full` / `--nightly`; `--shard` / `--again` need `--all-time`. SIGTERM / SIGINT releases the lock of the page being read. The other modes only changed to the per-page lock. |
| app/Jobs/SyncBotcakeCustomersJob.php, app/Jobs/SyncAllBotcakeCustomersJob.php | `withLock($page, …)`. |
| resources/views/page_settings/index.blade.php:116 | Banner: "Another sync of the same page skips it until it ends … other pages still sync." |
| tests/Feature/Botcake/BotcakeAllTimeReadTest.php | New, 12 tests (below). |
| tests/Feature/Botcake/BotcakeCustomerSyncTest.php, tests/Feature/PageSettings/SyncAllTest.php, tests/Feature/PageSettings/PageSettingsGridTest.php | The existing lock tests hold a page lock instead of the global one. |

No migration, no new table (decision 2): read_kind `all_time` fits the existing `varchar(20)`.

## Tests

New, BotcakeAllTimeReadTest (12), all failed first on 7de7176f (10 errors, 2 failures), all pass now:

- `test_all_time_reads_every_batch_to_the_end_without_the_age_stop`: customers 400 days old, read 1..4 to the empty batch, run done / end / all_time, 30 new.
- `test_all_time_has_no_max_batches_cap_while_full_still_stops_at_the_cap`: cap 2 → `--full` stops `cap`, all time reads to the end.
- `test_all_time_resumes_after_a_failure_from_its_reached_batch_and_never_restarts_at_1`: 500 ×4 at batch 13 → failed at 12; next start from 7 (12 − 5) to the end, no duplicate rows.
- `test_a_killed_all_time_run_left_running_is_marked_killed_and_resumed`.
- `test_an_unfinished_full_read_from_batch_1_is_continued_instead_of_read_again`: a running `full` at 11 batches → all time starts at 6 and the old run is marked killed; a `full` that stopped on age → from 1.
- `test_a_long_page_is_read_in_runs_of_at_most_an_hour_in_one_command_with_sleep_ms`: 200 s per batch → run 1 stops `time` at 19, run 2 continues at 14 to the end, in one command.
- `test_finished_pages_are_skipped_unless_again`.
- `test_the_shard_split_follows_the_stable_page_list`: 5 pages, 2/3 = index 1 and 4, 1/3 = 0 and 3, 3/3 = 2, one run per page; bad values rejected.
- `test_the_lock_is_per_page_so_two_pages_run_at_once`: nested locks of two pages; with Page Uno held elsewhere, all time still reads Page Dos.
- `test_the_same_page_is_refused_to_a_second_process`: `" page uno "` is the same lock; no run created while held.
- `test_the_nightly_sync_skips_a_locked_page_and_goes_on_and_ignores_all_time_runs`: `--nightly` skips the held page with the "since" message and reads the other; a later all-time run does not change the other page's window resume (plan and plans); the grid's `lockedSince()` shows the holder and clears after.
- `test_inactive_only_after_an_all_time_chain_from_batch_1_reaches_the_end`: chain from 1 (failed, resumed) → undated row not seen in 31 days becomes inactive; a chain that continued a `full` → none.

tests/Feature/Botcake and tests/Feature/PageSettings: 290 tests, all pass.

Full suite on this branch (cdd74800): 4957 tests, 2 failures, the two known ones (ExampleTest::test_the_application_returns_a_successful_response, ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order), 6 skipped. No new failures against 7de7176f (its known 2; this branch adds 12 tests). The base suite was not re-run here.

## Production numbers (read-only, plain SELECT, counts and run numbers only)

Host 72.62.79.215, `mysql likha`, 2026-11-10 21:32–21:50 Manila. Scripts: the two `.sql` files at the end.

- 94 pages have an active `botcake.page_id` and `botcake.token`.
- botcake_psids: 872,282 api rows (available), 201,535 chat_verify.
- The one-off (`--full`, started 19:18): run 2853 (first page) done / end after 2 batches; run 2854 still `running`, start_batch 1, 12,746 batches at 21:32 (oldest subscriber 2025-07-23) and 13,420 at 21:42 (oldest 2025-05-18): about 0.63 s per batch. It is Alexa Angeles (index 1 in the page order, so shard 2 of 6).

### Estimate of the batches left

Botcake gives no customer total, so per page: the batches of its last clean 45-day read from batch 1 (window or full, stop age) ÷ the days it covered × the days since the page's first order (macro_output), or, for pages whose 45-day read ended (`end`, 33 small pages), that read's batches.

| | Pages | Estimated batches |
|---|---|---|
| Ended inside 45 days (whole list already read once) | 33 | 1,889 |
| Age-stopped, first order after the data start | 43 | ≈ 160,600 |
| Age-stopped, first order at the data start (2025-06-02), so older than that | 18 | ≈ 168,400 (lower bound) |
| **All pages** | 94 | **≈ 331,000** |

Per shard of 6 (same page order as `pages()`, approximated in SQL by the binary name sort): 21k, 71k, 47k, 64k, 58k, 69k batches; the largest single page ≈ 20k.

The 18 old pages go back further than the order data: Alexa Angeles is already past 2025-05-18 at 13,420 batches. Its rate drops going back (≈ 27 batches a day in the last 45 days, ≈ 10 a day between 2025-07-23 and 2025-05-18), and api rows exist back to 2023-06-02. If those 18 pages go back two more years at a third of their recent rate, add ≈ 80k. So **about 330k–410k batches in total, minus the ≈ 13.4k Alexa already read: ≈ 320k–400k left.**

Time: at 0.63 s per batch one process needs ≈ 56–70 h. Six shards: the heaviest (shard 2, ≈ 71k, minus Alexa's 13k already read) needs ≈ 10–13 h, if Botcake and the database keep the same speed with six readers.

## How to run it (after the deploy; nothing here was run)

Paths and user: `/var/www/likha` and `www-data`, as in docs/night-run.md and earlier results; not checked on the server (production reads here were SQL only). Run the steps as root on 72.62.79.215.

1. **Stop the current one-off first** (it holds the old global lock, which the new code no longer sees; leaving it running would let a new process read Alexa Angeles at the same time):

   ```sh
   pgrep -af 'botcake:sync-customers --full'          # note the PID
   kill -TERM <pid>                                    # its trap releases the old lock and exits
   sleep 5; pgrep -af 'botcake:sync-customers' || echo stopped
   ```

   Then check the run it leaves (it stays `running` with its reached batch):

   ```sql
   SELECT id, status, read_kind, start_batch, batches_read FROM botcake_psid_import_runs WHERE id = 2854;
   ```

2. **Deploy** the branch as usual, including the queue worker restart (`php artisan queue:restart` / Horizon terminate), so no worker still runs the old global lock next to the new per-page locks.

3. **Start 6 shards**, each as www-data, in the background, with its own log:

   ```sh
   cd /var/www/likha
   for k in 1 2 3 4 5 6; do
     sudo -u www-data sh -c "nohup php artisan botcake:sync-customers --all-time --shard=$k/6 > storage/logs/botcake-all-time-$k.log 2>&1 &"
   done
   pgrep -af 'botcake:sync-customers --all-time'      # 6 processes
   ```

   Add `--sleep-ms=200` (for example) if Botcake answers 429 often (`grep -c 429 storage/logs/laravel.log` or FAILED lines with rate_limited in the shard logs).

4. **Progress** (SQL):

   ```sql
   -- pages finished (last all-time run done / end), and batches read so far
   SELECT COUNT(*) FROM botcake_psid_import_runs r
     JOIN (SELECT MAX(id) id FROM botcake_psid_import_runs WHERE source='api' AND read_kind='all_time' GROUP BY LOWER(TRIM(page_name))) l ON l.id = r.id
    WHERE r.status = 'done' AND r.stop_reason = 'end';
   SELECT SUM(batches_read) FROM botcake_psid_import_runs WHERE source='api' AND read_kind='all_time';
   ```

   Each shard log has one line per run (`OK <page>: all time, resumed from batch N, … batch (time|end) …`) and a closing `All time: …` summary.

5. **Stop and restart safely** at any time: `pkill -TERM -f 'botcake:sync-customers --all-time'`. Each process releases the lock of its page. Starting the same 6 commands again resumes every unfinished page from its reached batch (minus 5) and skips the finished ones. A page that failed (rate limit, error) in a run is also left for the next start: run the 6 commands again until the summary lines say 0 failed.

### Alexa Angeles (the page the one-off already started)

The data allows a continuation. Run 2854 is a `full` read from batch 1 that did not finish. On its first all-time start (shard 2, where it is the first page), the command takes the page's lock, marks run 2854 `failed / killed` and starts the all-time chain at its batches_read minus 5 (≈ 13,4xx), not at batch 1. Check after the start:

```sql
SELECT id, status, stop_reason, read_kind, start_batch, batches_read FROM botcake_psid_import_runs
 WHERE source='api' AND LOWER(TRIM(page_name)) = 'alexa angeles' ORDER BY id DESC LIMIT 3;
```

Because that chain did not start at batch 1 in all-time mode, its end does not mark Alexa Angeles rows inactive (the conservative side; the Sunday full read keeps its own 45-day rule). The first page the one-off finished (run 2853, 2 batches) is read again; it is 2 batches.

## Not done / notes

- **Rule for continuing a `full` read.** A page with no all-time run continues its latest `full` read from batch 1 if that read did not end (end / age), whatever its age. A Sunday full read that failed weeks ago would be continued too. Its batches were read then; the list mostly moves down as new customers arrive (so the overlap reads some again), and the new ones are read by the nightly by date. A page that should start over can be started with `--page=X` after its old full run is finished, or this rule can be limited by age in a follow-up.
- **The resume after a `time` stop always moves on by at least one batch** (`max(start + 1, reached − 5)`), so a very slow hour (≤ 6 batches) cannot repeat forever. After a failure the plain "reached − 5" applies.
- **SIGTERM needs pcntl** (TODO.md:423 notes it was never checked on the server). Without it, a killed shard leaves its page's lock for up to 2 h; a restart inside that time prints "Skipped … Laktaw ang <page>" for that page and goes on.
- **While a shard holds a page:** the nightly skips that page (its by-date chain catches up the next night from its last clean date), the row Sync button skips it, and "Sync lahat" waits on it (it waited on the global lock before). The grid banner shows the earliest holder.
- **Matching** runs after every all-time run (at most once an hour per page), as after every other read.
- The leftover config `services.botcake.sync_max_batches` (default 300) is still unused, as before.
- Measurement scripts (read-only): C:\Users\Forbeast\AppData\Local\Temp\claude\2e760ef270dbe960\07319e29-2afc-45d1-9b8d-9083b1ed643e\scratchpad\m262\est.sql and C:\Users\Forbeast\AppData\Local\Temp\claude\2e760ef270dbe960\07319e29-2afc-45d1-9b8d-9083b1ed643e\scratchpad\m262\shard.sql.

## Amendments

### 262-1: a clean `full` read from batch 1 to the end counts as finished

The amendment, as given: handoff/262-psid-backfill-parallel/AMENDMENT-1.md (main checkout, not committed). Owner, 2026-11-10: Alexa Angeles (run 2854: full, start 1, 13,444 batches, end), Alyssa Aquino (2856) and Amanda Villadolid (2857) were read again from batch 1 after the deploy. Run 2854 had reached `end` before the deploy, so the "Alexa Angeles" paragraph above (continue from its batches_read minus 5) no longer applied: a finished `full` was not continued and not counted as finished, so the all-time read started at batch 1.

| File | What |
|---|---|
| app/Services/BotcakeCustomerSync.php | `fullReadFinished($page)`: the page has a `full` run with start_batch 1, status done, stop_reason end. `--full` always starts at batch 1 (`plan($page, true)`), so its chain is that one run. `age`, `cap`, `time`, failed and killed do not count, and neither does an `end` that started past batch 1. `planAllTime()` is unchanged: such a `full` is still continued from its reached batch, `age` still starts at 1. |
| app/Console/Commands/SyncBotcakeCustomers.php | `--all-time` skips a page when its last all-time run is done / end (as before) or, on the page's first run in this command, when `fullReadFinished()` (checked inside the page lock). Line: `Laktaw ang <page>: tapos na ang full read mula batch 1 (end); --again para basahin ulit.`, counted in the summary's "tapos na dati". `--again` reads it from batch 1 (no all-time run yet) or continues its unfinished all-time chain. The full check is only on the first run, so the hourly continuation (`time`) of an `--again` chain is not cut off by the old `full`. |

A skipped page's all-time run that is still `running` from before (e.g. run 2859) is left alone, as the amendment says. Note: the killed-run cleanup is `markKilled()`, called at the start of an all-time `syncPage()` of that page and by the sync jobs' `failed()`. A skipped page reaches neither, so such a row stays `running` in the grid until someone reads that page with `--again` (or marks it by hand). Nothing else reads it: `plan()` and `plans()` ignore all-time runs, and the lock is in the cache.

Finished pages for the progress check (step 4), now including the `full` rule:

```sql
SELECT COUNT(DISTINCT LOWER(TRIM(page_name))) FROM botcake_psid_import_runs r
 WHERE r.source = 'api' AND (
   (r.read_kind = 'full' AND r.start_batch = 1 AND r.status = 'done' AND r.stop_reason = 'end')
   OR r.id IN (SELECT MAX(id) FROM botcake_psid_import_runs WHERE source = 'api' AND read_kind = 'all_time' AND status = 'done' AND stop_reason = 'end'
               GROUP BY LOWER(TRIM(page_name))));
```

(The inner query gives each page's last done / end all-time run, a slight over-count if a later all-time run of the same page is unfinished; that only happens with `--again`.)

Tests, new in BotcakeAllTimeReadTest (3), each failed first on 144403a2 (3 failures), all pass now:

- `test_a_page_with_a_clean_full_read_from_batch_1_to_the_end_is_skipped`: Page Uno has a full / 1 / 13,444 / end and an all-time run left `running`: not read, the skip line, "1 natapos ngayon, 1 tapos na dati", the running row unchanged, no new run; Page Dos is read.
- `test_a_full_read_that_stopped_before_the_end_or_did_not_start_at_batch_1_is_not_finished`: full + age → from 1; full + cap, + time, failed / killed (8 batches) → from 3 (8 − 5); full end from batch 40 → from 1; only full / 1 / end is skipped.
- `test_again_reads_a_page_finished_by_a_full_read_and_goes_on_after_each_hour`: without `--again` nothing is read; with `--again` and 200 s per batch: all-time runs time 1..19, then end 14..26 in the same command. Without the first-run guard the second run is skipped (checked by removing it: the test fails).

tests/Feature/Botcake 144 and tests/Feature/PageSettings 149: 293 tests, all pass (290 before + 3).

Full suite: 4960 tests (4957 + 3), 2 failures, the two known ones (ExampleTest::test_the_application_returns_a_successful_response, ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order), 6 skipped. No new failures.

## Final sha

The tip of the branch is the commit that adds this file; the code is at cdd74800. Amendment 262-1 is in the commit after it (code, tests and this section together).
