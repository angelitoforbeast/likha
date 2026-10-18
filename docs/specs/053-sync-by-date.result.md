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
| `97ffe001` | fix (from review): a by date read stops after 22 minutes; accepted findings in `TODO.md` |

## Migration

`2026_10_19_100000_add_subscribed_dates_to_botcake_psid_import_runs.php` adds three columns to `botcake_psid_import_runs`. All three are additive, nullable `date` columns, guarded with `hasTable` and `hasColumn`:

- `subscribed_newest`: the newest `date_subscribed_at` the run read (Manila date);
- `subscribed_oldest`: the oldest one it read;
- `stop_date`: the stop date a by date run used.

"Clean" is not stored. It is already on the row: `status = done` and `stop_reason` is `date`, `age` or `end`. The dates are saved after every batch, so a failed run still shows how far it got. **Run `php artisan migrate --force` right after the pull.** The new columns are read without a guard (see the accepted findings).

## How the stop date is picked

1. `plan()` keeps the old rules for `full`, the window read and its resume. Wherever it used to return `incremental` (the last deciding run finished with `age`, `end` or now `date`), it now goes through `byDate()`.
2. **Last clean date** is the `subscribed_newest` of the page's latest run that meets all of these:
   - `status = done`;
   - `stop_reason` is `date`, `age` or `end`;
   - `start_batch = 1`;
   - `read_kind` is `by_date`, `window` or `full`;
   - `subscribed_newest` is not null.
3. **Stop date** is the later of two dates: (last clean date − 1 day), or (today in Manila − `sync_max_age_days`, which is 45).
4. If no clean date is found, the page gets a window read from batch 1. This covers a page with no clean run, and old runs from before the migration, which have no dates.
5. Failed, killed and time-stopped runs are never clean, so they never move the stop date. After days of failures, the next read goes back to the last clean date − 1 day (catch-up), and never further than 45 days.
6. The by date read starts at batch 1. After storing each batch it checks, in this order:
   - `age`: a customer is older than 45 days;
   - `date`: every customer in the batch is dated before the stop date (an undated customer never triggers this stop);
   - `time`: 22 minutes have passed, the same limit as the window read;
   - `end`: the batch came back empty.

   There is no known-PSID stop and no 300-batch cap. Inactive marking is unchanged: only a clean window or full read from batch 1.
7. `plans()` (used by the grid and Sync lahat) computes the same result in one extra `ROW_NUMBER` query, with the same `TRIM`/`BTRIM` driver branch as the existing query.

## `--nightly` and the Incepxion cron line

`php artisan botcake:sync-customers --nightly`:

- When `services.botcake.sync_enabled` is false, it prints `Skipped: naka-off ang Botcake sync (BOTCAKE_SYNC_ENABLED).` and exits 0 at once.
- Otherwise it runs `--full` when the **Manila** date is a Sunday, and the normal plan (by date) on other days.

The cron line for the `www-data` crontab on Incepxion. It uses the same path as the existing `chatblast:auto` line.

If the server clock is Asia/Manila:

```
0 1 * * * cd /var/www/incepxion.pro && php artisan botcake:sync-customers --nightly >> /dev/null 2>&1
```

If the server clock is UTC, 01:00 Manila is 17:00 UTC the day before. `--nightly` decides Sunday from the Manila date, so the Sunday full read still lands on Sunday night in Manila:

```
0 17 * * * cd /var/www/incepxion.pro && php artisan botcake:sync-customers --nightly >> /dev/null 2>&1
```

Check the server timezone (`date`) before choosing. The release adds the line; I touched no server.

## Schedule (`routes/console.php`, Asia/Manila)

| Entry | When | Options |
|---|---|---|
| `botcake:sync-customers` | `0 1 * * 1-6` (Monday–Saturday 01:00) | `when(sync_enabled)`, `withoutOverlapping()`, `runInBackground()` |
| `botcake:sync-customers --full` | `0 1 * * 0` (Sunday 01:00) | same |

The hourly `:15` entry is removed. Only one read runs each night.

## What /page-settings shows

All changes are in the existing **Huling sync** and **Susunod na sync** cells. The flow cells and the Test token code are not touched. The view only changes `infoSync`, a new `paintSync`, `refreshInfo`, one line of the row builder, and the `SUMMARY` constant.

- **Huling sync:** `Okt 17 → Okt 18 · by date · 2026-10-18 01:04 · date · +12 bago · 3 na-match`. The dates go from oldest to newest, and only one date is shown when they are the same. The months are in Filipino (Ene … Dis).
- **Failed latest run:** the cell is red (`text-red-600`) and adds the error and the next plan, for example `… · rate_limited · Rate limit · susunod: by date, mula Okt 15`.
- **Susunod na sync / Sync lahat:** the kind `incremental` is now `by_date`, with the label `by date, mula Okt 16`. The summary reads, for example, `1 window, 2 tuloy, 1 by date, 1 laktaw (sariwa)`. The freshness skip still applies to it.

## Case table

| Spec test | Test | Result |
|---|---|---|
| Stops at the first batch older than the stop date, not on known PSIDs | `BotcakeByDateReadTest::test_by_date_reads_past_a_known_batch_and_stops_at_the_first_batch_before_the_stop_date` | pass |
| The new subscriber below a known batch is stored (gap) | same test (`gap-new` in batch 3, under the all-known batch 2) | pass |
| Stop date = last clean − 1; failed doesn't move it; catch-up; 45-day cap | `test_the_stop_date_is_the_last_clean_date_minus_one_day_within_45_days` (10 data sets), `test_a_by_date_read_stops_at_the_45_day_limit_before_the_stop_date` | pass |
| A page with no clean run does the window read | data sets "walang malinis na run", "lumang run na walang petsa", "wala pang run" | pass |
| Dates recorded per run | first test (`stop_date`, newest, oldest), `test_a_failed_run_still_records_the_dates_it_read` | pass |
| `--nightly` weekday / Sunday (fake clock, Manila) / exits when disabled | `BotcakeSyncScheduleTest::test_nightly_runs_the_full_read_on_a_manila_sunday_and_by_date_on_other_days` (4 data sets, including UTC times that fall on another Manila day), `test_nightly_exits_at_once_when_the_flag_is_off` | pass |
| Schedule entries | `test_the_daily_by_date_sync_and_the_sunday_full_sync_run_at_1am_only_when_the_flag_is_on` | pass |
| Row Sync and Sync lahat use by date | `SyncAllTest::test_the_row_sync_now_ignores_the_freshness_rule` (now asserts `by_date`), `test_sync_all_runs_a_by_date_read_for_a_page_that_finished_its_window` | pass |
| /page-settings row shows the dates and the failed state | `SyncPlanTest::test_the_last_sync_shows_the_dates_it_read_and_a_failed_run_in_red_with_the_next_stop_date` | pass |
| (from review) the 22-minute stop | `test_a_long_by_date_read_stops_after_22_minutes_and_the_next_one_reads_again_to_the_same_stop_date` | pass |

All HTTP is faked (`Http::preventStrayRequests`), the clocks are fake (`FakeBotcakeClock`, `travelTo`), and the names and ids in the tests are made up.

**Existing tests changed** because the behaviour they pinned was replaced:

- **Removed:**
  - the known-batch stop test;
  - the incremental 300-cap test;
  - "known batch before age".
- **Rewritten:**
  - the old known-batch test now checks that undated customers never stop a by date read (it reads to the end), and keeps the ad-id log check;
  - the errored-full / incremental inactive test now uses by date.
- **Updated:**
  - the plan table in `BotcakeWindowReadTest`: dated runs give `by_date`, and an `end` of an incremental run gives a window read;
  - the `SyncPlanTest` / `SyncAllTest` labels and fixtures.

**Red runs:**

- by date tests: 10 of 12 failed on assertions (window/incremental instead of `by_date`);
- `--nightly`: "The "--nightly" option does not exist.";
- schedule: `'15 * * * *'`;
- page settings: `Undefined array key "dates"`;
- time stop: `time` expected, `date`/`end` read.

## Suite

`php artisan test`: **158 passed, 2 failed (17814 assertions)**. The two failures are the known ones the spec allows:

- `Tests\Feature\ExampleTest > the application returns a successful response`;
- `Tests\Feature\NightRun\ImportStartTest > macro job final write applies only whi…` (fresh worktree).

The warnings come from the missing Vite manifest in a fresh worktree. `vendor/` was installed in this worktree with `composer install` from the unchanged lock file. No `npm run build` was needed: the page's script is inline in the Blade view.

## Review findings and fixes

`skeptic-reviewer`, standard depth, on `835d89ea..HEAD`, then a re-check of the fix.

- **Major (fixed, `97ffe001`):** the by date read had no time limit. A 45-day catch-up on a slow API could pass the worker's 1800 s timeout, and the killed job would hold the lock for 2 hours.
  - It now stops with `time` after 22 minutes, like the window read.
  - `decide()` sends a by_date run back to by date, so there is no resume and no fall into a window read. The stop date stays the same because a time-stopped run is not clean.
  - The re-check confirmed the major is closed.
- **Minors, accepted in `TODO.md` under "053":**
  - `sync_max_batches` is now unused;
  - a window read that only finishes after a resume is not clean;
  - a by date read has no resume (it converges on Sunday's `--full`, which has no time stop);
  - the new columns are not guarded before the migration runs;
  - the Filipino month list is fixed in the code;
  - the red cell is checked as script text, not in a browser.
- **Fixed in place:** the stale 042 note in `TODO.md` that relied on the 300-batch cap.
- **Added:** a missing test for the `age` stop of a by date read.

**Browser check:** not run. This session has no browser tools. Check in the browser: a page whose last sync failed shows its Huling sync in red, with "susunod: …".

## Story changes

None. The spec brought its test list instead of stories in `qa/stories.md`, and the case table follows that list. The kit asks for stories (and the story-writer when a spec brings none). The spec's own test list was used as the cases, a small conflict noted here.

## Process notes

- The plan was written and committed before any code. The brief asked me to start, so I did not wait for a separate "go".

## What the brief got wrong or left open

- **"A page with no clean run yet does the window read"** also applies to every page right after the release. None of the existing runs has a recorded date, so on the first night every page does one 45-day window read (about 556 s each, by the brief's own table). From the next night on, it does by date. The other option was to use the old runs' `created_at` as their clean date. I did not take it, because the brief defines the clean date as the newest date read.
- **The 300-batch cap of the old incremental read no longer applies.** The brief did not say what bounds the partial read. It is now bounded by the stop date, 45 days and the 22-minute limit (that last one added after review).
- **The example "Huling sync: Okt 17 → Okt 18 · by date · 01:04"** shows only the time. I kept the full `2026-10-18 01:04`, so a run several days old does not look current. The header already says "Huling sync".
- **`date_subscribed_at` is date only**, but some answers (and older test data) carry a full timestamp. Both are reduced to the Manila calendar date.
- **The Incepxion cron line depends on the server's timezone,** which the brief does not give. Both lines are above.
