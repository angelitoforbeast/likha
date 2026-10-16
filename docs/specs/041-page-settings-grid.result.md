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

## After release (server)

Nothing to run: no migration and no new config. `npm run build` is not needed, because the page uses the Tailwind CDN like before, with no Vite asset.

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
