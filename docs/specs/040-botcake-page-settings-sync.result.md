# Result 040: per-page settings as Page | Key | Value, and Likha finds customers' Botcake PSIDs through the Botcake API

## What shipped

**Part A** (`feat: per-page settings as rows with a CEO page and a one-time Botcake import (spec 040)`)

- Table `page_settings` (migration `2026_10_17_100000`):
  - Columns: `page_name`, `provider`, `key`, `value` (text), `is_secret`, `active`, `replaced_at`, `created_by` (null = system or command), timestamps.
  - Indexes on (page_name, provider, key, active) and (provider, key, active).
  - The key is stored without its provider: `botcake.page_id` is provider `botcake` + key `page_id`.
- Model `App\Models\PageSetting`:
  - `value` is hidden from `toArray` / JSON.
  - `plainValue()` decrypts, on the server only.
  - `displayValue()` shows a secret as `••••` plus its last 4 characters.
- Service `App\Services\PageSettings`:
  - One ACTIVE row per page + provider + key, written in a transaction with `lockForUpdate`.
  - A new value marks the old row `active = false` with `replaced_at`, kept as history, and adds a new row.
  - The same value again adds nothing.
  - Pages are compared trimmed (spaces collapsed) and case-insensitive. The first spelling recorded is kept.
  - Secrets are stored with `Crypt::encryptString`. `botcake.token` is always secret.
  - Every write logs page, key, setting id and user id. The value is never logged.
- Validation (in code):
  - `botcake.page_id`, `botcake.flow.<purpose>` and `botcake.field.<name>`: digits only, so "442139078/" is rejected.
  - `botcake.token`: must not be empty.
  - `botcake.token_status`: written by the system only; the page refuses it.
  - Any other key: free text, with a "secret" checkbox.
  - Provider: `[a-z0-9_]`. Key: `[a-z0-9_]` segments joined by `.`.
- UI `GET /page-settings` (routes `web`, `auth`, `allowed_ip`; CEO only, 404 for everyone else):
  - Filters: page, provider, key.
  - One card per page: the active rows (provider, key, value or masked secret, when and by which user), an inline "Save" per row (a new value replaces the old one), "Deactivate", and a collapsible history per key.
  - An add form at the top.
  - Routes: `POST /page-settings`, `POST /page-settings/{id}/deactivate`.
- Command `page-settings:import-botcake {spreadsheetId} {--range='BOTCAKE API'!A2:D} {--apply}`:
  - Reads the range through the existing Sheets wrapper (new read-only `BotcakePsidSheet::readRange`).
  - The dry run is the default.
  - `--apply` writes `botcake.page_id` (C), `botcake.token` (B) and `botcake.flow.shipping` (D). A value that fails validation is reported and skipped. Re-running adds no rows.

**Part B** (`feat: Likha reads each page's Botcake customers through the API and matches recent orders to their PSID (spec 040)`)

- Migration `2026_10_17_100100`:
  - Adds to `botcake_psids`: `source` (default `sheet`), `phone_numbers` (json), `subscribed_at`, `last_interactive_at`, `botcake_customer_id`, and an index on (source, page, psid).
  - Adds to `botcake_psid_import_runs`: `source` (default `sheet`), `page_name`, `batches_read`, `customers_new`, `customers_updated`, `seconds`, `matched_by_name`, `matched_by_phone`, `ambiguous`, `no_match`.
- Client `App\Services\BotcakeClient`:
  - Calls `GET /pages/{id}/customer?page=N` and `GET /pages/{id}/custom_fields?page=1`.
  - The `access-token` header only. Connect timeout 10 s, timeout 30 s.
  - At least 0.5 s between request starts, retries included, so at most 2 a second.
  - On 429, 5xx or a dropped connection it waits 2, 4, then 8 s, or `Retry-After` when that is longer (capped at 60 s). After 3 retries it throws `BotcakeException`.
  - 401/403 or `success:false` throws an "auth" `BotcakeException`.
  - Error messages carry the HTTP status only, never the token.
  - The clock (`App\Support\BotcakeClock`) is injectable; tests record its sleeps.
- Sync `App\Services\BotcakeCustomerSync` and command `botcake:sync-customers {--page=} {--full}`:
  - Covers every page with an active `botcake.page_id` and `botcake.token`, or the one `--page`, one page at a time.
  - A cache lock `botcake-sync-customers` (3 h) is shared by the command, the schedule and the button. When the lock is held, the run prints "Skipped" and does nothing.
  - Incremental: reads pages 1, 2, … and stops after the first batch whose psids were all already stored for that page, or at an empty batch. `--full` reads to the empty batch. A cap of 20,000 batches guards against an API that repeats itself.
  - Each customer gets one row per page + psid, `source = api`, `status = available`. A known psid has its name, phones, customer id and times updated; it counts as "updated" only when the name or the phones changed. An empty phone list is stored as null.
  - 401/403/`success:false`: `botcake.token_status` becomes `invalid <time>` and that page stops. 429/5xx after the retries: that page stops, its run is `failed` with the error, and the next page goes on.
  - `token_status` is rewritten during a sync only when ok/invalid changes, so the history does not grow every hour. "Test token" always writes it.
  - Each page's run row holds: source `api`, page, batches read (the final empty batch included), new, updated, seconds, the four match counts, and the error.
- Matching `App\Services\BotcakeCustomerMatcher::matchPage($page)`:
  - Runs after each page's sync, whether it succeeded or not, against what is stored.
  - Orders: `macro_output` rows of that PAGE (trimmed, case-insensitive), `ts_date` in the last 30 days (Manila), with an empty `botcake_psid`.
  - Candidates: that page's `source = api`, `status = available` rows.
  - (1) The normalised `fb_name` (trim, collapse spaces, lower case) has exactly one distinct psid → write it.
  - (2) Otherwise the normalised `PHONE NUMBER` (09XXXXXXXXX; +63 / 63 / 9XXXXXXXXX forms are converted, anything else ignored) belongs to exactly one psid → write it.
  - (3) Otherwise: `ambiguous` when either step had two or more candidates, else `no_match`.
  - The update re-checks that the PSID is still empty, so a filled PSID is never overwritten.
  - The 039 hourly job also runs `matchPagesWithTokens()` after its reorder pass, inside its own try/catch so a failure there never breaks the sheet import.
- UI, per page that has botcake settings:
  - Customers stored, token status, and the last sync with its counts and error.
  - "Test token": one custom_fields call, then the result is shown as "Token: ok" or "Token: invalid".
  - "Sync Botcake customers now": queues `SyncBotcakeCustomersJob`, an incremental sync of that page.
  - A link "Orders (30 araw) na wala pang PSID" (`?details=<page>`) shows that page's live list split into Ambiguous / Walang tugma (order id, date, fb name; CEO only, like the whole page).
  - Both buttons are CEO only and rate limited (20/min and 10/min).
- Flag and schedules: `config('services.botcake.sync_enabled')` = env `BOTCAKE_SYNC_ENABLED`, default false (added to `.env.example`). Both entries are in `routes/console.php`, in Asia/Manila, with `withoutOverlapping()` and `->when(flag)`. They show in `schedule:list` but do nothing while the flag is off:
  - `botcake:sync-customers`: hourly at :15 (after the :00 sheet import).
  - `botcake:sync-customers --full`: Sunday 01:00.
  - 039's entries are unchanged.

**Amendment 1** (`feat: inactive Botcake customers, per-run cap and stop reasons, safer phone rule, and a paginated PSID coverage page (spec 040 amendment 1)`)

- Migration `2026_10_17_100200`:
  - `botcake_psids.last_seen_at`, with an index on (source, page, status, last_seen_at).
  - `botcake_psid_import_runs.stop_reason`.
  - (Amendment 1 also added a `botcake_psid_misses` table. Amendment 3 removed it before it shipped; the migration is now `2026_10_17_100200_add_last_seen_and_stop_reason_for_botcake_sync`.)
- Inactive customers:
  - API customers are never deleted. Every sync that returns a customer sets its `last_seen_at`.
  - After a `--full` read that reaches the empty batch without error (`stop_reason = end`), that page's `available` API rows whose `last_seen_at` is older than 30 days (or null) become `inactive`.
  - A customer seen again goes back to `available` (counted as updated).
  - Nothing is marked inactive after an errored or capped full read, or after an incremental run.
  - Matching reads `available` rows only.
- Per-run cap: an incremental run reads at most `config('services.botcake.sync_max_batches')` batches (env `BOTCAKE_SYNC_MAX_BATCHES`, default 300, added to `.env.example`), then stops with `stop_reason = cap`. The next run starts again from page 1. The known-batch stop is checked first. `--full` has no cap except the old 20,000-batch guard.
- Stop reasons (`stop_reason` on every API run, also shown in the panel):
  - `known_batch`, `end`, `cap`.
  - `rate_limited`: still 429 after 3 retries.
  - `token_invalid`: 401, 403 or `success:false`.
  - `error`: 5xx or a dropped connection after the retries, an unexpected body, or a missing page id / token.
- Phone rule, safer version (`BotcakeCustomerMatcher::decide`):
  - Exactly one name candidate → by name.
  - Two or more name candidates → by phone only if exactly one of those candidates also has the order's phone; otherwise ambiguous. A phone never brings in a third person.
  - No name candidate → by phone if the phone belongs to exactly one customer; two or more → ambiguous; none → no match.
- Botcake fields: `date_subscribed_at` (falls back to `subscribed_at`, then `inserted_at`) and `last_interactive_at` (read as UTC, stored in the app's timezone).
- PSID coverage page, CEO only (404 for everyone else), linked from the top of `/page-settings` and from each Botcake card:
  - `GET /page-settings/psid-coverage?from=&to=`. Default range is the last 7 days (Manila); anything longer is cut to the 31 days ending at `to`.
  - One grouped query over `macro_output`, giving per page: orders, with PSID, %, without PSID. Since amendment 3 the ambiguous / no match split is on the page's own list (see below).
  - `GET /page-settings/psid-coverage/orders?page_name=&from=&to=&jnt=&reason=` lists that page's orders without PSID, newest first (ts_date, id), 50 per page with previous / next.
  - Filter `jnt`: all / In Transit / Delivering, via `EXISTS` on `from_jnts` by waybill. Delivering excludes rows with an RTS reason, the same rule as the J&T status page.
  - Filter `reason`: all / ambiguous / no_match / not_synced.
  - Columns: order id, date, fb name, phone, waybill, J&T status, reason.
- The old `?details=` list on `/page-settings` is removed (it loaded every row).
- Indexes and load: see amendment 3 below (the version that ships).

**Amendments 2 and 3** (`feat: keep each Botcake customer's ad ids, save the ad on the order with its PSID, and compute coverage reasons on demand (spec 040 amendments 2 and 3)`)

- Migration `2026_10_17_100300` (`botcake_psids`):
  - `ad_id` (string 64, nullable, indexed) and `ad_ids` (json).
  - `name_key` (normalised name) and `phone_key` (first phone as 09XXXXXXXXX). The model fills both for API rows on every save.
  - Covering indexes (source, page, status, name_key, psid) and (source, page, status, phone_key, psid).
- Migration `2026_10_17_100400`: `macro_output.botcake_ad_id` (varchar 64, nullable) with the index `macro_output_botcake_ad_id_idx`.
  - Guarded: it skips when the table is missing, or when the column or index already exists.
  - Postgres builds the index `CONCURRENTLY`, outside a transaction, like the existing `ts_date` index migration.
  - The `down()` drops only these.
- Ad ids (amendment 2): every sync that returns a customer writes Botcake's `ad_id` (null when missing or empty) and `ad_ids` (the full list; `[]` stays `[]`; a missing list stays null). A customer who gains an ad gets the new list and the new `ad_id`. No report reads them yet.
- Ad on the order (amendment 3):
  - When the matching writes a PSID (by name or by phone, from an API customer), the same parameterised UPDATE sets `botcake_ad_id = COALESCE(botcake_ad_id, <that customer's ad_id>)`. It is null when the customer has no ad.
  - The update still runs only on orders with an empty PSID. A matched order is never touched again, and the COALESCE keeps the first ad even if the PSID is later cleared and the order matched again.
  - The 039 sheet path (`BotcakePsidMatcher`) never writes it.
  - If the column does not exist yet (before the migration runs), the matching writes the PSID only.
- `botcake_psid_misses` is gone (amendment 3); the matching stores no reasons.
- Coverage reasons, on demand (`PsidCoverageController::ordersQuery`):
  - For the page and date range shown, each order without PSID gets three correlated counts of that page's `available` API customers: by name (`name_key` = the order's normalised `fb_name`), by phone (`phone_key` = the order's normalised `PHONE NUMBER`), and by both. Each count is a lookup on a covering index.
  - The reason mirrors the matching:
    - `not_synced` ("maikakabit pa / hindi pa nasusuri"): one name candidate; or two or more name candidates of whom exactly one shares the phone; or no name candidate and exactly one phone candidate. The next match run would write these.
    - `ambiguous`: two or more by name that the phone does not settle, or two or more by phone.
    - `no_match`: otherwise.
  - A page with no Botcake settings shows every order as `not_synced`.
  - The page's list keeps the 50-row pages, newest first, and the reason and J&T filters. Above it, the counts per reason come from one grouped query over the same filters.
  - The overall summary shows orders / with / % / without per page. The split per page lives on that page's list, because computing it for all pages at once would join every page's customers on each load.
  - SQL normalisation, the same on MySQL, Postgres and sqlite:
    - Name: `LOWER(TRIM(fb_name))`, with double spaces collapsed three times (runs of up to 8 spaces).
    - Phone: digits after removing spaces, `-`, `+`, `(`, `)` and `.`; then `639XXXXXXXXX` → `09…` and `9XXXXXXXXX` → `09…`. Concatenation uses `CONCAT` on MySQL and `||` on Postgres and sqlite.
  - It can differ from the PHP matching only for tabs or other non-space whitespace in a name, more than 8 spaces in a row, non-ASCII case folding on sqlite, and customers with a second phone (only the first is in `phone_key`).
- Indexes and query plans (asserted in a test on sqlite):
  - Summary: `SEARCH mo USING INDEX macro_output_ts_date_page_idx (ts_date>? AND ts_date<?)`.
  - Page list: the same index on `macro_output`. The customer counts use `SEARCH b USING COVERING INDEX botcake_psids_source_page_status_name_key_psid_index (source=? AND page=? AND status=? AND name_key=?)` and the `phone_key` twin. The J&T status lookup uses `idx_from_jnts_waybill`.
  - No full scan of `macro_output` or `botcake_psids`. Without `psid` at the end of the key indexes, sqlite picked the (source, page, psid) index and scanned all of a page's customers for each order. That is why the indexes are covering.
- Load check, worst case: a throwaway phpunit file outside the repo, not committed. Sqlite with `ANALYZE`; 1,000,000 orders in 31 days, 900,000 of them on one page (300,000 without PSID), and 200,000 available customers on that page.

  | Query | Time |
  |---|---|
  | Summary over all pages (1M rows in range) | 1.41 s |
  | That page's first 50 rows | 0.10 s |
  | Its row count | 0.86 s |
  | Count with `reason=no_match` | 2.38 s |
  | Split per reason | 2.43 s |

  - A page of that size loads in about 3.5 s over 31 days; the default 7-day range is about a quarter of that.
  - Not measured on MySQL (no production-like MySQL on this machine). MySQL may materialise the derived table before paging, so the first page could cost about as much as the count.
  - If it is too slow there, the fix is an index, not a table: (`PAGE`, `ts_date`, `botcake_psid`) on `macro_output`, so the one-page range is read from the index alone. It is not added now: that is a long build on the biggest table.

## Keeping 039 unchanged

- `BotcakePsidMatcher::tableQuery` (conflict detection, waiting → conflict) now looks at `source = sheet` records only. Without this, a Botcake customer with the same name and a different psid would turn a sheet PSID into a `conflict`. A test pins this.
- The 039 import page's "latest run" reads `source = sheet` runs only, so an API sync never appears as the sheet import's run.
- All 039 tests pass; their test case now also runs the new migration.

## Import dry-run output shape

```
DRY RUN: walang isusulat (gamitin ang --apply).
Row 2: <Page Name> | orders: oo | keys: botcake.page_id, botcake.token, botcake.flow.shipping
Row 3: <Page Name> | orders: wala (walang PAGE na ganito sa orders) | keys: botcake.page_id, botcake.token | flow id "7002/" laktaw (numero lang)
Laktaw kapag --apply: 1.
```

`--apply` prints the same lines, then `Naisulat: N na setting. Laktaw: M.` A bad token only shows "token: hindi tinanggap" or "walang token", never the value.

## Tests

| Case | Test | Result |
|---|---|---|
| One active row per key; a new value replaces and keeps history; the same value adds nothing; created_by | `PageSettingsTest::test_a_new_value_replaces_the_active_row_and_keeps_the_old_one_as_history` | pass |
| Token encrypted at rest; never in the page, the model JSON or a log; last 4 shown | `PageSettingsTest::test_the_token_is_encrypted_at_rest_and_never_in_a_response_or_a_log` | pass |
| Known-key validation ("442139078/" rejected, empty token, flow / field digits, token_status refused) and free keys accepted (9 rows) | `PageSettingsTest::test_known_keys_are_validated_and_free_keys_are_accepted` | pass |
| A free key marked secret is encrypted | `PageSettingsTest::test_a_free_key_marked_secret_is_encrypted` | pass |
| Deactivate keeps the row as history | `PageSettingsTest::test_deactivate_keeps_the_row_as_history` | pass |
| CEO only (404) | `PageSettingsTest::test_only_the_ceo_reaches_the_page`, `BotcakePanelTest::test_the_buttons_are_ceo_only` | pass |
| Test token: ok / success:false / 401, one call, no token in the response | `BotcakePanelTest::test_test_token_makes_one_custom_fields_call_and_records_ok_or_invalid` (3 rows) | pass |
| Sync now queues an incremental sync | `BotcakePanelTest::test_sync_now_queues_an_incremental_sync_for_that_page` | pass |
| Panel: customers, last sync counts, a link to the paginated list (no names on the card) | `BotcakePanelTest::test_the_panel_shows_customers_the_last_sync_and_links_to_the_orders_without_a_psid` | pass |
| Import dry run writes nothing and never prints a token | `ImportBotcakeSettingsTest::test_dry_run_writes_nothing_and_never_prints_a_token` | pass |
| Import --apply writes the three keys and skips a bad flow id; re-run adds nothing | `ImportBotcakeSettingsTest::test_apply_writes_the_three_keys_and_skips_a_bad_flow_id` | pass |
| Incremental stops at the first all-known batch; stop reason `known_batch` | `BotcakeCustomerSyncTest::test_incremental_stops_at_the_first_batch_whose_psids_are_all_known` | pass |
| Full reads to the empty page; one row per page + psid; a changed name is updated; stop reason `end` | `BotcakeCustomerSyncTest::test_full_reads_to_the_empty_page_and_updates_changed_names` | pass |
| 429 then 503 then success: backoff 2 s, 4 s; every request ≥ 0.5 s after the previous one (clock) | `BotcakeCustomerSyncTest::test_429_then_success_retries_with_backoff_and_requests_stay_at_most_two_a_second` | pass |
| Four 429s / four 500s: the page stops; stop reason `rate_limited` / `error` | `BotcakeCustomerSyncTest::test_four_failures_stop_the_page_and_report_why` (2 rows) | pass |
| 401 marks the token invalid, stops the page (stop reason `token_invalid`), no token in logs or output | `BotcakeCustomerSyncTest::test_401_marks_the_token_invalid_and_stops_that_page_without_logging_the_token` | pass |
| Matching: unique name written; two psids same name → ambiguous, nothing written; phone when there is no name candidate; phone decides between two same-name candidates; a third person's phone does not override an ambiguous name; inactive rows unused; non-empty never overwritten; older than 30 days untouched; other page and sheet records are not candidates | `BotcakeCustomerMatchTest::test_orders_get_a_psid_only_when_exactly_one_customer_fits` | pass |
| An API customer never turns a sheet PSID into a conflict (039 unchanged) | `BotcakeCustomerMatchTest::test_an_api_customer_never_turns_a_sheet_psid_into_a_conflict` | pass |
| The customer's ad_id is written with the PSID (by name and by phone), null without an ad, not changed by a later sync with another ad nor by a re-match (amendment 3) | `BotcakeCustomerMatchTest::test_the_customers_ad_id_is_written_with_the_psid_and_never_changed_afterwards` | pass |
| A PSID from the sheet import leaves botcake_ad_id empty (amendment 3) | `BotcakeCustomerMatchTest::test_a_psid_from_the_sheet_import_leaves_the_ad_id_empty` | pass |
| ad_id / ad_ids stored on insert, updated when the list grows, null / empty kept as null / [] (amendment 2) | `BotcakeCustomerSyncTest::test_the_ad_id_and_ad_ids_are_stored_and_follow_the_customer` | pass |
| Cap: an incremental run stops at 300 batches (default config) with reason `cap`; the next run starts from page 1 (amendment 1) | `BotcakeCustomerSyncTest::test_an_incremental_run_stops_at_the_cap_of_300_batches_and_starts_from_page_1_next_run` | pass |
| A clean full read marks customers unseen for 30 days inactive, a returning one available, deletes nothing (amendment 1) | `BotcakeCustomerSyncTest::test_a_clean_full_read_marks_customers_unseen_for_30_days_inactive_and_a_returning_one_available` | pass |
| An errored full read (stop reason `error`) or an incremental run marks nobody inactive (amendment 1) | `BotcakeCustomerSyncTest::test_an_errored_full_read_or_an_incremental_run_marks_nobody_inactive` | pass |
| `date_subscribed_at` and `last_interactive_at` (UTC) are read (amendment 1) | `BotcakeCustomerSyncTest::test_the_live_field_names_for_subscribed_and_last_interactive_are_read` | pass |
| Coverage summary per page in one grouped query: orders, with, %, without; default 7 days (amendments 1, 3) | `PsidCoverageTest::test_the_summary_counts_each_page_in_one_grouped_query` | pass |
| Reasons computed on demand: ambiguous by name and by phone, phone that does not settle a name ambiguity, no match, inactive customers not counted, matchable = not yet checked; the counts per reason and the reason filter (amendment 3) | `PsidCoverageTest::test_the_reason_of_each_order_without_psid_is_computed_from_the_pages_customers` | pass |
| A page without Botcake customers shows everything as not yet checked (amendment 3) | `PsidCoverageTest::test_a_page_without_botcake_customers_shows_everything_as_not_yet_checked` | pass |
| Range at most 31 days (amendment 1) | `PsidCoverageTest::test_the_range_is_at_most_31_days` | pass |
| A page's orders without PSID: 50 per page, newest first, J&T filter (amendment 1) | `PsidCoverageTest::test_a_page_lists_its_orders_without_psid_50_at_a_time_newest_first_with_the_jnt_filter` | pass |
| Coverage CEO only, linked from /page-settings (amendment 1) | `PsidCoverageTest::test_only_the_ceo_reaches_it_and_page_settings_links_to_it` | pass |
| Coverage queries use the (ts_date, PAGE) index and the covering name / phone key indexes, no scan (amendment 3) | `PsidCoverageTest::test_the_queries_use_the_ts_date_page_index_and_the_customer_key_indexes` | pass |
| Schedules exist (hourly :15, Sunday 01:00, Manila, withoutOverlapping) and do nothing when the flag is off | `BotcakeSyncScheduleTest::test_hourly_sync_and_the_sunday_full_sync_exist_and_run_only_when_the_flag_is_on` | pass |
| Flag off by default | `BotcakeSyncScheduleTest::test_the_flag_is_off_by_default` | pass |
| The 039 hourly import also matches pages with tokens (no HTTP) | `BotcakeSyncScheduleTest::test_the_hourly_sheet_import_also_matches_pages_with_tokens` | pass |

Red runs:
- Settings: failed on 404 (no route) and `Class "App\Models\PageSetting" not found`.
- Import and sync: failed on `CommandNotFoundException`.
- Matching: failed on `Target class [App\Services\BotcakeCustomerMatcher] does not exist`.
- Schedule: failed on "Failed asserting that two arrays are equal" (no entries), and the 039 hook on "null is identical to 'psid-a'".
- Panel: failed on 404.
- The 039 conflict guard was written after its fix. I then confirmed it by removing the fix: "Failed asserting that two strings are identical".
- Amendment 1:
  - Sync tests failed on "Failed asserting that two arrays are identical" (no `stop_reason`) and "null is identical to 'error'".
  - The matching test failed on the safer phone rule and inactive rows; the misses test on the missing reasons.
  - The coverage tests failed on 404 (no route) and the missing controller.
- Amendments 2 and 3:
  - The ad-on-order test failed on "Failed asserting that two arrays are identical" (no `botcake_ad_id`).
  - The coverage tests failed on the missing `split` and reasons. The query-plan test failed first on the non-covering indexes.
  - The ad-ids sync test went red together with the others. Removing the misses table broke the matcher until it was rewritten, so that red was not clean.

Full suite (`vendor/bin/phpunit`), after amendments 2 and 3: `Tests: 1275, Assertions: 16351, Errors: 1, Failures: 1, Skipped: 3` (1271 after amendment 1, 1261 before). The only reds are the known `ExampleTest::test_the_application_returns_a_successful_response` and, in this fresh worktree, `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`. All 47 tests of this spec pass (63 with the 039 Botcake tests).

## After release (server)

1. `php artisan migrate --force`: five new migrations, plus 039's two if they have not run yet. `2026_10_17_100400` adds a column and an index to `macro_output`. It is additive and nullable, but the index build on the big table takes time, so run it in a quiet hour.
2. `php artisan page-settings:import-botcake <spreadsheetId>`: dry run; check every row says `orders: oo` and the keys.
3. `php artisan page-settings:import-botcake <spreadsheetId> --apply`
4. On `/page-settings`, press "Test token" for one page, or skip it and go to step 5.
5. `php artisan botcake:sync-customers --page="<Page Name>" --full`, then read the printed line (batches, stop reason, new, updated, inactive, matches) and `/page-settings/psid-coverage` for that page.
6. When that page looks right: set `BOTCAKE_SYNC_ENABLED=true` in the server's environment, then `php artisan config:cache` if the config is cached. `php artisan schedule:list` shows the two `botcake:sync-customers` entries either way.

The `--range` default is `'BOTCAKE API'!A2:D`; pass `--range=...` only if the tab is named differently.

## What the brief got wrong, and decisions it left open

- **`edited_phone_number` is not a phone.** In `macro_output` it is a boolean edit flag (migration `2025_07_21_174014`); an edited phone overwrites `PHONE NUMBER` itself. Matching uses `PHONE NUMBER` only.
- The repo has no `CLAUDE.md`; I followed `CLAUDE.local.md`.
- Rule (2): replaced by the safer version (amendment 1). When two or more name candidates exist and two or more of them share the order's phone, the order stays ambiguous.
- "Who and when" for edits: each new row carries `created_by` and `created_at`; a replaced row carries `replaced_at`. Deactivation and replacement also write a log line with the user id. There is no `deactivated_by` column, to keep to the brief's column list.
- The orders-without-PSID list moved to `/page-settings/psid-coverage` (amendment 1). Since amendment 3 its reasons are computed on demand, and "not yet synced" means "maikakabit pa / hindi pa nasusuri": the next match would write it, or the page has no Botcake settings.
- `name_key` and `phone_key` are two new columns on `botcake_psids`, not a new table. They are needed so the on-demand reasons can use an index instead of normalising every customer per query.
- Botcake fields: now `date_subscribed_at` and `last_interactive_at` (UTC), with the old fallbacks (amendment 1).
- Inactive marking also covers API rows whose `last_seen_at` is null: after a clean full read, a row not returned is not a current customer. On the server there are none yet, since no API row exists before release.
- Hourly sync runs at :15, not :00, to stay clear of the 039 import at :00. It shares nothing with that import's lock.
- Rate: one client per page and pages run in sequence, so the 2/s limit also holds across pages.
- Conflict with `CLAUDE.local.md`: none.

## Amendment changes

Amendment 1 (kept in the handoff folder, not committed):
1. `last_seen_at`; `inactive` after 30 days unseen, only on a clean `--full` read; seen again → `available`; matching uses `available` only; customers never deleted.
2. Per-run cap for incremental runs, `BOTCAKE_SYNC_MAX_BATCHES` (default 300); reason `cap`; next run from page 1.
3. `stop_reason` on every API run: known_batch, end, cap, rate_limited, token_invalid, error.
4. `/page-settings/psid-coverage`: grouped summary per page for 7 to 31 days, and a paginated list per page with J&T status and reason filters; per-order reasons in a new `botcake_psid_misses` table (removed again by amendment 3); index use checked in a test and on 1M rows in sqlite.
5. Safer phone rule.
6. Botcake field names `date_subscribed_at` and `last_interactive_at` (UTC).
7. The tests listed above, marked "(amendment 1)".

Amendments 2 and 3 (kept in the handoff folder, not committed):
1. `botcake_psids.ad_id` (indexed) and `ad_ids` (json), updated on every sync that returns the customer.
2. `macro_output.botcake_ad_id` (guarded, additive, indexed), written once together with the PSID from an API customer, never by the sheet path.
3. `botcake_psid_misses` removed (its creation dropped from the unshipped `100200` migration, which was renamed); coverage reasons computed on demand with the new `name_key` / `phone_key` columns and covering indexes on `botcake_psids`.
4. Tests: ad ids on customers, ad on orders (written, null, never overwritten, sheet path empty), on-demand reasons, no-customers page, the new query plans.

## Story changes

None. The brief brought no `qa/stories.md` stories; the cases above come from its Tests list.
