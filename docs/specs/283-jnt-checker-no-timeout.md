# 283 — JNT checker: Upload and Update finish without a gateway timeout

## Why
On the second site (identical code), staff got **504 Gateway Timeout** on /jnt/checker on 2026-11-17: `POST /jnt/checker/upload` at 10:17 Manila (ran over 60 s; a retry at 10:20 took 17 s) and `POST /jnt/checker/update` at 11:13 (and twice on 2026-11-16, 11:20 and 11:22). nginx gives PHP 60 s (fastcgi_read_timeout default). Measured from the nginx logs: Upload normally 17–20 s, Update normally 50–57 s. `JntCheckerController@update` runs one `UPDATE macro_output ... WHERE id IN (...)` per waybill: thousands of queries on a large, busy table. The 11:13 update still finished after the 504 (about 9,650 rows got their waybill by 11:13), so only the screen showed an error. The owner decided: fix both Upload and Update in code; the nginx timeout is not raised.

## Stories and cases
**RR. Update (P1).** As staff, after a matched upload, I click Update and every matched order gets its waybill, with no 504.
- H: ~10,000 matched rows across ~4,000 waybills: the request answers in under 10 s (done, or a visible "running" state that completes by itself); every matched row has its waybill; the success count is shown.
- N: nothing updatable from the last upload: same error message as today.
- E: double click or two tabs: writes are idempotent, only one update run per upload, the count is reported once.
- E: a matched row deleted between Upload and Update: skipped; the count reflects rows actually written.

**RU. Upload (P1).** As staff, uploading a J&T Excel or ZIP never gives a 504, and I see the same results screen as today (matched / not matched / not in Excel / mapping missing / Cancel skipped / files processed, shipping-fee checks, perfect match, updatable rows).
- H: an upload that takes over 60 s today completes and shows its results; if async, the page shows progress, then the results, without re-uploading.
- N: bad file type, ZIP without Excel, unreadable file, missing header: same error messages as today.
- E: **results identical** to the current code's for the same file and date range: proven by a regression test on a fixture.
- E: two staff upload at the same time: each sees only their own results, and each one's Update uses its own matched rows.

**RS (P1).** No /jnt/checker* web request does heavy work longer than about 20 s by design: long work runs in a queued job and the page reads its state.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Fix in code; do not raise the nginx timeout | Owner: once the code is fixed, the timeout is not needed |
| Both Upload and Update | The 504s happened on both |
| The same change ships to both sites | The controller is identical on both |

## Constraints
- **Do not change the matching rules.** Fix how and when the work runs, not what it computes.
- `macro_output` is large, live and busy: no ALTER of it, no CREATE TABLE ... AS SELECT, no table locks; write in short batches (e.g. a CASE update of up to ~500 ids per statement), each transaction well under 1 s.
- **Lean schema:** prefer the cache or the existing queue tables for run state and results. A new table only if you show why the cache can't do it, and ask in your plan first.
- Background work uses the default queue connection (config/queue.php, QUEUE_CONNECTION, database); both sites already run a default-queue worker. Do not add a supervisor program. Name the queue you use in the result.
- Results now travel in the session flash. If you move them, keep them per user or per run, and expire them (e.g. 24 h).
- This repo's Laravel conventions; feature tests in tests/Feature.

## Threat model and risk
Untrusted: the uploaded Excel or ZIP from staff (ZIP path traversal, huge files): keep today's validation; don't widen what is accepted. Trusted: logged-in staff. Risk tier: medium (it writes the waybill on live orders).

## Allowed commands
composer install; php artisan test (with filters); vendor/bin/phpunit; php artisan route:list; git add, git commit, git push -u origin fix/283-jnt-checker-no-timeout.

## Done when
1. Feature tests for RR, RU and RS pass; the full suite adds no new failures compared with base 16dade83 (list the base failures, if any).
2. A regression test shows the Upload results for a fixture are identical before and after.
3. Local timing on a seeded fixture of at least 5,000 matched rows across at least 2,000 waybills, numbers in the result: the Update request answers in under 10 s and all rows are written; the Upload request answers in under 10 s.
4. Double-submit tests for Update and Upload pass.
5. The branch is pushed to origin; the result file has evidence and a merge-danger section (one-way or two-way door, how to revert, what queue worker must be running).

## Out of scope
nginx or PHP config; other JNT pages; matching rules; the release to either site (the reviewer does it).

## Budget
Medium job. At most 2 attempts at the same failing step, then stop and report.
