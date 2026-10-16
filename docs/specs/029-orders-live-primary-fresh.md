# Brief 029: orders are counted live next to proceed, and each page's main item stays fresh

## Why

On `/owner/private` (item summary, range mode) TCPR = (1 − proceed / orders) shows negative values (e.g. −2.9%, −35.4%), which is impossible. Measured on production: `orders` comes from `daily_page_primary_item.primary_orders`, a snapshot written by `App\Services\DailyPrimaryItemService` only after a macro sheet import, and only for the dates seen in that import; `proceed_orders` is counted live from `macro_output` (`OwnerPrivateController::itemSummary`, `statRows` around lines 1779-1911). Orders that arrive or are edited after a day's last recompute never reach the snapshot. Example: one page-day had 24 orders in the snapshot and 64 live, 60 of them proceed. The same stale count feeds ORDERS, CPP, gross, profit %, orders 1D/3D/7D, per-order columns, TCPR and breakeven CPP; `JntSupplyController` and the breakdown matrix have the same pattern. The main-item pick itself (which item wins per page per day) also goes stale the same way. A tie for the top count stores no row, so that day's orders, proceed and ad spend drop out of every total (rare: 2 of 1,286 page-days in 30 days).

## Decisions already made (do not reopen)

| Decision | Why |
|---|---|
| Orders and proceed are counted in the same query, from `macro_output`, for the same page, item key and date | So proceed ≤ orders always holds and every ratio is consistent |
| The stored table stays the source of which item is the main item per page per day, its price and the streak | Picking live on every page load is too heavy |
| "1 x X" and "2 x X" stay different items | Owner's rule |
| A tie keeps the day: the tied item with more proceed rows wins; still tied, the page's previous-day main item if it is one of them; still tied, the higher mode COD, then the key alphabetically. The row is flagged as a tie | Owner agreed; the day must not vanish |
| Nightly recompute of the last 14 days at 03:50 Manila (after the night Astra step, which runs 03:00 to about 03:45) | Safety net for every path that changes rows |

## Part A: orders live (commit on its own)

- `OwnerPrivateController::itemSummary`: add `COUNT(*)` to `statRows`, carry it through `statByKeyDate`, and use it as the orders of each included date (fall back to the stored count only when there is no live row). Everything that reads `included_dates['orders']` or `$totalOrders` follows. Keep the included-days and price filter as it is.
- Same change where the stored `primary_orders` is used as orders next to a live proceed: `JntSupplyController` (orders and gross denominator), `renderBreakdownMatrix` cells, `pageRangeBreakdown` gross and display (it already loads live counts). Find any other reader with `git grep primary_orders` and say in the result what you did with each.
- The owner-private cache must not hide fresh numbers: bump its version (`bumpCacheVersion()`) in `refreshPrimaryItems`, after the Likha sheet import, and after inline edits and bulk updates of `macro_output` item, COD, status or date.

## Part B: the main item stays fresh (commit on its own)

- Recompute `daily_page_primary_item` for the dates of rows that the Likha sheet import (`ImportLikhaFromGoogleSheet`) creates or changes, and for the `ts_date` of rows changed by the `macro_output` inline edit and bulk update paths, and after an item alias mapping is saved. The macro import already does this for its dates; check that its date list matches `ts_date` (it parses TIMESTAMP in PHP while `ts_date` comes from a DB trigger) and fix a mismatch if you find one.
- Schedule the existing command `supply:refresh-primary-items` for the last 14 days nightly at 03:50 Asia/Manila in `routes/console.php`, `withoutOverlapping()`. Add the option to the command if it has no way to say 14 days.
- Ties: implement the rule in the table above; add a boolean `is_tie` (migration, additive, default false) and show a small "tie" mark next to the main item where the page shows it. Count tie resolutions instead of skips in the command and button messages.

## Tests

Part A: a page-day whose stored `primary_orders` is lower than the live rows shows the live orders, TCPR is not negative and equals 1 − proceed/live orders; CPP uses the live orders; the JNT supply and breakdown numbers use live orders; cache bump calls happen on the named paths. Part B: a Likha import and an inline item edit recompute their dates; the nightly schedule is registered at 03:50 Manila; the tie rule picks by proceed, then previous day, then COD, and flags `is_tie`; a tie day keeps its orders and ad spend in the totals. Red first where natural. The whole suite must pass except the known `ExampleTest` and `ImportStartTest`; name any other.

## Out of scope

Picking the main item live on page load; merging "1 x" and "2 x" items; any change to how profit is defined; back-filling or editing production data (the reviewer runs the refresh after release); the checklist.

## Final message

Commits; what changed per reader of `primary_orders`; the migration; the schedule line; the tie rule as built; test lines and the suite result; what the owner should click or check after release (no browser here); anything you found that the brief got wrong.
