# Brief 024: a PROF(1D)-M column on the owner's private page

**Why.** On /owner/private (CEO page) the owner reads "Prof.Profit(1D)" (column id `proj_prof_1d`, payload field `projected_profit_last_day`) for each page. He wants a second column beside it, labelled **PROF(1D)-M**, that computes the same day's profit with his stricter rules. The existing column, and every other column and number on every page, stays exactly as it is.

**Where it is today** (main/develop 5ea46f3; verify the lines):
- `app/Http/Controllers/OwnerPrivateController.php` `itemSummary` (~1577-2726): per-day loop ~2437-2517 (the 1D value is the `$isLastDay` slice, the end date `$loadEnd`); partial-date override ~2531-2587; result row ~2634; fee lookups `$resolveAsOf` ~2160-2169 and ~2466-2468; the 422 for a missing `shipping_fee_per_order` ~1703-1710; cache with `Cache::forever` and `bumpCacheVersion()` ~1581-1592, 2723.
- `app/Http/Controllers/OwnerColumnSettingsController.php`: `CATALOG['owner_private']` ~66-114, `DEFAULT_VISIBLE` ~204-217, `loadConfig` ~732-799 (new ids are appended at the end of a saved order; hidden from Marketing roles until the CEO ticks them).
- `resources/views/owner/private.blade.php`: `defaultCols` ~2101-2142, body cell ~928-942, TOTAL cell ~1420-1428 and the exclusion list ~1429, styles ~762 / ~1353 (`pbStyleN`), `tot()` ~2877-2904, `cellValueFor` ~3044 (format rules need the number from here).
- `app/Models/FeeSetting.php`, `app/Http/Controllers/FeeSettingController.php` (key `in:` list ~32; `max:1` only for the two cod keys), `resources/views/jnt/fee-settings.blade.php` (option list ~43, per-key table ~98, JS keyed to `shipping_fee_per_order` ~308).
- `tests/...ItemTableFitScriptTest` asserts the owner_private catalog count (44) and needs a `MINW` and `DROP` entry in `public/js/item-table-fit.js` for every catalog id.

**The formula.** Today, for the end date d, per page:
```
revenue  = proceed x price x (1 - RTS%)
PROF(1D)   = revenue - proceed x SF(d) - proceed x (1 - RTS%) x cogs(d) - ads - proceed x (1 - RTS%) x price x cod_rate(d) x (1 + vat(d))
PROF(1D)-M = revenue - proceed x SF_M(d) - proceed x cogs(d) - ads x 1.12 - proceed x (1 - RTS%) x price x cod_rate(d) x (1 + vat(d))
```
So -M differs in exactly three terms:
1. **Shipping:** `SF_M(d)` from a new date-based fee setting `shipping_fee_per_order_m`, not the existing shipping fee.
2. **COGS:** on every proceed order (shipped = proceed), with no RTS factor. Same cogs / cogs_ceo rule as today.
3. **Ads:** times 1.12, hardcoded in the -M computation only (a named constant with a comment saying why).
Revenue, the COD fee (still from the fee settings, `1 + vat`), the window, the date logic, the skipped-day rule (end day skipped -> null), page grouping and the partial-date override all behave as for the existing column; the partial-date override gets the same three changes (it reads SF_M at the end date, as it does the shipping fee today). Put the -M day formula in one small function used by both the loop and the override, so it can be unit-tested; do not change the existing formula's code path or results.

**What to build:**
1. **Fee setting.** A migration seeds `shipping_fee_per_order_m` = 40 with `effective_date` 2000-01-01 for both scopes (`likha` and `incepxion`), idempotent (a second run adds nothing). The 2000-01-01 date means every page date has a value without the back-fill warning. Fee Settings accepts the new key (`numeric|min:0`, no `max:1`), shows it in the option list and in its own table block with edit and delete, like "Shipping Fee per Order". If no SF_M row exists, -M is null for every page; the page never fails (no 422) because of SF_M.
2. **Column.** Catalog id `proj_prof_1d_m`, label `PROF(1D)-M`, directly after `proj_prof_1d`, in `DEFAULT_VISIBLE`; payload field `projected_profit_last_day_m`. A settings migration puts the id right after `proj_prof_1d` in a saved `owner_private_cols` order (nothing else in the saved config changes; idempotent). Not in `CEO_ONLY`; Marketing roles do not see it until the CEO ticks it (the existing rule); a Marketing viewer's value uses `cogs`, as the existing column does. Add it everywhere the page needs a money column: `defaultCols`, body cell (same money format as Prof.Profit(1D)), TOTAL cell and the exclusion list, row and TOTAL styles, `tot()` (sum of the filtered rows, nulls skipped, blank when all null), `cellValueFor` (so sorting and the owner's format rules work). Update `ItemTableFitScriptTest` to 45 and give the id a width and drop entry in `item-table-fit.js` (same as `proj_prof_1d`); /item must show no extra column.
3. **Fresh data.** Storing, updating or deleting any fee setting calls `bumpCacheVersion()` (a failed validation does not). The migration also bumps it, so payloads cached before the release (without the new field) are not served after it.

**Worked examples (end date 2026-10-09, CEO viewing as CEO, scope likha).** Use them in tests (floats with delta 0.001):

| | A | B | C | D: partial_date 2026-10-10 |
|---|---|---|---|---|
| proceed | 50 | 20 | 20 | orders 60 x proceed rate 0.8 = 48 |
| price | 599 | 799 | 799 | 599 |
| RTS% | 30 | 0 | 0 | 30 |
| cogs_ceo / cogs | 180 / 170 | 250 / 240 | 250 / 240 | 180 / 170 |
| ads | 4,000 | 1,500 | 1,500 | 3,000 |
| cod_fee_rate / vat | 0.015 / 0.12 | 0.02 / 0.10 | 0.015 / 0.12 | 0.015 / 0.12 |
| shipping_fee_per_order | 33 | 35 | 33 | 33 |
| SF_M | 40 | 40 | 40 | 40 |
| **PROF(1D)** | **8,662.788** | **8,428.44** | **8,551.536** | **9,156.27648** |
| **PROF(1D)-M** | **5,132.788** | **8,148.44** | **8,231.536** | **5,868.27648** |
| -M, viewer non-CEO (cogs) | 5,632.788 | 8,348.44 | | |

A worked: revenue 50x599x0.7 = 20,965. -M = 20,965 - 2,000 (50x40) - 9,000 (50x180) - 4,480 (4,000x1.12) - 352.212 (35x599x0.015x1.12) = 5,132.788.
B worked: 15,980 - 800 - 5,000 - 1,680 - 351.56 (20x799x0.02x1.10) = 8,148.44.
More cases: RTS 100% on A -> -M = -15,480; proceed 0 with everything else present -> -M = -4,480 (existing -4,000); SF_M 40 from 2000-01-01 plus 45 from 2026-10-10 -> A at end date 2026-10-10 = 4,882.788, at 2026-10-09 = 5,132.788, existing unchanged; no RTS history, no COGS history, or end-day price missing -> -M null like the existing column; no SF_M rows -> -M null, page loads, existing value unchanged; pages A + C TOTAL: existing 17,214.324, -M 13,364.324. The existing PROF(1D) and every other old payload field must be identical before and after for A to D.

**Tests:** unit tests on the extracted -M function with the table above; feature tests (sqlite, real migrations) for the seed and settings migrations (idempotent, placement after `proj_prof_1d`, Marketing hidden), the fee-settings store/update/delete with the new key and its bad inputs (unknown key, -1, "abc", empty, duplicate key+date), the cache version changing on a fee save and not on a failed one, and a render check that the page contains the new column's id in the places listed. If `itemSummary` cannot run on sqlite, say so and rely on the unit tests. Show the new tests failing before the code where that is natural. The whole existing suite must still pass (known old failures: `ExampleTest`, and the credentials-file test in a fresh worktree; name any other).

**Out of scope:** the /item page beyond keeping it unchanged, the private snapshot pages, the four other copies of the profit formula (breakdown matrix, breakdown per-date, daily summary, JntSupplyController), a percent column "Prof.%(1D)-M", 3D/7D/1M versions of -M, and any change to the existing Prof.Profit(1D).

**Final message:** what you changed (files), the test lines (new tests and the full suite), the values your tests got for A to D, anything you could not check (no browser here: say exactly what the owner should look at on the live page after release), and the commit ids. One clean commit for the feature is preferred (the reviewer squashes onto develop).
