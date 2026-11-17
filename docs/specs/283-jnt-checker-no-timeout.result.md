# 283 result: JNT checker, Upload and Update without a gateway timeout

## Plan

Base 16dade83. First step before any code: run the full suite on the base and record its failures here.

**1. Move the computation, unchanged, into a service.** New `App\Services\Jnt\JntCheckerMatcher`: today's `upload()` steps A to G and the private helpers (parse, ZIP helpers, normText, normPhone, normCod, makeKey) moved verbatim. Input: the stored file path(s), original name, filter dates, and the request host (captured in the web request, because the job has no request; FeeSetting needs it). Output: one plain array with exactly what the screen gets today (results, the counts, notInExcelRows as plain arrays, perfectMatch, SF fields, updatable list). No matching rule changes.

**2. Run state and results in the cache, no new table.** Each upload gets a run id (uuid). Key `jnt_checker:run:{uuid}` holds `{user_id, status queued|running|done|failed, error, payload, update:{status, count}}`, TTL 24 h. The session keeps only the user's current run id. The old `jnt_checker_runs` tables and the unused `ProcessJntCheckerRun` job belong to an older, different checker; I leave them untouched.

**3. Upload (RU).** The request keeps today's `validate()` rules, does the cheap ZIP checks inline (open fails, no .xlsx/.xls entry: same messages, listed by entry name, no extraction), stores the file under `storage/app/jnt_checker/{uuid}/`, writes the run as queued, dispatches `App\Jobs\RunJntCheckerUpload` on the default connection, queue `default`, and redirects to `/jnt/checker?run={uuid}`. The job extracts and parses exactly as today (same extractTo, same per-file error messages), stores the payload, deletes the files. Unreadable file / missing header end as a failed run whose error shows in the same red box with today's text.

**4. Page.** `index()` reads the run (from `?run=` or the session), refuses another user's run (404), and passes the same view variables as today (notInExcelRows converted back to objects so the blade is unchanged). While queued/running it shows a "Processing upload..." box and polls a new `GET /jnt/checker/status/{run}` (JSON, cache read only) every 3 s, then reloads into the results.

**5. Update (RR).** The form posts the run id. The request checks owner and that the run is done with updatable rows (else today's error message), then `Cache::add("jnt_checker:update:{uuid}")`: only the first click dispatches `RunJntCheckerUpdate`; later clicks or tabs just show that run's update state. The job writes `waybill` with one `UPDATE macro_output SET waybill = CASE id WHEN ? THEN ? ... END WHERE id IN (...)` per 500 ids, each its own short autocommit statement, no lock, no transaction around the whole. Count = affected rows summed, as today; deleted rows are simply not matched. The page shows "Updating..." then "WAYBILL updated for N row(s)." once.

**6. Tests (tests/Feature/Jnt/JntCheckerTest.php).** Fixture xlsx and zip built in the test with PhpSpreadsheet; a reference copy of today's upload algorithm kept in the test to prove identical output (done-when 2) on duplicates, shared phones, name tie-breaks, mapping missing, Cancel rows, SF mismatch, date range. Plus: error messages, per-user isolation, two concurrent uploads, double Update, deleted row, request does no matching queries (query log), status endpoint. Timing (done-when 3): seeded 5,000+ rows / 2,000+ waybills, request time and job time measured locally (sqlite unless a local MySQL is available; I will say which).

**Questions before go:**
- Q1: Update also goes to the queue (my plan) or stays in the request with the 500-id CASE batches (about 10 to 20 statements, likely 1 to 3 s)? Queue is safer under RS; in-request avoids waiting on a busy `default` worker.
- Q2: Results of a 10,000-row upload are a few MB per run in the cache for 24 h. If the live cache is the LRU Redis, a run can be evicted early (the user would re-upload). Acceptable?
- Q3: The job sets `$timeout = 600` (job value wins over the worker's `--timeout`; under `retry_after` 1900) and `$tries = 1`. OK?

## What shipped

Code commit `47770a44` on `fix/283-jnt-checker-no-timeout` (base 16dade83).

- `app/Services/Jnt/JntCheckerMatcher.php` (new): the old `upload()` steps A to G and every helper, moved as they were. `diff -w` against the old controller shows only these changes: `back()->with('error', ...)` became a returned error, the request reads became parameters (host, dates, original file name), the ZIP extract folder is now `storage/app/jnt_checker/{uuid}/extracted` and is removed in a `finally`, and notInExcelRows became plain arrays (`getAttributes()`) so they can go into JSON. It also adds `zipPrecheckError()`, which reads only the ZIP's entry list in the web request (no extract) so the two ZIP errors come back at once, as today.
- `app/Services/Jnt/JntCheckerRuns.php` (new): run state in the cache (`jnt_checker:run:{uuid}`: owner user id, status queued/running/done/error/failed, short error, counts, dates; 24 h). Update state is in the cache too (`jnt_checker:update:{uuid}`, taken with `Cache::add`, so there is one Update per upload). Result rows go to `storage/app/jnt_checker/{uuid}.json` (written to a `.tmp` file, then renamed). Every new run deletes result files and leftover upload folders older than 24 h. No new table, no migration, no cron.
- `app/Jobs/RunJntCheckerUpload.php`, `app/Jobs/RunJntCheckerUpdate.php` (new): default connection, **queue `default`**, `$timeout = 600`, `$tries = 1`, and a `failed()` that writes a failed state with a short reason. The Update writes `UPDATE macro_output SET waybill = CASE id WHEN ? THEN ? ... END, updated_at = ? WHERE id IN (...)`, with up to 500 ids per statement. Each statement autocommits on its own: no transaction around the run, no lock, no ALTER. `updated_at` is still set, as the old Eloquent update did. The count is the sum of affected rows, as before.
- `app/Http/Controllers/JntCheckerController.php`:
  - `upload`: same `validate()` rules, the ZIP pre-check, stores the file, dispatches, redirects to `/jnt/checker?run={uuid}`. The same file with the same dates, sent again by the same user while the first run is still queued or running, returns the first run (double submit).
  - `index`: reads the run from `?run=` or the session. Another user's run gives 404. The view variables are the same as before.
  - New `status/{run}`: JSON, reads the cache only.
  - `update`: takes the run id. Today's "No matched rows..." message is unchanged. Only the first click queues the job. After a failed update the same rows can be queued again.
  - A "running" state older than 15 minutes is shown as failed, so the page never spins forever.
- `resources/views/jnt/checker.blade.php`:
  - New boxes: expired, error or failed, "Waiting to start..." (with how long it has waited, plus a "tell the admin" note after 10 min), "Checking the orders...", "Update waiting to start...", "Updating WAYBILL...", "WAYBILL updated for N row(s).", and failed with "TRY THE UPDATE AGAIN".
  - The page polls the status every 3 s while something is queued or running, and reloads when the state changes.
  - Upload and Update buttons disable themselves on submit.
  - The results tables are unchanged.
- `routes/web.php`: `GET /jnt/checker/status/{run}` (`jnt.checker.status`). It sits in the same web, auth, allowed_ip group as the other checker routes (`route:list -v`).
- Tests:
  - `tests/Feature/Jnt/JntCheckerTest.php` (21 tests).
  - `tests/Feature/Jnt/Legacy/LegacyJntCheckerController.php`: the 16dade83 controller, verbatim except for its namespace and class name, used as the reference.
  - `tests/Feature/Orders/PhoneTenDigitsTest.php` reads `makeKey` from `JntCheckerMatcher`, where it now lives.
- Not touched: the old, unused `ProcessJntCheckerRun` job and the `jnt_checker_runs` tables (a different, older checker); the matching rules; the ZIP extract (same `extractTo` as today; validation not widened).

## Evidence per done-when item

PHP 8.4.16 (Herd). Tests use the repo's phpunit.xml: sqlite in memory, array cache, sync queue. The tests use `Queue::fake()` wherever they need to see the queued state, and register sqlite stand-ins for the MySQL `STR_TO_DATE` and `RIGHT` that the date filter uses.

**1. Feature tests for RR, RU, RS pass; the full suite adds no new failures.**

```
php vendor/bin/phpunit tests/Feature/Jnt/JntCheckerTest.php tests/Feature/Orders/PhoneTenDigitsTest.php
OK (34 tests, 262 assertions)
```

Tests per story (`JntCheckerTest`):
- RU-H / RS: `test_RU_H_RS_upload_answers_at_once_runs_in_the_default_queue_then_shows_the_results`. The upload request runs no query on macro_output, page_sender_mappings or fee_settings (query log). The job is pushed on `default` with host `likha.test`. The page shows "Waiting to start" and the status URL. After the job runs, the results and the Update button (carrying the run id) appear. The work folder is deleted and the JSON is kept.
- RU-H: `test_RU_H_a_running_check_shows_running_and_a_stale_one_shows_failed_not_a_spinner`, `test_RU_H_a_failed_job_shows_a_failed_state_with_a_short_reason`.
- RU-N:
  - `test_RU_N_bad_file_type_is_refused_by_the_same_validation`.
  - `test_RU_N_zip_problems_answer_at_once_with_todays_messages`: "ZIP contains no .xlsx or .xls files." and "Failed to open ZIP file.", with nothing queued.
  - `test_RU_N_unreadable_file_and_missing_header_show_todays_messages_on_the_page`: "Failed to read Excel file.", "Missing column(s): COD, Order Status", "File bad.xlsx: Failed to read Excel file.", and "File notes.xlsx: Missing column(s): ...". In every case the work folder is deleted.
  - `test_RU_N_all_cancel_rows_show_todays_success_message`.
- RU-E: `test_RU_E_two_staff_each_see_only_their_own_results_and_update_their_own_rows`. Another user's run gives 404 on the page, the status and the update. Each Update writes only its own run's waybill.
- RR-H: `test_RR_H_update_runs_in_the_default_queue_writes_every_matched_row_and_shows_the_count`. The update request runs no macro_output query. The page shows "Update waiting to start", every matched id gets its waybill, and "WAYBILL updated for N row(s)." is shown.
- RR-N: `test_RR_N_nothing_updatable_gives_todays_message`.
- RR-E: `test_RR_E_a_matched_row_deleted_before_update_is_skipped_and_not_counted`.
- RR: `test_RR_a_failed_update_shows_the_reason_and_can_be_tried_again`, `test_RR_update_writes_in_batches_of_at_most_500_ids_per_statement` (1,201 ids give 3 UPDATE statements, and updated_at is set).
- Expired: `test_expired_state_or_missing_result_file_shows_the_expired_message`. A missing JSON, an emptied cache, or an invalid id all show "This check expired, please upload again." with status 200; the status endpoint answers `expired`.
- Cleanup: `test_result_files_and_leftover_upload_folders_older_than_24_hours_are_deleted_on_a_new_run`.
- RS: `test_RS_the_checker_routes_are_the_known_light_ones`. The `/jnt/checker*` routes are exactly index, status, upload (POST and the GET redirect) and update. Each of them stores, dispatches or reads state; none does matching or writes waybills.

Full suite: `php -d memory_limit=2G vendor/bin/phpunit --no-progress`

| | Tests | Failures |
|---|---|---|
| base 16dade83 | 5,273 | 3 |
| branch | 5,294 (+21 new) | 4 |

The 3 base failures are the known ones and fail on the branch too:
- `ExampleTest::test_the_application_returns_a_successful_response`
- `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`
- `SharedPagesBasePinTest::test_S_42_4 ... "/owner/private"`

The 4th failure on the branch is `PageSettings\FlowNamesTest::test_S_052_7_a_refresh_with_the_same_answers_writes_no_new_row` ("11 is identical to 10"). It is not related to this change (it tests Botcake page settings, and none of its code or tables are touched here). It passes when re-run alone three times and when its whole file is re-run (`tests/Feature/PageSettings/FlowNamesTest.php`: 21 tests OK), so it is flaky. In the full branch run the timing line was: upload request 0.01 s, update request 0.04 s.

**2. Regression: Upload results identical before and after.** Two tests run the old controller (`LegacyJntCheckerController`, 16dade83) and the new path through the real request, the job and the results page, on the same seeded data. They `assertSame` on: every view variable (results rows in order, all counts, perfectMatch, SF counts, expectedShippingFee, dates), notInExcelRows, and the updatable list.
- `test_RU_E_upload_results_are_identical_to_the_old_code_for_an_xlsx_and_a_date_range`
- `test_RU_E_upload_results_are_identical_to_the_old_code_for_a_zip_and_a_single_date` (two files, one in a subfolder)

The fixture covers: a plain match; a `63…` phone and a `9…` phone; a page name with different case and spaces; a COD written as `₱299.00`; duplicate keys matched in order and then exhausted; a shared phone with a name match and without one; a mapping missing; a Cancel row (` cancel  ORDER `); a match without a waybill; macro rows outside the date range or not PROCEED; macro rows not in the Excel; SF correct, SF mismatch and SF no data. The test also checks that each of these counts is above 0, so the comparison is not empty.

**3. Timing, seeded fixture: 5,000 matched rows across 2,000 waybills** (1,000 waybills × 3 items + 1,000 × 2). `test_timing_5000_matched_rows_across_2000_waybills`, local sqlite in memory (I did not use a local MySQL):

```
[283 timing] 5,000 rows / 2,000 waybills: upload request 0.01 s, upload job 0.63 s, results page 0.05 s, update request 0.02 s, update job 0.04 s
```

The Upload request answers in 0.01 s and the Update request in 0.02 s (the test asserts under 10 s). All 5,000 rows are written, with 2,000 distinct waybills and a count of 5,000. The job times are from sqlite, not the live MySQL. On the live site the Upload job will take about as long as today's request (17 to 20 s normally, sometimes over 60 s), but it now runs in the worker, not in the request. The Update now needs 10 statements for 5,000 rows instead of one per waybill (about 2,000 or more).

**4. Double submit.**
- Update: `test_RR_E_double_click_and_two_tabs_run_one_update_and_report_the_count_once`. Two clicks give one job; a third click after it finished gives no new job; a second copy of the job does nothing and keeps the first count.
- Upload: `test_RU_E_double_submit_of_the_same_upload_makes_one_run_and_one_job`. The same file twice while queued gives the same run and one job. The same file after the run is done gives a new run, for example after a mapping fix.

**5. Pushed; merge danger below.** `git push -u origin fix/283-jnt-checker-no-timeout` created the new branch on origin. `git ls-remote origin fix/283-jnt-checker-no-timeout` showed `1a39c287`. The commit that adds this line is pushed the same way. Nothing was pushed to main or develop, and nothing was deployed.

## Story changes

- RU-N: "ZIP without Excel" and "ZIP cannot be opened" still answer at once in the request, with the same messages. "Unreadable file" and "missing header" are found by the job, so they appear on the run's page in the same red box, with the same text, a moment later.
- RU-E double submit: the same user sending the same file and dates while the first run is not finished reuses that run. Any other upload is a new run.
- RR: after a failed Update (job error or timeout), the page offers "TRY THE UPDATE AGAIN". It writes the same waybills to the same rows, so repeating it is safe.

## Rulings

Answers to the plan's questions (go given with these):

- **Q1, Update goes to the queue.** Both sites run one default-queue worker (database connection, queue `default`); the backlog is usually 1 to 4 jobs. So the page shows a "waiting to start" state while the job is queued, as well as "running".
- **Q2, results are not kept in the cache only.** The live cache is Redis with `allkeys-lru` on both sites, so entries can be evicted early. The run state (status, counts, owner user id) stays in the cache. Each run's result rows are written as a JSON file `storage/app/jnt_checker/{uuid}.json`, kept 24 h. Old files are deleted when a new run is stored (no new table, no new cron entry). If the state or the file is gone, the page says "This check expired, please upload again"; never an error page.
- **Q3, `$timeout = 600`, `$tries = 1`.** A failed job shows a failed state with a short reason; the page never keeps spinning.
- The upload file and the results file stay inside `storage/app`. The extracted ZIP folder is cleaned up in a `finally` block.

## Merge danger

- **Two-way door.** No migration, no schema change, no new table, no config or env change. To revert, `git revert` the code commit and deploy. Runs in the cache and the JSON files are simply never read again. Optionally delete `storage/app/jnt_checker/` (nothing else uses it).
- **A worker must be running on each site:** `php artisan queue:work` on the default connection (database), listening on queue **`default`**. Without it, Upload and Update stay on "Waiting to start..." (after 10 minutes the page says the worker may be stopped). The jobs set `$timeout = 600`, which overrides the worker's `--timeout`; `retry_after` is 1900, so a running job is not handed out twice.
- **After deploy, restart the worker** (`php artisan queue:restart`) so it loads the new job classes. A worker still running the old code can fail to load the new classes (for example, with an authoritative classmap), and the run then shows as failed.
- The worker's PHP must have enough memory for PhpSpreadsheet. Until now the load ran under PHP-FPM's `memory_limit`; it now runs under the CLI's. If the CLI limit is lower than FPM's, a big upload can fail in the job (the page shows "The check stopped: ..."). Check `php -i | grep memory_limit` on the CLI on both sites.
- The web user (PHP-FPM) writes `storage/app/jnt_checker/`, and the worker reads it and deletes from it. Both must run as users that can do this (folders are created 0775). If the worker runs as a different user without group write, deleting the uploaded file fails.
- Pages left open across the deploy: an old results page posts Update without a run id. It falls back to the session's run; with none, it shows today's "No matched rows..." message, so the user uploads again. Old flashed results are not shown after the deploy.
- The same change ships to both sites (identical controller). The fee-rate host still comes from the web request, passed to the job.
