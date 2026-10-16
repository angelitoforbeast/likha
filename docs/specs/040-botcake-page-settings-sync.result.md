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
| Panel: customers, last sync counts, the ambiguous / no-match list under details | `BotcakePanelTest::test_the_panel_shows_customers_the_last_sync_and_the_orders_still_without_a_psid` | pass |
| Import dry run writes nothing and never prints a token | `ImportBotcakeSettingsTest::test_dry_run_writes_nothing_and_never_prints_a_token` | pass |
| Import --apply writes the three keys and skips a bad flow id; re-run adds nothing | `ImportBotcakeSettingsTest::test_apply_writes_the_three_keys_and_skips_a_bad_flow_id` | pass |
| Incremental stops at the first all-known batch | `BotcakeCustomerSyncTest::test_incremental_stops_at_the_first_batch_whose_psids_are_all_known` | pass |
| Full reads to the empty page; one row per page + psid; a changed name is updated | `BotcakeCustomerSyncTest::test_full_reads_to_the_empty_page_and_updates_changed_names` | pass |
| 429 then 503 then success: backoff 2 s, 4 s; every request ≥ 0.5 s after the previous one (clock) | `BotcakeCustomerSyncTest::test_429_then_success_retries_with_backoff_and_requests_stay_at_most_two_a_second` | pass |
| Four 429s: the page stops and the run reports the error | `BotcakeCustomerSyncTest::test_four_429s_stop_the_page_and_report` | pass |
| 401 marks the token invalid, stops the page, no token in logs or output | `BotcakeCustomerSyncTest::test_401_marks_the_token_invalid_and_stops_that_page_without_logging_the_token` | pass |
| Matching: unique name written; two psids same name → ambiguous, nothing written; phone when the name fails; non-empty never overwritten; older than 30 days untouched; other page and sheet records are not candidates | `BotcakeCustomerMatchTest::test_orders_get_a_psid_only_when_exactly_one_customer_fits` | pass |
| An API customer never turns a sheet PSID into a conflict (039 unchanged) | `BotcakeCustomerMatchTest::test_an_api_customer_never_turns_a_sheet_psid_into_a_conflict` | pass |
| Preview lists ambiguous / no match and writes nothing | `BotcakeCustomerMatchTest::test_the_preview_lists_unmatched_orders_and_writes_nothing` | pass |
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

Full suite (`vendor/bin/phpunit`): `Tests: 1261, Assertions: 15966, Errors: 1, Failures: 1, Skipped: 3`. The only reds are the known `ExampleTest::test_the_application_returns_a_successful_response` and, in this fresh worktree, `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`. All 33 new tests (49 with the 039 Botcake tests) pass.

## After release (server)

1. `php artisan migrate --force` (two new migrations, plus 039's two if they are not run yet).
2. `php artisan page-settings:import-botcake <spreadsheetId>`: dry run; check every row says `orders: oo` and the keys.
3. `php artisan page-settings:import-botcake <spreadsheetId> --apply`
4. On `/page-settings`, press "Test token" for one page, or skip it and go to step 5.
5. `php artisan botcake:sync-customers --page="<Page Name>" --full`, then read the printed counts and the page's panel (customers, matches, ambiguous / no match).
6. When that page looks right: set `BOTCAKE_SYNC_ENABLED=true` in the server's environment, then `php artisan config:cache` if the config is cached. `php artisan schedule:list` shows the two `botcake:sync-customers` entries either way.

The `--range` default is `'BOTCAKE API'!A2:D`; pass `--range=...` only if the tab is named differently.

## What the brief got wrong, and decisions it left open

- **`edited_phone_number` is not a phone.** In `macro_output` it is a boolean edit flag (migration `2025_07_21_174014`); an edited phone overwrites `PHONE NUMBER` itself. Matching uses `PHONE NUMBER` only.
- The repo has no `CLAUDE.md`; I followed `CLAUDE.local.md`.
- Rule (2) is applied as written: when the name gives no single psid (none, or two or more), a phone that belongs to exactly one customer is written. So an order whose name has two candidates can still get a PSID by phone. If that is too loose, the safer variant is "phone only when the name had no candidate, or the phone's psid is one of the name's candidates"; it is a one-line change.
- "Who and when" for edits: each new row carries `created_by` and `created_at`; a replaced row carries `replaced_at`. Deactivation and replacement also write a log line with the user id. There is no `deactivated_by` column, to keep to the brief's column list.
- The orders-without-PSID list is a live query per page, so it loads only for the page opened with `?details=`. The card for every page shows the last run's ambiguous / no-match counts instead.
- The brief does not name the Botcake fields for `subscribed_at` / `last_interactive_at`. They are read from `subscribed_at` (or `inserted_at`) and `last_interactive_at` when present, otherwise left null. Check them against a real response during the one-page test.
- Hourly sync runs at :15, not :00, to stay clear of the 039 import at :00. It shares nothing with that import's lock.
- Rate: one client per page and pages run in sequence, so the 2/s limit also holds across pages.
- Conflict with `CLAUDE.local.md`: none.

## Story changes

None. The brief brought no `qa/stories.md` stories; the cases above come from its Tests list.
