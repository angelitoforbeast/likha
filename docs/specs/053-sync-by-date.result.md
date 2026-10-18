# Result 053: daily partial Botcake sync by subscription date, weekly 45-day read, automatic

Branch `feat/053-sync-by-date` from `develop` at 835d89ea. Not pushed. Plan: `docs/plans/053-sync-by-date.md`.

## Commits

| Commit | What |
|---|---|
| `b43b047f` | docs: the spec, saved word for word |
| `ec10e453` | docs: the plan |
| `c78cb3e8` | feat: the by date read replaces the incremental read; every run records the dates it read; the migration; Sync lahat uses `by_date` |
| `0486b559` | feat: the schedule (01:00 daily by date, Sunday `--full`) and `--nightly` |
| `3bfd62bc` | feat: /page-settings shows the dates, and a failed run in red with the next stop date |
| `97ffe001` | fix (first review): a by date read stops after 22 minutes; accepted findings in `TODO.md` |
| `d971fea0` | docs: the first result |
| `4961b2b9` | fix (independent review): resumable chains; old clean runs use their run date; freshness counts only clean runs; a busy page is skipped and the rest carry on; the nightly mutex expires after 12 hours; a failed sync always shows its next stop date |
| (the docs commit after it) | docs: this result and `TODO.md` updated for the independent review |

## Migration

`2026_10_19_100000_add_subscribed_dates_to_botcake_psid_import_runs.php` adds three columns to `botcake_psid_import_runs`. All three are additive, nullable `date` columns, guarded with `hasTable` and `hasColumn`:

- `subscribed_newest`: the newest `date_subscribed_at` of the run's chain (Manila date). A run from batch 1 writes its own newest; a resumed run carries the chain's.
- `subscribed_oldest`: the oldest date the run itself read.
- `stop_date`: the stop date of a by date run. A resumed run carries the chain's.

"Clean" is not stored. It is already on the row: `status = done` and `stop_reason` is `date`, `age` or `end`. The dates are saved after every batch, so a failed run still shows how far it got. Existing rows are never written. **Run `php artisan migrate --force` right after the pull.** New code before the migration answers 500 (accepted, see `TODO.md`).

## How the stop date is picked

1. **What the next sync does.** `plan()` / `decide()` look at the latest deciding run: a run that stopped with `cap`, `time`, `age`, `end` or `date`, or any window or by date run, including failed and killed ones.
   - Finished (`age`, `end` or `date`): **by date** from batch 1, with a new stop date.
   - An unfinished window or by date run: **resumed** from the batch it reached minus `RESUME_OVERLAP` (5). A by date resume keeps the chain's stop date. Every resumed run starts with the chain's newest date.
   - The stuck rule is kept for both kinds: two zero-batch runs of the same kind from the same start go back to batch 1. For a by date read that means a new chain with a new stop date.
   - Anything else (an old incremental `cap`, or a run with no kind): a window read from batch 1, as before.
2. **Last clean date.** This is the newest date of the page's latest clean run. A clean run is either a single run or the end of a chain, and meets all of these:
   - `status = done`;
   - `stop_reason` is `date`, `age` or `end`;
   - `read_kind` is `by_date`, `window` or `full`;
   - it has a `subscribed_newest`, which for a chain end is carried from the chain's first run.

   **Old runs:** a clean window or full read from batch 1 (`age` or `end`) with no recorded date uses the Manila date of its `created_at`. This is worked out in code (`cleanDate()`) and the rows are not written. Likha's pages, which already had clean window reads from Sync lahat, can start by date on the first night.
3. **Stop date** is the later of (last clean date − 1 day) and (today in Manila − `sync_max_age_days`, which is 45).
4. **No clean date** means a window read from batch 1.
5. **Catch-up.** Failed, killed and time-stopped runs are never clean, so they never move the stop date. A cut read is continued, not restarted. After days of failures, the next read goes back to the last clean date − 1 day, and never further than 45 days.
6. **When a by date read stops.** After storing each batch it checks, in this order:
   - `age`: a customer is older than 45 days;
   - `date`: every customer in the batch is dated before the stop date (an undated customer never triggers this stop);
   - `time`: 22 minutes have passed, the same limit as the window read;
   - `end`: the batch came back empty.

   There is no known-PSID stop and no 300-batch cap. Inactive marking is unchanged: only a clean window or full read from batch 1.
7. **The grid and Sync lahat** use `plans()`, which computes the same result in one extra `ROW_NUMBER` query, with the same `TRIM`/`BTRIM` driver branch as the existing query. The date fallback for old runs is done in PHP, so the SQL has no driver-specific date functions.
8. **Sync lahat freshness.** `lastDoneAt()` counts only clean runs and clean chain ends, so a done run that stopped on `time`, `cap` or `known_batch` no longer makes a page "laktaw (sariwa)".

## `--nightly` and the Incepxion cron line

`php artisan botcake:sync-customers --nightly`:

- When `services.botcake.sync_enabled` is false, it prints `Skipped: naka-off ang Botcake sync (BOTCAKE_SYNC_ENABLED).` and exits 0 at once.
- Otherwise it runs `--full` when the **Manila** date is a Sunday, and the normal plan (by date) on other days.
- When the per-page lock is busy, it skips only that page. It prints `Skipped: may tumatakbo pang Botcake customer sync (mula …). Laktaw ang <page>; tuloy sa susunod na page.` and carries on.

Both servers run on a UTC clock, so 01:00 Manila is 17:00 UTC the day before. `--nightly` decides Sunday from the Manila date, so the 45-day read lands on Sunday night in Manila. The cron line for the `www-data` crontab on Incepxion, with `flock -n` so a long run is never doubled (same path as the existing `chatblast:auto` line):

```
0 17 * * * cd /var/www/incepxion.pro && flock -n storage/framework/botcake-nightly.lock php artisan botcake:sync-customers --nightly >> /dev/null 2>&1
```

The release adds it; I touched no server.

## Schedule (`routes/console.php`, Asia/Manila)

| Entry | When | Options |
|---|---|---|
| `botcake:sync-customers` | `0 1 * * 1-6` (Monday–Saturday 01:00) | `when(sync_enabled)`, `withoutOverlapping(720)`, `runInBackground()` |
| `botcake:sync-customers --full` | `0 1 * * 0` (Sunday 01:00) | same |

The hourly `:15` entry is removed. Only one read runs each night. The mutex expires after 12 hours, so a dead process can't block the next night.

## What /page-settings shows

All changes are in the existing **Huling sync** and **Susunod na sync** cells. The flow cells and the Test token code are not touched. The view only changes `infoSync`, a new `paintSync`, `refreshInfo`, one line of the row builder, and the `SUMMARY` constant.

- **Huling sync:** `Okt 17 → Okt 18 · by date · 2026-10-18 01:04 · date · +12 bago · 3 na-match`.
  - The dates go from oldest to newest, and only one date is shown when they are the same.
  - For a resumed run, the range is the chain's newest date to the run's own oldest date.
  - The months are in Filipino (Ene … Dis).
- **Failed latest run:** the cell is red (`text-red-600`) and adds the error and the next stop date, for example `… · rate_limited · Rate limit · susunod: mula Okt 15`. It always shows the date, also when the plan label is "laktaw (sariwa …)" or a by date resume. For a window read, which has no stop date, it shows the plan label.
- **Susunod na sync / Sync lahat:**
  - `by date, mula Okt 16`;
  - a by date resume shows `tuloy mula batch 17 (by date, mula Okt 9)`;
  - `next()` now carries `from` (the stop date) for by date, fresh and by date resume.

  The summary reads, for example, `1 window, 2 tuloy, 2 by date, 1 laktaw (sariwa)`.

## Case table

| Spec test / review item | Test | Result |
|---|---|---|
| Stops at the first batch older than the stop date, not on known PSIDs | `BotcakeByDateReadTest::test_by_date_reads_past_a_known_batch_and_stops_at_the_first_batch_before_the_stop_date` | pass |
| The new subscriber below a known batch is stored (gap) | same test (`gap-new` in batch 3, under the all-known batch 2) | pass |
| Stop date = last clean − 1; failed doesn't move it; catch-up; 45-day cap | `test_the_stop_date_is_the_last_clean_date_minus_one_day_within_45_days` (12 data sets), `test_a_by_date_read_stops_at_the_45_day_limit_before_the_stop_date` | pass |
| A page with no clean run does the window read | data sets "walang malinis na run", "lumang tuloy na window na walang petsa", "wala pang run" | pass |
| Dates recorded per run | first test (`stop_date`, newest, oldest), `test_a_failed_run_still_records_the_dates_it_read` | pass |
| `--nightly` weekday / Sunday (fake clock, Manila) / exits when disabled | `BotcakeSyncScheduleTest::test_nightly_runs_the_full_read_on_a_manila_sunday_and_by_date_on_other_days` (4 data sets, including UTC times that fall on another Manila day), `test_nightly_exits_at_once_when_the_flag_is_off` | pass |
| Schedule entries | `test_the_daily_by_date_sync_and_the_sunday_full_sync_run_at_1am_only_when_the_flag_is_on` | pass |
| Row Sync and Sync lahat use by date | `SyncAllTest::test_the_row_sync_now_ignores_the_freshness_rule` (asserts `by_date`), `test_sync_all_runs_a_by_date_read_for_a_page_that_finished_its_window` | pass |
| /page-settings row shows the dates and the failed state | `SyncPlanTest::test_the_last_sync_shows_the_dates_it_read_and_a_failed_run_in_red_with_the_next_stop_date` | pass |
| Review 1: old clean runs use their run date, no row written | data sets "lumang window na walang petsa: petsa ng run", "lumang full na walang petsa: petsa ng run" (stop 2026-10-19), plus the assertion "walang lumang row na sinusulatan" | pass |
| Review 2: by date resumes with the same stop date; stuck rule | `test_an_unfinished_by_date_read_is_continued_like_a_window_read_with_the_same_stop_date` (5 data sets) | pass |
| Review 2: the chain carries its newest date and its clean end moves the stop date | `test_a_long_by_date_read_stops_after_22_minutes_and_the_next_one_continues_the_chain_to_a_clean_end` | pass |
| Review 2: a window chain that ends cleanly is clean | `test_a_window_chain_that_ends_cleanly_counts_as_clean_with_the_newest_date_of_its_first_run`, data set "natapos na tuloy na window: malinis ang chain" | pass |
| Review 3: freshness counts only clean runs | `SyncPlanTest::test_each_page_shows_what_its_next_sync_would_do_and_the_summary_counts_them` ("Page Putol") | pass |
| Review 4: a busy page is skipped, the next one still runs | `BotcakeCustomerSyncTest::test_a_signal_handler_force_releases_the_lock_and_the_skip_message_says_since_when` ("Laktaw ang Page Uno", "Laktaw ang Page Dos") | pass |
| Review 5: `withoutOverlapping(720)` | schedule test (`expiresAt` 720) | pass |
| Review 6: the failed state always shows the stop date | `SyncPlanTest` failed-state test (the plan is "fresh", and `from` is `Okt 15`), and the script line | pass |

All HTTP is faked (`Http::preventStrayRequests`), the clocks are fake (`FakeBotcakeClock`, `travelTo`), and the names and ids in the tests are made up.

**Existing tests changed** because the behaviour they pinned was replaced:

- **Removed:**
  - the known-batch stop test;
  - the incremental 300-cap test;
  - "known batch before age".
- **Rewritten:**
  - the old known-batch test now checks that undated customers never stop a by date read, and keeps the ad-id log check;
  - the errored-full / incremental inactive test now uses by date;
  - the 22-minute test now checks the chain resume instead of a restart.
- **Updated:**
  - `BotcakeWindowReadTest`: the plan table (dated runs give `by_date`; an `end` of an incremental run gives a window read), and the killed-run test, because a resume now returns `[kind, start, stopDate, chainNewest]`;
  - the `SyncPlanTest` / `SyncAllTest` labels, fixtures and the new `from` key. The failed run in the dates test now reads 3 batches, so it stays a restart rather than a resume.

**Red runs.**

First round:
- by date tests: 10 of 12 failed on assertions;
- `--nightly`: "The "--nightly" option does not exist.";
- schedule: `'15 * * * *'`;
- page settings: `Undefined array key "dates"`;
- time stop: `time` expected.

Independent review round: 13 failures before the fix, all on assertions:
- the old-run and chain data sets (`window` instead of `by_date`, wrong first batch);
- the chain tests;
- the missing "Laktaw ang Page Dos" line;
- `expiresAt` 1440 instead of 720;
- the missing `from` key, and "Page Putol" shown as fresh.

## Suite

`php artisan test`: **158 passed, 2 failed (17849 assertions)**. The two failures are the known ones the spec allows:

- `Tests\Feature\ExampleTest > the application returns a successful response`;
- `Tests\Feature\NightRun\ImportStartTest > macro job final write applies only whi…` (fresh worktree).

The warnings come from the missing Vite manifest in a fresh worktree. `vendor/` was installed in this worktree with `composer install` from the unchanged lock file. No `npm run build` was needed: the page's script is inline in the Blade view.

## Review findings and fixes

**First review** (`skeptic-reviewer`, standard depth, `835d89ea..97ffe001`):

- **Major (fixed, `97ffe001`):** the by date read had no time limit, so a 45-day catch-up could pass the worker's 1800 s timeout. It now stops with `time` after 22 minutes. The re-check confirmed this. The independent review then replaced the restart with a resume.
- **Fixed in place:** the stale 042 note in `TODO.md`.
- **Added:** a test for the `age` stop.

**Independent review** (`835d89ea..d971fea0`, no majors). All six items were fixed in `4961b2b9`, each with a regression test (see the case table):

1. Old clean window or full runs with NULL dates use the Manila date of `created_at`, in code only.
2. By date and window reads resume as one chain that carries its first run's newest date and stop date, and the chain's clean end is clean. The stuck rule is kept.
3. Freshness counts only clean runs and clean chain ends.
4. A busy page is skipped and the command carries on.
5. `withoutOverlapping(720)` on both nightly entries.
6. The failed state always shows "susunod: mula …".

Accepted as asked, in `TODO.md`:
- the re-chat order within one date can still miss a customer who moves above the read position (Sunday's `--full` catches them);
- new code before `migrate` answers 500.

**Re-check of `4961b2b9`** (`skeptic-reviewer`, standard depth): no blocker or major. It confirmed that each of the six items has a regression test that fails without its fix, that `plan()` and `plans()` agree, that window behaviour is unchanged and that inactive marking is unchanged. Its minors are accepted in `TODO.md`:
- the clean end of a resumed chain counts as fresh. The brief's item 3 allows clean chain ends, and the next nightly run covers the top;
- a chain begun before the migration ends with an older newest date once (it reads more, never less);
- the old-run date fallback also covers a new undated clean run from batch 1;
- missing tests for a failed `--full` between a by date run and its resume, and for a resume next to another chain.

Earlier accepted minors, still in `TODO.md`:
- `sync_max_batches` is unused;
- the Filipino month list is fixed in the code;
- the red cell is checked as script text.

Two earlier minors were fixed by the chains: a resumed window was never clean, and a by date read had no resume.

**Browser check:** not run. This session has no browser tools. Check in the browser: a page whose last sync failed shows its Huling sync in red, with "susunod: mula …".

## Story changes

None. The spec brought its test list instead of stories in `qa/stories.md`, and the case table follows that list. The kit asks for stories (and the story-writer when a spec brings none). The spec's own test list was used as the cases, a small conflict noted here.

## Process notes

- The plan was written and committed before any code. The brief asked me to start, so I did not wait for a separate "go".

## What the brief got wrong or left open

- **"A page with no clean run yet does the window read"** would have meant a window read on every page on the first night after the release: about 9 h on Likha (59 pages) and about 17 h on Incepxion (112 pages). The independent review settled it: old clean window and full runs use their run date (see above). A page whose last deciding run is an old incremental `cap` run still does one window read; that rule is unchanged.
- **"Last cleanly finished date … a clean run that started at batch 1"** left a window read that needs a resume never clean. It is now a chain whose clean end counts, as the independent review asked.
- **The 300-batch cap of the old incremental read no longer applies.** The partial read is now bounded by the stop date, 45 days and the 22-minute stop, and it resumes after that stop.
- **The example "Huling sync: Okt 17 → Okt 18 · by date · 01:04"** shows only the time. I kept the full `2026-10-18 01:04`, so a run several days old does not look current.
- **`date_subscribed_at` is date only**, but some answers (and older test data) carry a full timestamp. Both are reduced to the Manila calendar date.
