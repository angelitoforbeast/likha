# Result: brief 024 A PROF(1D)-M column on the owner's private page

> Committed beside the spec: names no people and no decision ids.

**Status:** done (not seen in a browser: the browser checks are open, listed below)
**Date:** 2026-10-14
**Branch / PR:** `feat/024-prof-1d-m` (no PR, no push; the reviewer reviews the branch)
**Preview or run link:** n/a (no dev server and no browser in this run)

## Summary

A new column **PROF(1D)-M** (`proj_prof_1d_m`, payload field `projected_profit_last_day_m`) sits right after
Prof.Profit(1D) on /owner/private. It is the same day's profit with three stricter terms: shipping from the new
fee setting `shipping_fee_per_order_m` (SF_M), COGS on every proceed order (no RTS factor), and ads × 1.12.

- `app/Support/ProfitDayM.php` (new): the -M day formula, `ProfitDayM::compute()`, with the constant
  `ADS_FACTOR = 1.12`. Null when RTS%, cogs, the COD fee rate, VAT or SF_M is missing, or there is no price.
- `app/Http/Controllers/OwnerPrivateController.php` `itemSummary`: SF_M read as of the end date beside the other
  fees (no 422 when missing); in the per-day loop the end-date slice (`$isLastDay`) also computes -M, with SF_M
  resolved per day through the same `$resolveAsOf` as the other fees; the partial-date override computes -M from
  the same projection (SF_M as of the end date, like the shipping fee) and nulls it wherever it nulls the 1D
  profit; the row gets `projected_profit_last_day_m`. The existing formula's lines are untouched (only lines
  added), and SF_M takes no part in the back-fill flags.
- `app/Http/Controllers/OwnerColumnSettingsController.php`: catalog entry after `proj_prof_1d`, and in
  `DEFAULT_VISIBLE`. Not in `CEO_ONLY`.
- `resources/views/owner/private.blade.php`: `defaultCols`, body cell (the same `md()` money format and
  `pbColor` as Prof.Profit(1D)), TOTAL cell and the exclusion list, row and TOTAL styles (`pbStyleN(…, 1)`),
  `tot()` (sum of the filtered rows, nulls skipped, blank when all are null), `cellValueFor`.
- `app/Http/Controllers/FeeSettingController.php`: the key is accepted (`numeric|min:0`, no `max:1`); store,
  update and delete call `OwnerPrivateController::bumpCacheVersion()` after the write, so a refused input
  bumps nothing.
- `resources/views/jnt/fee-settings.blade.php`: the option "Shipping Fee per Order (M) — PROF(1D)-M", its own
  history block with Edit and Delete like "Shipping Fee per Order", and the amount input (₱, step 0.01) for it.
- `database/migrations/2026_10_14_100000_seed_shipping_fee_per_order_m.php` (new): SF_M = 40 from 2000-01-01
  for `likha` and `incepxion`, skipped when the row is there; bumps the cache version.
- `database/migrations/2026_10_14_100100_add_proj_prof_1d_m_to_owner_private_cols.php` (new): puts the id right
  after `proj_prof_1d` in a saved `owner_private_cols` order; `hidden` and `visible_by_role` are not touched; no-op
  when there is no saved order, no `proj_prof_1d` in it, or the id is already there.
- `public/js/item-table-fit.js`: `MINW` 78 and a `DROP` place right after `proj_prof_1d`.
  /item shows no extra column: its own `defaultCols` has no `proj_prof_1d_m`, and `initCols` drops ids it does
  not know.

**Decisions made here**

- The formula lives in `App\Support` (beside `ItemBaseKey`), not as a controller method, so the unit test needs
  no app.
- The comment on `ADS_FACTOR` gives the reason as the 12% VAT that comes with the ad spend. The brief gave the
  factor but not its reason; if the owner's reason is a different one, the comment should say that instead.
- In the per-day loop, SF_M is resolved with `$resolveAsOf` (an SF_M that starts after a day is back-filled from
  its earliest row, as the other fees are). With the 2000-01-01 seed that never happens.
- In the partial-date override, SF_M comes from `FeeSetting::getRate()` at the end date, as the shipping fee does.

## Tests

New tests (`php artisan test` on these files): 39 passed.

| Test | Result |
| --- | --- |
| `Unit\ProfitDayMTest` the worked examples (9 rows: A, B, C, D, A and B as a non-CEO viewer, RTS 100%, proceed 0, SF_M 45) | pass |
| … a missing input gives null (6 rows: no RTS, cogs, cod rate, vat, SF_M, price 0) | pass |
| … ads are counted at 112 percent | pass |
| `OwnerPrivate\ProfitOneDayMTest` the seed gives both scopes 40 from 2000-01-01 once and bumps the cache | pass |
| … the settings migration puts the column right after prof 1d and changes nothing else (and is idempotent; Marketing and Marketing - OIC hidden) | pass |
| … without a saved config the CEO sees the column after prof 1d and Marketing does not | pass |
| … fee settings store, update and delete the new key and each bumps the cache | pass |
| … a bad store is refused and leaves the cache version (5 rows: unknown key, -1, "abc", empty, duplicate key+date) | pass |
| … a bad update is refused and leaves the cache version (4 rows: -1, "abc", empty, duplicate key+date) | pass |
| … the fee settings page offers the new key and lists its rows with edit and delete | pass |
| … the page has the column in every place a money column needs | pass |
| `OwnerPrivate\ItemSummaryProfitMTest` (real `itemSummary` on sqlite) pages A and C get the brief values and prof 1d stays as it was | pass |
| … page B with its own cod fee, vat and shipping (CEO, and Marketing from cogs) | pass |
| … a Marketing viewer gets the value from cogs (A) | pass |
| … page D on a partial date projects 48 proceed | pass |
| … SF_M follows its effective date (40, then 45 from 2026-10-10) | pass |
| … without SF_M rows the page loads and only -M is null | pass |
| … -M is null where Prof.Profit(1D) is null (2 rows: no RTS history, no end-day price) | pass |

Values the tests got (delta 0.001):

| | PROF(1D) | PROF(1D)-M | -M, Marketing viewer |
| --- | --- | --- | --- |
| A | 8,662.788 | 5,132.788 | 5,632.788 |
| B | 8,428.44 | 8,148.44 | 8,348.44 |
| C | 8,551.536 | 8,231.536 | |
| D (partial_date 2026-10-10) | 9,156.27648 | 5,868.27648 | |
| A, end date 2026-10-10 with SF_M 45 | 8,662.788 | 4,882.788 | |
| A, RTS 100% / proceed 0 (unit) | | -15,480 / -4,480 | |

A + C TOTAL is computed in the browser by `tot()` (sum of the rows): 17,214.324 and 13,364.324 from the row values
above; no JS harness runs `tot()` here, so the markup test checks its code, not its output.

**Old payload unchanged.** The `itemSummary` rows for A, B (CEO), A + C, D, the Marketing viewer, the no-SF_M case
and the null case were dumped from the controller before this change and after it, on the same fixtures: every
field is identical; the only difference is the added `projected_profit_last_day_m`.

Red first: the unit tests failed with `Class "App\Support\ProfitDayM" not found`; with the production code taken
out (migrations kept), six feature tests failed on the behaviour: the column order (`Failed asserting that two
strings are identical`), the store of the new key (`The selected setting key is invalid`), the duplicate
key+date (`Session missing error: effective_date`), the fee settings page and the /owner/private markup
(needles missing); with the migrations also out, the seed test failed (`File does not exist`).
`ItemSummaryProfitMTest` was written after the code and passed on its first complete run; its payload
comparison against the old controller is above.

**Existing tests changed**

- `Item\ItemTableFitScriptTest`: catalog count 44 → 45, as the brief asks.
- `OwnerPrivate\SharedPagesBasePinTest`: the two pinned render hashes (/owner/private and the owner_private
  column settings) are re-pinned. The test pins the whole render of those pages; this brief changes both on
  purpose. Before re-pinning, the old code was checked to give the old hashes, and a diff of the old and new
  renders holds only the new column: the body and TOTAL cells, the two style expressions, the exclusion list,
  `defaultCols`, `tot()`, `cellValueFor`, the injected column order, and the catalog and default list on the
  settings page.

Full suite in this worktree (`php artisan test`): 911 passed (Collision shows most of them as "warnings"), 2
failed, neither touched by this change:

- `Tests\Feature\ExampleTest > the application returns a successful response`: the old known failure.
- `Tests\Feature\NightRun\ImportStartTest > macro job final write applies only whi…`: needs
  `storage/app/credentials.json`, a file git does not track and this worktree does not have.

The warnings on every test that boots the app come from the worktree's missing local environment file.
`node --test 'test/hooks/*.test.mjs'`: 1026 pass, 5 fail, all five `EPERM … symlink` on Windows in the kit's
own format-on-edit tests (as before this change).

Lint: `php -l` clean on every changed PHP file. No Vite input changed (`item-table-fit.js` is served as is, and
the Blade uses only class names already in those files), so no build was needed.

## Not checked

No browser here. After release (with both migrations run), the owner should look at:

1. /owner/private as CEO: a **PROF(1D)-M** column right after **Prof.Profit(1D)**, with peso values in the same
   style, red/green backgrounds by the same thresholds, a TOTAL in the TOTAL row, and sorting by its header. Each
   value should be below the Prof.Profit(1D) beside it whenever proceed > 0 (more COGS, SF ₱40 vs today's
   shipping fee, ads × 1.12); with a partial date set, both 1D columns change together.
2. /owner/private with "view as Marketing": the -M value rises by proceed × (cogs_ceo − cogs), as it does for
   Prof.Profit(1D).
3. /owner/column-settings, owner_private section: PROF(1D)-M after Prof.Profit(1D), ticked for the CEO and
   unticked for Marketing and Marketing - OIC.
4. A Marketing account on /owner/private: no PROF(1D)-M until the CEO ticks it.
5. /jnt/fee-settings: the option "Shipping Fee per Order (M) — PROF(1D)-M" and its history block with ₱40.00 from
   Jan 01, 2000; editing it to another amount changes the -M column on the next load of /owner/private.
6. /item: no new column.
7. The owner's format rules on /owner/column-settings can now pick PROF(1D)-M; one rule on it should colour its
   cells.

## Story changes

None (fast mode: no stories for this brief).

## Accepted findings and suggestions

- The new Fee Settings block copies the markup of the "Shipping Fee per Order" block. Merging the two blocks into
  one partial is a refactor left for the owner.
- `FeeSetting::getRate()` (used by the 422 check and the partial-date override) does not back-fill, while the
  per-day loop does (`$resolveAsOf`). Old behaviour, kept as it is; with the 2000-01-01 SF_M seed it never shows.
- The four other copies of the profit formula (breakdown matrix, breakdown per-date, daily summary,
  JntSupplyController) do not get -M; out of scope.
