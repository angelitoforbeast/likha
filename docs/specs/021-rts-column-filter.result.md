# Result: brief 021 A filter on each column header of the RTS table

> Committed beside the spec: names no people and no decision ids.

**Status:** done (not seen in a browser: the browser checks are open, listed below)
**Date:** 2026-10-11
**Branch / PR:** `feat/021-rts-column-filter` (no PR, no push; the reviewer reviews the branch)
**Preview or run link:** n/a (no dev server and no browser in this run)

## Summary

The page is `GET /jnt_rts` → `FromJntController::rtsView` (→ `rtsFiltered`) → `resources/views/jnt_rts.blade.php`.
The server sends every row of the date range in one render and DataTables 1.13 (from the CDN) sorts, searches
and pages them in the browser, so the column filters are built in the browser. Nothing changed on the server:
no route, controller, query or permission.

- `public/js/rts-column-filter.js` (new, pure functions, one global `RtsColumnFilter`): distinct values with
  counts and their order, whether a row passes, the filter a panel gives, and what stays after a reload.
- `resources/views/jnt_rts.blade.php`: a filter button in each of the 13 headers, one shared panel, a
  "Clear column filters" button beside "Show N entries", the styles, and the wiring.
- `tests/js/call.cjs`: takes the name of the script's global as an optional argument (it was fixed to
  `ItemTableFit`), so the same runner serves the new script.

**How it joins the search path.** The Search box calls `dt.search(value).draw()`. The column filters are one
DataTables custom filter (`$.fn.dataTable.ext.search`) followed by the same `dt.draw()`, so everything that
hangs on a draw follows them as it follows the search: the rows and paging, the "Showing … (filtered from N)"
line, the TOTAL row, and both donut charts (the page redraws Full Range and RTS Projection from the filtered
rows in `updateTotals` on every draw; the server's numbers are only their first paint).

**Decisions made here**

- The list in a panel holds the values of the rows that pass Search and Item (exact), whatever the other
  column filters say; each count is the number of those rows.
- Checkboxes and the at least / at most condition of one column both apply. A cell without a number (N/A)
  never passes a condition. COD is treated as a number column (order and condition) though it is stored as text.
- Apply of the top form reloads the page: the filters are handed over once through `sessionStorage`, then
  chosen values that no longer exist are dropped (a filter with none left and no condition disappears). Reset,
  a plain reload and a fresh visit start without column filters, as the Search box does.
- Search and Item (exact) do not reload the page; they leave the column filters as they are.

## Tests

`php artisan test tests/Feature/Rts`: 17 passed (84 assertions).

| Test | Result |
| --- | --- |
| `RtsColumnFilterScriptTest` distinct text values are sorted a to z with their row counts | pass |
| … distinct numbers are sorted by value not as text and n a comes last | pass |
| … distinct date ranges are sorted by start date | pass |
| … only rows with a checked value pass | pass |
| … the at least and at most condition compares the number and n a never passes (4 rows) | pass |
| … a row must pass every filtered column and both the checkboxes and the condition | pass |
| … the panel gives a filter only when it narrows something | pass |
| … after a reload chosen values that still exist are kept and the others dropped | pass |
| … a broken saved state gives no filters and no error (5 rows) | pass |
| `RtsPageTest` every column header has a filter control and the page loads the filter script | pass |

Red first: the 16 script tests failed with `ENOENT … public\js\rts-column-filter.js`; the page test failed with
`Failed asserting that '<th …>Date Range</th>' matches PCRE pattern "/<button type="button" class="rts-cf-btn" data-col="0" …`.

Full suite in this worktree: 125 passed, 2 failed, neither touched by this change:

- `Tests\Feature\ExampleTest > the application returns a successful response` (302 instead of 200): the old
  known failure.
- `Tests\Feature\NightRun\ImportStartTest > macro job final write applies only whi…`: needs
  `storage/app/credentials.json`, a file git does not track and this worktree does not have.

The warnings on every test that boots the app are the missing local environment file of the worktree.
`node --test 'test/hooks/*.test.mjs'`: 1026 pass, 5 fail, all five `EPERM … symlink` on Windows in the kit's
own format-on-edit tests.

## Not checked

No browser in this run: the wiring in the view (DataTables calls, the panel, the keyboard, the placement) was
read and syntax-checked (`node --check` on the inline scripts) but never executed. `npm run build` was not
run: no Vite asset changed (the new script is a static file under `public/js`). To check on the site:

1. Open RTS. Each header shows a small funnel button beside the sort arrows; clicking a header's text still sorts.
2. Click the funnel of SENDER: a panel opens under the header with a search box, Select all, Clear, one
   checkbox per sender with a count, Cancel and Apply. Click Clear, check two senders, Apply: only their rows
   remain; the header and its funnel turn teal; "Showing … (filtered from N)", the TOTAL row and both donuts
   change; "Clear column filters" appears beside "Show N entries".
3. Funnel of RTS%: type 20 in "At least", Apply: only rows of the two senders with RTS% of 20 or more.
   The list in this panel is ordered by value (9.50% before 30.00%).
4. Type in SEARCH: the rows narrow further; reopen a funnel: its list shows only values of the rows that
   pass the search.
5. Keyboard: Tab to a funnel, Enter opens it; Tab moves inside the panel and wraps; Space checks a box;
   Enter in the search box applies; Escape closes and returns to the funnel. Clicking outside closes it.
   Opening another funnel closes the first.
6. Change the date range and press Apply (top form): the page reloads and the sender filter is still on, if
   those senders have rows in the new range; a sender without rows is dropped without an error.
7. Reset: no column filter remains. "Clear column filters" does the same without a reload.
8. Narrow the window to phone width and open the funnel of the last column: the panel stays on screen.

## Story changes

None: the brief brought no stories and asked for none.

## Accepted findings and suggestions

- No reviewer agent ran (the brief's fast mode); nothing was accepted or dropped.
- Suggestion for the owner, not done: jQuery, DataTables and flatpickr of this page come from public CDNs.
