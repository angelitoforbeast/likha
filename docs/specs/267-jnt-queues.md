# 267: J&T booking and waybill printing get their own queues

Weight: bounded. Risk tier: medium (live booking with J&T; must never book an order twice). Size: small.
Budget: 2 attempts per failing step; stop and report if blocked.

## Why

J&T booking jobs (`app/Jobs/CreateJntOrder.php`, one job per order, dispatched in
`JntOrderUiController::createBatch` ~489-512, also by retry routes) and waybill bulk print jobs
(`app/Jobs/BuildJntWaybillBulkPrint.php`, dispatched in `JntWaybillPrintController` ~176) have no `onQueue`, so
both run on `default`, which live has ONE worker shared with other jobs. On 2026-11-12, 51 print runs each waited
~9 min in line (build ~34 s each) and bookings queued behind them. The owner approved more workers. The pages,
buttons and files staff see must not change.

## Changes

1. `CreateJntOrder` runs on queue `jnt-book`; `BuildJntWaybillBulkPrint` on queue `jnt-print`. Set it so EVERY
   dispatch site uses them (prefer `public $queue` / constructor `$this->onQueue(...)` in the job, so
   retry/re-dispatch paths also follow; grep all dispatch sites incl. retryFailed, retryByDatePage, printOne-like
   paths, commands, and ProcessJntCreateOrdersBatch if it dispatches). Queue names in config (`config/jnt.php` keys
   `queues.book` / `queues.print`, env `JNT_BOOK_QUEUE` / `JNT_PRINT_QUEUE`, defaults `jnt-book` / `jnt-print`).
2. Parallel safety for `CreateJntOrder` with several workers: confirm (and test) that the existing lockForUpdate /
   mailno check (~CreateJntOrder.php:144-146) prevents a second booking of the same shipment when two workers take
   duplicate jobs; if there is any gap (e.g. the lock is released before the J&T call and a second worker could call
   J&T for the same shipment), close it with the smallest change (e.g. an atomic claim before calling J&T). Never
   call J&T twice for one shipment.
3. Counting bug: on exception, markFail adds to processed/fail_count and rethrows, so with `$tries=3` a failure
   counts up to 3 times (CreateJntOrder ~187-192), and a missing SENDER_NAME is retried 3 times for nothing (~294).
   Make a failure count once (count only on the final attempt, or make the run counters idempotent per shipment)
   and fail a missing SENDER_NAME at once without retry. Keep the run's finished detection (~177-180, 215-217)
   correct.
4. Add `deploy/supervisor/likha-queue-jnt.conf` (repo copy, like the other confs in `deploy/supervisor/`): two
   programs, `likha-queue-jnt-book` (`queue:work database --queue=jnt-book --sleep=1 --tries=3 --timeout=150
   --max-time=3600`, numprocs=3) and `likha-queue-jnt-print` (`queue:work database --queue=jnt-print --sleep=1
   --tries=1 --timeout=1800 --max-time=3600 --memory=512`, numprocs=3), user www-data, logs in
   `/var/log/supervisor/`. The print job's own `$timeout=3600` is above the database queue's retry_after 1900
   (`config/queue.php:52`): set `BuildJntWaybillBulkPrint::$timeout` to 1800 (a run of ~75-400 labels takes seconds
   to a few minutes) so a run can never be picked up twice.
5. Nothing visible changes: same routes, views, buttons, PDFs.

## Second branch: Incepxion

Incepxion runs an older fork. Its live commit is 355d0d45 (local branch `release/264-incx` head d202235a =
355d0d45 + docs; not on origin). Incepxion does NOT use in-app waybill printing (none since June), so for it do
changes 1 (booking queue only; print may also move but it is unused), 2, 3, and a supervisor conf
`deploy/supervisor/incepxion-queue-jnt.conf` with program `incepxion-queue-jnt-book` (php path `/usr/bin/php`, app
`/var/www/incepxion.pro`, numprocs=3). Branch `release/267-incx` from d202235a in a second worktree; port the
changes (cherry-pick if clean), run the related tests there.

## Tests

- jobs land on the right queue from every dispatch site (Queue::fake assertions);
- two workers / a duplicated job for one shipment → J&T client called once (mock the client);
- a failing order counts once in fail_count after 3 attempts; a missing SENDER_NAME fails once without retry; the
  run still reaches finished;
- existing J&T tests stay green.

## Constraints

- Smallest change, no refactors, no UI change. Conventional Commits, no co-author or generated lines, no person
  names.
- This spec as `docs/specs/267-jnt-queues.md` and a result `docs/specs/267-jnt-queues.result.md` (the exact
  supervisor programs and the deploy order: release code, then reread/update supervisor, then queue:restart, and
  what happens to jobs already on `default` at release time).

## Allowed

composer install, php artisan test (related tests + the full suite once on main; known pre-existing failures at
462b183e: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest), pint on changed PHP files, git add/commit,
push of `feat/267-jnt-queues` and `release/267-incx`. No merge, PR, push to main or deploy.
