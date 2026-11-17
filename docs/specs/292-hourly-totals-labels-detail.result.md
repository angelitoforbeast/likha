# 292 result: Hourly totals, one label column, readable figures, detail on click

Base: live dea0c621. Branch `feat/292-hourly-totals-labels-detail`.

## Plan

View only (`resources/views/ads_manager/cpp_timeline.blade.php`): no new route, no controller change, no migration. The Hourly and Hourly-totals endpoints stay as they are.

### HL-1 Label column
- A new `<col class="tl-tot-lbl">` between the date column and hour 00, with a header cell, and in every date row a `<th class="tl-tot-lbls">` with three stacked lines "Adspent", "Orders", "CPP" using the same line height as the cell figures, so each label sits on its figure's line.
- The cells keep the three figures, with no labels.
- The date cell shows the date on one line and the weekday under it ("Nov 18" / "Wed"), from the endpoint's `label`, so the date column can shrink to pay for the label column.
- Width at 1280 px: box 1165 px (as in 291: 1280 − 17 scroll bar − 64 page padding − 32 card padding − 2 border). Date 46 + labels 44 = 90, leaving 1075 for 24 hours, about 44.8 px each. The table's `min-width` becomes 46 + 44 + 24 × 44 = 1146 px (≤ 1165), so nothing scrolls at ≥ 1280 px; below that only `.tl-tot-wrap` scrolls, as in 291.

### HL-2 Readable figures
- Adspent: whole pesos with thousands separators (`42,101`). Orders: thousands separators (`1,144`). CPP: two decimals (`36.80`, `999.99`).
- A figure has room for 7 characters (at most 7 × 6 px of 10 px monospace = 42 px; a cell keeps 43.8 px with no side padding and its 1 px border, see Evidence 2). A formatted figure longer than 7 characters falls back to the 291 compact form for that value only: Adspent from ₱999,999.50 up (`1.02M`), CPP from ₱1,000 up (`1,000.00` is 8 characters, so `1.0k`, `3.2k`), orders from 10,000,000 (never in practice).
- The 291 rules stay: "—" for spend and CPP without hourly spend, the amber edge, empty future hours, CPP colours, the tooltip.

### HL-3 Detail on click
- A filled cell (`c` not null) gets `cursor: pointer` and opens a second modal with the Buckets modal's markup and classes (`tl-modal-backdrop`, `tl-modal`, `tl-modal-head`, `tl-detail-table`, the sortable header pattern with `CppSort`). Empty cells have no handler, so no modal.
- Title "Nov 17, 2026 · 9AM"; under it "Cumulative 00:00 – 9:59 AM · N pages".
- Data: the existing Hourly endpoint `GET /ads_manager/cpp/timeline/hourly?date=` for that date; rows = each page whose cell at that hour is filled; totals = that endpoint's `total[h]`. That `total` is the same array as the totals row (pinned by 291), and on open the clicked row's `total` is replaced with the fresh one, so the modal and its cell show the same figures even when orders moved since the last 5-minute refresh.
- Totals line at the top: "Adspent ₱42,100.51 · Orders 1,144 · CPP ₱36.80" (2 decimals). Table: Page, Adspent, Orders, CPP, + Adspent (this hour), + Orders (this hour), sortable; a TOTAL row = the cell. Pages with orders only show "—" for Adspent and CPP.
- The clicked date and hour are checked in the script (Y-m-d, integer 0–23) before the fetch, and the reply's `date` must equal the requested date (the Hourly endpoint falls back to today on a bad date, and its output must not change).
- Close: the close button, a click on the backdrop (`@click.self`), and Esc (`@keydown.escape.window` on the open modal).
- Copy button, reusing `copyDetail()` with the table id as a parameter (default unchanged).

### Tests
Extend `tests/Feature/CPP/CppHourlyTotalsTest.php` (and adjust the 291 CSS assertions that pin the old column widths): label column once per row in the served template; the width arithmetic; number formats and the compact fallback in Node; modal title, sub line, totals equal to the cell, per-page rows that add up to the totals, the `total` refreshed into the row, no modal for empty cells, invalid date/hour refused without a fetch, Esc/close/backdrop wiring in the served HTML; today's current hour. Then the CPP folder and the full suite against base dea0c621.

## What shipped

One view file and its tests. No route, controller, service, migration, config or schedule change; the Hourly (`/hourly`) and Hourly-totals (`/hourly-totals`) endpoints are untouched, so their outputs are the same (the 291 golden test still passes byte for byte).

**`resources/views/ads_manager/cpp_timeline.blade.php`**
- HL-1: a label column `<col class="tl-tot-lbl">` (44 px) between the date and hour 00. Every date row has one `<th class="tl-tot-lbls">` with three lines "Adspent", "Orders", "CPP" (titles "Adspent (₱)", "CPP (₱)"), at the cells' font size and line height (10 px, 1.2), so each label sits on its figure's line. The date column is 46 px with the date on two lines ("Nov 18" over "Wed", from the endpoint's `label`). The table `min-width` is 1146 px (was 1060), hour cells have no side padding (`2px 0`). The empty-range row spans 26 columns.
- HL-2: cell figures by `figSpend` / `figOrders` / `figCpp`: Adspent whole pesos with separators (`42,101`), Orders with separators (`1,144`), CPP 2 decimals (`36.80`). A figure over 7 characters takes the 291 `compact()` form for that value only (`3.55M`, `1.02M`, `3.2k`). "—" for spend and CPP without hourly spend; colours, the amber edge, the last-hour edge, empty future hours and the tooltip are the 291 ones (`totClass`, `totTip` unchanged).
- HL-3: a filled cell has `tl-tot-click` (pointer, blue outline on hover) and `@click="c && openHour(row, h)"`; an empty cell has no handler. `openHour` checks the date (`^\d{4}-\d{2}-\d{2}$`) and the hour (integer 0–23) and that the cell is filled, then fetches the existing `GET /ads_manager/cpp/timeline/hourly?date=`. A reply whose `date` is not the one asked for is an error ("Hindi ma-load ang pages ng oras na ito."), since that endpoint falls back to today on a bad date. The reply's `total` (the same array as the row, one code path) replaces the row's cells, so the cell and the modal agree even when orders came in since the last refresh.
- The modal (`#tl-hm`) uses the snapshot modal's markup and classes (`tl-modal-backdrop`, `tl-modal`, `tl-modal-head`, `tl-modal-title`, `tl-modal-sub`, `tl-modal-close`, `tl-detail-table`, the `CppSort` header pattern with `aria-sort` ▼/▲, the 📋 Copy button):
  - Title "Nov 17, 2026 · 9AM", sub line "Cumulative 00:00 – 9:59 AM · 6 pages".
  - Totals line "Adspent ₱48,797.06 · Orders 494 · CPP ₱98.78" (the cell, 2 decimals).
  - Table: Page, Adspent, Orders, CPP, + Adspent this hour, + Orders this hour (the Hourly cells' `add_spend` / `add_orders`), one row per page whose Hourly cell at that hour is filled. Orders-only pages show "—" for Adspent, CPP and + Adspent, on an amber row with the tooltip "Orders only: Meta hourly spend not stored yet". TOTAL row = the cell.
  - Close: ✕ Close, a click on the backdrop (`@click.self`), Esc (`@keydown.escape.window`, bound only while the modal is open).
- `copyDetail(id = 'tlDetailTable')`: the snapshot modal calls it as before; the hour modal passes `'tlHourTable'`.

**`tests/Feature/CPP/CppHourlyTotalsTest.php`**: 5 new tests, the 291 width assertions updated, `js()` takes extra globals.

**Commits on `feat/292-hourly-totals-labels-detail`**
- `60cdbe1d` docs: spec and result skeleton
- `edaef738` docs: plan
- `49c19361` feat: view + tests
- the final commit: this result file

## Evidence per done-when item

### 1. Tests
`tests/Feature/CPP/CppHourlyTotalsTest.php`, 18 tests (13 from 291, 5 new), all pass:
- `test_HL1_the_label_column_and_the_width_at_1280_px`: the colgroup is date, labels, 24 hours; the header is Date, the label header, 00…; the row template has exactly one `tl-tot-lbls` cell with Adspent / Orders / CPP in that order, the hour cells hold `figSpend(c)` / `figOrders(c)` / `figCpp(c)` and no label text; `colspan="26"`; the label and cell CSS share font size and line height; the width arithmetic (below).
- `test_HL2_the_cell_figures_read_like_money_and_counts` (Node, the page's own script):
  - `42100.51 / 1144 / 36.8` → `42,101 / 1,144 / 36.80`;
  - `850.4 / 3 / 283.47` → `850 / 3 / 283.47`;
  - no hourly spend → `— / 7 / —`;
  - the longest that still fit: `999,999 / 99,999 / 999.99`;
  - fallback for that value only: `1023456.78 / 1234567 / 1234.56` → `1.02M / 1.23M / 1.2k`, and `12345678.9 / 5 / 12345.67` → `12.35M / 5 / 12.3k`;
  - every figure ≤ 7 characters;
  - hour labels `12AM 9AM 11AM 12PM 3PM 11PM`, date lines `Nov 18 / Wed`.
- `test_HL3_a_click_on_an_hour_cell_opens_its_detail` (Node, seeded 291 data, the fetch answers the real `/hourly` JSON):
  - one fetch, `/ads_manager/cpp/timeline/hourly?date=2026-11-09`;
  - title `Nov 9, 2026 · 9AM`, sub `Cumulative 00:00 – 9:59 AM`;
  - modal total equals the cell (`assertEquals`), line `Adspent ₱140.00 · Orders 4 · CPP ₱35.00`;
  - rows `Page P1 ₱100.00 3 ₱33.33 ₱100.00 2` and `page q ₱40.00 1 ₱40.00 ₱0.00 0`, TOTAL `₱140.00 4 ₱35.00 ₱100.00 2`;
  - at 11 (with an orders-only page): page spend, orders and orders-added sum to the cell, `Page P2 — 1 — — 1`, `Unknown ad ₱30.00 0 —`;
  - sort by Adspent ▼ then ▲; close;
  - hour 23 of a past day opens `11PM`, `Cumulative 00:00 – 11:59 PM`;
  - an empty cell (hour 0), hour 24, `'9'`, `9.5`, an SQL-ish date, and a row without cells: no modal, **no fetch**;
  - the row takes the fetched Total row.
- `test_HL3_todays_current_hour_shows_what_is_stored_so_far`: at 10:20, hour 10 opens `Nov 9, 2026 · 10AM` with the cell (₱250, 5 orders, CPP 50) and page rows summing to it; hour 11 (future, empty) opens nothing.
- `test_HL3_the_modal_markup_reuses_the_buckets_modal_and_closes_three_ways`: served HTML has `@click="c && openHour(row, h)"`, the backdrop with `@click.self="closeHour()"` and `@keydown.escape.window="closeHour()"` and the snapshot modal's classes, the ✕ Close button, the six sortable headers in order, and a TOTAL row.
- The 291 tests pass. One 291 assertion block was changed: `test_HT2_…` pinned `min-width: 1060px` and the 76 px date column; it now pins 1146 px and 46 px (the HL-1 test holds the new arithmetic). The 291 class assertions (`cpp-n tl-hour-orders`, `cpp-r tl-tot-last`, …) are unchanged because `totClass()` is unchanged; the click class is added in the template.

**Folders:** `tests/Feature/CPP` OK (58 tests, 465 assertions).

FULLSUITE

### 2. Rendered row, modal, width at 1280 px
A local render: a throwaway test (not committed, deleted) seeded 6 pages over 2026-11-12..18 with now = 2026-11-18 15:40 (hourly spend stored up to H14 today), 2026-11-15 with orders but no hourly spend, 2026-11-14 with no data, and 2026-11-12 with spend over ₱1M. It saved the served page and the two endpoints' JSON.
- The built-in browser pane shows local files only as static snapshots (no script), and a web server is not on the allowed commands. So the page's own script (`cppTimeline()` taken from the served HTML, with `CppSort`) ran in Node (TZ Asia/Manila) on that JSON, and the template's expressions were printed. It was not a browser screenshot.

One row (today; hour 0, hour 9, the current hour 15, the first empty hour; tooltips cut here):
```html
<tr>
  <th class="tl-hour-name" title="2026-11-18 (Nov 18 Wed)"><div>Nov 18</div><div>Wed</div></th>
  <th class="tl-tot-lbls"><div title="Adspent (₱)">Adspent</div><div>Orders</div><div title="CPP (₱)">CPP</div></th>
  <td class="cpp-r tl-tot-click" title="Nov 18 Wed · all pages · 00:00–00:59 (cumulative)&#10;CPP: ₱89.95&#10;Spend: ₱5,217.06…"><div><div>5,217</div><div>58</div><div>89.95</div></div></td>
  …
  <td class="cpp-r tl-tot-click" title="Nov 18 Wed · all pages · 00:00–09:59 (cumulative)&#10;CPP: ₱96.44&#10;Spend: ₱51,694.06…"><div><div>51,694</div><div>536</div><div>96.44</div></div></td>
  …
  <td class="cpp-r tl-tot-last tl-tot-click" title="Nov 18 Wed · all pages · 00:00–15:59 (cumulative)&#10;CPP: ₱93.52&#10;Spend: ₱76,596.06…"><div><div>76,596</div><div>819</div><div>93.52</div></div></td>
  <td class="tl-hour-empty" title="Nov 18 Wed · 16:00–16:59 · no data"></td>
  …
</tr>
```
Other rows:
- 2026-11-17, hour 23: `119,956 / 1,222 / 98.16`.
- 2026-11-12 (big day): hour 4 `755,583 / 257 / 2.9k` (CPP ₱2,940.01 falls back); hour 23 `3.55M / 1,266 / 2.8k` (spend ₱3,552,303… falls back).
- 2026-11-15 (orders, no hourly spend): `— / 55 / —` with `cpp-n tl-hour-orders`.
- 2026-11-14: 24 empty cells.
- The widest figure in the range was `100,622` (7 characters).

Modal, 2026-11-17 hour 09 (cell `48,797 / 494 / 98.78`; cell and modal total identical):
```
Nov 17, 2026 · 9AM
Cumulative 00:00 – 9:59 AM · 6 pages
Adspent ₱48,797.06 · Orders 494 · CPP ₱98.78
Page          | Adspent    | Orders | CPP     | + Adspent this hour | + Orders this hour
Alpha Shop    | ₱4,245.51  | 75     | ₱56.61  | ₱426.00             | 10
Beta Store    | ₱6,484.51  | 81     | ₱80.06  | ₱748.00             | 6
Delta Deals   | ₱8,544.51  | 86     | ₱99.35  | ₱618.00             | 11
Echo Mart     | ₱10,794.51 | 88     | ₱122.66 | ₱1,015.00           | 6
Foxtrot Finds | ₱11,192.51 | 80     | ₱139.91 | ₱1,016.00           | 12
Gamma PH      | ₱7,535.51  | 84     | ₱89.71  | ₱500.00             | 8
TOTAL         | ₱48,797.06 | 494    | ₱98.78  | ₱4,323.00           | 53
```
- Sum of the pages: ₱48,797.06 and 494 orders, the same as the cell.
- Sorted by Adspent ▼: Foxtrot Finds, Echo Mart, Delta Deals, Gamma PH, Beta Store, Alpha Shop.
- Today's current hour (2026-11-18 · 3PM, "Cumulative 00:00 – 3:59 PM") shows ₱76,596.06 · 819 · ₱93.52, the stored H14 spend with the orders so far, equal to its cell. "+ Adspent this hour" is ₱0.00 because H15 is not stored yet.

**Width at 1280 px:**
- The box is the same as in 291: 1280 − 17 (scroll bar) − 64 (`lg:px-8` page padding) − 32 (card `p-4`) − 2 (border) = **1165 px**.
- The table is `table-layout: fixed; width: 100%`, so widths come from the `<colgroup>` only. Date 46 + labels 44 = 90 px, leaving 1075 px for 24 hours: **44.8 px each**.
- A cell has no side padding and a 1 px collapsed border, so it keeps **43.8 px**. A figure is at most 7 characters of 10 px monospace: 7 × 6 px = 42 px for SFMono / Menlo (0.6 em), 38.5 px for Consolas (0.55 em). Anything longer becomes compact. 42 < 43.8, so no figure is cut.
- Labels: "Adspent" at 10 px semibold sans is about 38–40 px, inside 44 − 2 padding − 1 border = 41 px. The date "Nov 18" at 11 px is about 36 px, inside 46 − 3 − 1 = 42 px.
- `min-width: 1146px` (46 + 44 + 24 × 44) ≤ 1165, so at ≥ 1280 px nothing scrolls. Below about a 1261 px window (1146 + 115), only `.tl-tot-wrap` (`overflow-x: auto`) scrolls, never the page (the layout also has `html, body { overflow-x: hidden }`).
- This is arithmetic from the CSS and font metrics, not a measurement in a browser (see the render note above).

### 3. Branch and merge danger
PUSHLINE

## Story changes
None.

## Rulings
1. **Esc:** the Buckets snapshot modal closes on ✕ and a backdrop click, but has no Esc handler today. The hour modal has all three (Esc as the spec lists). The Buckets modal was left as it is (out of scope).
2. **The date goes on two lines** ("Nov 18" over "Wed") to pay for the label column. The endpoint's `label` is unchanged; the view splits it.
3. **The fit limit is 7 characters for every figure**, one rule for the three lines. Adspent falls back from ₱999,999.50 (it would round to `1,000,000`). CPP falls back from ₱1,000 (`1,000.00` is 8 characters), e.g. `3.2k`. Orders would fall back from 10,000,000. The tooltip and the modal always show the exact figures.
4. **No ₱ in the cell or on the label line**: the ₱ would cost a character in every cell, and "Adspent (₱)" does not fit 44 px. The ₱ is in the label's tooltip ("Adspent (₱)", "CPP (₱)"), in the view's note ("Adspent (₱), Orders and CPP (₱)"), and in the modal.
5. **The modal refreshes its row.** The modal reads the Hourly endpoint, whose `total` is the totals row for that date (291, pinned by test). On open, that fresh `total` replaces the row's cells, so a cell drawn up to 5 minutes earlier cannot disagree with its modal.
6. **No new route.** The clicked date and hour are checked in the script. The Hourly endpoint keeps its own rule (a bad date falls back to today; its output must not change), and the script refuses a reply for another date.
7. **"+ Adspent this hour" / "+ Orders this hour"** are the Hourly cells' `add_spend` / `add_orders`, already in the reply (cheap). Both columns add up to the TOTAL row's (a page's spend never goes back to "none" once stored, and the Total row is the sum of the same page figures; to the centavo, up to rounding): at 09 in the test ₱100.00 + ₱0.00 = ₱100.00 and 2 + 0 = 2, in the render ₱4,323.00 and 53. A page whose first stored H-row is this hour adds its whole spend, and so does the TOTAL.
8. **Orders-only page rows** show "—" for Adspent, CPP and + Adspent, on an amber row (the Hourly view's amber meaning).

## Merge danger
- **What ships:** `resources/views/ads_manager/cpp_timeline.blade.php` and `tests/Feature/CPP/CppHourlyTotalsTest.php`. Nothing else.
- **Migrations, routes, schedule, workers:** none. A `php artisan view:cache` refresh if the deploy caches views.
- **Behaviour change on existing pages:**
  - Hourly totals: new layout (label column, two-line date, full figures), and cells open a modal.
  - Buckets: the snapshot modal is unchanged (its Copy calls `copyDetail()` with the same default table id).
  - Hourly: unchanged.
- **Load:** a click on a cell makes one `/hourly?date=` request, the same request the Hourly view makes for a day (3 queries for one date). There is no new heavy query.
- **Second site:** same code. Cherry-pick `49c19361` (the docs commits are optional). There are no site-specific paths.
- **Revert:** `git revert 49c19361`. There is no data to clean up.
