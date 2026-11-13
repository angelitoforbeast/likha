# Result 269: labels fetched after booking, printing stitches them, booking takes all ready pages

Branch `feat/269-jnt-labels-ahead` from `e4f37b91` (Likha, live). Risk tier kept: medium. No migration, no new table or
column. Routes unchanged. The only UI changes are one dropdown option and the summary after an "All ready pages"
batch.

## What changed

| File | Change |
|---|---|
| `app/Jobs/FetchJntLabel.php` | New. Queue `jnt-label`, tries 4, timeout 120 s. It fetches one waybill's label and keeps it. When J&T has no label yet it releases itself after 60, 180 and 600 s. After the 4th try it logs one info line and ends, with no `failed_jobs` row. It uses `RestoresMissingDefaults` (the spec 219 guard for optional typed properties). |
| `app/Jobs/CreateJntOrder.php` | After the transaction that stores a successful booking with a mailno, it dispatches `FetchJntLabel(mailno, page, shipmentId)`. A dispatch error is logged and never fails the booking. The 267 lock and the mailno re-read are untouched. |
| `app/Services/Jnt/JntLabelStore.php` | New. Keeps labels on disk, looks them up, deletes them and prunes them (see Storage). |
| `app/Services/Jnt/JntLabelFetcher.php` | New. Fetches labels in parallel (`Http::pool`) under the limiter and keeps them. |
| `app/Services/Jnt/JntLabelLimiter.php` | New. Shared cap on J&T label calls in flight across all workers. |
| `app/Services/Jnt/JntClient.php` | `printWaybill` is split into `printWaybillForm()` (the same signed BILLQUERY form), `printWaybillUrl()`, `getTimeoutSeconds()` and `printWaybillJson()` (the same HTTP/JSON checks and messages). `printWaybill()` behaves exactly as before. |
| `app/Jobs/BuildJntWaybillBulkPrint.php` | Stitches kept labels. Only the missing labels are fetched through the fetcher (see below). Output, order, parts/ZIP, counters and error texts are unchanged. |
| `app/Http/Controllers/JntOrderUiController.php` | `ALL_PAGES` choice. The body of `createBatch` moved unchanged into `batchPage()`, plus a per date+page lock. New `createBatchAllPages()`. `index()` shows the "All ready pages" choice the same way as no page picked. |
| `resources/views/jnt/orders/index.blade.php` | The option `— All ready pages —` and the summary card. The page-only cards (J&T account, "No mapping found") are hidden for that choice. |
| `app/Console/Commands/PruneJntLabels.php`, `routes/console.php` | `jnt:prune-labels` runs daily at 04:30 Manila. |
| `config/jnt.php` | `queues.label` (`JNT_LABEL_QUEUE`, default `jnt-label`). `labels.batch` 10, `labels.max_concurrent` 20, `labels.keep_days` 14 (`JNT_LABEL_BATCH`, `JNT_LABEL_MAX_CONCURRENT`, `JNT_LABEL_KEEP_DAYS`). |
| `deploy/supervisor/likha-queue-jnt.conf` | New program `likha-queue-jnt-label`. |
| `tests/Feature/Jnt/JntLabelsAheadTest.php` | New, 18 tests. |
| `tests/Feature/Jnt/JntQueuesTest.php` | Its fake J&T now answers BILLQUERY with "no label", so the label call after booking is not counted as a booking. Nothing else in the test changed. |

## Storage design

- The file is the record: `jnt_labels/{Y-m-d}/{mailno}.pdf` on the `local` disk. In Laravel 12 that disk's root is
  `storage/app/private`, so on live the path is `/var/www/likha/storage/app/private/jnt_labels/...`. The file holds
  the decoded PDF bytes. `{Y-m-d}` is the day it was fetched (Asia/Manila). There is no column, no table and no
  marker file: the file's existence, size and mtime are the marker.
- Lookup goes from today back 14 days, newest first. That is at most 15 `is_file` checks per mailno. A label is
  usually found in the first one or two folders.
- A write goes to `{mailno}.pdf.{random}.tmp` first, then is renamed. A reader never sees half a file. Only bytes
  that start with `%PDF` are kept.
- A label is kept only under the mailno it was requested for. The job checks that the shipment's stored mailno is
  still that mailno, and only for booked shipments. An answer that names another waybill (`billCode`, `billcode` or
  `mailno` in `responseitems`) is refused and never kept or printed. Mailnos that are not `[A-Za-z0-9_-]{1,64}` are
  never used as file names.
- Disk use: about 4,000 labels a day for 14 days, roughly 56k small PDFs. Pruning deletes whole day folders older
  than 14 days, daily at 04:30 Manila.

## Parallel fetch and limiter

`BuildJntWaybillBulkPrint` → per part (1,000 items, as before) → per slice of 100 items (`FETCH_SLICE`, so progress
saves still move every few seconds):
1. `JntLabelStore::path()` for each mailno. Kept labels are used as they are.
2. The missing labels go to `JntLabelFetcher::fetchMany([mailno => page])` with `SLOT_WAIT_SECONDS` = 300. Its loop:
   - `JntLabelLimiter::acquire(min(10, remaining))`: up to 10 slots from 20 shared slots.
   - `fetchChunk()` with as many mailnos as slots were granted. `Http::pool` sends them together, each with its page's
     `JntClient` (`fromPageOrConfig`; no page means `fromConfig`, as before). Each call uses that client's signed form,
     URL and timeout.
   - Each answer is checked as `printWaybill` checks it. `pdfFromPrintResponse` keeps the messages `no base64Url` and
     `invalid base64`, and the label is kept.
   - The slots are released.

   A label that cannot be kept (not a PDF, disk error) is still handed back as a temp file for this run.
3. Stitch in `seq` order. An error is written to the item as `{mailno} | {reason}`, as before.
4. A kept file that FPDI cannot parse is deleted, fetched once more and stitched from a fresh copy. (FPDI caches a
   source by its path, so the fresh label needs its own copy.) If the second try fails, the item fails.

The limiter is `Cache::lock('jnt-label:slot:{0..19}', 90 s)` on the shared cache store (redis on live per the brief;
database works too). Each worker takes the free slots it needs, starting at a random slot, and waits in 250 ms steps
when none is free. A killed worker's slot frees itself after 90 s. The label job takes 1 slot and waits up to 30 s;
then it tries later. So with 3 print workers asking for 10 each and 2 label workers asking for 1 each, J&T never
has more than 20 label calls in flight.

## All ready pages

- The dropdown gets `— All ready pages —`, value `__all_ready__`. It sits right under the `-- Select Page --`
  placeholder: if it were the very first option, a browser would show it selected when no page is chosen. Picking it
  shows the date as with no page picked, and Create Batch is enabled. Retry Failed stays hidden (it needs one page).
- Create Batch with it takes every distinct `PAGE` that has `macro_output` rows on the date and runs `batchPage()` for
  each. These are the same checks, in the same order and with the same messages as a single page: J&T account
  mapping, STATUS on all rows, validate_1/validate_2/item_checker on PROCEED rows, eligible rows, and skipping orders
  that already have a shipment. Each ready page gets one `jnt_batch_runs` row (`filters` `{date, page}`, as before)
  and one `CreateJntOrder` per new order.
- Redirect: `/jnt/orders?date=…&page=__all_ready__`. The flash is `success` ("All ready pages: N page(s) queued, M
  order(s). K page(s) skipped.") or `error` when no page was ready. `batch_summary` lists the queued pages (with a
  link to each run and its order count) and the skipped pages with the reason.
- Nothing books twice. A second click finds every order with a shipment and skips it ("No new orders to batch…").
  `batchPage()` also holds a cache lock per date+page (120 s) while it checks and inserts. A second click on the same
  page while the first is still inserting gets "A batch for … is being created right now". Only the single-page path
  can see this new message, and only on a real double click. Without the lock, the unique `macro_output_id` would have
  thrown a 500 there. An error on one page in the all-pages loop is reported as that page's skip reason and does not
  stop the other pages.
- A single page works exactly as before: same redirects, messages, run rows and jobs (tested).

**Incepxion:** `JntOrderUiController.php`, `index.blade.php` and `CreateJntOrder.php` are byte-identical between
`e4f37b91` and `release/267-incx`. The "All ready pages" part (controller and view) would therefore cherry-pick
cleanly. It does not need any of the label code. Not ported, as instructed.

## Tests

- `tests/Feature/Jnt/JntLabelsAheadTest.php`, 18 tests: booking queues the label job on `jnt-label` (mailno, page,
  shipment); a refused booking queues none; the job keeps the label with the page's account and does not call again
  once kept; a mailno the shipment does not have is not fetched; an answer naming another waybill is not kept; no
  label yet → released after 60/180/600 s through the real database worker, 4 calls, then gone with no
  `failed_jobs`; a label that arrives on the 2nd try is kept; a print run with all labels kept makes 0 J&T calls with
  the same page count and output pages as the fetched run; 3 kept + 22 missing → only the 22 fetched, batches
  `[10, 10, 2]`, the page account vs .env account per mailno, 28 pages; 15 of 20 slots held elsewhere → batches
  `[5, 5, 2]` and every slot freed after; a broken kept file is deleted and fetched once; "no base64Url" fails the
  item as before; All ready pages (ready / STATUS / validation / no account / already shipped / other day, second
  click books nothing); single page unchanged; the double-click lock; the dropdown renders and stays selected; prune
  keeps 0/1/14 days, removes 15/30 and leaves non-date folders; queue and schedule are wired.
- `tests/Feature/Jnt` (67 tests) green.
- Full suite: 3 failed, 236 passed (80889 assertions). The 3 failures are the known ones (ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest). In the first full run, `QueuedJobCompatTest` (the spec 219 guard) also failed on `FetchJntLabel`'s optional typed properties. The job now uses `RestoresMissingDefaults`, and the second run has only the 3 known failures. Every test shows the usual missing-`.env` warning of the worktree.
- Pint: run on the new files. The changed legacy files already failed pint before this change and were not
  reformatted, to keep the diff small.

## Supervisor program

```ini
[program:likha-queue-jnt-label]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/likha/artisan queue:work database --queue=jnt-label --sleep=1 --tries=4 --timeout=120 --max-time=3600
directory=/var/www/likha
numprocs=2
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=140
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/supervisor/likha-queue-jnt-label.log
```

## Deploy order

1. Release the code, as usual for Likha. There is no migration. `config:cache` if the release does it.
2. Copy `deploy/supervisor/likha-queue-jnt.conf` to `/etc/supervisor/conf.d/`, then run `sudo supervisorctl reread`
   and `sudo supervisorctl update`. Check with `sudo supervisorctl status likha-queue-jnt-label:`: it should show 2
   RUNNING. The book and print programs are unchanged, so `update` leaves them running.
3. `php artisan queue:restart`. The jnt-book workers then dispatch label jobs, and the jnt-print workers use kept
   labels and the parallel fetch.

Between steps 1 and 2, label jobs wait on `jnt-label` with no worker. Nothing breaks: printing fetches whatever is not
kept yet. Run step 2 soon after step 1, because a label job that waits longer than about 14 days is pointless. Before
step 3, the old workers still run the old code: they book without label jobs and print one label at a time. Bookings
from before the release have no kept labels, so their print runs fetch them in parallel and keep them.

Check after deploy: `ls storage/app/private/jnt_labels/$(date +%F) | wc -l` grows as orders are booked. In
`laravel.log`, the line "J&T label not kept; the print run fetches it" should be rare. The scheduler line
`jnt:prune-labels` appears in `php artisan schedule:list`.

Rollback: stop `likha-queue-jnt-label` and redeploy the old code. Kept files are harmless and the prune, or `rm -rf`,
removes them.

## Not done / open

- Not run against J&T: all tests use a fake. The real label-ready delay after booking is unknown, which is why the
  backoff is 1/3/10 min.
- `JntOrderUiController::printOne`, `printRunZip` and `JntWaybillPrintController::printOne` still fetch live, one at
  a time. They are out of scope; they could use the store later.
- I assumed J&T's BILLQUERY answer either has no waybill field or echoes the requested one (compared on letters and
  digits only, ignoring case). If it echoes a different form of it (for example with a prefix), those labels are
  refused and the item fails. The 2026-11-13 live test output would confirm this.
