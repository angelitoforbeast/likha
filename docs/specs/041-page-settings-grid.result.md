# Result 041: `/page-settings` looks and works like a Google Sheet

## What shipped

Commits:
- `feat: /page-settings as a sheet-like grid with per-cell auto-save, polling for other users' changes, history and + Page / + Column (spec 041)`
- `fix: grid applies a change made by someone else after the edited cell closes, polls with a 2 s overlap, and keeps a free secret column secret on every page (spec 041)`
- `fix: a successful save drops the poll value set aside during the edit, and focus returns to the cell after Enter or Escape (spec 041)`

Storage is unchanged: `page_settings` with Page | Provider | Key | Value, as in spec 040. No migration.

### The grid (`GET /page-settings`, CEO only, 404 for everyone else)

- **Rows:** one per page that has at least one active setting, sorted by name (natural, case-insensitive). The page name is the first column and stays in place when scrolling sideways. The header row stays in place when scrolling down. A "Hanapin ang page" box filters the rows.
- **Columns, in this order:**
  - Botcake ID, Botcake token, Shipping flow ID, Delivering flow ID, In transit flow ID, Broadcast flow ID, Message field ID, Token status (read-only, marked "(system)").
  - Then every other active provider + key, sorted by name, headed by its key (e.g. `sms.sender`). A new key becomes a column on its own.
  - Then three read-only columns: Customers (`available` API rows), Huling sync (time · stop reason, or the status, plus the error), and the actions: Test token and Sync now (only when the page has both a Botcake ID and a token), and a Coverage link (`/page-settings/psid-coverage/orders?page_name=…`).
- **Who and when:** each cell's tooltip shows who changed it (user name, or "system" for the command and the sync) and when (Manila time).
- **Secrets:** the token, and any key with an active secret row, show `••••` plus the last 4 characters. The value itself is never in the HTML or in any JSON.

### Editing

- Click a cell, or Tab to it and press Enter or F2. It becomes an input.
  - Normal cells save by themselves: on Enter, on leaving the cell, or 1.5 s after the last keystroke. Escape cancels.
  - Secret cells open empty. They save on Enter or on leaving the cell only, never on a pause, so half a token is never saved. Blank means no change.
- Each cell shows a mark: "Saving…", then "Saved" (or "✓" when the value was the same) for 2.5 s. An error shows "!", the message under the cell and a red background, and nothing is saved.
- The rules are those of spec 040, from `PageSettings::validate`:
  - Digits only for `botcake.page_id`, `botcake.flow.*` and `botcake.field.*`, so "442139078/" is refused.
  - The token must not be empty.
  - `botcake.token_status` is refused.
  - Provider and key formats are checked.
  - New: a page name may have at most 255 characters. This also applies to the old `POST /page-settings`, because the rule is shared.
- **Clearing:** a blank normal cell deactivates the setting, which stays in the history. A secret is cleared with "Burahin ang value" in the history panel.
- **History:** the "⋯" button on a cell opens a side panel with the current value (who and when, and "Burahin ang value") and up to 50 earlier values: value (secret = last 4), who, set at → replaced at.
- **+ Page:** type a name. The server trims it, collapses spaces, and reports whether any `macro_output.PAGE` matches it (trimmed, case-insensitive). If none matches, the grid warns "walang order na may PAGE na ganito — tama ba ang baybay?" but still adds the row. Nothing is stored until a value is entered in that row.
- **+ Column:** provider, key and a "secret" checkbox, checked with the same format rules. It adds an empty column, stored only once a value is entered.
- **Last write wins.** If another user changes the cell you are editing, the grid keeps their value aside. You see it when you leave the cell without saving (Escape, or no change). If you save, your value replaces theirs.

### Endpoints (all CEO only)

| Route | What it does |
|---|---|
| `GET /page-settings` | The grid page; the grid data is embedded as JSON. |
| `GET /page-settings/grid` | The full grid as JSON: `{columns, rows, now}`. |
| `GET /page-settings/grid?since=YYYY-MM-DD HH:MM:SS` | The poll. See below. A malformed `since` gets 422. |
| `POST /page-settings/cell` | Saves one cell. Body: `page_name, provider, key, value, is_secret?, clear?`. Returns `{status: created\|replaced\|unchanged\|cleared, cell}`, or 422 `{error}`. Throttled to 120 a minute. |
| `GET /page-settings/history?page_name=&provider=&key=` | The cell's history as JSON. |
| `GET /page-settings/page-check?page_name=` | For "+ Page": `{name, key, in_orders}`. Writes nothing. Throttled to 30 a minute. |

The 040 routes are kept and unchanged: `POST /page-settings`, `/{id}/deactivate`, `/test-token`, `/sync`, `/psid-coverage`, `/psid-coverage/orders`. Test token and Sync now are forms posting to the 040 routes, which redirect back to the grid with their message.

### Polling

The page polls every 5 s, skipping while the tab is hidden.

- `changes($since)` is one query: `page_settings` rows with `created_at >= since` or `replaced_at >= since`.
- It returns each changed cell once: `{page (key), name, key, cell}`. `cell` is null when the cell was cleared. This works because a cell has only one active row.
- It also returns `info` (customers and last sync) only for pages with an API run updated since then. When no run changed, the customer and run queries are not made.
- `now` comes back 2 s early, so a save that commits during the poll's query is picked up next time. A cell sent twice does no harm.
- On the client, a cell being edited is never overwritten. A new page or column arriving from the poll rebuilds the table, deferred while someone is editing.

### Query count

| Request | Queries |
|---|---|
| Grid | 4 |
| Poll, with a run changed | 5 |
| Poll, with nothing changed | 2 |

These counts leave out the auth and session queries.

- The grid's four queries are: active settings, user names (one `whereIn`), customers grouped by page, and the last API run per page (one `whereIn (SELECT MAX(id) … GROUP BY page)`).
- No query runs per cell or per page. The test measures 5 pages and 50 pages (4 settings each, plus customers and runs) and gets the same counts, at most 6.

### Removed

- The card view and `_botcake_panel.blade.php`. The brief allowed this.
- The card view's filters (page / provider / key). The grid has a page search box instead.
- The 040 test `BotcakePanelTest::test_the_panel_shows_customers_the_last_sync_and_links_to_the_orders_without_a_psid`. It asserted the card's text; the grid's info test covers the same data.
- `PageSettingsTest::test_deactivate_keeps_the_row_as_history` now reads the history through `/page-settings/history`, not the page HTML.

## Tests

| Case | Test | Result |
|---|---|---|
| One row per page; known columns in order, then other keys by name; labels; token status read-only; empty cell absent; "system" as author | `PageSettingsGridTest::test_the_grid_has_one_row_per_page_and_the_known_columns_in_order_then_other_keys_by_name` | pass |
| Empty table still shows the known columns, the four flows included | `…::test_an_empty_table_still_shows_the_known_columns_including_the_four_flows` | pass |
| Cell save: valid → replaced (old row kept inactive), author in the response; "442139078/" and token_status → 422, nothing saved; same value → unchanged | `…::test_a_valid_cell_replaces_the_active_row_an_invalid_one_saves_nothing_and_the_same_value_adds_nothing` | pass |
| Secret never in the save response, the page HTML, the grid JSON, the poll JSON or the history JSON; replaced token → `••••qq7z`; blank secret = unchanged; a free secret key stays secret, also on a new page sent with `is_secret=false` | `…::test_the_secret_is_never_sent_and_a_replaced_token_shows_only_its_last_4` | pass |
| Clearing deactivates and keeps the value in the history; blank on an empty cell does nothing; `clear` removes a token; two active rows are both deactivated | `…::test_clearing_a_cell_deactivates_the_setting_and_keeps_it_in_the_history` | pass |
| + Page check (trimmed name, key, in_orders yes / no, blank → 422, nothing written); a value in a new page and a new column creates the row and the column; bad provider and a page name over 255 characters → 422 | `…::test_a_new_page_and_a_new_column_exist_once_a_value_is_entered` | pass |
| Poll returns only the changed cells (replaced, cleared → null, new page with a secret as last 4), `now` with the 2 s overlap, nothing after the last change, 422 on a malformed `since` | `…::test_polling_returns_only_the_cells_changed_since_the_timestamp` | pass |
| Info: available customers only, the latest run's stop reason, Test token / Sync now / Coverage present | `…::test_the_info_columns_show_available_customers_the_last_sync_and_the_actions` | pass |
| Query count is the same for 5 and 50 pages (grid page, grid JSON, poll) and at most 6 | `…::test_the_query_count_stays_flat_from_5_to_50_pages` | pass |
| CEO only: page, grid, history, page-check and cell all return 404, and nothing is written | `…::test_only_the_ceo_reaches_the_grid_endpoints` | pass |
| The 040 tests (settings, panel buttons, import, coverage) still pass | `tests/Feature/PageSettings/*` | pass (38 tests) |

Red runs:
- First run, before the code: 404 on every new route, and `viewData('grid')` missing on the page.
- Fix loop:
  - The overlap test failed on "09:05:00" vs "09:04:58".
  - The `is_secret=false` secret case failed on "two strings are identical".
  - The duplicate-active clear failed on "'701' is null".
  - The page-check `key` failed on "array has the subset".

Full suite (`vendor/bin/phpunit`) on the final tree: `Tests: 1284, Assertions: 16481, Errors: 1, Failures: 1, PHPUnit Deprecations: 3, Skipped: 3`. The only reds are the known `ExampleTest::test_the_application_returns_a_successful_response` and, in this fresh worktree, `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`.

Other checks:
- `php -l` passes on every changed PHP file.
- `node --check` passes on the page's script.
- `artisan view:cache` compiles.

## Review

- One review (standard depth) and one fix loop.
- The major: a change by another user to the cell being edited was lost on screen until a reload. It is fixed.
- These minors were also fixed: the 2 s poll overlap, the server deciding whether a free key is secret, reuse of `PageSettings::pageQuery` (now public), the page key from the server, the 255-character cap, clearing every active row, keyboard access, and a parked poll value dropped after a successful save.
- The accepted minors are in `TODO.md` under 041, with reasons.

## Browser check

- Not done. The app needs the server database (`.env`, not read), and this session has no running app.
- The JavaScript is checked by syntax and by reading only.
- The steps to check are in `TODO.md`: edit a cell while another tab changes it, then press Escape; type a token and press Enter; add a page and a column.

## Amendment 1

Commits:
- `feat: Botcake sync reads only the last 45 days of subscribers, and its lock expires after 2 hours, is released on SIGTERM/SIGINT and shows since when on the grid (spec 041 amendment 1)`
- `feat: read-only Botcake customers view per page with PSID, ad ids, phones, dates and order count, linked from the grid (spec 041 amendment 1)`
- `feat: the coverage list shows every order of the page with a With / Without PSID filter, its PSID, ad id and ad name (spec 041 amendment 1)`
- `fix: one sync lock per page so a long run never outlives the 2 h TTL, the signal handler frees only its own lock, and the customers view ignores array query parameters (spec 041 amendment 1)`

The second commit on its own leaves `PsidCoverageTest`'s query-plan test red: its new index made sqlite pick that index for the old "empty PSID" condition. The third commit fixes this. The branch is squash-merged, so no red commit reaches `develop`.

### Sync window (`App\Services\BotcakeCustomerSync`)

- **Window:** every sync stops after storing the first batch that holds a customer subscribed more than N days ago, with stop reason `age`. This covers incremental runs, `--full`, the button and the schedule.
  - N is `config('services.botcake.sync_max_age_days')`, env `BOTCAKE_SYNC_MAX_AGE_DAYS`, default 45. It was added to `config/services.php` and `.env.example`.
  - The date is `date_subscribed_at`, falling back to `subscribed_at`, then `inserted_at`.
  - A customer with no date never triggers the stop.
  - "Older than" is strict: exactly 45 days does not stop.
- **Order of the stop checks:** all-known batch (incremental only), then `age`, then `cap`.
- **Inactive marking:** it now also runs after a full read that stops on `age`, not only on `end`, and only touches customers with `subscribed_at` inside the window. Older customers and customers with no `subscribed_at` are never marked inactive.

### Lock

- **TTL:** 2 hours (it was 3), and there is now one lock per page. The command takes and releases the lock for each page, so a run over many pages never outlives the TTL.
  - A run that finds the lock held prints `Skipped: may tumatakbo pang Botcake customer sync (mula YYYY-MM-DD HH:MM).` and stops.
  - The queued job (one page) still takes one lock.
- **Since when:** a cache key `botcake-sync-customers:since` holds when the lock was taken, with the same TTL. It is cleared on release. `BotcakeCustomerSync::lockedSince()` reads it.
- **Signals:** `botcake:sync-customers` traps SIGTERM and SIGINT when pcntl is loaded. If the run holds a lock at that moment, it force-releases the lock, then exits. Without pcntl (Windows), the TTL covers it.
- **Grid:** the grid and every poll return `sync_lock` (Manila time, or null). The page shows "May tumatakbong Botcake sync mula …" while a lock is held.

### Customers view: `GET /page-settings/customers?page=<page name>` (CEO only, read-only)

- **Rows:** that page's `botcake_psids` rows with `source = api`. They are matched on the page's spelling in the Botcake settings, which is the spelling the sync wrote.
- **Order and pages:** newest `last_interactive_at` first, with rows that have no date last (`NULLS LAST` on Postgres), then id. 50 per page, with `p` as the page parameter.
- **Search (`q`):**
  - A PH mobile number (any of the 09 / 9 / 63 / +63 forms) matches `phone_key` exactly.
  - Anything else matches the start of `name_key` (`LIKE 'name%'`).
  - Both use the existing indexes.
- **Filters:** `status` (all / available / inactive) and `has_ad` (an `ad_id` is present).
- **Columns:** FB name, PSID, ad id (with "+N" when `ad_ids` has more, and the full list on hover), phones, subscribed, last interaction, last seen, status, orders.
- **Orders column:** one grouped query (`macro_output` where `botcake_psid IN (the 50 PSIDs)` and the page matches), served by a new index on `macro_output.botcake_psid`.
- **Links:** "Customers" in each grid row, and links between this view and the page's orders list.
- **Robustness:** an array in `page` or `q` (`?q[]=x`) counts as blank instead of returning a 500.

### Orders view: `GET /page-settings/psid-coverage/orders?page_name=…`

The amendment names `?details=`, but that list moved to this route in 040 amendment 1. I extended the existing route.

- **Rows:** every order of the page in the range by default. A new filter `psid` = all / with / without.
- **Reasons:** only orders without a PSID get a reason. Orders with a PSID have `reason = null`, and the reason filter only returns orders without a PSID. The correlated customer counts run only for orders without a PSID (`CASE WHEN … THEN (subquery) ELSE 0`).
- **Counts above the list:** one grouped query gives "May PSID: N" and the ambiguous / no match / not yet checked split.
- **New columns:** PSID, ad id, and the ad name ("campaign / ad set / headline").
  - The name comes from the ad's row with the highest `id` in `ads_manager_reports`.
  - It is one query for all ad ids on the page shown: `id IN (SELECT MAX(id) … WHERE ad_id IN (…) GROUP BY ad_id)`.
- **Query plan:**
  - The "empty PSID" test is now `COALESCE(mo.botcake_psid, '') = ''`. Without that, the new PSID index was chosen for `IS NULL OR = ''`, which matches almost every order.
  - The plan test asserts that "with" and "without" use `macro_output_ts_date_page_idx` and never the PSID index.
  - It also asserts that the customers view's order count uses `macro_output_botcake_psid_idx`.
- **Unchanged:** 50 per page, newest first, and the J&T filter.

### Migration `2026_10_17_110000_add_botcake_psid_index_to_macro_output`

- Adds index `macro_output_botcake_psid_idx` on `macro_output (botcake_psid)`.
- It is guarded:
  - It skips when the table or column is missing.
  - On MySQL it skips when any index already starts with `botcake_psid`.
  - On Postgres it runs `CREATE INDEX CONCURRENTLY IF NOT EXISTS` outside a transaction.
- `down()` drops only this index.

### Tests (amendment 1)

| Case | Test | Result |
|---|---|---|
| The sync stops at the first batch with a customer older than the window (stop reason `age`): incremental and full at 46 days; full at 44 days reads to the end; config 10 days → stops at 11 | `BotcakeCustomerSyncTest::test_every_sync_stops_at_the_first_batch_with_a_customer_subscribed_before_the_window` (4 rows) | pass |
| The all-known-batch stop comes before `age` | `…::test_the_all_known_batch_stop_comes_before_the_age_stop` | pass |
| A windowed full read marks inactive only customers subscribed within the window; older and undated stay available | `…::test_a_windowed_full_read_marks_inactive_only_customers_subscribed_within_the_window` | pass |
| The lock has a 2 h TTL (held at 1 h 59, free at 2 h 01); "since" set while held and cleared after; a second `withLock` skips | `…::test_the_lock_expires_after_2_hours_and_shows_since_when_it_is_held` | pass |
| The skip message says since when; `forceRelease` frees the lock and the "since" key | `…::test_a_signal_handler_force_releases_the_lock_and_the_skip_message_says_since_when` | pass |
| The grid and the poll show since when a sync holds the lock | `PageSettingsGridTest::test_the_grid_and_the_poll_show_since_when_a_sync_holds_the_lock` | pass |
| Customers: API rows of the page only, newest interaction first, 50 per page, no date last, page 2 link | `BotcakeCustomersViewTest::test_the_page_lists_its_api_customers_newest_interaction_first_50_at_a_time` | pass |
| Customers: search by name start / full name / phone (09 and +63 forms), status and has-ad filters (9 rows) | `…::test_search_by_name_or_phone_and_filter_by_status_and_ad` | pass |
| Customers: ad id with "+2" and the full list on hover, phones, subscribed; order count per PSID (other page excluded); no token or setting value in the HTML | `…::test_each_customer_shows_its_ad_ids_and_its_orders_with_one_grouped_count` | pass |
| Customers: the same query count for 5 and 50 customers (at most 6) | `…::test_the_query_count_is_the_same_for_5_and_50_customers` | pass |
| Customers: CEO only, linked from the grid, an array query parameter does not break the page | `…::test_only_the_ceo_reaches_it_and_the_grid_links_to_it` | pass |
| Orders: every order by default, with / without filter, PSID and ad id columns, reason only without PSID, ad name from the latest row, unknown ad id shown without a name, no token in the HTML | `PsidCoverageTest::test_the_list_shows_every_order_with_a_psid_filter_and_the_ad_of_each_order` | pass |
| Orders: the ad names of 50 orders are one query | `…::test_the_ad_names_of_50_orders_are_one_query` | pass |
| Orders: 50 per page, newest first, J&T filter (now with the `psid` filter) | `…::test_a_page_lists_its_orders_50_at_a_time_newest_first_with_the_jnt_filter` (updated) | pass |
| Query plans: ts_date/page index for all / with / without, never the PSID index; the order count uses the PSID index | `…::test_the_queries_use_the_ts_date_page_index_and_the_customer_key_indexes` (extended) | pass |

Red runs:
- Sync window: failed on "Failed asserting that two arrays are identical" (pages 3 and 4 were read) for three data rows. The inactive test failed on "two strings are identical".
- Lock: failed on `Call to undefined method …::lockedSince()` and `Undefined constant …::LOCK_TTL`.
- Grid lock notice: failed on `Undefined array key "sync_lock"`.
- Orders view: failed on 56 vs 57 (the default was still "without"), on the missing `with` split, and on 0 vs 1 ad query.
- Customers view: not a clean red. The tests were written before the controller, but their first run happened after it, and it died on a helper named `view()` that clashed with Laravel's. The array-parameter case was also written together with its fix.

Updated existing tests:
- `apiRow()` in `BotcakeCustomerSyncTest` now gives rows a `subscribed_at` (20 days ago by default), because inactive marking needs it.
- The `split` expectations in `PsidCoverageTest` now include `with`.

Full suite on the final tree: `Tests: 1300, Assertions: 16597, Errors: 1, Failures: 1, PHPUnit Deprecations: 4, Skipped: 3`. The only reds are the known `ExampleTest` and, in this fresh worktree, `ImportStartTest`.

### Review (amendment 1)

- One review at standard depth found no blockers and no majors.
- These minors were fixed:
  - One lock per page, so the TTL cannot run out during a long full run.
  - The signal handler frees only a lock its own run holds.
  - An array in a query parameter no longer gives a 500.
  - The plan test now uses the controller's real order-count query.
- The rest are accepted in `TODO.md` under "041 amendment 1", with reasons: the ad name by highest id, `LIKE` wildcards, the third copy of the page-match SQL, no test for the signal handler or Postgres `NULLS LAST`, interleaving between pages, and the existing lack of a unique key on `botcake_psids`.

### Amendment changes (amendment 1, kept in the handoff folder, not committed)

1. A read-only customers view per page: PSID, ad ids, phones, dates, status, order count; search, filters, 50 per page; linked from the grid.
2. The coverage list shows every order with a With / Without PSID filter, plus PSID, ad id and ad name columns.
3. A 45-day sync window (`BOTCAKE_SYNC_MAX_AGE_DAYS`, stop reason `age`); inactive marking limited to the window.
4. The sync lock: 2 h TTL, released on SIGTERM/SIGINT, "since when" shown on the grid and in the skip message.
5. Tests for the above.

### What the amendment got wrong, and decisions it left open

- **`?details=<page>`** no longer exists. The per-page list has been `/page-settings/psid-coverage/orders?page_name=` since 040 amendment 1, and that is the list I extended.
- **A new index was needed.** Counting orders per PSID needs `macro_output.botcake_psid` indexed, or every customers page reads the whole table. The brief kept storage unchanged, but this is an additive index, not a change to how data is stored.
- **"Latest row" of an ad** is taken as the highest `id`, which keeps it to one simple query.
- **Lock TTL per page,** not per run, so that a full run over many pages stays inside the 2 h TTL.
- **Customers with no `subscribed_at`** never trigger the window stop and are never marked inactive by a windowed read.

## After release (server)

1. `php artisan migrate --force`: one new migration, `2026_10_17_110000`, an index on `macro_output.botcake_psid`. It is additive, but building the index on the biggest table takes time, so run it in a quiet hour. Postgres builds it `CONCURRENTLY`.
2. Optional: set `BOTCAKE_SYNC_MAX_AGE_DAYS` in the server's environment (default 45), then `php artisan config:cache` if the config is cached.
3. If a stuck lock is still held from the earlier killed run, it now expires within 2 hours on its own. The grid shows since when it has been held.

`npm run build` is not needed: the pages use the Tailwind CDN like before.

## What the brief got wrong, and decisions it left open

- **"+ Page" / "+ Column" cannot be saved before a value exists.** Storage holds rows only, and the brief keeps storage unchanged. An added empty page or column lives in that browser until the first value is typed, and a reload drops it. This is in `TODO.md`.
- **All eight known columns always show,** not only the four flows: Botcake ID, token, message field and token status too. A sheet whose Botcake ID column disappears when it is empty would be odd. It is a superset of the decision.
- **Customers = `available` API rows,** as the brief says. The 040 card counted every API row, inactive ones included.
- **Clearing a secret:** the brief has both "blank = no change" for secrets and "clearing a cell deactivates". A secret is therefore cleared with "Burahin ang value" in the history panel (`clear: true`).
- **Who changed it:** the user's `users.name` (one query for all cells), or "system" when `created_by` is null.
- **The poll returns `now` 2 s early.** Times are stored to the second, so this overlap keeps a save that lands during the poll from being missed.
- **The page name** shown is the trimmed, space-collapsed spelling of the earliest active row of that page.
- Conflict with `CLAUDE.local.md`: none.

## Story changes

None. The brief brought no `qa/stories.md` stories; the cases above come from its Tests list.
