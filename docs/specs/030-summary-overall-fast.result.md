# Result: brief 030 The actual net profit page (/summary/overall) loads fast, shows how settled each number is, and is CEO only

Branch `feat/030-summary-overall-fast`, cut from `develop` at d6352b4.

## Commits

| Commit | What |
|---|---|
| `27644c1` docs | The brief, verbatim |
| `8838fca` test | Reference capture: small sqlite branches so the old code runs in tests; its `/data` JSON saved on a fixture (All Pages, single page, padded single page) |
| `2ec5436` feat | Part A: same numbers, three combined queries, cached columns, ads filter on `day`, indexed page list, no-date default, one table rendered, one formatter per cell |
| `e5f2403` feat | Part B: realized, expected, pending, settled %, final per row and TOTAL |
| `3b6ecd7` feat | Part C: CEO only; dead `/daily` route removed |
| (last commit) docs | This result |

## Part A: statements per `/data` call

Counted with `DB::listen` around one request on the test fixture (sqlite), the same way before and after.

| Mode | Before | After (column cache warm) | After (cold, first call of the day) |
|---|---|---|---|
| All Pages | 42 | 16 | 17 on sqlite; on MySQL +3 column listings +1 collation lookup |
| Single page | 42 | 16 | same |

The 16 statements: 3 fee settings, 1 ads, 2 pre-drops, `CREATE _mo_norm`, `CREATE _jnt_agg`, `hasTable cogs_ceo`, 1 cogs, orders, shipped, items, then the per-date shipped pass (All Pages) or the COD mode (single page), 2 drops. On Postgres one `ALTER ... ADD PRIMARY KEY` is still added (Postgres cannot declare a key in `CREATE ... AS`).

### How the joins were combined

1. `_mo_norm` (temporary table): the `macro_output` rows in range (and page, in single-page mode), each normalised once: `day_key`, `page_key`/`page_label` (TRIM/LOWER), `status_n`, `wb`, `item_key`/`item_label`, raw `COD`. The COD cast stays in the SELECTs, because a CAST inside `CREATE ... SELECT` fails under strict mode on a dirty COD (in a plain SELECT it is only a warning, as today).
2. `_jnt_agg`: built once from `from_jnts` for the waybills in `_mo_norm`; MySQL declares `(PRIMARY KEY (wb))` in the CREATE (no ALTER). `submission_time` is DATETIME, so `MIN(COALESCE(submission_time, created_at))` replaces the three `STR_TO_DATE`s. The `UPPER` variant of each status LIKE is dropped only when `from_jnts.status` has a `_ci` collation (read from `information_schema` once a day); otherwise both LIKEs stay (Postgres keeps both).
3. Orders query (`_mo_norm` only): orders, proceed, cannot proceed, ODZ and proceed COD sum per key, by conditional aggregation (used to be two queries).
4. Shipped query: per (key, waybill) first (MAX of the status flags, SUM of shipping cost, MAX COD, MIN delay), then per key: shipped, delivered, returned, for return, in transit, actual shipping, gross (delivered COD), all COD, average delay. This replaces five joins (counts, actual shipping, gross, all COD, delay). In All Pages mode the same query runs once more per (date, page) for the Estimated RTS, which replaces the seventh join.
5. Items query (`_mo_norm` LEFT JOIN `_jnt_agg`): per (key, item) the proceed qty, last proceed date, COD min/max and label, plus the delivered waybill count and last delivered date for COGS. This replaces the proceed-items query and the delivered-items join.
6. Ads: `day BETWEEN ? AND ?` (`day` is a DATE column, so the same rows); page filter unchanged.
7. Column names: `Schema::getColumnListing` once per table, cached a day (`summary_overall.columns.<driver>`) plus a static per process; the same candidate lists and the same case rule as `Schema::hasColumn`.
8. `index` page list: `SELECT DISTINCT page_name ... ORDER BY page_name` (uses `ads_report_page_idx`), then trim, one label per lower-cased name and a case-insensitive sort in PHP.
9. `/data` without dates: last 30 days in PH time (today − 29 to today), like the page.
10. Front end: each table sits in its own `<template x-if>`, so only the visible one is built; each cell has 2 templates (items, or one `cellText`/`cellClass`/`cellStyle` formatter) instead of 15–28 `x-if`s. Same classes, styles and text as before.

## Part B: the formulas as found (unchanged)

**Net Profit (actual, "Realized")**, per row:
`gross − adspent − shipping − cod_fee − cod_fee_vat − cogs`, where
- `gross` = COD (per waybill, max COD of its rows) of **delivered** waybills only;
- `shipping` = J&T `total_shipping_cost` summed over all **shipped** rows (delivered, returned, for return, in transit; a waybill with two `macro_output` rows counts twice);
- `cod_fee` = gross × COD fee rate; `cod_fee_vat` = cod_fee × VAT rate;
- `cogs` = delivered waybill count per item × unit cost on the item's last delivered order date (CEO: `cogs_ceo`);
- held orders (proceed, not shipped) add nothing; returned and for return add only their shipping cost; ads count in full.
- `net_profit_pct` = net profit ÷ COD of all shipped waybills.
- TOTAL: the same formula on the summed parts.

**Projected Net Profit ("Expected")**, per row:
`P × (1 − RTS) − adspent − P × cod_rate − (P × cod_rate) × vat − U × (1 − RTS) − ship_rate × proceed`, where `P` = COD sum of **all proceed** orders (held, in transit, delivered, returned, for return alike), `U` = their unit cost sum, `ship_rate` = the per-order shipping fee setting.
- RTS, All Pages: per page from the dates whose in-transit share < 3%; if none, the page's raw RTS; if none, 30%.
- RTS, single page: from the days with in-transit < 3%; else from all days; else 30%.
- The COD fee is charged on all proceed COD (not reduced by RTS).
- It **does** count held orders (they are in proceed), so no change was needed there.
- TOTAL row: `projected_net_profit` is null in the JSON today; the page shows only the TOTAL %, which is (sum of row projections) ÷ (sum of proceed COD).

**New fields** in each `/data` row (`ads_daily[]`) and the TOTAL row:

| Field | Value |
|---|---|
| `realized_net_profit` | `net_profit` |
| `expected_net_profit` | `projected_net_profit`; TOTAL: sum of the rows' projections (the same sum the TOTAL % uses) |
| `pending_net_profit` | expected − realized |
| `settled_pct` | (delivered + returned + for return) ÷ proceed × 100, clamped 0–100; null when proceed = 0 |
| `is_final` | hold ≤ 0 and in transit = 0 |

On the page: a new column "Realized → Expected" at the end of both column sets (sortable by pending): `₱realized → ₱expected`, `pending ₱x`, `NN.NN% settled · ✓ final` (green when final), in one 11 px cell on three lines.

## Part C

`index` and `data` call `checkAccess()`: the trimmed, whitespace-collapsed role must match `/^ceo$/iu` (the check the controller already used for the CEO cost table), else 403. The `/summary/overall/daily` route (no method behind it; it answered 500) is removed, so it is a 404 for everyone.

## Case table

| Case | Test | Result |
|---|---|---|
| A: JSON equals the old output, All Pages | `SameNumbersTest::test_data_json_equals_the_old_output_field_by_field` "all pages" | pass |
| A: same, single page | same, "single page" | pass |
| A: same, single page with a padded lower-case name | same, "single page, padded" | pass |
| A: fewer statements (≤ 21, was 42), 3 modes | `test_data_runs_fewer_statements_than_before` | pass (red before: 42 > 21) |
| A: no-date call works, last 30 days | `test_data_without_dates_uses_the_last_30_days` | pass (red before: SQL syntax error near "AND") |
| A: page list unchanged | `test_page_list_is_the_trimmed_distinct_ads_pages` | pass (characterisation) |
| A: one table rendered, 2 templates per cell | `PageCellsTest::test_only_the_visible_table_is_rendered_and_cells_use_one_formatter` | pass |
| A/B: formatter text, class, style | `PageCellsTest::test_cell_formatters_give_the_same_text_class_and_style_as_before` | pass |
| B: All Pages rows + TOTAL fields | `SettledTest::test_all_pages_rows_and_total_carry_realized_expected_pending_settled_and_final` | pass (red before: undefined key) |
| B: single page days | `SettledTest::test_single_page_days_carry_the_fields` | pass (red before) |
| B: final when no hold and no in transit | `SettledTest::test_row_is_final_when_nothing_is_on_hold_or_in_transit` | pass (red before) |
| C: CEO 200, padded "ceo" 200, Marketing - OIC / Marketing / Encoder 403, `/daily` 404 | `CeoOnlyTest::test_page_and_data_are_ceo_only` (5 roles) | pass (red before: 200 and 500) |

Fixture (`SummaryOverallTestCase::seedFixture`): delivered, returned, for return, in transit, a waybill in transit then delivered, an upper-case and a lower-case DELIVERED, hold (null and empty waybill), cannot proceed, ODZ, a duplicate `macro_output` row, two pages (one with a padded lower-case label), a page with ads only, two items (one written `Item-One`), two dates plus one outside the range, CODs `1,250.00`, `1,250`, `1250`, `899`. The reference numbers were checked by hand (Page Alpha: 11 orders, 9 proceed, 6 shipped, 3 delivered, gross 3,399, shipping 438, COGS 1,140, net profit 863.3968, delay 11/6).

## Suite

`php artisan test` on the final tree: **Tests: 2 failed, 974 warnings, 141 passed (15823 assertions)**. The 2 failures are the known ones: `Tests\Feature\ExampleTest` (the application returns a successful response) and `Tests\Feature\NightRun\ImportStartTest` (macro job final write applies only while the run is still active). No other test fails. The warnings come from this worktree having no environment file (`artisan test` tries to read it), not from the tests.

`tests/Feature/SummaryOverall`: 18 tests, all pass. `php -l` clean on every changed PHP file. No Vite asset changed (the page loads its scripts from a CDN), so no `npm run build`.

The kit's `node --test 'test/hooks/*.test.mjs'` does not finish on this Windows machine (EPERM creating a symlink in the format-on-edit QA test); this change touches no kit file.

## Brief vs code

- Tests run on sqlite, so the MySQL SQL (the `PRIMARY KEY` in the CREATE, `DATEDIFF`, the collation lookup) is not run by any test. The reference is the old code run on sqlite through two small sqlite branches (temp table without `STR_TO_DATE`/ALTER, delay via `julianday`); those branches do nothing on MySQL or Postgres.
- "Statements about 30" in the brief: on the fixture the old code ran 42 (16 of them `hasColumn`).
- Order-dependent details, the same as before: when a page's labels differ only in letter case, which spelling shows can depend on row order (as it did); the Target CPP mode ties also depend on row order, as before.
- The front-end tests were written with the change, not red first (the markup test would have failed on the old `x-show`).
- `in_transit` counts a waybill as in transit when **any** of its `from_jnts` rows has a non-final status (the existing flag). If `from_jnts` keeps several status rows per waybill, a delivered waybill with an older "In Transit" row stays "in transit" (the fixture's WB05), and such a row never gets the **final** mark. I kept the brief's definition and did not change the flag.
- The dead `/daily` route was removed (a 404 for everyone, CEO too), one of the two options the brief gave.

## Owner checks after release (no browser here)

1. `/summary/overall`, All Pages, default 30 days: the numbers match yesterday's (spot check a page: Net Profit, Projected %, RTS, Delay) and it loads in well under the old 6–8 s.
2. One page selected: the daily rows, the top summary and Target/Breakeven CPP are as before.
3. The new "Realized → Expected" column fits with no horizontal scroll at 1366 px in both the limited and the full columns; the TOTAL row shows the sums.
4. A saved column order resets once to the default, because the column list gained a key (the page's own check resets an order whose keys differ).
5. Logged in as Marketing - OIC: the page and its data give 403. The "Back to Summary" link on `/item/cogs` now leads non-CEO users to that 403.
6. Whether rows ever show **final** (see the `in_transit` point above).

## Suggestions for the owner (not done)

- `fmtItemsWithCosts` puts item labels into `x-html` unescaped (stored text from orders). CEO-only now, but worth escaping.
- `_proj_cod_den` (an internal helper value) leaks into the JSON rows, as before.
- The TOTAL row's `projected_net_profit` stays null in the JSON (unchanged); `expected_net_profit` carries the sum.
