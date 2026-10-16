# Brief 041: `/page-settings` looks and works like a Google Sheet

## Why

The owner opened `/page-settings` (spec 040: one card per page) and wants it "parang gsheet lang itsura": one row per page, one column per setting ("page, botcake id, botcake token, delivering flow id, etc."). The storage stays Page | Key | Value (`page_settings`, spec 040); only the page changes.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| One grid: one row per page (all pages that have any active setting, plus pages added from the grid), one column per distinct active provider + key, built from the data (a new key becomes a new column automatically). Fixed first column: page name (sticky); then friendly-labelled known columns in this order: Botcake ID (`botcake.page_id`), Botcake token (`botcake.token`), Shipping flow ID (`botcake.flow.shipping`), Delivering flow ID (`botcake.flow.on_delivery`), In transit flow ID (`botcake.flow.in_transit`), Broadcast flow ID (`botcake.flow.broadcast`), Message field ID (`botcake.field.message`), Token status (`botcake.token_status`, read-only); then any other keys by name; then read-only info columns Customers (count of `available` API rows), Last sync (time + stop reason) and actions (Test token, Sync now, Coverage link) | Owner: "parang gsheet ... page, botcake id, botcake token, delivering flow id etc" |
| The four flow columns above always show, even before any page has a value, so the owner can fill them | He named delivering explicitly |
| Editing like a sheet: click a cell, type, it saves by itself on blur / Enter / a short pause after typing (no Save button), with a small Saving / Saved / error mark per cell; the grid polls every few seconds and refreshes cells nobody is editing, so other users see changes without reloading; last write wins and the cell's tooltip shows who changed it and when | Owner precedent (TeleSMS Shop names page, 2026-10-04): "hindi auto save per cell? autoupdate din dapat ... parang gsheet" |
| Secret cells (token and any key marked secret) show `•••• last4`; clicking opens an empty input: typing a value replaces it (blank = no change); the value is never sent to the browser | Tokens |
| The same validation as spec 040 per key (digits for ids, non-empty token, token_status system-only); an invalid value shows the error in the cell and saves nothing | Typos like "442139078/" |
| "+ Column": a small form (provider, key, secret checkbox) that adds a column (stored as rows only when a value is entered); "+ Page": adds a row by page name (trimmed; warn when it matches no `macro_output.PAGE`); per-cell history (popover or a side panel) from the replaced rows; clearing a cell deactivates that setting | Page | Key | Value: new kinds without new columns |
| CEO only (404 for others), as in 040. Keep `/page-settings/psid-coverage` and the 040 routes working; the old card view may be removed | — |
| Fast at 200+ pages and 30 keys: one query for the settings, one grouped query for customers and last sync; no query per cell | Scale |

## Tests

Grid shows one row per page and the known columns in order, including empty flow columns; a new key becomes a column; cell save (valid → new active row, old one replaced; invalid → error, nothing saved; same value → nothing); secret never in any response (HTML or JSON); token replace keeps last4 only; clear cell deactivates; + Page and + Column; polling endpoint returns only changed cells since a timestamp; CEO only; query count stays flat for 50 pages. Red first where natural. The whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree).

## Out of scope

Changes to storage, sync, matching or the coverage page; sending messages.
