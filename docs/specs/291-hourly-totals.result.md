# 291 result: CPP timeline hourly totals per day

Base: live ebab078e. Branch `feat/291-hourly-totals`.

## Plan

### Shared code (one copy of the rules)
- New `App\Services\MetaAds\HourlyTimeline`, extracted from `CPPController::timelineHourly()` without changing its rules: per date, pages = pages with hourly spend rows (cpp_snapshots H00..H23) plus pages with report spend > 0 that day (ads_manager_reports); spend, CPI and CPM per page carried forward from the latest stored H-row; orders per page and hour from macro_output by order time, only for those pages; the same cell shape and the same Total row.
- It works on a list of dates with **three queries for the whole range**: spend (cpp_snapshots H00..H23, `snapshot_date BETWEEN`), ad-spend pages (ads_manager_reports `day BETWEEN`, GROUP BY day, page_name HAVING spend > 0), orders.
- **Orders on MySQL are aggregated in SQL**: GROUP BY ts_date, page, hour over the indexed `ts_date` range, with the hour and the date part cut from TIMESTAMP by the same rule the PHP parser uses (`H:i d-m-Y`, checked with REGEXP). Pages are grouped raw and normalised in PHP (lower case, no whitespace), so the page rule stays one copy. sqlite/pgsql (and a table without ts_date) keep the PHP parse path. A test runs both paths on the same data (sqlite with REGEXP and SUBSTRING_INDEX registered) and checks they agree.
- `timelineHourly()` calls the service for its one date and returns the same JSON. A golden test recorded **before** the refactor pins its output byte for byte.

### HT-1..HT-3 endpoint
- `GET /ads_manager/cpp/timeline/hourly-totals?start&end` in the same CPP route group, same `checkAccess()`.
- `start`/`end` must be valid `Y-m-d` (strict, round-trip checked) or empty; anything else is 422 with a message. Defaults as Buckets: end = today (Manila), start = end − 6; swapped if reversed (as Buckets does). A range over 31 days is **clamped** to the 31 days ending at `end`, with `clamped: true` and a note in the view.
- Output: dates newest first (as Buckets orders them), each with a label ("Nov 18 Wed"), `last_hour` and the 24 Total cells; `stored_at` (latest H-row snapshot in the range); for today in the range, `today_stored_at` and `stale` (more than 2 hours old, or nothing stored today after 02:00).

### View
- Third toggle button "Hourly totals". Start/End inputs bound to the same range as Buckets, Apply.
- `table-layout: fixed; width: 100%` with a 76 px date column and 24 hour columns (about 46 px each in the 1184 px box at 1280 px). Each cell: three stacked lines (spend compact "12.4k", orders integer, CPP whole peso); "—" for spend and CPP when only orders are known, with the Hourly view's amber edge; CPP colour classes and the Hourly tooltip (`hourTip`). The last filled hour of each row gets a stronger edge. Under 1280 px the table scrolls inside its own wrapper.
- Auto-refresh every 5 minutes while the view is open (stopped when another view is chosen); "Meta hourly spend as of …" and the amber "hourly spend not updated since …" note.
- The page does not remember the chosen view today (no storage), so this view is not remembered either.

### Tests (tests/Feature/CPP/CppHourlyTotalsTest.php)
Golden Hourly output; Total equality per date and hour on seeded data; today's future hours empty; orders without spend; empty date rows; clamp; invalid dates 422; access 404; SQL path = PHP path; the page renders the toggle, 24 headers and the fixed-layout CSS; a Node run of the view script (cell text, classes, tooltip, refresh timer, stale note).

## What shipped

No migration, no new table, no config change, nothing new scheduled. One new GET route in the existing CPP group (`web`, `auth`, `allowed_ip`, plus `checkAccess()`).

**Shared code: `app/Services/MetaAds/HourlyTimeline.php`**
- The rules of `CPPController::timelineHourly()` moved here unchanged: the pages of a day, the spend carry-forward from the latest stored H-row, orders by order time for those pages only, the cell shape and the Total row (including CPI/CPM recovered from the stored CPI/CPM).
- `days(array $dates)` works on any list of dates with three reads for the whole range (spend, ad-spend pages, orders).
- Orders on MySQL with `ts_date`: counted in SQL per (ts_date, page, hour, date part of TIMESTAMP), only rows whose TIMESTAMP has the `H:i d-m-Y` shape (REGEXP). The page is grouped as stored and normalised in PHP (lower case, no whitespace), as before. Other drivers, or a table without `ts_date`, use the PHP parse path (the old code, extended to a range).
- `timelineHourly()` now calls `days([$date])` and returns the same JSON.

**Endpoint: `GET /ads_manager/cpp/timeline/hourly-totals?start=&end=` (`CPPController::timelineHourlyTotals`)**
- `start` / `end`: empty = default; otherwise strict `Y-m-d` (regex plus a round-trip through `createFromFormat('!Y-m-d')`), else 422 `{"ok":false,"message":"Invalid start date: use YYYY-MM-DD."}`. An array parameter is also 422.
- Default: end = today (Manila), start = end − 6, a reversed range is swapped: the same as the bucket grid.
- More than 31 days: clamped to the 31 days ending at `end`, `clamped: true`.
- Output: `start`, `end`, `clamped`, `max_days`, `hours`, `stored_at` (latest H-row `snapshot_at` in the range), `today` (when in range), `today_stored_at`, `stale`, and `days` newest first: `{date, label ("Nov 18 Wed"), last_hour, total[24]}`. Each `total` is exactly the Hourly view's `total` for that date.
- `stale`: today is in the range and today's latest stored H-row is more than 2 hours old, or nothing is stored today and it is past 02:00.

**View: `resources/views/ads_manager/cpp_timeline.blade.php`**
- Third toggle button "Hourly totals". Start/End inputs bound to the same `start`/`end` as Buckets, plus Apply.
- Table `tl-hour tl-tot`: the Hourly table's fixed layout (`table-layout: fixed; width: 100%`), a 76 px date column and 24 hour columns, headers `00`…`23` rendered on the server, `min-width: 1060px` in a wrapper with `overflow-x: auto`.
- Cell: three stacked lines. Spend compact (`850`, `12.4k`, `245k`, `1.23M`), orders as an integer, CPP rounded to a whole peso. When only orders are known: `—` for spend and CPP.
- Colours: the Hourly view's `hourClass()` (CPP tones, `tl-hour-orders` amber edge, `tl-hour-empty`). The last filled hour of each row gets `tl-tot-last` (2 px dark right border).
- Tooltip `totTip()`: the Hourly tooltip's lines (cumulative CPP, spend with 2 decimals, orders, CPI, CPM, "This hour: +spend, +orders", "Orders only: …"), labelled "all pages". For an orders-only cell the CPP line reads "— (no hourly spend yet)".
- "Meta hourly spend as of <time>" and, when `stale`, an amber note "⚠ Hourly spend not updated since <time>" (or "⚠ No hourly spend stored yet today").
- Auto-refresh: `setInterval` every 5 min while the view is open (skipped while the browser tab is hidden), cleared when another view is chosen. No page reload.
- Range clamp note: "Showing the 31 days ending <date> (the longest range this view shows)". A 422 message shows in red.

**Commits on `feat/291-hourly-totals`**
- `d189de3c` docs: spec and result skeleton
- `effad512` docs: plan
- `ff9b7f2e` test: pin the hourly timeline output before the refactor (fixtures recorded from base code)
- `e02506f0` feat: shared class + endpoint
- `33c7a152` feat: view + tests
- plus this result commit

## Evidence per done-when item

### 1. Tests
`tests/Feature/CPP/CppHourlyTotalsTest.php`, 13 tests, all pass:
- `test_the_hourly_endpoint_output_is_byte_for_byte_unchanged`: `assertSame` of the raw response body against 4 fixtures (`tests/Feature/CPP/fixtures/291-hourly-golden-*.json`) recorded from the base code on the same seed and committed before the refactor (`ff9b7f2e`, test green on base). The seed covers spend carried over an hour without a row, an "Unknown ad" page with hourly spend but no report spend, a page spelled differently in each table (`Page  Q` / `page q` / `PageQ`), an orders-only page, an organic page, a page with report spend 0, a one-digit hour, a TIMESTAMP with spaces around it, hour 25, a TIMESTAMP whose date differs from `ts_date`, garbage, an empty day, and today at 10:20.
- `test_HT1_each_row_is_the_hourly_total_row_of_that_date`: for every date, `days[i].total` is identical (`assertSame`) to `/hourly?date=` `total`, and `last_hour` matches. Hour 15 of the seeded day is pinned: ₱330, 7 orders, CPP 47.14.
- `test_HT1_orders_without_hourly_spend_show_the_orders_and_no_spend_or_cpp`: a day with report spend and orders but no H-rows gives `spend` null, `cpp` null, `cpi`/`cpm` null, `source` "orders" in every cell from the first order on. Also covers the hours before the first stored hour of a normal day.
- `test_HT1_a_date_without_data_still_has_its_row`: 5 rows for 5 dates, the empty date has 24 nulls.
- `test_HT1_today_leaves_the_hours_after_the_current_manila_hour_empty`: at 10:20, `last_hour` 10; hour 10 shows what is stored so far (₱250, 5 orders, CPP 50); hours 11–23 are null; equal to the Hourly Total.
- `test_HT1_the_default_range_is_the_7_days_ending_today_like_the_buckets`: no params gives 2026-11-04..2026-11-10, newest first, "Nov 10 Tue". `end` only gives end − 6; a reversed range is swapped.
- `test_HT1_a_range_over_31_days_is_clamped_to_the_31_days_ending_at_end`: 2026-09-01..2026-11-09 becomes 2026-10-10..2026-11-09, 31 rows, `clamped` true. Exactly 31 days is not clamped.
- `test_HT1_invalid_dates_are_refused`: `2026-13-01`, `abc`, `2026-02-30`, `2026-1-5`, an SQL-ish string, `2026-11-09T00:00`, `start[]=` all give 422 with `ok: false`.
- `test_HT1_the_route_has_the_timeline_permission`: Data Encoder gets 404.
- `test_HT3_stale_hourly_spend_today_is_flagged`: stored at 10:10. At 12:05 not stale; at 12:15 stale. A range without today is never stale. With nothing stored: not stale at 01:30, stale at 02:30.
- `test_the_mysql_orders_query_gives_the_same_figures_as_the_php_path`: the SQL path runs on sqlite with REGEXP and SUBSTRING_INDEX registered as PHP functions (MySQL's semantics). `days()` over 4 dates gives an identical array to the PHP path. Exactly 3 reads hit the 3 tables for the whole range, and the orders query has the GROUP BY and the `ts_date` range.
- `test_HT2_the_page_renders_the_hourly_totals_view_that_fits_without_page_scroll`: the toggle has exactly Buckets / Hourly / Hourly totals. The totals table has 24 `<th class="tl-tot-h">` `00`…`23`, 1 date `<col>` and 24 hour `<col>`s. The CSS is checked: fixed layout, wrapper `overflow-x: auto`, `min-width: 1060px`, date column 76 px. The width arithmetic is asserted.
- `test_HT_the_view_script_draws_the_cells_and_refreshes_every_5_minutes`: the page's own `cppTimeline()` runs in Node (TZ Asia/Manila) and checks:
  - the fetch URL;
  - one timer of 300000 ms, whose tick refetches;
  - the timer is cleared when leaving the view;
  - compact spend values;
  - classes: amber orders-only, CPP tones, `tl-tot-last` on the last filled hour (10 today, 23 yesterday), empty after now;
  - the exact tooltip text for a meta cell and for an orders-only cell;
  - the stale note "⚠ Hourly spend not updated since Nov 9, 8:10 AM".

**Folders:** `tests/Feature/CPP` OK (53 tests, 365 assertions), with the 288 `CppHourlyTimelineTest` unchanged and green. `tests/Feature/MetaAds` OK (283 tests, 1421 assertions).

**Full suite:** SUITE_PLACEHOLDER

`php artisan test` prints a `file_get_contents(.env)` warning per test in this worktree because it has no `.env` (known, see 241/286). The suites were run with `vendor/bin/phpunit` under Herd PHP 8.4.16. `php artisan view:cache` compiles the Blade, and `route:list -v` shows the new route with `web`, `auth`, `allowed_ip`, the same as `/hourly`.

### 2. Render with seeded data, and the width
A local render: a throwaway test (not committed) seeded 7 days × 6 pages, with now = 2026-11-18 15:40. 2026-11-15 has orders but no hourly spend, and 2026-11-14 has no data. It saved the page HTML and the endpoint JSON.
- The built-in browser pane only shows local files as static snapshots, and a local web server is not on this spec's allowed command list. So the row below is the template's expressions run through the page's own script functions (`totClass`, `totTip`, `compact`) on that JSON, in Node. It was not a browser screenshot.

Toggle, as served:
```html
<button type="button" @click="setView('buckets')" class="text-sm px-3 py-1 rounded border" …>Buckets</button>
<button type="button" @click="setView('hourly')" class="text-sm px-3 py-1 rounded border" …>Hourly</button>
<button type="button" @click="setView('totals')" class="text-sm px-3 py-1 rounded border" …>Hourly totals</button>
```
Header, as served (24 of these): `<th class="tl-tot-h" title="00:00–00:59">00</th>` … `<th class="tl-tot-h" title="00:00–23:59">23</th>`.

One row (today, 2026-11-18 at 15:40; `last_hour` 15, hours 16–23 empty). The first cell, the last filled cell, and one empty cell:
```html
<tr>
  <th class="tl-hour-name" title="2026-11-18">Nov 18 Wed</th>
  <td class="cpp-r" title="Nov 18 Wed · all pages · 00:00–00:59 (cumulative)&#10;CPP: ₱493.86&#10;Spend: ₱8,889.49&#10;Orders: 18&#10;CPI (per 1k impressions): ₱118.65&#10;CPM (per message): ₱20.99&#10;This hour: +₱8,889.49 spend, +18 orders"><div><div>8.9k</div><div>18</div><div>494</div></div></td>
  …
  <td class="cpp-r tl-tot-last" title="Nov 18 Wed · all pages · 00:00–15:59 (cumulative)&#10;CPP: ₱253.04&#10;Spend: ₱180,671.65&#10;Orders: 714&#10;…&#10;This hour: +₱0.00 spend, +0 orders"><div><div>181k</div><div>714</div><div>253</div></div></td>
  <td class="tl-hour-empty" title="Nov 18 Wed · 16:00–16:59 · no data"></td>
  …
</tr>
```
In this seed the latest stored hour today is H14 (stored 14:10), so hour 15, the current hour, carries H14's spend with the orders so far.

The orders-only day (2026-11-15), first cell:
```html
<td class="cpp-n tl-hour-orders" title="Nov 15 Sun · all pages · 00:00–00:59 (cumulative)&#10;CPP: — (no hourly spend yet)&#10;Spend: no hourly spend stored yet&#10;Orders: 7&#10;…&#10;Orders only: Meta hourly spend not stored yet"><div><div>—</div><div>7</div><div>—</div></div></td>
```
2026-11-14 (no data): row present, 24 empty cells.

**Width at 1280 px (no horizontal scroll):**
- The page is `max-w-7xl` (1280 px) with `lg:px-8`, and the card is `p-4` with a 1 px border. At a 1280 px window with a 17 px vertical scroll bar, the box is 1280 − 17 − 64 − 32 − 2 = **1165 px**.
- The table is `table-layout: fixed; width: 100%`, so column widths come only from the `<colgroup>`, never from the content. The date column is 76 px, leaving 1089 px, about **45 px per hour**.
- The widest figure is 5 characters of 10 px monospace (`12.4k`, `1.23M`, `4812`), about 28 px plus 2 px padding. Text that does not fit is cut by `overflow: hidden; text-overflow: ellipsis; white-space: nowrap`.
- `min-width: 1060px` (76 + 24 × 41) is below 1165, so at ≥ 1280 px nothing scrolls.
- Below a window of about 1190 px, the box drops under 1060 and only `.tl-tot-wrap` (`overflow-x: auto`) scrolls, never the page.

### 3. SQL per query (MySQL, as Laravel builds it; `?` = bindings)
1. **Spend over the range** (cpp_snapshots H00..H23):
   ```sql
   select `snapshot_date`, `snapshot_bucket`, `page_name`, `page_key`, `amount_spent`, `cpi`, `cpm`, `snapshot_at`
   from `cpp_snapshots`
   where `snapshot_date` between ? and ?
     and `snapshot_bucket` in ('H00','H01','H02','H03','H04','H05','H06','H07','H08','H09','H10','H11','H12','H13','H14','H15','H16','H17','H18','H19','H20','H21','H22','H23')
   ```
   Index: `cpp_snap_date_bucket_idx (snapshot_date, snapshot_bucket)` (range), or the unique `cpp_snap_date_bucket_page_unique (snapshot_date, snapshot_bucket, page_name)`. Rows: about pages × 24 × days.
2. **Ad-spend pages over the range:**
   ```sql
   select day, page_name, SUM(CAST(REPLACE(REPLACE(REPLACE(COALESCE(amount_spent_php,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))) AS spent
   from `ads_manager_reports`
   where `day` between ? and ?
   group by `day`, `page_name`
   having SUM(CAST(REPLACE(REPLACE(REPLACE(COALESCE(amount_spent_php,''), '₱',''), ',', ''), ' ', '') AS DECIMAL(18,2))) > 0
   ```
   Index: `ads_report_day_campaign_adset_ad_unique (day, campaign_id, ad_set_id, ad_id)`, leftmost `day` range (the same index the old `where day = ?` used).
3. **Orders per (date, page, hour) over the range:**
   ```sql
   select ts_date AS d, `PAGE` AS pg, SUBSTRING_INDEX(TRIM(`TIMESTAMP`), ':', 1) AS hh, SUBSTRING_INDEX(TRIM(`TIMESTAMP`), ' ', -1) AS dmy, COUNT(*) AS n
   from `macro_output`
   where `ts_date` between ? and ?
     and TRIM(`TIMESTAMP`) REGEXP ?
   group by ts_date, `PAGE`, SUBSTRING_INDEX(TRIM(`TIMESTAMP`), ':', 1), SUBSTRING_INDEX(TRIM(`TIMESTAMP`), ' ', -1)
   ```
   The REGEXP binding is `^[0-9]{1,2}:[0-9]{2}[[:space:]]+[0-9]{2}-[0-9]{2}-[0-9]{4}$`. The page column is the first of `PAGE` / `page` / `page_name` / `Page` / `Page_Name` that exists (`PAGE` on both sites).
   - Index: `macro_output_ts_date_page_idx (ts_date, PAGE)` range (or `macro_output_ts_date_index`). The same `ts_date` range `timelineData()` already uses.
   - TIMESTAMP is read from the row (not covering). Expect `Using where; Using temporary` for the GROUP BY.
   - Result size is at most pages × 24 × days (for example 60 pages × 24 × 31 ≈ 45k groups, in practice far fewer), instead of every order row.
   - Suggested check on the server: `EXPLAIN` with `?` = `'2026-10-19','2026-11-18'` and the pattern above.

### 4. Branch and merge danger
Pushed: `git push -u origin feat/291-hourly-totals` (see the report for the final hash). The merge-danger section is below.

## Story changes
None. HT-1's "newest date on top as Buckets orders them" holds: the bucket grid builds its dates descending, and this view does the same.

## Rulings
1. **Range over 31 days: clamped**, not refused. The view keeps working when the shared Start/End came from a long Buckets preset (Last 30 Days is 30, This Year is longer). The JSON says `clamped: true`, and the view shows "Showing the 31 days ending <date>". The inputs are not rewritten, so going back to Buckets keeps the user's range.
2. **Invalid dates are refused** with 422 and a message. Empty means default. The 288 Hourly endpoint keeps its old behaviour (an invalid date falls back to today), because its output must not change.
3. **Remembering the view:** the page does not remember the chosen view today (no storage, no query parameter), so nothing was added. Buckets stays the default.
4. **Stale rule:** today in range and (latest H-row today older than 2 h, or none stored after 02:00). Without spend there are no H-rows, so a day with all ads off would also show the note after 02:00. That is the honest reading of "not updated".
5. **Orders-only tooltip:** the CPP line reads "— (no hourly spend yet)". The Hourly view's own tooltip says "no orders yet" there; it was left unchanged because Hourly is out of scope.
6. **Orders as an integer** without a thousands separator in the cell (`1022`), to stay within 5 characters. The tooltip uses the separator.
7. **MySQL SQL vs the old PHP parse** (also now used by the Hourly endpoint on MySQL; sqlite and pgsql keep the PHP path):
   - MySQL `TRIM` strips spaces only, where PHP `trim` also strips tabs and newlines. A TIMESTAMP with a leading tab or newline counted before and does not now.
   - Grouping by `PAGE` follows the column's collation: case and trailing spaces merge, which the normalised key merges anyway. Under an accent-insensitive collation, two pages differing only by an accent would fall into one group under one spelling.
   - Both cases are malformed data; on clean `H:i d-m-Y` values the figures are the same (test above).

## Merge danger
- **What ships:**
  - `app/Services/MetaAds/HourlyTimeline.php` (new);
  - `CPPController` (new constants `TOTALS_MAX_DAYS` and `TOTALS_STALE_HOURS`, `timelineHourly()` delegating, new `timelineHourlyTotals()`);
  - one route in `routes/web.php`;
  - the Blade view;
  - tests and fixtures.
- **Migrations:** none. **Schedule / workers:** nothing new; no queue worker restart needed (web-only change). A `php artisan route:cache` / `view:cache` refresh, if the deploy uses them.
- **Behaviour change on existing pages:**
  - Buckets: unchanged.
  - Hourly: same JSON. On MySQL its orders now come from the GROUP BY query instead of loading the day's rows, so it is lighter, with the edge cases in Ruling 7.
  - `setView()` now also starts or stops the totals timer.
- **Load:** each open totals view makes 3 queries per 5 minutes over at most 31 days. The orders query on the bigger site is the one to EXPLAIN (query 3).
- **Second site:** same code. Cherry-pick the 5 code/test commits (the docs commits optional). There are no site-specific paths.
- **Revert:** `git revert` the feat commits (`e02506f0`, `33c7a152`). There is no data to clean up.
