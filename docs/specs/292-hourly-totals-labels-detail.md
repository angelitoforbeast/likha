# 292 — Hourly totals: one label column, readable figures, and a detail on click

## Why
Spec 291 added the "Hourly totals" view on `/ads_manager/cpp/timeline` (rows = date, columns = hour 00–23, each cell three stacked figures: Adspent, Orders, CPP, cumulative from 00:00). The owner reads the Buckets view, whose cells say "Adspent ₱42,100.51 / Orders 1,144 / CPP ₱36.80", and clicking a Buckets cell opens a modal "Nov 17, 2026 · 10AM Snapshot" with the per-page table. He wants the hourly view to read the same way: the labels written once in one column (not in every cell, so the 24 hours still fit), figures that read like money and counts rather than "12.4k", and a click on any hour cell that opens the same kind of modal for that hour.

## Stories and cases
**HL-1 One label column (P1).** Between the Date column and hour 00, a narrow column shows the three labels once per date row, aligned with the three lines of every cell: "Adspent", "Orders", "CPP". The cells keep the three stacked figures without labels.
- H: each date row shows the labels Adspent / Orders / CPP once, on the same lines as the figures.
- E: all 24 hour columns still fit with no horizontal page scroll at ≥ 1280 px (state the width math); under that, only the table's own box scrolls (as in 291).

**HL-2 Readable figures (P1).** In the cells: Adspent as whole pesos with thousands separators (e.g. "42,101"; the ₱ sign may sit in the label "Adspent (₱)"), Orders with thousands separators ("1,144"), CPP with two decimals ("36.80"). If a value cannot fit its cell at 1280 px (e.g. adspent ≥ 1,000,000), fall back to the compact form for that value only ("1.02M") and say so in the result. The 291 rules stay: "—" for spend and CPP when no hourly spend is stored, the amber marking, empty future hours, CPP colours, tooltip with exact figures.

**HL-3 Detail on click (P1).** Clicking a filled hour cell opens a modal in the same style as the Buckets detail modal (reuse its markup/classes where possible):
- Title: "<date> · <hour label>", e.g. "Nov 17, 2026 · 9AM", with a short line under it saying the figures are cumulative from 00:00 to the end of that hour (e.g. "00:00 – 9:59 AM").
- Totals at the top, exact: "Adspent ₱42,100.51 · Orders 1,144 · CPP ₱36.80" (the cell's own figures, unrounded to 2 decimals).
- A per-page table for that date and hour: Page, Adspent, Orders, CPP (and, if cheap, what that hour added), sortable like the Buckets modal, with a total row equal to the cell. Data: the existing Hourly endpoint for that date (its page rows at that hour); no new heavy query. Pages with orders but no hourly spend show "—" for Adspent and CPP, as in the Hourly view.
- H: click Nov 17 hour 09 → modal "Nov 17, 2026 · 9AM", totals equal to the cell, per-page rows that add up to them (test).
- E: empty cell (future hour, or no data) → no modal.
- E: today's current hour → the modal shows what is stored so far, like the cell.
- E: Esc, the close button and a click outside close it, as in the Buckets modal.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Labels once per row in their own column, not in every cell | The 24 hours must still fit on one screen without horizontal scroll |
| The modal reuses the Hourly endpoint and the 291 shared code | The modal, the cell and the Hourly view must never disagree |
| Keep the Buckets and Hourly views as they are | The owner still uses both |

## Constraints
- Blade/Alpine in `resources/views/ads_manager/cpp_timeline.blade.php`, same style as the existing views; keep every comment in the language and style of the file around it.
- No migration, no new schedule. If a route is needed, same middleware and `checkAccess()` as the other timeline routes.
- Tests in `tests/Feature/CPP` (extend the 291 tests and their Node run of the view script where useful); the Hourly and Hourly-totals endpoints' outputs stay the same unless the result explains a change. The full suite adds no new failures compared with base dea0c621 (list the base failures).

## Threat model and risk
Untrusted: the date/hour sent when a cell is clicked (validate as today's rules: Y-m-d, hour 0–23). Read-only. Risk: a modal that disagrees with its cell, or the table no longer fitting. Risk tier: low.

## Allowed commands
composer install; vendor/bin/phpunit and php artisan test (with filters) via Herd PHP; php artisan route:list; php artisan view:cache; node (to run the view script in tests, as 291 did); git add, git commit, git push -u origin feat/292-hourly-totals-labels-detail.

## Done when
1. Tests for HL-1..HL-3 pass, including: label column present once per row, number formats (and the compact fallback), modal totals equal the cell, per-page rows add up to the totals, no modal on empty cells; the 291 tests still pass; the full suite adds no new failures compared with base dea0c621 (list them).
2. Evidence of the rendered row and of the modal (HTML excerpt or a Node-rendered snapshot) and the width math for 1280 px, in the result.
3. The branch is pushed; the result has a merge-danger section (what ships, how to revert).

## Out of scope
Changing what the figures mean (still cumulative from 00:00); the Buckets and Hourly views; the Meta sync; deploy (the reviewer does it).

## Budget
Small job. At most 2 attempts at the same failing step, then stop and report.
