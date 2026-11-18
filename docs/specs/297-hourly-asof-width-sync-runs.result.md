# 297 result: Hourly totals "as of" columns, full width, hourly sync runs in Upload History

Base: live 2940b58b. Branch `feat/297-hourly-asof-width-sync-runs`.

## Plan

No migration, no new route, no schedule change, nothing new stored by the sync.

### AS-1 "As of" columns
- The 24 stored figures do not change: column "as of h:00" is exactly the 291 Total cell of hour h−1 (spend from the stored bucket H(h−1), orders before h:00). So the view keeps the 291 array `total[0..23]` and only relabels it: `total[k]` sits under "as of (k+1):00", 1AM … 11AM, 12PM, 1PM … 11PM, 12AM. The modal keeps reading the Hourly endpoint at index k (cells and Total row), so the per-page table, the modal totals and the cell stay one code path.
- Today's gate is computed in the shared class `HourlyTimeline` (no second copy): a new per-day `asof_hours` = how many "as of" columns are filled.
  - Past day: 24 (all columns; a bucket that was never stored shows the 291 orders-only cell: orders, "—", amber).
  - Future day: 0.
  - Today: the largest k with k:00 ≤ now **and** k:00 ≤ the day's stored hourly spend time (latest `cpp_snapshots.snapshot_at` of today's H-rows). Orders alone never open a column.
- `GET /ads_manager/cpp/timeline/hourly-totals`: each day row gets `asof_hours`. Nothing else changes. `GET /ads_manager/cpp/timeline/hourly`: unchanged (the 291 golden test stays byte for byte).
- View: header `1AM` … `12AM` with titles "as of 8AM: 00:00 – 7:59 AM"; a cell shows `total[k]` only when `k < asof_hours`; tooltip "Nov 17 Tue · all pages · as of 8AM" / "00:00 – 7:59 AM (cumulative)"; modal title "Nov 17, 2026 · as of 8AM", line "00:00 – 7:59 AM · N pages"; the last-hour edge goes on the last filled as-of column. The "+ … this hour" modal columns become "+ … last hour" (the hour before the as-of time, the bucket's own addition). The note above the table explains the columns.

### AS-2 Full width
- Inside the page only: the page root drops its own `max-w-7xl` while the Hourly totals view is shown (`tl-wide`), and one CSS rule in the page, `main > div:has(> #tl-root.tl-wide)`, lifts the layout's `max-w-7xl` box and sets a 16 px side gutter. The layout file is not touched, so the cherry-pick does not depend on 295 (the layout's content box is `main > div.max-w-7xl` before and after 295).
- The table's `min-width` goes from 1146 to 1122 px (43 px per hour: a cell keeps 42 px, exactly 7 figures × 6 px), so it fits a 1280 px window with 295's collapsed rail.

### AS-3 Hourly runs in Upload History
- `PaymentActivityController::create()` reads the payment uploads (latest 50, as today) and the hourly runs (`upload_logs` type `meta_ads_hourly`) with their own count, merged newest first. Filter `?show=all|payment|hourly` (anything else = all).
- Ruling to record: a separate count per type (payment uploads keep their 50; hourly runs: 24 under All, one day of runs, 72 under "Hourly sync"), so the hourly runs never push payment uploads out of the list.
- The table gets a Type column ("Payment upload" / "Meta hourly sync"), "Started" and "Finished" (Manila); hourly runs show status (done green, partial/skipped amber, failed red), rows written, and the error's first line with the full text on hover and in a `<details>` expand, Blade-escaped.
- Same route, middleware (`web`, `auth`, `allowed_ip`) and page access as today.

### Tests
- `tests/Feature/CPP/CppHourlyTotalsTest.php`: `asof_hours` per day (past 24, future 0, today gated by the stored time, orders alone never open a column); Node: header labels, column 8AM = old 07 and 12AM = old 23 for every figure, today's 8AM column empty at 8:05 and filled after the 8:10 run, a past day that stops at H21 shows orders/"—"/amber after it, tooltip and modal "as of" text, modal totals = the cell; the 291/292 assertions that pinned 00–23 labels, the old modal title/range, the min-width and the "+ … this hour" headers are updated (listed in the evidence).
- New `tests/Feature/PaymentActivity/UploadHistoryTest.php`: done and blocked hourly runs listed with the payment uploads, colours, the filter, the per-type counts, escaped error text, guests see nothing.
- Then the CPP folder and the full suite against base 2940b58b.

## What shipped

No migration, no new route, no schedule change, nothing new stored by the sync, the layout file untouched.

**AS-1, shared code `app/Services/MetaAds/HourlyTimeline.php`**
- `asOfHours($date, $storedAt)` and a new `asof_hours` per day in `days()`: how many "as of" columns are filled. A past day 24, a future day 0, today the largest k with k:00 ≤ now **and** k:00 ≤ the day's stored hourly spend time.
- **The timestamp used: the day's latest `cpp_snapshots.snapshot_at` among its H00..H23 rows** (the `stored_at` 291 already computed, and the `today_stored_at` the endpoint already returned). Why:
  - every scheduled run rewrites the day's H-rows with one `snapshot_at` (a complete run deletes and re-inserts the day), so the latest one is the time of the latest run that stored the day;
  - a run that stores at or after k:00 asked Meta for the day after hour k−1 had ended, so H(k−1) holds the full hour;
  - not the run's `upload_logs` row: a run can be logged and store nothing (blocked, partial with no rows), and a log row is not per day;
  - not the `snapshot_at` of the H(k−1) rows themselves: a bucket with no row has none, and the totals carry spend forward.
  - `snapshot_at` is written after the fetch, so a run that started before k:00 and stored after it would open column k with a partial hour. The scheduled run is at :10, so this does not happen in practice.
- On a **past day**, spend is no longer carried past the day's last stored hour (any page). When the sync stopped early, the later hours have orders only ("—", amber), as AS-1 E3 asks, never the spend of an earlier hour. Today is unchanged (its hours stop at the current hour, and the gate hides the open ones).
- `CPPController::timelineHourlyTotals()`: each day row gets `asof_hours`. Nothing else in that JSON changes.

**Endpoint changes (AS-1 needs):**
- `/hourly-totals`: adds `asof_hours` to each day row. `total`, `last_hour`, `stored_at`, `today_stored_at`, `stale` and the rest are unchanged for the same data, except for the past-day cut below.
- `/hourly` (and `/hourly-totals` `total`), past days only: the hours after the day's last stored H-bucket have `spend`/`cpp`/`cpi`/`cpm`/`add_spend` null and `source: "orders"` instead of the carried spend. A day stored up to H23 (every normal day) and today are byte-identical (the 291 golden test passes unchanged). The modal reads `/hourly`, so it shows the same cells as the totals view.

**AS-1/AS-2, view `resources/views/ads_manager/cpp_timeline.blade.php`**
- Header `1AM` … `11AM`, `12PM`, `1PM` … `11PM`, `12AM`, with titles `As of 8AM: 00:00 – 7:59 AM`, `As of 1AM: 00:00 – 0:59 AM`, `As of 12AM: 00:00 – 11:59 PM (end of day)`.
- `totCells(row)`: cell h (as of (h+1):00) is `total[h]` while `h < asof_hours`, else empty. The row template, the last-filled edge (`totClass`) and `openHour` all use it, so an empty column has no click.
- Tooltip: `Nov 17 Tue · all pages · as of 8AM` / `00:00 – 7:59 AM (cumulative)` / CPP, Spend, Orders, CPI, CPM / `Last hour (7:00 – 7:59 AM): +₱… spend, +N orders`. An empty column: `Nov 18 Wed · as of 9AM · no data`.
- Modal: title `Nov 17, 2026 · as of 8AM`, line `00:00 – 7:59 AM · N pages`. The headers "+ Adspent this hour" / "+ Orders this hour" became "+ Adspent last hour" / "+ Orders last hour", with the hover text "Adspent added 7:00 – 7:59 AM". Rows and TOTAL are bucket h of `/hourly`, the same as the cell.
- The note above the table explains the columns and that today's column fills after the covering run.
- AS-2: the page root has `id="tl-root"` and `:class="view === 'totals' ? 'tl-wide' : ''"`. The CSS has `#tl-root.tl-wide { max-width: none; }` and `main > div:has(> #tl-root.tl-wide) { max-width: none; padding-left: 16px; padding-right: 16px; }`. That rule targets the layout's content box (`<main>` > `div.mx-auto.max-w-7xl`, the same element before and after 295). `.tl-tot { min-width: 1122px; }` (was 1146).

**AS-3, `PaymentActivityController::create()` and `resources/views/ads_manager/payment/upload.blade.php`**
- Payment uploads (latest 50, as before) and hourly runs (type `meta_ads_hourly`: latest 24 under All, 72 under Hourly sync) each with their own query, merged newest first by `created_at` (the run's start). `?show=all|payment|hourly`; anything else, including an array, means All.
- Upload History card (`id="upload-history"`): filter links All / Payment uploads / Hourly sync (the chosen one dark, `aria-current`), a count note, and a new **Type** column ("Payment upload" grey / "Meta hourly sync" indigo).
- "Uploaded / Started" (hourly runs: `started_at`) and a new **Finished** column, both Manila.
- Status colours: Done green, Failed red, Partial / Skipped amber (new), Processing blue, Queued yellow.
- Hourly runs:
  - "Total Rows" = rows written (`processed_rows`), and "–" for the file counts;
  - the error's first line (at most 160 characters) under the status, amber (red when failed), with the full text in `title` on hover;
  - a `<details>` "Full error (N lines)" with the full text in a `<pre>` when there is more than one line or the line was cut;
  - all of it through Blade `{{ }}`, so escaped.
- The payment rows look as before, plus the Type and Finished cells.
- Same route, the same `web`, `auth` and `allowed_ip` middleware. The controller has no extra check, as before.

**Commits on `feat/297-hourly-asof-width-sync-runs`**
- `f53ca636` docs: spec 297 and result skeleton
- `74bf01a1` docs: plan (297)
- `b4443cfb` feat(cpp): Hourly totals columns as of 1AM-12AM, today's column waits for its covering run, full width (297)
- `bc3dc3c7` feat(payment): Upload History lists the Meta hourly sync runs, with a type filter (297)
- `6148658d` fix(cpp): the 1AM column reads 00:00 – 0:59 AM (297)
- the final commit: this result file

## Evidence per done-when item

### 1. Tests

`tests/Feature/CPP/CppHourlyTotalsTest.php` has 22 tests (18 from 291/292 and 4 new), all passing.

**New tests:**
- `test_AS1_column_8AM_is_the_old_hour_07_and_12AM_the_old_hour_23`
  - Nov 17 is stored by the real `HourlySpend::store()`: the 23:10 run, then the 00:10 closing run. Orders at 01:15, 06:40, 07:59, 08:00, 12:30 and 23:59. Now is Nov 18 15:00.
  - `asof_hours` = 24, and the endpoint `total` = `/hourly` `total`.
  - Served header: `[7]` = `8AM` with "As of 8AM: 00:00 – 7:59 AM", `[23]` = `12AM` "(end of day)", `[0]` = `1AM` "00:00 – 0:59 AM", `[11]` = `12PM`.
  - Node: `totCells(row)[7]` is `assertSame` to the old hour-07 cell (`/hourly` `total[7]`) for every key. Same for `[23]` = old 23.
  - Figures: ₱1,200 / 3 / ₱400. The 07:59 order counts as of 8AM; the 08:00 order counts as of 9AM.
  - Cell `1,200 / 3 / 400.00`; labels `1AM 8AM 12PM 1PM 12AM`; ranges `00:00 – 0:59 AM` … `00:00 – 11:59 PM`; the tooltip's first lines.
  - Modal: `Nov 17, 2026 · as of 8AM` / `00:00 – 7:59 AM` / last hour `7:00 – 7:59 AM`. Modal total `assertSame` the cell; the page rows add up to it; foot `₱1,200.00 3 ₱400.00 ₱150.00 1`.
  - `as of 12AM` / `00:00 – 11:59 PM` = cell 23.
- `test_AS1_today_a_column_fills_only_after_the_run_that_covers_its_full_hour` (runs through `HourlySpend::store()`):
  - 7:10 run (H07 = ten minutes). At 8:05: `today_stored_at` 07:10, `asof_hours` 7, filled cells 0–6, 8AM empty, the edge on 7AM, a click on 8AM opens nothing and fetches nothing.
  - 8:10 run. At 8:15: `asof_hours` 8, 8AM = `/hourly` `total[7]` = ₱1,200 / 2 / ₱600 (the full H07, not the ₱15 the 7:10 run saw); 9AM empty.
  - An order at 08:30 and no 9:10 run. At 9:30: still 8 columns; 9AM stays empty.
  - Nothing stored today: 0 columns, although `total[7]` has orders.
  - A past day 24, a future day 0.
- `test_AS1_a_past_day_that_stops_early_shows_orders_only_after_its_last_stored_bucket`
  - Nov 17 is stored by the 21:10 run up to H21; orders at 09:00, 21:30, 22:30 and 23:30.
  - 10PM = H21 (₱3,300, 2 orders, meta).
  - 11PM and 12AM: spend, CPP, CPI, CPM and add_spend null, orders 3 and 4, `source: orders`. `/hourly` page cells at 22 also have no spend, and `/hourly` `total` = the totals row.
  - Classes `cpp-r` / `cpp-n tl-hour-orders` / `cpp-n tl-hour-orders tl-tot-last`, figures `— 3 —` and `— 4 —`, and the tooltip "CPP: — (no hourly spend yet) … Orders only: Meta hourly spend not stored yet".
  - Modal `Nov 17, 2026 · as of 11PM`: total = the cell, row `Alpha — 3 — — 1`.
  - AS-1 E5 ("Meta hourly spend as of" and the stale note): `stored_at` `2026-11-17 21:10:00`, `stale` true, note "⚠ Hourly spend not updated since Nov 17, 9:10 PM". The 291 test `test_HT3_stale_hourly_spend_today_is_flagged` is unchanged and passes.
- `test_AS2_the_hourly_totals_view_uses_the_full_window_width`
  - The served root `<div class="max-w-7xl mx-auto" id="tl-root" :class="view === 'totals' ? 'tl-wide' : ''" …>` and both CSS rules.
  - A DOM parse of the served page: `#tl-root`'s parent is a `div` with `max-w-7xl`, whose parent is `<main>`. This is what the `:has()` rule targets.
  - The width math below.

**Changed 291/292 assertions, each because a label or the width changed:**
1. `test_HT2_the_page_renders_…`: headers `00`…`23` became `1AM`…`12AM`; `min-width: 1146px` became `1122px`; the box sum is now the full-width one (1197 ≥ 1122).
2. `test_HL1_the_label_column_and_the_width_at_1280_px`: the header regex `…>00</th>` became `…>1AM</th>`; the width arithmetic is now 1197 px box, 46.1 px per hour, min-width 1122 = 46 + 44 + 24 × 43.
3. `test_HL3_a_click_on_an_hour_cell_opens_its_detail`:
   - `Nov 9, 2026 · 9AM` / `Cumulative 00:00 – 9:59 AM` became `Nov 9, 2026 · as of 10AM` / `00:00 – 9:59 AM`;
   - `Nov 9, 2026 · 11PM` / `Cumulative 00:00 – 11:59 PM` became `· as of 12AM` / `00:00 – 11:59 PM`.
   - The figures, rows and fetch assertions are unchanged.
4. `test_HL3_todays_current_hour_shows_what_is_stored_so_far` was replaced by `test_HL3_todays_last_filled_column_opens_and_the_running_hour_does_not`. At 10:20, `asof_hours` 10. "As of 10AM" (cell 9) opens equal to its cell, ₱140 / 4 / ₱35, with the page rows summing to it. Cell 10 (the running hour, which 292 showed) opens nothing and fetches nothing.
5. `test_HL3_the_modal_markup_…`: headers `+ Adspent this hour` / `+ Orders this hour` became `last hour`.
6. `test_HT_the_view_script_draws_the_cells_…`:
   - the classes are read through `totCells()`;
   - the last-filled edge moved from cell 10 to cell 9 (at 10:20 cell 10 is not open);
   - cell 11 became cell 10 for "empty", and cell 8 was added;
   - the meta tooltip is now cell 9's "as of 10AM" text, and the empty one is `Nov 9 Mon · as of 11AM · no data`.
   - The compact values, fetch URL, timer, orders-only tooltip and stale note are unchanged.

The 291 golden test (`/hourly` byte for byte), the range, clamp, invalid-date, access, stale and SQL-path tests are unchanged and pass.

`tests/Feature/PaymentActivity/UploadHistoryTest.php` has 5 tests, all passing:
- `test_AS3_hourly_runs_show_with_the_payment_uploads_newest_first`:
  - a payment upload at 08:45, a `done` run at 09:10 (216 rows) and a `partial` run at 10:10 with two lines of "Graph error 200: API access blocked"; a `jnt` log is not shown;
  - order: hourly, hourly, payment; types `Meta hourly sync` ×2 and `Payment upload`; statuses `Partial` (amber `bg-amber-100 text-amber-800`), `Done` (green), `Done`;
  - the first error line in the row, the full text in `title`, `Full error (2 lines)`; rows `0 / 216 / 120`; started and finished `Nov 18, 2026 10:10 AM`.
- `test_AS3_the_filter_shows_one_type_and_the_status_colours`:
  - `?show=hourly` shows only runs: `Skipped` amber, `Failed` red with a red first line and no expand for one short line;
  - `?show=payment` shows only payment uploads, the chosen link marked;
  - `''`, `?show=all`, `?show=bogus`, `?show[]=hourly` and `?show=<script>` show all, with no `<script>` in the section.
- `test_AS3_the_hourly_runs_never_push_the_payment_uploads_out`:
  - with 55 payment uploads and 80 runs, All = 24 runs (the latest day, on top) + the 50 latest payment uploads;
  - Hourly sync = 72; Payment uploads = 50.
- `test_AS3_the_error_text_is_escaped`: an error with `<script>`, `<b>`, `<img onerror>`, quotes and `&`, and a run name with `<i>`, is escaped in the first line, the `title` and the `<pre>`. No raw tag reaches the page.
- `test_AS3_a_user_without_access_sees_nothing_new`: a guest is redirected to `/login` (also with `?show=hourly`). The route's middleware is still exactly `web, auth, allowed_ip`, under the name `ads_payment.upload`.

`tests/Feature/PaymentActivity` and `tests/Feature/AdAccount` (which also open this page): OK, 35 tests. `tests/Feature/CPP`: OK, 62 tests, 562 assertions. `php artisan view:cache` compiles both views.

FULL_SUITE_PLACEHOLDER

### 2. Rendered header, row, modal, Upload History rows, width
A throwaway test, not committed, rendered this local data:
- Nov 17 stored by the 00:10 closing run;
- Nov 18 stored by the 8:10 run;
- now Nov 18 08:45;
- the page's own `cppTimeline()` run in Node (TZ Asia/Manila) over the served script, printing the template's expressions.

There is no browser screenshot: the built-in browser pane shows local files only as static snapshots, and a web server is not on the allowed commands.

Header (served HTML, first columns and the last):
```html
<th class="tl-hour-name">Date</th>
<th class="tl-tot-lbls" title="Per cell, top to bottom: Adspent (₱), Orders, CPP (₱)"></th>
<th class="tl-tot-h" title="As of 1AM: 00:00 – 0:59 AM">1AM</th><th class="tl-tot-h" title="As of 2AM: 00:00 – 1:59 AM">2AM</th> …
<th class="tl-tot-h" title="As of 8AM: 00:00 – 7:59 AM">8AM</th> … <th class="tl-tot-h" title="As of 12PM: 00:00 – 11:59 AM">12PM</th> …
<th class="tl-tot-h" title="As of 12AM: 00:00 – 11:59 PM (end of day)">12AM</th>
```
Today's row at 08:45 (`asof_hours` 8: the 8:10 run stored H07 whole, so 8AM is filled and 9AM waits for the 9:10 run):
```html
<td class="cpp-r tl-tot-click" title="Nov 18 Wed · all pages · as of 7AM&#10;00:00 – 6:59 AM (cumulative)&#10;CPP: ₱1,050.00&#10;…"><div><div>1,050</div><div>1</div><div>1.1k</div></div></td>
<td class="cpp-r tl-tot-last tl-tot-click" title="Nov 18 Wed · all pages · as of 8AM&#10;00:00 – 7:59 AM (cumulative)&#10;CPP: ₱600.00&#10;Spend: ₱1,200.00&#10;Orders: 2&#10;CPI (per 1k impressions): ₱100.00&#10;CPM (per message): ₱21.43&#10;Last hour (7:00 – 7:59 AM): +₱150.00 spend, +1 orders"><div><div>1,200</div><div>2</div><div>600.00</div></div></td>
<td class="tl-hour-empty" title="Nov 18 Wed · as of 9AM · no data"></td>
…
<td class="tl-hour-empty" title="Nov 18 Wed · as of 12AM · no data"></td>
```
Nov 17 (past day), 8AM and 12AM:
```html
<td class="cpp-r tl-tot-click" title="Nov 17 Tue · all pages · as of 8AM&#10;00:00 – 7:59 AM (cumulative)&#10;CPP: ₱400.00&#10;Spend: ₱1,200.00&#10;Orders: 3&#10;…"><div><div>1,200</div><div>3</div><div>400.00</div></div></td>
<td class="cpp-r tl-tot-last tl-tot-click" title="Nov 17 Tue · all pages · as of 12AM&#10;00:00 – 11:59 PM (cumulative)&#10;CPP: ₱600.00&#10;Spend: ₱3,600.00&#10;Orders: 6&#10;…"><div><div>3,600</div><div>6</div><div>600.00</div></div></td>
```
The modal of Nov 17, 8AM (cell and TOTAL identical):
```
Nov 17, 2026 · as of 8AM
00:00 – 7:59 AM · 2 pages
Adspent ₱1,200.00 · Orders 3 · CPP ₱400.00
Page  | Adspent   | Orders | CPP     | + Adspent last hour | + Orders last hour
Alpha | ₱800.00   | 2      | ₱400.00 | ₱100.00             | 1
Beta  | ₱400.00   | 1      | ₱400.00 | ₱50.00              | 0
TOTAL | ₱1,200.00 | 3      | ₱400.00 | ₱150.00             | 1
```
Upload History, served HTML: a blocked run, a done run and a payment upload, newest first. Whitespace is trimmed and the five "–" count cells are cut.
```html
<tr class="border-b hover:bg-gray-50" data-log-type="hourly">
  <td …><span class="… bg-indigo-50 text-indigo-700">Meta hourly sync</span></td>
  <td class="px-3 py-2 border font-medium">Meta hourly 2026-11-18</td>
  <td class="px-3 py-2 border">
    <span class="… bg-amber-100 text-amber-800">Partial</span>
    <div class="mt-1 text-xs text-amber-800" title="act_111 2026-11-18: Graph error 200: API access blocked
act_222 2026-11-18: Graph error 200: API access blocked" data-error-first>act_111 2026-11-18: Graph error 200: API access blocked</div>
    <details class="mt-1 text-xs text-gray-700"><summary class="cursor-pointer text-gray-500">Full error (2 lines)</summary>
      <pre … data-error-full>act_111 2026-11-18: Graph error 200: API access blocked
act_222 2026-11-18: Graph error 200: API access blocked</pre></details>
  </td>
  <td class="px-3 py-2 border text-right" title="Rows written to the hourly CPP timeline">0</td> …
  <td …>Nov 18, 2026 10:10 AM</td><td …>Nov 18, 2026 10:10 AM</td><td …>4 seconds</td>
</tr>
<tr … data-log-type="hourly"> … Meta hourly sync | Meta hourly 2026-11-18 | <span class="… bg-green-100 text-green-700">Done</span> | 216 | – … | Nov 18, 2026 09:10 AM | Nov 18, 2026 09:10 AM | 4 seconds</tr>
<tr … data-log-type="payment"> … Payment upload | payments-nov.csv | Done | 120 | 120 | 120 | 0 | 0 | 0 | Nov 18, 2026 08:45 AM | Nov 18, 2026 08:45 AM | 4 seconds</tr>
```

**Width (the box the table gets):** the window minus a 17 px scroll bar, the sidebar, the 2 × 16 px gutter, the card's 2 × 16 px padding and its 2 px border. The table takes date 46 + labels 44 = 90 px; the 24 hours share the rest. Columns come from the `<colgroup>` (`table-layout: fixed`), and `min-width: 1122px` (43 px per hour: 42 px inside the border = 7 figures × 6 px, the 292 fit rule).

| Window | Layout | Box | Per hour | Fits? |
|---|---|---|---|---|
| 1280 | 295 sidebar open (248 px) | 1280 − 17 − 248 − 32 − 34 = **949** | (below min-width) | only `.tl-tot-wrap` scrolls, never the page (as before 297: the old box was 917) |
| 1280 | 295 rail (64 px) | 1280 − 17 − 64 − 32 − 34 = **1133** | 43.5 | yes (≥ 1122) |
| 1280 | no sidebar (a site without 295) | 1280 − 17 − 32 − 34 = **1197** | 46.1 | yes |
| 1920 | 295 sidebar open | 1920 − 17 − 248 − 32 − 34 = **1589** | 62.5 | yes |
| 1920 | 295 rail | **1773** | 70.1 | yes |
| 1920 | no sidebar | **1837** | 72.8 | yes |

- Before 297 the box never grew past 1280 − 64 (`lg:px-8`) − 34 = 1182 px at any window width (the `max-w-7xl` cap), so at 1920 px the 24 hours had (1182 − 90) / 24 = 45.5 px each; now 62.5–72.8 px.
- 292's "fits at 1280 px with no page scroll" was computed without a sidebar; 295's open sidebar already made it scroll inside its box at 1280. With 297 it fits at 1280 with the rail or without a sidebar, and never scrolls the page (`.tl-tot-wrap { overflow-x: auto }`, `html, body { overflow-x: hidden }` in the layout).
- Buckets and Hourly keep `max-w-7xl` (`tl-wide` is only bound while `view === 'totals'`).
- `:has()` needs Chrome/Edge 105+, Safari 15.4+ or Firefox 121+. In an older browser the layout box stays at 1280 px (the 292 width) and nothing breaks.
- This is arithmetic from the CSS, not a browser measurement.

### 3. Branch and merge danger
Pushed to `origin/feat/297-hourly-asof-width-sync-runs` (`git push -u`); the last commit is this result file. The merge-danger section is below.

## Story changes
None.

## Rulings
1. **Gate timestamp:** the day's latest `cpp_snapshots.snapshot_at` of its H-rows (see What shipped for why).
2. **The gate applies to today only**, as AS-1 E2 says. A past day shows all 24 columns. AS-1 E3 needs this: Incepxion's Nov 17 (yesterday on Nov 18) must show orders with "—" after its last stored bucket, not empty columns.
3. **No spend is carried past a past day's last stored hour** (E3). This touches the shared rule, so the per-page Hourly view of such a day shows the same orders-only cells. One copy of the rules, so the modal still equals the cell. Today and fully stored days are unchanged (golden test).
4. **Upload History counts per type:** payment uploads keep their 50. Hourly runs show 24 under All (one day, the 12-in-a-row block fits) and 72 under Hourly sync (three days). Under All they sit on top when newer, but they never cut a payment upload from the list.
5. **Filter parameter `show`**: whitelisted (`payment`, `hourly`), anything else is All. It is never echoed back.
6. **Hourly run row counts:** "Total Rows" = rows written (`processed_rows`), the other count columns "–" (a run has no file).
7. **"+ … last hour"** replaces "+ … this hour" in the modal: under "as of 8AM" the bucket's own addition is 7:00–7:59.
8. **1AM's range reads `00:00 – 0:59 AM`** (not "12:59 AM"), as the spec writes it. Hour spans in "Last hour (12:00 – 12:59 AM)" keep the 12-hour clock.
9. **min-width 1146 → 1122 px** (43 px per hour, exactly the 42 px a 7-character figure needs inside the border), so the table fits a 1280 px window with 295's rail.

**For the reviewer to decide:**
- **Yesterday's 12AM between 00:00 and the 00:10 run.** Yesterday is a past day, so its 12AM column shows H23 as the 23:10 run wrote it (about ten minutes of hour 23) for those ten minutes, until the 00:10 run closes the day.
  - The same holds for a past day whose sync stopped mid-day: its last stored bucket (Incepxion Nov 17 H21, written by the 21:10 run) is shown as stored. Only the buckets after it become orders-only.
  - Gating past days too would make Nov 17's 10PM–12AM empty instead of the orders-only E3 asks for, so it was left as the spec reads.
- **A day with no hourly spend at all today** (all ads off, or every run blocked) shows an empty row today. Orders alone never open a column. The amber "No hourly spend stored yet today" note explains it after 02:00.

## Merge danger
- **What ships:**
  - `app/Services/MetaAds/HourlyTimeline.php` (`asOfHours`, `asof_hours`, the past-day spend cut);
  - `app/Http/Controllers/CPPController.php` (one field per day row, docblock);
  - `resources/views/ads_manager/cpp_timeline.blade.php`;
  - `app/Http/Controllers/PaymentActivityController.php` (`create()` takes the Request, three constants);
  - `resources/views/ads_manager/payment/upload.blade.php`;
  - tests: `tests/Feature/CPP/CppHourlyTotalsTest.php`, the new `tests/Feature/PaymentActivity/UploadHistoryTest.php`.
- **Migrations, routes, schedule, workers, config:** none. Run `php artisan view:cache` if the deploy caches views. No worker restart (web only).
- **Behaviour change:**
  - Hourly totals: new labels, today's columns wait for the covering run (the running hour no longer shows), full width.
  - Hourly view and `/hourly`: only a past day whose stored hours stop before 23 changes, with orders-only after the last stored hour.
  - Buckets: unchanged.
  - Payment upload page: Upload History has hourly runs, a filter, Type and Finished columns.
- **Load:** the payment page makes one more small query (`upload_logs` by type, newest 24 or 72; the `type,status` index covers the type filter). The timeline queries are unchanged.
- **Revert:** `git revert 6148658d bc3dc3c7 b4443cfb`. There is no data to clean up.
- **The other site's cherry-pick:**
  - Cherry-pick `b4443cfb`, `bc3dc3c7` and `6148658d` (the docs commits are optional).
  - None of the five code files changed on this line between 292 (`49c19361`) and base `2940b58b`, so on a line with 291 and 292 they should apply as they do here.
  - The layout is not touched. The full-width rule targets `main > div.max-w-7xl`, which is the content box of the layout without 295 too (checked against the pre-295 layout at `dea0c621`).
  - The new test extends `OwnerPrivateTestCase` and uses only migrations older than 293–296.
  - If that site's `upload.blade.php` lacks the "Ad Account ID" button block, the conflict would be outside the replaced Upload History section and easy to resolve.
