# Result 039: Botcake PSIDs wait in a table until their order arrives

## What shipped

**Part A** (`feat: Botcake PSIDs wait in botcake_psids until their order arrives (spec 039)`)

- Table `botcake_psids` (migration `2026_10_16_400000`): `setting_id`, `page`, `fb_name`, `psid`, `sheet_date` (column D as text), `status`, `first_seen_at`, `matched_at`, `matched_rows`, `last_checked_at`, timestamps; indexes on (page, fb_name), status, psid. Rows are never deleted. Model `App\Models\BotcakePsid`.
- Statuses:
  - `waiting`: no order yet. J = "waiting".
  - `matched`: written to `macro_output.botcake_psid`. J = "imported".
  - `expired`: waited more than 30 days (from `first_seen_at`) without an order. No longer tried.
  - `conflict`: the same PAGE + fb_name (case-insensitive) has two or more different PSIDs, counting every record in the table and the PSIDs already on that customer's order rows. Nothing is attached. J = "conflict". Any other `waiting` record of that customer also becomes `conflict`.
- Migration `2026_10_16_400100` adds run counts to `botcake_psid_import_runs`: `total_waiting`, `total_matched_from_waiting`, `total_expired`, `total_conflicts`, `total_reorder_copies`, `total_deleted_rows`. They are returned by the status endpoint and shown on the button page.
- `App\Services\BotcakePsidMatcher` holds the rules. `App\Services\BotcakePsidSheet` is a thin Sheets wrapper (read rows, write J, delete row ranges); tests replace it with an in-memory fake, so no test calls Google.
- How a run goes (`ImportBotcakePsidFromGoogleSheet`):
  1. Take the cache lock `botcake-psid-import` (30 minutes). If another run holds it, this run is marked done with "Skipped" and changes nothing. The lock covers the button and the schedule.
  2. Optional clean (03:00 only, see Part B).
  3. Retry every `waiting` record. A match writes only `macro_output` rows whose `botcake_psid` is empty. A record still unmatched after 30 days becomes `expired`.
  4. For every setting, process every sheet row with a blank J. Order found (exact PAGE + fb_name, then case-insensitive, as before) → `matched`, J "imported". No order → `waiting`, J "waiting". Conflict → `conflict`, J "conflict". A row missing page, name or PSID still gets "not existing" and is not recorded. A sheet row identical to an already recorded one (same setting, page, name, PSID and date) reuses that record. The button's cutoff works as before.
  5. Reorder pass: a `macro_output` row with an empty PSID and `ts_date` in the last 30 days, whose exact PAGE + fb_name has exactly one distinct PSID on other rows and no `conflict` record, gets that PSID.
- `php artisan botcake:psid-backfill` (dry run) and `--apply`. Details below.

**Part B** (`feat: Botcake PSID import runs every hour and cleans the sheet at 03:00 (spec 039)`)

- `php artisan botcake:psid-import [--clean-sheet]` creates a run row and queues the job on `database` / `default`, the same queue as the button.
- `routes/console.php`, both entries in Asia/Manila with `withoutOverlapping()`:
  - `botcake:psid-import`, cron `0 0-2,4-23 * * *`: every hour except 03:00.
  - `botcake:psid-import --clean-sheet`, `dailyAt('03:00')`: delete first, then import.
- The clean step, per setting:
  - Re-reads the sheet right before deleting.
  - Candidates are rows from row 2 down whose J is imported, waiting or conflict and whose page, name, PSID and D (whitespace-normalised) equal a record of that setting.
  - Rows with a blank J, other J values, or content that differs from the record are kept, as is the header.
  - Consecutive rows are merged into ranges ordered bottom to top, and all of them go in one `batchUpdate` `deleteDimension` call (500 ranges per call).
  - The count is logged (`ImportBotcakePsid: daily clean deleted N sheet rows`), stored in `total_deleted_rows` and written as a `clean_done` event.

## How the scan works after deletions

The K1 seed, the scan chunks and the L1 cursor are gone. Every run reads the whole tab (A2:J, 5000 rows per request until a short chunk) and processes each row whose J is blank. Each row number comes from that same read, moments before J is written. The daily clean keeps the sheet small, so reading it all is cheap. Deleting rows therefore cannot shift a stored cursor, because none is stored. Botcake appends at the bottom, so a row added between the read and the write never moves the rows above it. The run columns `k1_value`, `selected_start_row` and `next_scan_from` are no longer filled; `seed_row` is always 2.

## Backfill

`php artisan botcake:psid-backfill` reads every setting and prints, without writing anything:

```
Setting #1 (<gsheet name> / <tab>): N na "not existing" row ang itatala bilang waiting; M kulang ang page/pangalan/PSID, hindi gagalawin
Dry run: walang isinulat. Kabuuan: N row. Patakbuhin ulit na may --apply para isulat.
```

`--apply` records those rows as `waiting`, with `first_seen_at` = now so they get the full 30 days. It sets their J to "waiting", runs the waiting retry once and prints `Naitala: N. Matched: X, conflict: Y, expired: Z.` The next 03:00 run deletes these rows from the sheet.

## Case table

| Case | Test | Result |
|---|---|---|
| Existing order → macro_output written, matched, J imported | `BotcakePsidImportTest::test_psid_with_existing_order_is_written_recorded_matched_and_marked_imported` | pass |
| No order → waiting, J waiting, macro_output untouched | `test_psid_without_order_waits_and_macro_output_is_untouched` | pass |
| Order arrives, next run without new rows → matched | `test_waiting_psid_is_attached_when_the_order_arrives_on_a_run_without_new_rows` | pass |
| A row already holding a PSID is not overwritten from waiting | `test_waiting_only_fills_empty_rows_of_the_order` | pass |
| 31 days waiting → expired, not tried after | `test_waiting_for_more_than_30_days_expires_and_is_no_longer_tried` | pass |
| Two PSIDs in the sheet for one page + name → conflict, nothing written | `test_two_psids_for_one_customer_in_the_sheet_are_a_conflict_and_nothing_is_written` | pass |
| Incoming PSID differs from the one on the customer's orders → conflict | `test_incoming_psid_that_differs_from_the_one_on_the_customers_orders_is_a_conflict` | pass |
| Reorder copy (single PSID, last 30 days; skips two-PSID customers and old rows) | `test_reorder_gets_the_customers_single_known_psid_within_30_days` | pass |
| 03:00 delete: only recorded rows with matching content, bottom to top, one call; keeps header, blank-J and changed rows; then imports | `test_daily_clean_deletes_only_recorded_rows_with_matching_content_bottom_to_top_then_imports` | pass |
| Never two at once | `test_second_import_while_one_is_running_is_skipped` | pass |
| Button page gets the new counts | `test_status_endpoint_returns_the_new_counts` | pass |
| Backfill dry run writes nothing | `BotcakePsidBackfillTest::test_dry_run_writes_nothing_and_prints_the_counts` | pass |
| Backfill --apply records, marks, matches | `test_apply_records_waiting_marks_the_sheet_and_matches_once` | pass |
| Schedule entries and times | `BotcakePsidScheduleTest::test_hourly_import_and_the_0300_clean_then_import_are_scheduled_in_manila_time` | pass |
| Command queues the import | `test_command_creates_a_run_and_queues_the_import` | pass |

Red runs: the import tests first failed on `ModelNotFoundException` (no `botcake_psids` record), the backfill and command tests on `CommandNotFoundException`, and the schedule test on "Failed asserting that two arrays are equal" (no entries).

Full suite (`vendor/bin/phpunit`): `Tests: 1227, Assertions: 15784, Errors: 1, Failures: 1, Skipped: 3`. The only reds are the known `ExampleTest::test_the_application_returns_a_successful_response` and, in this fresh worktree, `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`. All 15 new Botcake tests pass. (`artisan test` shows a `file_get_contents` warning on every test in this machine's environment, existing suites included; `vendor/bin/phpunit` runs clean.)

## After release (server)

1. `php artisan migrate --force` (two new migrations).
2. `php artisan botcake:psid-backfill` and read the counts.
3. `php artisan botcake:psid-backfill --apply`.
4. Check that the scheduler cron and the queue workers are running (`php artisan schedule:list` shows the two `botcake:psid-import` entries).

## Notes and things the brief left open

- Old rows with J = "imported" from before this change are not in the table, so the 03:00 clean never deletes them. The backfill only covers "not existing" rows. If the owner wants those old rows gone too, a small `--imported` option on the backfill could record them as `matched`. Not built.
- Retrying a `waiting` record checks the exact PAGE + fb_name first (indexed). The case-insensitive fallback only looks at `macro_output` rows with `ts_date` from two days before the PSID was first seen, so an hourly retry does not scan the whole table for every waiting PSID. New sheet rows keep the old rule exactly: exact, then case-insensitive over all rows.
- Conflict counts every recorded PSID of the customer, `expired` ones included. Once a customer is `conflict`, they never get an automatic PSID again, and reorders are skipped too.
- A duplicate sheet row of an `expired` record gets J = "expired". The clean does not delete it, because "expired" is not in the brief's delete list.
- The lock uses the cache store. Production needs a store that supports locks (database, file and redis all do).
- The reorder pass compares exact PAGE + fb_name, which uses the index. It runs one or two indexed queries per customer that has an empty-PSID row in the last 30 days.
- Conflict with this file: none.

## Story changes

None. The brief brought no `qa/stories.md` stories (fast mode); the cases above come from the brief's Tests list.
