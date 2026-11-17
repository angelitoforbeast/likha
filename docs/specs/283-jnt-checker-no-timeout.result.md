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

## Evidence per done-when item

## Story changes

## Rulings

## Merge danger
