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
- Adspent: whole pesos with thousands separators (`42,101`). Orders: thousands separators (`1,144`). CPP: two decimals (`36.80`, `1,234.50`).
- A figure has room for 7 characters (7 × 6 px of 10 px monospace = 42 px within the ~42.8 px a cell keeps after its padding and border). A formatted figure longer than 7 characters falls back to the 291 compact form for that value only: Adspent from ₱999,999.50 up (`1.02M`), CPP from ₱10,000 up and CPP from ₱1,000 that would read 8 characters (`1.2k`), orders from 10,000,000 (never in practice).
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

## Evidence per done-when item

## Story changes

## Rulings

## Merge danger
