# 291 — CPP timeline: hourly totals per day (rows = date, columns = hour)

## Why
On `/ads_manager/cpp/timeline` the owner reads the **Buckets** view: rows = date, columns = the 10AM / 3PM / 7PM / 11:59PM snapshots, each cell the day's total Adspent, Orders and CPP. Spec 288 added an **Hourly** view, but it is one day at a time with rows per page and shows only CPP. The owner wants the Buckets layout with every hour instead of four buckets: rows = date, columns = 00–23, each cell the day's **total Adspent, Orders and CPP** up to that hour, filled automatically by the hourly sync that already runs (`meta-ads:hourly --scheduled`, every hour at :10 Manila). His words: "yung dito pa din, hindi yung per page … hourly adspent, orders, cpp, tapos magaauto sync system natin."

## Stories and cases
**HT-1 Hourly totals view (P1).** A third button in the View toggle, "Hourly totals", next to Buckets and Hourly. It shows one row per date in the chosen range (the same start/end inputs and default as Buckets: the last 7 days ending today, newest date on top as Buckets orders them, or the Buckets order if that differs; say which) and one column per hour 00–23 (Asia/Manila). Each cell shows three small stacked figures: Adspent, Orders, CPP, all **cumulative from 00:00 to the end of that hour**, for all pages with ad spend that day: exactly the figures of the Hourly view's **Total** row for that date (same rules, same sources: spend from cpp_snapshots H00..H23, orders from macro_output by order time for pages with ad spend that day).
- H: a date with hourly spend and orders → every hour up to 23 shows Adspent, Orders and CPP; the cell at hour 15 equals the Hourly view's Total row at 15 for that date (test pins equality on seeded data).
- H: hover a cell → tooltip with the exact spend (2 decimals), orders, CPP, CPI, CPM and what that hour added (spend and orders), like the Hourly view's tooltip.
- H: CPP colours use the Hourly view's existing CPP colour classes.
- E: today → hours after the current Manila hour are empty; the current hour shows what is stored so far.
- E: a date with orders but no hourly spend stored (for example when Meta's hourly breakdown is blocked or not yet synced) → the cell shows the orders, "—" for Adspent and CPP, with the Hourly view's amber "no hourly spend yet" marking; never a CPP computed from missing spend.
- E: a date with no data at all → the row still shows, empty, so missing days are visible.
- N: a range over 31 days → clamped to 31 days ending at `end` (or refused with a clear message; pick one and record the ruling).

**HT-2 Fits and reads at a glance (P1).** All 24 hour columns fit the page width with **no horizontal scroll** at desktop widths ≥ 1280 px, with the date column on the left. Figures are compact (for example spend as "12.4k", orders as an integer, CPP rounded to a whole peso); exact values are in the tooltip. A row label shows the date and weekday (for example "Nov 18 Wed"). The last filled hour of each day (23 for past days) should be easy to find, e.g. a slightly stronger border or the EOD column already being 23. Under 1280 px the table may scroll inside its own box, never the page.

**HT-3 Auto-sync visible (P1).** The view refreshes its data every few minutes while open (no page reload), and shows "Meta hourly spend as of <time>" (latest stored H-bucket snapshot in the range), like the Hourly view. Nothing new is scheduled: `meta-ads:hourly --scheduled` already stores spend every hour; orders are live from macro_output.
- E: the latest hourly spend for today is older than 2 hours → a short amber note "hourly spend not updated since <time>", so a blocked or failing sync is visible.

## Decisions already made (don't reopen)
| Decision | Reason |
|---|---|
| Cells are cumulative from 00:00, like the Buckets snapshots and the Hourly Total row | The owner compares against Buckets: hour 15 must mean the same as the 3PM bucket |
| Same figures as the Hourly view's Total row, computed by shared code (extract it; no second copy of the rules) | Two views of the same day must never disagree |
| Keep Buckets and Hourly as they are | The owner still uses both |
| Lean schema: no new table, no migration expected | Data already exists in cpp_snapshots and macro_output |

## Constraints
- One new JSON endpoint (for example `GET /ads_manager/cpp/timeline/hourly-totals?start&end`) with the same middleware and `checkAccess()` as the other timeline routes; the Hourly endpoint's output stays byte-for-byte the same for the same data (test).
- Performance on the bigger site (macro_output has many rows per day): aggregate orders in SQL on MySQL (GROUP BY date, page, hour over the indexed `ts_date` range, the way timelineData already parses TIMESTAMP), not by loading every order row into PHP for a 31-day range; keep a PHP path for sqlite/pgsql if needed. One query for spend over the range (cpp_snapshots H00..H23), one for ad-spend pages over the range, one for orders. List the exact MySQL SQL of each query in the result so the reviewer can EXPLAIN it on the server.
- Blade/Alpine in `resources/views/ads_manager/cpp_timeline.blade.php`, same style as the existing views; remember the chosen view as the page already does (if it does).
- Keep every comment in the language and style of the file around it.
- Feature tests in the existing CPP timeline test area; the full suite adds no new failures compared with base ebab078e (list the base failures).

## Threat model and risk
Untrusted: the `start`/`end` query strings (validate as Y-m-d, clamp the range). No writes. Risk: a slow query on the bigger site's macro_output, or numbers that differ from the Hourly Total row. Risk tier: low-medium (read-only page, big table).

## Allowed commands
composer install; php artisan test (with filters) and vendor/bin/phpunit via Herd PHP; php artisan route:list; php artisan view:cache (to check the Blade compiles); git add, git commit, git push -u origin feat/291-hourly-totals.

## Done when
1. Tests for HT-1..HT-3 pass, including: equality with the Hourly Total row on seeded data, today's future hours empty, orders-without-spend shows "—" and no CPP, the range clamp, invalid dates rejected, and the Hourly endpoint's output unchanged. The full suite adds no new failures compared with base ebab078e (list the base failures).
2. The page renders with seeded data: evidence (HTML excerpt of the toggle and one row, or a screenshot from a local run) in the result, plus the width reasoning for no horizontal scroll at 1280 px.
3. The result lists each query's MySQL SQL and the index it should use.
4. The branch is pushed; the result has evidence and a merge-danger section (what ships, migrations if any, worker restarts if any, how to revert).

## Out of scope
Per-page rows in this view; changing the Buckets or Hourly views; fixing Meta's hourly block on the bigger site; new scheduled jobs; deploy (the reviewer does it).

## Budget
Small-to-medium job. At most 2 attempts at the same failing step, then stop and report.
