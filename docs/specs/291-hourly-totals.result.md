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

## Evidence per done-when item

## Story changes

## Rulings

## Merge danger
