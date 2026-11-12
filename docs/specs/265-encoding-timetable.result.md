# Result 265: Encoding timetable

Branch `feat/265-encoding-timetable` from `db70b9c1`. Risk tier kept: low-medium (read-only page on live tables).

## What changed

| File | Change |
|---|---|
| `app/Http/Controllers/Encoder/EncoderTimetableController.php` | New. `index()` checks `NavAccess::allows(role, '/encoder/timetable')` (403 otherwise), reads the range, runs six SELECTs, builds the rows, gaps, per-page breakdown and summary. |
| `resources/views/encoder/timetable.blade.php` | New. `<x-layout>` page like `/encoder/summary`: date filter, summary cards, the table, and a `<details>` per date for the per-page and per-waybill-filter lists. No JS, no new assets. |
| `routes/web.php` | `GET /encoder/timetable` → `encoder.timetable`, next to `/encoder/summary` inside the existing `web, auth, allowed_ip` group (guests go to `/login`). |
| `database/migrations/2026_11_24_100000_add_encoding_timetable_nav_link.php` | New data migration, a copy of the spec 243 Storage one: key `encoding_timetable`, label `Encoding Timetable`, `/encoder/timetable`, `fa-solid fa-clock`, `encoder/timetable*`, sort order max + 1; visibility CEO 1, Marketing 1, Marketing - OIC 1, Data Encoder 0, Data Encoder - OIC 0. Idempotent; `down()` removes the link and its rows. |
| `tests/Feature/EncoderTimetable/*` | New: base case, page tests (S1), access tests (S2), performance tests (S3). |

No new table, column or index. No `npm run build` (the view uses the Tailwind classes nearby pages use).

## How the page reads the data

- Range: `date_from` / `date_to` (order dates). Default: the 14 order dates up to yesterday. More than 62 days is cut to the last 62 up to `date_to`, with a message. A reversed range is swapped.
- Encoding per order date: start = first, finish = last Checker 1 event (validate, edit, status; source `checker_1`) on that date's orders; orders = distinct orders touched; "huli" = the user of the last event.
- J&T: per order date and page from `filters.date` / `filters.page`; finish = latest `finished_at` of runs with status `finished` or `done`; `done/runs`; any run not finished is flagged (failed/stopped counted separately).
- Waybill: per order date (`date`) and `filter_by` + `filter_value`; finish = latest `finished_at` of `finished` runs (a failed run also gets a `finished_at`, which is not a ready file); unfinished or failed runs are flagged.
- Excel: the latest `downloaded_macro_output_logs` row whose `timestamp` is the order date.
- Times: `H:i` when on order date + 1, otherwise `M j H:i` (S1.4). Gaps: signed minutes encoding → J&T → waybill.
- Summary: per step, each day's finish is measured in minutes from 00:00 of order date + 1 (so a finish two days later counts as later, not earlier); average and the worst day; a day more than 30 minutes over the average is red.
- "wala nang record": a date with no encoding rows that is before the day of the oldest `staff_events` row (pruned after 90 days). Other empty cells show "—".

## The queries (MySQL, as built)

1. Encoding, grouped in SQL (one row per order date and page):

```sql
select /*+ JOIN_ORDER(se, mo) */ mo.ts_date AS d, mo.PAGE AS page, MIN(se.created_at) AS started,
       MAX(se.created_at) AS finished, COUNT(DISTINCT se.ref) AS orders
from `staff_events` as `se`
inner join `macro_output` as `mo` on `mo`.`id` = CAST(se.ref AS SIGNED INTEGER)
where `se`.`kind` in ('checker.validate', 'checker.edit', 'checker.status')
  and `se`.`source` = 'checker_1'
  and `mo`.`ts_date` between :from and :to
  and `se`.`created_at` >= :from_minus_1_day_00_00
  and `se`.`created_at` < :to_plus_4_days_00_00      -- i.e. through the end of to + 3 days
group by `mo`.`ts_date`, `mo`.`PAGE`
```

2. Who finished last (only the per-date finish times, so a handful of index point lookups):

```sql
select /*+ JOIN_ORDER(se, mo) */ mo.ts_date AS d, se.created_at, u.name
from `staff_events` as `se`
inner join `macro_output` as `mo` on `mo`.`id` = CAST(se.ref AS SIGNED INTEGER)
left join `users` as `u` on `u`.`id` = `se`.`user_id`
where `se`.`kind` in (...same three...) and `se`.`source` = 'checker_1'
  and `mo`.`ts_date` between :from and :to
  and `se`.`created_at` in (:finish_1, :finish_2, ...)   -- at most one per order date
order by `se`.`id` desc
```

3. Oldest event: `select min(created_at) from staff_events` (answered from the created_at index).

4. J&T:

```sql
select JSON_UNQUOTE(JSON_EXTRACT(filters, '$.date')) AS d, JSON_UNQUOTE(JSON_EXTRACT(filters, '$.page')) AS page,
       COUNT(*) AS runs,
       SUM(CASE WHEN status IN ('finished', 'done') THEN 1 ELSE 0 END) AS done_runs,
       SUM(CASE WHEN status IN ('failed', 'stopped') THEN 1 ELSE 0 END) AS failed_runs,
       MAX(CASE WHEN status IN ('finished', 'done') THEN finished_at END) AS finished
from `jnt_batch_runs`
where `created_at` >= :from_minus_1_day
  and JSON_UNQUOTE(JSON_EXTRACT(filters, '$.date')) BETWEEN :from AND :to
group by d, page
```

5. Waybill:

```sql
select DATE(date) AS d, filter_by, filter_value, COUNT(*) AS runs,
       SUM(CASE WHEN status = 'finished' THEN 1 ELSE 0 END) AS done_runs,
       SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_runs,
       MAX(CASE WHEN status = 'finished' THEN finished_at END) AS finished
from `jnt_waybill_print_runs`
where `date` >= :from and `date` < :to_plus_1_day
group by DATE(date), filter_by, filter_value
```

6. Excel: `select timestamp, page, downloaded_by, downloaded_at from downloaded_macro_output_logs where timestamp in (:each order date) order by downloaded_at`.

Plus one `Schema::hasTable` check per table read (6, all SELECTs on the schema).

## EXPLAIN notes

- No MySQL was reachable from this machine without credentials, and the spec forbids touching the server, so the plan was checked on SQLite with the same indexes the live tables have (`staff_events(created_at)`, `staff_events(user_id, created_at)`, `macro_output` primary key and `ts_date` index). The test `test_S3_1_staff_events_is_read_by_a_created_at_range_and_grouped_in_sql` asserts this plan for query 1:

```
SEARCH se USING INDEX staff_events_created_at_index (created_at>? AND created_at<?)
SEARCH mo USING INTEGER PRIMARY KEY (rowid=?)
USE TEMP B-TREE FOR GROUP BY
USE TEMP B-TREE FOR count(DISTINCT)
```

- MySQL reasoning for query 1: `staff_events` has no index on `kind`, `source` or `ref`, so the only usable access path is the `created_at` range (`type=range`, key `staff_events_created_at_index`, kind/source checked per row: "Using index condition; Using where"). The `JOIN_ORDER(se, mo)` hint (MySQL 8 optimizer hint; on another engine it is only a comment) keeps the optimizer from starting at `macro_output` via its `ts_date` index and hash-joining `staff_events`. Each event then reads one `macro_output` row by `PRIMARY` (`type=eq_ref`, `ref=func` because of the CAST, which is there so the comparison is integer to integer and the primary key is used). Grouping is in SQL ("Using temporary"), so PHP gets one row per order date and page (about 50 a day), never the ~9k events a day. The "temporary" is MySQL's internal grouping table for this one SELECT, not a `CREATE TEMPORARY TABLE` and not a CTAS; a plain InnoDB SELECT is a consistent read and takes no locks that block the app's inserts.
- Window size: the default 14 order dates read created_at from the day before to 3 days after (18 days), about 20% of the 90 days `staff_events` keeps. At the 62-day maximum (66 days, ~70% of the table) MySQL may prefer a full scan of `staff_events` over the range; that is still one pass and still a plain SELECT.
- Query 2 is `created_at IN (...)`: point lookups on the same index, at most 62 values. Query 3 is `MIN` on an indexed column ("Select tables optimized away").
- Queries 4 and 5 read the small run tables (~1,500 and ~1,300 rows a month) and group in SQL; no index is needed or added.

## Tests

New: `tests/Feature/EncoderTimetable` (21 tests).

| Case | Test |
|---|---|
| S1.1 | `EncoderTimetablePageTest::test_S1_1_each_order_date_shows_when_each_step_finished_newest_first` (live 2026-11-12 pattern: encoding 08:10–10:21 with 3 orders and the last user, J&T 10:41 with 2/3 runs and the flag, waybill 11:24, Excel, gaps +20 and +43 min; other sources, other kinds and other order dates left out), `..._a_failed_print_run_is_flagged_and_does_not_count_as_the_finish`, `..._default_is_the_last_14_order_dates_up_to_yesterday`, `..._a_range_over_62_days_is_cut_to_62_with_a_message` |
| S1.2 | `test_S1_2_each_date_expands_to_pages_sorted_by_latest_finish_and_waybill_filters` |
| S1.3 | `test_S1_3_summary_shows_average_and_worst_day_and_highlights_days_over_30_minutes_late` |
| S1.4 | `test_S1_4_a_finish_later_than_the_next_day_shows_its_date` |
| S1.5 | `test_S1_5_empty_dates_show_a_dash_and_dates_before_the_oldest_event_show_no_record` |
| S2.1 | `EncoderTimetableAccessTest::test_S2_1_the_migration_adds_the_link_checked_for_ceo_marketing_and_oic`, `..._the_migration_is_idempotent_and_down_removes_the_link`, `..._allowed_roles_open_the_page_and_see_the_link` (CEO, Marketing, Marketing - OIC), `test_S2_access_follows_the_nav_settings_checkbox` |
| S2.2 | `test_S2_2_data_encoders_get_403_and_no_link` (Data Encoder, Data Encoder - OIC, no role), `test_S2_2_guests_are_sent_to_login` |
| S3.1 | `EncoderTimetablePerformanceTest::test_S3_1_every_query_is_a_plain_select_and_their_number_does_not_grow_with_days` (6 data queries + 6 table checks, all SELECT, same count for 2 and 60 days), `test_S3_1_staff_events_is_read_by_a_created_at_range_and_grouped_in_sql` (bounds, PK join, GROUP BY, query plan) |
| S3.2 | `test_S3_2_fourteen_days_of_live_volume_render_in_under_two_seconds` |

S3.2 measured: 14 order dates × 3,100 orders × 3 Checker 1 events (130,200) plus 8,680 other events = 138,880 `staff_events` rows, 43,400 orders over 50 pages, 50 J&T runs and 42 print runs a day. After one warm-up request, the full page request (layout included) took 0.19–0.25 s on SQLite in memory on this Windows machine; the test asserts under 2 s.

Commands (Herd PHP 8.4.16, PHPUnit 11.5.24, SQLite in memory from `phpunit.xml`):

```
php vendor/bin/phpunit tests/Feature/EncoderTimetable
OK (21 tests, 165 assertions)

php vendor/bin/phpunit
Time: 17:57.355
Tests: 5028, Assertions: 80113, Failures: 3, PHPUnit Deprecations: 102, Skipped: 6.
```

The 3 failures are all pre-existing on `db70b9c1` (none is new):

1. `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` (known, listed since spec 243).
2. `Tests\Feature\Item\ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order` (known, listed since spec 243).
3. `Tests\Feature\OwnerPrivate\SharedPagesBasePinTest::test_S_42_4_..._at_the_base` with data set `/owner/private` (pinned sha1 `cb5ad1ee…`, now `5055307b…`). Checked: it fails the same way in a clean detached checkout of `db70b9c1`, and also on this branch with the new migration and route taken out, so it is not from this change.

The `artisan test` runner shows a `file_get_contents(...)` warning on the new page tests; the existing `tests/Feature/SystemMonitor/StorageViewTest.php` shows the same warning on `db70b9c1` (a layout read on this machine), and plain `phpunit` reports the tests OK.

`vendor/bin/pint` was run on the controller. The tests and the migration keep the repo's existing style (the spec 243 migration and tests it copies do not pass pint's defaults either, and pint's `php_unit_method_casing` would rename the story-named test methods).

## Not done / notes

- MySQL `EXPLAIN` itself was not run (no reachable MySQL without credentials; no server access by rule). The reasoning above is from the indexes and the SQLite plan.
- `jnt_waybill_print_runs` has no create migration in the repo (only its items table and a later `pages` column); the tests create it by hand with the model's fillable columns, and the controller skips it when the table is missing.
- Release: `php artisan migrate --force` runs the one new data migration (one `nav_links` row and five `nav_link_role_visibility` rows).
