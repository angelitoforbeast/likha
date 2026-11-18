# 297 — Hourly totals "as of" columns, full width, and hourly sync runs in Upload History

## Why
On `/ads_manager/cpp/timeline` the **Hourly totals** view (291/292) labels its columns 00–23, and column 08 holds the day's cumulative figures up to the end of hour 08 (08:59). The owner reads columns the way he reads the Buckets view, where "10AM" means a snapshot **as of 10:00**. So he wants column "8AM" to mean "as of 8:00 AM" (00:00 to 7:59), with the first column "1AM" (00:00 to 0:59). This also removes a misleading cell: today the current hour is written by the :10 run with only about ten minutes of Meta spend (Incepxion, 2026-11-18 14:11: hour 14 added ₱1,884 where a normal hour adds ₱25–30k), so its CPP reads far too low.

The page sits in a `max-w-7xl` (1280 px) box, leaving the 24 columns cramped on wide screens; the owner wants the Hourly totals view edge to edge.

The hourly sync (`meta-ads:hourly --scheduled`, every hour at :10 Manila, `upload_logs` type `meta_ads_hourly`) has no screen listing its runs. On Incepxion, 12 runs in a row (2026-11-17 22:10 to 2026-11-18 09:10) ended `partial` with "Graph error 200: API access blocked" and nobody could see it. The owner looked for them in **Upload History** on `/ads_manager/payment/upload`, which today lists only `payment_activity` uploads.

## Stories and cases
**AS-1 "As of" columns (P1).** The Hourly totals columns are labelled 1AM, 2AM, … 11AM, 12PM, 1PM, … 11PM, 12AM (the last = end of day). Column "h" shows the day's cumulative Adspent, Orders and CPP **as of h:00**: spend from the stored hourly bucket H(h−1) (1AM = H00, 8AM = H07, 12AM = H23), orders with order time before h:00 (same macro_output rules and pages as 291), CPP from those two. The values are Meta's hourly breakdown as stored by the sync (not a frozen copy of what an earlier run saw), so a later run or a backfill corrects them.
- H: Nov 17 column 8AM equals the 291/292 cell at hour 07 for every figure (test on seeded data); 12AM equals the old hour 23.
- E: today, a column is filled only when its as-of time has passed **and** the day's stored hourly spend was written at or after that time (cpp_snapshots.snapshot_at ≥ as-of time). At 8:05 the 8AM column is empty (the 7:10 run's H07 is partial); after the 8:10 run it fills. Orders alone never fill a column early. State in the result which timestamp you used and why.
- E: a past day whose stored hourly spend stops early (Incepxion Nov 17 has H00..H21 only): the columns after the last stored bucket show orders, "—" for Adspent and CPP, and the 291 amber "no hourly spend" marking.
- E: tooltip and click modal speak "as of": title "Nov 17, 2026 · as of 8AM", line "00:00 – 7:59 AM"; the per-page table and the modal totals use the same bucket and order cut, and still equal the cell (extend the 292 tests).
- E: "Meta hourly spend as of <time>" and the amber "not updated since" note keep working.

**AS-2 Full width (P1).** When the Hourly totals view is shown, the page content uses the full window width (a small side gutter, about 16 px), not the 1280 px box; the 24 columns share the extra width. Buckets and Hourly keep their current width. At 1280 px it still fits with no page scroll (292's rule); state the width math for 1280 and 1920 px. Prefer doing this inside the page itself rather than in the shared layout, so the Incepxion cherry-pick does not depend on 295's layout; if the layout must change, explain in the result how it behaves on a site without 295.

**AS-3 Hourly sync runs in Upload History (P1).** The Upload History table on `/ads_manager/payment/upload` also lists the hourly sync runs (`upload_logs` type `meta_ads_hourly`), newest first, mixed with the payment uploads, with a column (or label) telling the two apart ("Payment upload" / "Meta hourly sync") and a simple filter (All / Payment uploads / Hourly sync). For a run show: started (Manila), finished, status (done green, partial or skipped amber, failed red), rows, and the error (first line in the row, the full text on hover or expand, escaped).
- H: a `done` run and a `partial` run with "Graph error 200: API access blocked" both show; the blocked one is amber with the error visible.
- E: the list keeps the page's current limit or pagination; the hourly runs (24 a day) must not push all payment uploads out of view when the filter is All: pick a rule (for example a separate count per type, or pagination) and record the ruling.
- N: a user without access to the page sees nothing new (same middleware and access check as the page).

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Columns mean "as of h:00", first column 1AM, last 12AM | The owner compares them with the Buckets snapshots, which are "as of" times |
| Values come from Meta's stored hourly breakdown, not a snapshot frozen at fetch time | Meta's hourly data can be backfilled and corrected for any past day; a frozen copy keeps Meta's partial numbers |
| A column fills only after the run that covers its full hour | No more partial-hour cells with a too-low CPP |
| Hourly runs appear in Upload History on the payment upload page | That is where the owner looked for them |
| Keep the Buckets and per-page Hourly views as they are | The owner still uses both |

## Constraints
- Blade/Alpine in `resources/views/ads_manager/cpp_timeline.blade.php` and the payment upload view, same style as the existing views; keep every comment in the language and style of the file around it.
- Reuse the 291 shared code (HourlyTimeline) for spend and orders; no second copy of the rules. No migration, no new schedule, no change to what the sync stores.
- The Hourly and Hourly-totals JSON endpoints may change only where AS-1 needs it; say what changed. Read-only pages.
- Tests in `tests/Feature/CPP` (extend 291/292 tests and their Node run of the view script where useful) and a feature test for the Upload History page. The full suite adds no new failures compared with base 2940b58b (list the base failures).
- Keep the change small enough to cherry-pick onto the other site's line: touch only the files this spec needs.

## Threat model and risk
Untrusted: query strings (dates, filter) and the stored error text (escape it). Read-only. Risk: a column that disagrees with Buckets or the modal; an error text breaking the page. Risk tier: low.

## Allowed commands
composer install; vendor/bin/phpunit and php artisan test (with filters) via Herd PHP; php artisan route:list; php artisan view:cache; node (to run the view script in tests); git add, git commit, git push -u origin feat/297-hourly-asof-width-sync-runs.

## Done when
1. Tests for AS-1..AS-3 pass, including: 8AM equals old hour 07 and 12AM equals old hour 23, today's column empty until the covering run, missing-spend columns show "—" with the marking, modal totals equal the cell, Upload History shows done and blocked hourly runs with the filter, error text escaped; the 291/292 tests still pass (updated where labels changed, each listed); the full suite adds no new failures compared with base 2940b58b (list them).
2. Evidence in the result: a rendered header and one row (HTML excerpt or Node snapshot), the modal title, the Upload History rows, and the width math for 1280 and 1920 px.
3. The branch is pushed; the result has a merge-danger section (what ships, how to revert, what the other site's cherry-pick needs).

## Out of scope
The Buckets and per-page Hourly views; the Meta sync itself; the Ads Manager report's Upload Logs; backfilling data; deploy (the reviewer does it).

## Budget
Small-to-medium job. At most 2 attempts at the same failing step, then stop and report.
