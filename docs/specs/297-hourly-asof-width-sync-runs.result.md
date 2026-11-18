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

## Evidence per done-when item

## Story changes

## Rulings

## Merge danger
