# Result: brief 029 Orders are counted live next to proceed, and each page's main item stays fresh

Branch `feat/029-orders-live-primary-fresh`, cut from `develop` at 941d986.

## Commits

| Commit | What |
|---|---|
| `0de0d08` docs | The brief, verbatim |
| `d3e2a0a` fix | Part A: orders counted live next to proceed; owner-private cache bumps |
| `edd1d11` feat | Part B: main item recomputed on every change path, nightly 14-day refresh, tie rule with `is_tie` |
| (this commit) docs | This result and the 029 entries in `TODO.md` |

## Part A: every reader of `primary_orders`

Orders are now counted with `COUNT(*)` in the same `macro_output` query as proceed, grouped by the same page, item key
and date, so proceed ≤ orders always. The stored `primary_orders` is used only when there is no live row.

| Reader | What changed |
|---|---|
| `OwnerPrivateController::itemSummary` `statRows` / `statByKeyDate` | `COUNT(*) AS orders_live` carried as `orders`; each included date's `orders` = live count. ORDERS, CPP, gross, profit %, orders 1D/3D/7D, per-order columns, TCPR and breakeven CPP follow. Included-days and price filter unchanged. |
| `itemSummary` partial-date slice (`$pdOrders`) | Live count of that date (same lookup), not the snapshot. |
| `renderBreakdownMatrix` cells | Proceed query gains `COUNT(*)`; cell `orders` and the cell PROJ% gross use live orders. |
| `pageRangeBreakdown` | `primary_orders` (display) and the gross denominator use the live count it already loaded; `total_orders` = live count of all items of that page-day. |
| `JntSupplyController::index` profit % | Proceed query gains `COUNT(*)`; slice orders and the gross denominator use it (see "Brief vs code" for the key). |
| `dailyDataImpl` (`/owner/private` daily data) | No change: it reads `primary_orders` only to pick the slice; its formula already uses live proceed only. |
| `private-breakdown.blade.php` (`r.primary_orders`) | No change; it now receives the live value. |
| `DailyPrimaryItemService` (writer), the create migration, existing tests | No change (writer / schema / fixtures). |

Cache: `bumpCacheVersion()` is now called after the refresh button (`refreshPrimaryItems`), at the end of the Likha
sheet import (also when the run is closed mid-way), after an inline edit (`updateField`) or bulk update
(`bulkUpdate`) of `macro_output` that touches `ITEM_NAME`, `COD`, `STATUS`, `TIMESTAMP` or `PAGE`, after an alias
save or delete, and by the `supply:refresh-primary-items` command. PAGE was added to the brief's list: moving a row
to another page changes both pages' counts the same way.

## Part B: the main item stays fresh

- **Likha import:** `importRow` records the date of every row it creates or updates (last 10 characters of
  `TIMESTAMP`, `d-m-Y`, the same rule as the DB trigger); `afterImport()` recomputes those dates (non-fatal, like the
  macro import) and bumps the cache.
- **Inline edit and bulk update:** the old `ts_date` (before save) and the new one (read back after save, because
  the trigger sets it) are recomputed.
- **Alias mapping save, inline save and delete:** the dates of the last 90 days that have rows with that item name
  are recomputed (`DailyPrimaryItemService::recomputeForItemNames`).
- **Macro import date list vs `ts_date`:** checked; no mismatch. The import normalises every parsed TIMESTAMP to
  `H:i d-m-Y` before both storing it and taking the date, and the trigger reads the same last 10 characters. A row
  whose TIMESTAMP cannot be parsed has no TIMESTAMP and so no `ts_date` on either side.
- **Schedule** (`routes/console.php`):
  `Schedule::command('supply:refresh-primary-items --last=14')->timezone('Asia/Manila')->dailyAt('03:50')->withoutOverlapping();`
  The command already had `--last=N`; no new option was needed.
- **Migration** `2026_10_16_000001_add_is_tie_to_daily_page_primary_item.php`: additive boolean `is_tie`, default
  false, guarded with `hasTable` / `hasColumn`. The service and readers work before it runs (the flag is skipped).
- **Tie rule as built** (`DailyPrimaryItemService`): among the items sharing the top count, the one with more
  proceed rows wins; still tied, the page's previous-day main item if it is one of them (taken from this run's pick
  when that day is inside the recompute, else from the stored row); still tied, the higher mode COD; then the item
  key alphabetically (ascending). Days are resolved oldest first. The row is stored with `is_tie = true` and its
  orders are the tied count. The "second" item is the next by count (ties by key).
- **Tie mark:** a small amber "tie" label next to the main item on `/owner/private` (anchor date's pick), each matrix
  cell of `/owner/private/breakdown`, and each day of the page breakdown. Inline styles only, so no asset build.
- **Messages:** the command table and the three refresh buttons now say "N tie(s) resolved"; the summary key is
  `ties_resolved` (was `ties_skipped`).

## Tests

New: `tests/Feature/OwnerPrivate/OrdersLiveTest.php`, `PrimaryItemFreshTest.php`, and the shared base
`MacroOutputTestCase.php` (the hand-made sqlite tables). Red runs, first failure line:

| Test | Red before the code |
|---|---|
| item summary uses live orders so tcpr is never negative | `Failed asserting that 24 is identical to 64.` |
| breakdown matrix cell shows live orders | `Failed asserting that 24 is identical to 64.` |
| page range breakdown uses live orders for display and gross | `Failed asserting that 24 is identical to 64.` |
| jnt supply profit uses live orders next to proceed (via `?debug_item=` JSON) | `Failed asserting that 24 is identical to 64.` |
| macro output edits of order fields bump the owner private cache (5 cases: inline COD, inline item, bulk status bump; address-only inline and bulk do not) | the 3 bump cases failed (`false` vs `true`) |
| refresh primary items button bumps the owner private cache | failed (`false` vs `true`) |
| likha import end bumps the owner private cache | failed (`false` vs `true`) |
| a tie keeps the day and picks by the tie rule (4 cases: more proceed, previous day main, higher mode COD, key) | `Failed asserting that null is not null.` (no row on a tie day) |
| a clear winner is not flagged as a tie | written with the tie rule (needs the new column) |
| a tie day keeps its orders and ad spend in the item summary totals (also the "tie" flag in the page breakdown) | written after the tie rule; passes 8 orders, 7 proceed, ₱300 ads |
| likha import recomputes the main item of its dates | `Failed asserting that null is identical to '1xalphalamp'.` |
| an inline item edit recomputes the main item of that day | `Failed asserting that two strings are identical.` |
| saving an item alias recomputes the days of that item | first a missing table in the test; with it, the old main item stayed |
| the last 14 days are recomputed nightly at 0350 manila | `Failed asserting that actual size 0 matches expected size 1.` |

Changed: `SharedPagesBasePinTest` re-pinned for `/owner/private` (`07469c67…`). Checked that the only change in that
render is the blade change (the new "tie" span and the "tie(s) resolved" text): with `private.blade.php` reverted the
old pin passes.

Suite: SUITE_LINE

## Review

No separate reviewer run (fast mode). Self-checked: proceed ≤ orders on every changed reader (same query, same key),
the fallback when no live row exists, the old and new date on an edit that moves a row, the previous-day lookup
when the previous day is inside the same recompute, and that tie days no longer delete rows. Accepted points with
reasons are in `TODO.md` under 029, including a pre-existing one: the encoder update routes write any column the
request names.

## For the owner, after release

1. After the migration (`php artisan migrate --force`), run the refresh once: on **/owner/private** click **🔄 Primary Items** and confirm
   (or `php artisan supply:refresh-primary-items --last=90`). The message ends with "N tie(s) resolved".
2. Open **/owner/private**, range mode, the last 7 or 14 days. TCPR is never negative; for any page,
   ORDERS ≥ PROCEED.
3. Open a page's **breakdown**: ORDERS per day match the order sheet for that page and item; a day that was a tie
   shows a small amber **tie** next to the item and is no longer "— no data —".
4. Open **/owner/private/breakdown** (matrix): the same days show **tie** in the cell.
5. Edit one order's item on **Encoder → Checker 1**, then reload /owner/private: that page-day's numbers change at
   once (no ?refresh=1 needed).
6. Next morning, check `php artisan schedule:list` shows `supply:refresh-primary-items --last=14` at 03:50 Asia/Manila.

## Brief vs code

- JNT supply already keys proceed by the base item (quantity prefix stripped, so "1 x X" and "2 x X" add up) while
  its main-item rows are exact items. Orders are counted on that same base key so proceed ≤ orders holds there too;
  the supply page's profit % therefore uses all quantities of the base item for both. Kept as is (no profit-definition
  change); noted in `TODO.md`.
- The brief's list of edit fields (item, COD, status, date) left out PAGE; added, for the same reason.
- `pageRangeBreakdown`'s `total_orders` ("primary / total" display) was also a snapshot; it is now live too, so the
  two numbers of that cell come from the same source.

## Story changes

None (fast mode, no stories file for this brief).

## Conflicts

None between the brief and the project's instructions.
