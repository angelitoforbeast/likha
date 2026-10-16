# Brief 042: `/page-settings` lists the pages that sell, and tests or syncs them all at once

## Why

The grid (spec 041) lists only pages that already have a setting: 41 pages copied from an old sheet. On live data, 51 pages had orders in the last 30 days and 18 of them are missing from the grid, among them the busiest (over 2,000 orders each); 8 grid pages had no order in 30 days. The owner: the pages should be based on the pages that are running and have orders. He also has to press Test token and Sync now one row at a time.

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| Grid rows = every page with at least one `macro_output` order whose `ts_date` is within the last N days (config `services.botcake.grid_order_days`, env `BOTCAKE_GRID_ORDER_DAYS`, default 30), plus every page with any active setting. Pages are the same page when `PageSettings::pageKey()` matches (trim, case, inner spaces). Display name: the setting's spelling when the page has settings, else the most common spelling in `macro_output.PAGE` | The owner, 2026-10-17: "dapat dun sa existing page na running/may order diba?" and "Oo, gawin mo ang 042: ang grid ay batay sa mga page na may order sa 30 araw (naka-gray sa ibaba ang walang order)" |
| Order: pages with orders first, most orders first (ties by name); then pages with settings but no order in the window, greyed out (whole row muted, still editable), by name. A new read-only info column "Orders (30 araw)" (label follows N) shows the count. A page is never removed from the grid because it stopped selling: its settings stay | Same; settings such as tokens must not be lost |
| A page with orders but no settings shows empty cells the owner can fill, exactly like a row added with "+ Page" | Same |
| The order counts are one grouped query over `macro_output` filtered by `ts_date` (indexed; about 1M rows), cached for 10 minutes; the polling endpoint (`changes`) does not run it. Blank or whitespace PAGE values are ignored | The grid polls every few seconds |
| "Test lahat ng token" button above the grid: tests every page that has `botcake.page_id` and `botcake.token`, one page at a time, and each row's Token status cell updates as its result comes in (ok / invalid / error), with a running count (e.g. "12 / 45") and a summary at the end (ok, invalid, error, skipped without id or token). No single HTTP request may run longer than about 30 seconds (one Botcake call per request is fine, e.g. the browser calls a JSON per-page test endpoint in turn). Same result recording as the per-row button (`botcake.token_status`) | The owner: "wala ba nung bulk test token at sync now?" then "may bulk Test lahat ng token at Sync lahat na isa-isang tatakbo" |
| "Sync lahat" button: queues an incremental sync of every page that has `botcake.page_id` and a token not marked invalid, and they run strictly one page at a time, in grid order. One failing page does not stop the rest. A page must not be silently skipped because another sync holds the lock (the scheduled sync or a row's Sync now): it waits and runs after. Pressing "Sync lahat" again while a run is in progress does not start a second run; it says one is running. The grid shows the run's progress (e.g. "Sync lahat: 7 / 40, now: <page>") through the existing polling, and each row's Last sync updates as before. Same 45-day window and stop rules as every sync (spec 041) | Same: "isa-isang tatakbo"; the Botcake API is read at 2 requests per second per run |
| The automatic schedule stays gated by `BOTCAKE_SYNC_ENABLED` (still off in production); the buttons work regardless, like the per-row Sync now | The owner decides the automatic sync separately |
| CEO only (404 for others) for every new route, as in 040 and 041. No token or setting value in any response | Tokens |

## Tests

Rows: a page with orders but no settings appears with empty cells; a page with settings and no recent orders appears greyed at the bottom; pages with orders sorted by count; spellings that differ only in case or spaces merge into one row; orders older than N days don't count; blank PAGE ignored; the order-count query runs once per grid build and not in `changes`; query count stays flat for 60 pages. Bulk test: the per-page endpoint records token_status like the row button, skips pages without id or token, CEO only, no token in the JSON. Sync all: pages run one at a time in order; a failing page does not stop the next; a held lock makes the page wait, not skip; a second press during a run starts nothing; pages without id or with an invalid token are left out; progress readable by the poll. Red first where natural. The whole suite must pass except the known `ExampleTest` (and `ImportStartTest` in a fresh worktree).

## Out of scope

Turning on the automatic schedule; changes to the sync's window, matching or storage; the customers and orders views; sending messages.

## Final message

Commits; how rows are chosen and sorted; how the bulk test and sync-all run (jobs, lock, progress, longest single request and job run time, and the queue worker's timeout it relies on); query counts; test lines and suite result; anything the brief got wrong.
