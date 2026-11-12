# Result 267: J&T booking and waybill printing get their own queues

Branch `feat/267-jnt-queues` from `462b183e` (Likha). Incepxion: branch `release/267-incx` from `d202235a`.
Risk tier kept: medium. No migration, no new table or column, no route, view, button or PDF change.

## What changed (Likha)

| File | Change |
|---|---|
| `app/Jobs/CreateJntOrder.php` | Constructor sets `onQueue(config('jnt.queues.book'))` (`jnt-book`). `handle()` takes a per-shipment cache lock (`jnt-book:shipment:{id}`, 180 s) and runs the old body (now `book()`); a worker that finds the lock taken drops its copy. On an exception the reason is written to the shipment but not counted; the new `failed()` counts the shipment once in its run when the job ends for good (last of 3 tries, a timeout, or `fail()`). `SENDER_NAME_*` errors call `fail()` at once (no retry). `markFail()` never touches a shipment that already has a mailno. |
| `app/Jobs/BuildJntWaybillBulkPrint.php` | Constructor sets `onQueue(config('jnt.queues.print'))` (`jnt-print`). `$timeout` 3600 → 1800 s. |
| `config/jnt.php` | `queues.book` = `env('JNT_BOOK_QUEUE', 'jnt-book')`, `queues.print` = `env('JNT_PRINT_QUEUE', 'jnt-print')`. |
| `deploy/supervisor/likha-queue-jnt.conf` | New: `likha-queue-jnt-book` and `likha-queue-jnt-print` (below). |
| `tests/Feature/Jnt/JntQueuesTest.php` | New, 12 tests. |

## Every dispatch site

The queue is set in each job's constructor, so every site follows without a change at the site, and so does any
retry (a released try and `queue:retry` from `failed_jobs` keep the queue stored in the payload).

`CreateJntOrder` (all now on `jnt-book`):
- `JntOrderUiController::createBatch` (`POST jnt/orders/batch`)
- `JntOrderUiController::retryByDatePage` (`POST jnt/orders/retry-by-date-page`)
- `JntOrderUiController::retryFailed` (`POST jnt/orders/batch/{runId}/retry-failed`)
- `JntShipmentController::createOne` (`POST jnt/shipments/create/{macroOutputId}`)
- `JntShipmentController::bulkCreate` (`POST jnt/shipments/bulk-create`)

`BuildJntWaybillBulkPrint` (now on `jnt-print`):
- `JntWaybillPrintController::printBulk` (`POST /jnt/waybills/print-bulk`, modes selected and all)

Not dispatch sites: `JntWaybillPrintController::printOne`, `JntOrderUiController::printOne` and `printRunZip` run in
the request (no job). `ProcessJntCreateOrdersBatch` dispatches nothing (and nothing dispatches it). No console
command dispatches either job.

Seen while grepping, not changed: `JntShipmentController::createOne` and `bulkCreate` pass a **macro_output id**
to `CreateJntOrder`, which expects a **jnt_shipments id**. Those two `/jnt/shipments` routes would book whichever
shipment has that id. They look unused (the J&T Orders page uses createBatch and the retries) but are still
routed. Worth a separate look.

## How double booking is prevented

The old code read `mailno` at the start, called J&T, and only took `lockForUpdate` after J&T answered. Two workers
holding duplicate jobs (a double-clicked Retry Failed, or a re-released job) could both pass the first check and
both call J&T. The row lock only stopped the second *write*.

Now:
1. `handle()` takes `Cache::lock('jnt-book:shipment:{id}', 180)` before anything else. It is atomic on the shared
   cache store (`database` on live per `.env.example`; the same lock the chatblast, botcake and checklist workers
   use). A second worker that cannot take it returns at once, drops its copy, and never calls J&T.
2. The lock holder reads the shipment after taking the lock. If a mailno is stored (an earlier copy booked it),
   it returns without calling J&T.
3. The lock is released in `finally`. If a worker is killed, the lock expires after 180 s. That is above the job
   timeout (120 s) and far below `retry_after` (1900 s), so a retry never meets a stale lock and a running booking
   never loses its lock.
4. `markFail()` skips a shipment that has a mailno, so a late failure of a duplicate can never mark a booked order
   failed or count it.

Limit: a shipment whose earlier booking *failed* (J&T said no, no mailno) can be sent again by a later copy or a
retry. That is a retry of a failed booking, not a second booking. It sends the same `txlogisticid`.

Tests: worker B runs the same shipment while worker A is inside its J&T call → one J&T call. A shipment locked by
another worker → no call. Two queued copies through the real database worker → one call, run 1/1 finished.

## Counting

- Any exception other than a missing sender: the reason is written on the shipment (as before) but the run is not
  counted; the exception is rethrown and the job retries (tries 3). When the job fails for good, `failed()` counts
  it once: `processed + 1`, `fail_count + 1`, and `finished` when `processed >= total`. That also covers a timeout
  on the last try, which used to be counted never.
- `SENDER_NAME_NOT_FOUND` (from the job) and `SENDER_NAME_NOT_SET` (from the payload builder): `fail()` at once,
  counted once through `failed()`, no retry, J&T never called.
- `macro_output not found`, J&T answers (success or not) and the finished detection are unchanged.

Tests: J&T down → 3 tries, `fail_count` 1, `processed` 1, one `failed_jobs` row. The run's second order then books
and the run reaches `finished` with 1 ok and 1 failed. Missing sender → one try, `fail_count` 1, run `finished`,
`failed_jobs.queue` = `jnt-book`. Retry Failed after that → books, run 1/1/0 `finished`.

## Print timeout

`BuildJntWaybillBulkPrint::$timeout` was 3600 s, above the database queue's `retry_after` 1900 s
(`config/queue.php:52`). With more than one print worker, a run still building after 1900 s would be handed to a
second worker. It is now 1800 s, below `retry_after`, so a run is never picked up twice. A run of ~75-400 labels
takes seconds to a few minutes. The worker's `--timeout=1800` matches.

## Supervisor programs (`deploy/supervisor/likha-queue-jnt.conf`)

```ini
[program:likha-queue-jnt-book]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/likha/artisan queue:work database --queue=jnt-book --sleep=1 --tries=3 --timeout=150 --max-time=3600
directory=/var/www/likha
numprocs=3
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=170
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/likha-queue-jnt-book.log

[program:likha-queue-jnt-print]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/likha/artisan queue:work database --queue=jnt-print --sleep=1 --tries=1 --timeout=1800 --max-time=3600 --memory=512
directory=/var/www/likha
numprocs=3
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=1820
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/likha-queue-jnt-print.log
```

The job's own `$tries` / `$timeout` (3 / 120 for booking, 1 / 1800 for print) take precedence over the worker
flags. `stopwaitsecs` is above each timeout so a stop lets the current job finish.

## Deploy order

Starting the new workers before the code is released is the wrong order. The right order:

1. Release the code (as usual for Likha).
2. Copy the conf to `/etc/supervisor/conf.d/likha-queue-jnt.conf`, then `sudo supervisorctl reread` and
   `sudo supervisorctl update`. Check with `sudo supervisorctl status likha-queue-jnt-book: likha-queue-jnt-print:`.
   That should show 6 RUNNING.
3. `php artisan queue:restart`. The existing workers (`default` and the others) pick up the new code.

From release on, new bookings and print runs go to `jnt-book` / `jnt-print`. Between steps 1 and 2 they wait there
(no worker yet), so do step 2 right after step 1. Jobs already on `default` at release time keep their stored queue
and still run there, on the old `default` worker, after the queue:restart with the new code. Nothing is lost or
moved. A queued booking from before the release also takes the lock and counts once.

Rollback: set `JNT_BOOK_QUEUE=default` and `JNT_PRINT_QUEUE=default`, then `php artisan config:cache` and
`queue:restart`. Jobs go back to `default`. Jobs already on `jnt-book` / `jnt-print` still need their workers
until those queues are empty.

The live `QUEUE_CONNECTION` must be `database` (it is per the spec and `deploy/supervisor/likha-horizon.conf`; I
did not read the server `.env`). The jobs use the default connection, as before.

## Incepxion (`release/267-incx` from `d202235a`)

The Likha commit cherry-picked cleanly (the J&T files are identical at `d202235a`), then one commit on top:
- `config/jnt.php`: `queues.print` defaults to `default`, and so does the job's fallback. Incepxion does not use bulk
  printing, so it stays on the `default` worker and needs no print program. A stray print run would still be built.
- `deploy/supervisor/likha-queue-jnt.conf` → `deploy/supervisor/incepxion-queue-jnt.conf`, with only:

```ini
[program:incepxion-queue-jnt-book]
process_name=%(program_name)s_%(process_num)02d
command=/usr/bin/php /var/www/incepxion.pro/artisan queue:work database --queue=jnt-book --sleep=1 --tries=3 --timeout=150 --max-time=3600
directory=/var/www/incepxion.pro
numprocs=3
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=170
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/incepxion-queue-jnt-book.log
```

- The test file's print test expects `default`.

The deploy order is the same: release, reread/update, `queue:restart`.

## Tests

- `tests/Feature/Jnt/JntQueuesTest.php`: 12 tests. Run through Queue::fake, the real `queue:work database` worker
  on sqlite, and a fake J&T (`Http::fake`).
- Related: `tests/Feature/Jnt` + `tests/Feature/EncoderTimetable`, green.
- Full suite on `feat/267-jnt-queues`: 3 failed, 230 passed (80169 assertions). The 3 failures are the known ones
  that already fail at `462b183e`: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest. In the first full
  run, two of the new worker tests also failed: past 128 MB of process memory, `queue:work` stops after one job. The
  test's worker now runs with `--memory=4096`, and the second full run is green apart from the 3 known failures.
- Incepxion branch: `tests/Feature/Jnt` 49 tests OK; `tests/Unit/JntSupplyLifecycleTest.php` 51 passed.
- Every test shows a warning that `.env` is missing in the worktree (phpdotenv). That is from the environment, not
  from these tests.
- Pint: run on the new test file. The three legacy files (`CreateJntOrder.php`, `BuildJntWaybillBulkPrint.php`,
  `config/jnt.php`) already fail pint at `462b183e`. They were not reformatted, to keep the diff small.
