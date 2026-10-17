# Result 044: the first sync of a page reads its whole 45-day window

## What shipped

Commits on `fix/044-first-sync-window` (cut from `develop` at 2ba4964):

- `docs: brief for spec 044 first sync reads the whole window`
- `fix: a page's first sync, or the one after a cap or an unfinished window read, reads the whole 45-day window without the 300-batch cap, stops itself after 25 minutes and continues from where it got to (spec 044)`
- `fix: a window read stops after 22 minutes so one slow batch still fits the worker timeout, retries the same start after a run that read nothing, and starts again from batch 1 after two failed window reads in a row (spec 044)`
- `docs: accepted review findings for spec 044 in TODO`
- `docs: result for spec 044`

### The rule (`BotcakeCustomerSync::plan($page, $full)`)

Every caller goes through `syncPage()`, so every caller follows this rule: the row's Sync now (`SyncBotcakeCustomersJob`), Sync lahat (`SyncAllBotcakeCustomersJob`), the scheduled sync and `botcake:sync-customers`. The plan looks at the page's api runs in `botcake_psid_import_runs`, matched on `LOWER(TRIM(page_name))`, newest first:

1. **`--full`:** a full read from batch 1, unchanged.
2. **The last run is a window read that did not stop at `age` or `end`:** it stopped on `time`, an error, a rate limit, or was killed (status `running`, no stop reason). The next sync **continues it as a window read** from the batch it reached minus 5: `start_batch + batches_read − 1 − 5`, at least 1. Two exceptions:
   - It read 0 batches: the next one tries the same start again.
   - It failed, and the run before it was also a failed window read: start again from batch 1, so a batch that always fails cannot trap the page.
3. **The last run stopped at `cap`:** a window read from batch 1.
4. **The page has no api run that stopped at `age` or `end`:** "never completed a window read". A window read from batch 1.
5. **Otherwise:** incremental, exactly as before (known-batch stop, the 300-batch cap, the 45-day `age` stop).

A **window read** has no 300-batch cap and no known-batch stop. It stops at `age` or `end`, or at the existing `MAX_BATCHES` safety (20,000). It also stops itself with stop reason **`time`** once 22 minutes have passed, checked after each batch on the `BotcakeClock`. Every batch is saved as it is read (`batches_read`), so a run that is killed can still be continued.

**Inactive marking** (30 days unseen, within the window) runs only after a window or full read that started at batch 1 and stopped at `age` or `end` in that one run. A continued read, a read cut short by time, or a read that failed never marks anyone inactive. The first sync of a page that reaches `age` or `end` now marks inactive, the same as a clean `--full`.

**Shown:**
- Each run's message starts with the kind of read: `Page: window, 6 batch (age), …`, `incremental`, `window, tuloy mula batch 17`, or `full`.
- The grid's "Huling sync" reads, for example, `2026-10-18 09:00 · window, tuloy mula batch 17 · time`.
- Older runs have no kind and show as before.

### Migration `2026_10_18_100000_add_read_kind_and_start_batch_to_botcake_psid_import_runs`

- Adds two nullable columns to `botcake_psid_import_runs`:
  - `read_kind` (string, 20): `incremental`, `window` or `full`.
  - `start_batch` (unsigned int).
- It is guarded with `Schema::hasTable` / `hasColumn`, and `down()` drops both columns.
- It is additive on a small table. Older runs keep NULL in both, which the rule treats as "not a window read".

### Longest single job run

- **A window read:** at most about 22 minutes of batches, plus the last batch, plus matching.
  - At 2 requests a second, 22 minutes is about 2,600 batches, or 26,000 customers, per run.
  - The last batch is normally under a second. Its worst case is about 5 minutes: 4 tries with a 30 s timeout, plus a 2/4/8 s backoff or a Retry-After of up to 60 s.
  - Matching is a few seconds per page.
- **Against the worker:** the worker's `--timeout=1800` therefore holds, except in that worst case right at the end (accepted in `TODO.md`).
- **Sync lahat:** its jobs (`$timeout = 1800`, below `retry_after` 1900) run one window read per page. A busy page continues in the next Sync lahat or Sync now.
- **The per-row job** keeps its old `$timeout = 3600`.

## Tests

| Case | Test | Result |
|---|---|---|
| First sync reads past the cap (cap set to 3) to `age` at batch 6; kind `window` from 1; inactive marking runs (complete window from batch 1); message says window | `BotcakeWindowReadTest::test_the_first_sync_reads_past_the_cap_to_the_age_window_and_marks_inactive` | pass |
| Last runs decide the read (12 rows): `cap` → window from 1; `time` → continue at 20 − 5 = 15; continued `time` (15 + 30 − 1 − 5) → 39; `age`, `end`, `known_batch` after `age` → incremental with its cap; only `known_batch` ever → window from 1; old run without kind stopped at `cap` → window from 1; rate limit mid-read → continue at 7; killed run (`running`, no stop) → continue at 7; a run that read 0 batches → same start 15; two failed window reads in a row → batch 1 | `BotcakeWindowReadTest::test_the_last_runs_decide_the_kind_of_read_and_where_it_starts` | pass |
| Time budget (fake clock, 60 s per batch): stops at batch 22 with `time`, no inactive marking; next sync starts at 17 (22 − 5), reaches `end`, still no inactive marking (not from batch 1); message and grid show "window, tuloy mula batch 17" and `end` | `BotcakeWindowReadTest::test_a_window_read_stops_after_22_minutes_and_the_next_one_continues_without_marking_inactive` | pass |
| Sync now (queued job) and Sync lahat follow the same rule: first sync reads past the cap; after a `cap` run, Sync lahat reads a window from batch 1 | `BotcakeWindowReadTest::test_sync_now_and_sync_lahat_use_the_same_rule` | pass |
| A page that completed a window read: incremental stops at the cap; the next run is a window read from page 1, past the cap and past known batches, to `end` | `BotcakeCustomerSyncTest::test_an_incremental_run_stops_at_the_cap_and_the_next_run_reads_the_whole_window_from_page_1` (rewritten from the old "starts from page 1 next run" test) | pass |

Red runs:
- **First run** of `BotcakeWindowReadTest`, before the code: all 11 runs failed. The messages were "walang cap na 3 batch … two arrays are identical", "Failed asserting that 1 is identical to 15", "… 1 is identical to 39", and for the incremental rows "two arrays are identical" (`read_kind` was null).
- **Existing tests** that assumed a fresh page reads incremental failed once the code was in, as expected:
  - `test_incremental_stops_at_the_first_batch_whose_psids_are_all_known`
  - `test_an_incremental_run_stops_at_the_cap_of_300_batches_…`
  - `test_an_errored_full_read_or_an_incremental_run_marks_nobody_inactive`
  - `test_the_all_known_batch_stop_comes_before_the_age_stop`
- **Fix loop:**
  - "0 batch" failed on "9 is identical to 15".
  - "two failed" failed on "15 is identical to 1".
  - The 22-minute test failed on `'end'` vs `'time'`.

Updated existing tests (`BotcakeCustomerSyncTest`):
- **A new helper `windowDone()`** records a completed window read, so the old incremental tests start from a page that already completed one. It is used in the known-batch, errored-full / incremental, and known-before-age tests.
- **`sole()` became `latest('id')`** where the setup now adds a run.
- **The cap test** now asserts the new behaviour: after a cap, the next run is a window read, not a known-batch stop. It still checks the default cap of 300, then uses a cap of 5 so it stays fast.

Full suite (`vendor/bin/phpunit`) after the first fix commit: `Tests: 1362, Assertions: 16725, Errors: 1, Failures: 1, PHPUnit Deprecations: 6, Skipped: 3`. The only reds are the known `ExampleTest::test_the_application_returns_a_successful_response` and, in this fresh worktree, `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`. On the final tree: `Tests: 1366, Assertions: 16733, Errors: 1, Failures: 1, PHPUnit Deprecations: 6, Skipped: 3`, with the same two known reds and nothing else.

Other checks:
- `php -l` passes on every changed PHP file.
- `node --check` passes on the page's script.
- `artisan view:cache` compiles.

## Review

- **One standard-depth review:** no blockers, no majors.
- **Fixed:**
  - A batch that always fails would have kept the page continuing from the same spot forever, with new subscribers no longer stored. Two failed window reads in a row now start again from batch 1.
  - A run that read 0 batches moved the next start back 6 batches each time. It now keeps the same start.
  - The budget is lowered from 25 to 22 minutes, so one worst-case batch and matching still fit the 1800 s worker timeout.
  - The overlap comment now gives the right reason.
- **Accepted in `TODO.md` under 044, with reasons:**
  - The budget is checked between batches, so a run can still pass 1800 s in theory.
  - The per-row job's old 3600 s timeout.
  - Two missing tests (an errored `--full` followed by a sync; the "Huling sync" text for old runs without a kind).

## Browser check

Not done. The app needs the server database (`.env`, not read), and this session has no running app. The only screen change is the "Huling sync" text, built by `infoSync` and checked by syntax.

## After release (server)

1. `php artisan migrate --force`: one new migration, `2026_10_18_100000`. It adds two nullable columns to `botcake_psid_import_runs`, a small table, so it is quick.
2. Restart the workers (`php artisan queue:restart`) so the queued sync jobs load the new code.
3. The next Sync now or Sync lahat of the page whose last stop reason was `cap` will be a window read from batch 1. If that page holds more than about 26,000 customers inside 45 days, it takes more than one sync, each continuing where the last stopped.

## What the brief got wrong, and decisions it left open

- **The reason for the overlap.** The list is newest first, so new subscribers push older customers **down**. A resumed read would then re-read some customers, not skip them. Customers can only be skipped when some ahead of the stop point disappear, which shifts the list **up**. The 5-batch overlap is kept for that case, and the comment says so.
- **"About 25 minutes" is now 22.** The time is checked between batches, and one batch can take up to about 5 minutes in its worst case, so 25 would not reliably stay inside the 1800 s worker timeout.
- **Continuing after more than `time`.** The brief names only `time`. I also continue after any unfinished window read: an error, a rate limit, or a run the worker killed. Otherwise one 429 after 20 minutes would throw away the whole read. Two bounds come with it, both described above: two failed window reads in a row start again from batch 1, and a run that read nothing keeps the same start.
- **What counts as "completed a window read".** It is any api run of the page that stopped at `age` or `end`, as the brief says. That includes an incremental run on a small page that reached the end, and any `--full`.
- **First syncs now mark inactive.** A first sync that reads from batch 1 to `age` or `end` in one run now marks inactive, like a clean `--full`, because it saw everyone in the window.
- **Conflict with `CLAUDE.local.md`:** the kit asks for a plan and a "go" before code. The brief came with every decision made and asked for about 60 minutes, so I worked straight from it.

## Story changes

None. The brief brought no `qa/stories.md` stories. The cases above come from its Tests list.

## Amendment 1

### Amendment changes (kept in the handoff folder, not committed)

1. `plan()` decides from the newest run that is `cap`, `time`, `age` or `end`, or an unfinished window read. It no longer decides from the last run alone.
2. Two failed window reads in a row restart from batch 1 only when both read 0 batches from the same start.
3. A run killed by the worker is marked failed with stop reason `killed`, from both sync jobs' `failed()`.
4. The migration's `down()` checks each column with `hasColumn`.

Commit: `fix: the read plan follows the newest cap, time, age, end or unfinished window run instead of the last run, restarts from batch 1 only after two empty reads from the same start, marks a killed sync as killed, and the migration rollback checks each column (spec 044 amendment 1)`

### The rule now (`BotcakeCustomerSync::plan`)

1. **`--full`:** a full read from batch 1.
2. **The deciding run** is the newest api run of the page that either:
   - stopped at `cap`, `time`, `age` or `end`, or
   - is a window read (`read_kind = window`), whatever its status or stop reason: failed, `killed`, rate-limited, or still marked running.

   Runs in between are skipped: `known_batch`, a failed or killed `--full`, an incremental that errored.
3. **No deciding run:** a window read from batch 1. The page never completed a window read.
4. **The deciding run stopped at `age` or `end`:** incremental. This holds even after later `known_batch` runs.
5. **The deciding run is not a window read:** it is an incremental `cap`, or an old run with no kind. A window read from batch 1.
6. **An unfinished window read:**
   - **It read some batches:** continue from `start_batch + batches_read − 1 − 5`, at least 1. Failed runs included, so two short rate-limited presses keep their progress.
   - **It read 0 batches:** try the same start again. If the window read before it also read 0 batches from that same start (above batch 1), start again from batch 1.

**Killed runs:** `SyncBotcakeCustomersJob::failed()` and `SyncAllBotcakeCustomersJob::failed()` call the new `BotcakeCustomerSync::markKilled($page)`. Sync lahat takes the page from its run state. That method changes every api run of the page still marked `running` to `failed` with stop reason `killed` and the error "Pinatay ang sync bago natapos (hal. timeout ng worker)". "Huling sync" shows `killed` instead of a run that looks endless. `plan()` continues from its saved batch.

**Migration:** `down()` drops each of `read_kind` and `start_batch` only when it exists. I edited the migration file itself. That breaks no rule here, because the migration has not been released or run on the server yet.

### Tests (amendment 1)

| Case | Test | Result |
|---|---|---|
| `cap`, then `known_batch` → window from 1; an unfinished window (`time` at 20), then a failed `--full` → continue at 15; the same with a killed `--full` → 15; `age`, then two `known_batch` → incremental; two failed window reads that read 6 batches each from 15 → continue at 15; two 0-batch reads from 15 → batch 1 (plus all earlier rows) | `BotcakeWindowReadTest::test_the_last_runs_decide_the_kind_of_read_and_where_it_starts` (18 rows) | pass |
| The row job's `failed()` marks the running run `failed` / `killed`, and `plan()` continues at 7; the Sync lahat job's `failed()` does the same for its page, and `plan()` continues at 31; a run already marked killed is left alone | `BotcakeWindowReadTest::test_a_run_killed_by_the_worker_is_marked_killed_and_the_next_sync_continues_from_it` | pass |

Red runs:
- "2 sunod na failed na may nabasa" failed on "1 is identical to 15".
- "cap, saka known_batch" failed on "two arrays are identical" (it read incremental).
- The two "saka failed / pinatay na full" rows failed on "1 is identical to 15".
- The killed test failed on "Call to undefined method SyncBotcakeCustomersJob::failed()".
- The "age, saka 2 known_batch" and "2 sunod na 0 batch" rows passed before the change. They pin behaviour the new rule must keep.
- I replaced the earlier row "2 sunod na failed: mula 1" with the two rows above.

Full suite after amendment 1: `Tests: 1372, Assertions: 16749, Errors: 1, Failures: 1, PHPUnit Deprecations: 6, Skipped: 3`. The only reds are the known `ExampleTest` and, in this fresh worktree, `ImportStartTest`. `php -l` passes on the four changed PHP files.

### Notes

- **What changed from the first version.** The review loop of the first version had made two failed window reads in a row restart from batch 1. This amendment narrows that to two reads that both read nothing from the same start. A page whose read always fails after a few batches now keeps continuing from the same place. That failure shows on the grid as the run's error.
- **`markKilled()` changes every run of the page still marked `running`.** Only one sync of a page can run at a time, because of the lock. The job calling `failed()` is the one that held it, so no live run is marked.
