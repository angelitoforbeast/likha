# Brief 030: the actual net profit page (/summary/overall) loads fast, shows how settled each number is, and is CEO only

## Why

`/summary/overall` is the owner's actual net profit page: real delivered and returned results from the J&T data, not the projection of `/owner/private`. Only the owner uses it. It is very slow. Measured on production for the default 30 days, all pages: the page shell loads, then one `GET /summary/overall/data` runs about 30 statements (`SummaryOverallController`): a temporary table `_jnt_agg` is built from `from_jnts` (894k rows) for about 60k waybills (~0.9 s, then an `ALTER ... ADD PRIMARY KEY`), and then seven separate queries each join the same ~62k `macro_output` rows to it again (shipped/delivered/returned counts, actual shipping, gross delivered, all COD, delay, delivered items, per-date counts), each 0.4-0.7 s, each redoing the per-row string work (TRIM/LOWER/REPLACE on PAGE, STATUS, ITEM, the COD cast). The ads query uses `DATE(day)` and scans all of `ads_manager_reports`. About 10 `Schema::hasColumn` lookups run per request. Nothing is cached. In the browser both the limited and the full table are built (`x-show`), each cell with 13-28 `x-if` templates. Total about 6-8 s of database time per load. Profit values also keep changing for days: held orders get shipped later, in-transit orders become delivered or returned, so the owner needs to see how final each number is.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| Stay a live query: no new stored summary table | The owner wants the page to always read the current state; history is kept outside the app |
| Every number the page shows today stays the same for the same data and filters | It is his money page; speed must not change results |
| No change to any existing profit formula | Same reason |
| CEO only (all routes of this page) | Only the owner uses it, and it shows profit |
| No horizontal scroll at 1366 px | Owner's standing rule for his pages |

## Part A: same numbers, much faster (commit on its own)

- Build the per-waybill J&T aggregate once, with the primary key in the CREATE (`CREATE TEMPORARY TABLE _jnt_agg (PRIMARY KEY (wb)) AS ...`, MySQL) or an equivalent that needs no ALTER; `from_jnts.submission_time` is already a datetime, so drop the `STR_TO_DATE` coalesces; collapse the duplicate case variants of the status LIKEs where the collation already ignores case (check the column collation first; keep behaviour identical).
- Compute every measure that joins `macro_output` to the J&T aggregate in one pass (or at most two: one per page, one per date-and-page), using conditional aggregation, instead of the seven separate joins. Normalise PAGE, STATUS, ITEM and COD once per row (a derived table) rather than in every query. Keep the Postgres branch working if the controller has one.
- Ads: filter `day BETWEEN ? AND ?` (no function on the column) so the date index can be used; keep the page filter result identical.
- Resolve the column names once per process (or cache them), not with `Schema::hasColumn` on every request.
- The page-name list in `index`: a query that can use an index, same list.
- Fix the no-date call: `/data` without dates must not produce invalid SQL (default to the last 30 days like the page does).
- Front end: render only the table the viewer can see (`x-if`, not `x-show`); reduce per-cell template work where it is simple (e.g. one formatter per column instead of a chain of `x-if`). No visual change.
- In the result, give the statement count per `/data` call before and after (log the queries in a test or count them), for both All Pages and single-page mode.

## Part B: how settled each number is (commit on its own)

- First, write in the result exactly how the page computes Net Profit and Projected Net Profit today (formula, which orders each counts: proceed, hold = proceed not yet shipped, in transit, delivered, returned, for return).
- Add per row (per page in All Pages mode, per day in single-page mode) and in the TOTAL row: **Realized** = the existing actual Net Profit; **Expected** = the existing Projected Net Profit; **Pending** = Expected − Realized; **Settled %** = (delivered + returned + for return) ÷ proceed, shown 0-100%; a small **final** mark when nothing is on hold or in transit for that row. If the existing Projected Net Profit does not account for held orders, say so in the result and stop short of changing it.
- These go into the limited and the full column sets, fitted without horizontal scroll (group them in one cell if needed). The `/data` JSON carries them as fields so a reader can take them without the page.

## Part C: CEO only (commit on its own)

- `GET /summary/overall`, `/summary/overall/data` and the dead `/summary/overall/daily` route (no controller method today): CEO only, 403 for everyone else; remove the dead route or point it to a clean 404. Use the same CEO check the app already uses elsewhere (trimmed, case-insensitive role "CEO").

## Tests

Part A: on fixture data covering delivered, returned, for return, in transit, hold, cannot proceed, ODZ, two pages, two items, two dates and a COD with a comma, the `/data` JSON of the new code equals the old code's JSON field by field in All Pages and single-page mode (keep the old query path in the test as the reference, or capture its output before you change it); statement count is lower; no-date call works. Part B: Realized, Expected, Pending, Settled % and final on fixtures. Part C: CEO 200, Marketing OIC and staff 403 on all routes. Red first where natural. The whole suite must pass except the known `ExampleTest` and `ImportStartTest`; name any other.

## Out of scope

A stored summary table; changing any profit formula; MySQL server settings (handled separately); other pages; `/owner/private`.

## Final message

Commits; statement counts before and after; how the joins were combined; the existing Net Profit and Projected formulas as found; the new fields; test lines and the suite result; what the owner should check after release (no browser here); anything the brief got wrong.
