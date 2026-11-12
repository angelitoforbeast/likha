# 269: Waybill labels are fetched right after booking, 10 at a time, and booking can do all ready pages in one click

Weight: bounded-to-architectural. Risk tier: medium (live J&T calls; never book twice; never print a wrong label).
Size: medium. Budget: 2 attempts per failing step; stop and report if blocked.

## Why

After booking, staff print waybills per product; each print run fetches every label from J&T one at a time (~0.47 s
each; 3,836 labels ≈ 30 min on 2026-11-12). A live test on 2026-11-13 (real waybills, read-only BILLQUERY print
calls) showed J&T answered 730 label calls with 5, 10, 20, 30 and 50 at the same time with no errors (avg
0.48–0.67 s each, max 2.1 s). The owner approved: fetch each label right after its order is booked and keep it, so
printing only stitches stored labels; fetch 10 at a time; and an "All ready pages" choice in the booking page. Staff
pages and buttons stay as they are otherwise.

## Stories

- S1 (P1) **Label kept after booking.** When CreateJntOrder gets a mailno, a follow-up job (queue `jnt-label`, new)
  fetches that waybill's label PDF via the same JntClient::printWaybill for that page's account and stores it on the
  local disk (e.g. storage/app/jnt_labels/{yyyy-mm-dd}/{mailno}.pdf, decoded bytes; plus a small marker of when/what
  size; choose the leanest: file existence is enough, or a nullable column only if needed — no new table unless
  justified). If J&T has no label yet, retry with backoff (e.g. 1, 3, 10 min; max 4 tries), then give up quietly (the
  print run fetches it as today).
- S2 (P1) **Print uses kept labels.** BuildJntWaybillBulkPrint first uses the stored PDF for each mailno; only missing
  ones are fetched from J&T — those in parallel, 10 at a time (Http::pool or equivalent, with the per-page client
  credentials and signing; respect a shared limiter so the total concurrent J&T label calls across all workers stays
  ≤ 20), then stored too. Output PDF, order, parts/ZIP and run counters unchanged. A stored file that fails to parse
  is deleted and re-fetched once.
- S3 (P1) **Booking "All ready pages".** The page dropdown on /jnt/orders gets a first option "— All ready pages —".
  Create Batch with it runs the same per-page gates as today for every page that has rows for the date (STATUS set on
  all rows, validate_1/validate_2/item_checker on PROCEED rows, J&T account mapping, skip already-shipped), creates
  one batch run per ready page (same jnt_batch_runs rows as today), and shows a summary: pages queued (with order
  counts) and pages skipped with the reason. Nothing else on the page changes; picking a single page works exactly as
  before.
- S4 (P1) **Safety.** Never call J&T create twice for one shipment (keep the 267 lock); label fetch is read-only; a
  label is only ever stored under the mailno J&T returned; prune stored labels older than 14 days (daily, in the
  existing scheduler).
- S5 (P1) **Workers.** Add program `likha-queue-jnt-label` to deploy/supervisor/likha-queue-jnt.conf
  (`queue:work database --queue=jnt-label --sleep=1 --tries=4 --timeout=120 --max-time=3600`, numprocs=2). State the
  deploy order in the result.

## Tests (fake HTTP; never call J&T)

- booking success dispatches the label job on jnt-label; label job stores the file; no-label-yet retries; give-up
  path;
- print run with all labels stored makes zero J&T calls and produces the same page count; with some missing, fetches
  only those, in parallel batches ≤10;
- "All ready pages": ready pages get runs, blocked pages are listed with reasons, single-page path unchanged, nothing
  books twice;
- prune removes only old files.

Full suite: no new failures (known pre-existing: ExampleTest, ItemTableFitScriptTest, SharedPagesBasePinTest).

## Constraints

- Smallest change; no UI change except the one dropdown option and the summary message. Conventional Commits, no
  co-author or generated lines, no person names. This spec as `docs/specs/269-jnt-labels-ahead.md` and the result as
  `docs/specs/269-jnt-labels-ahead.result.md`.
- Incepxion does not print in-app; do NOT port this to the Incepxion branch now, but note in the result whether the
  "All ready pages" part would port cleanly.

## Allowed

composer install, php artisan test, pint on new files, git add/commit, push of `feat/269-jnt-labels-ahead`. No merge,
PR, push to main or deploy.
