# 258: Days running on the page row, and one column list — result

Branch `feat/258-days-running-columns`, from `origin/feat/254-creative-per-ad` at d50d030c.

## What changed

| File | What |
|---|---|
| app/Support/OwnerPrivateColumns.php:27 | `COLUMNS`: the one list of page summary columns (id, settings label, page header, sort, align, minw, `label_fn`, `ceo_only`, `item_only`). `days_running` is at :29. |
| app/Support/OwnerPrivateColumns.php:100 | `forPage(bool $isCeo)`: the page's columns (drops `item_only`; drops `ceo_only` for non-CEO). |
| app/Support/OwnerPrivateColumns.php:120 | `jsList(bool $isCeo)`: the same as JS object literals for `defaultCols()`, strings escaped for a `<script>`. |
| app/Support/OwnerPrivateColumns.php:144 | `daysRunning($since, $end, $today)`: the count rule. |
| app/Http/Controllers/OwnerColumnSettingsController.php:70 | `CATALOG['owner_private'] = OwnerPrivateColumns::COLUMNS` (the 60-line hardcoded copy is gone). |
| app/Http/Controllers/OwnerColumnSettingsController.php:205 | `days_running` added to `DEFAULT_VISIBLE['owner_private']` (CEO sees it when nothing is saved). |
| app/Http/Controllers/OwnerPrivateController.php:303 | `withDaysRunning()`: sets `rows[*].days_running` from `anchor_first_date` and the payload's `end_date`; called on the cache-hit return (:1711) and the fresh return (:2965). |
| resources/views/owner/private.blade.php:2202 | `defaultCols()` is now `{!! OwnerPrivateColumns::jsList(!empty($isCEO)) !!}` (the 55-line hardcoded list is gone). |
| resources/views/owner/private.blade.php:765 | The `days_running` cell: the number, or `—` when null; hover shows `since <date>`. |
| tests/Feature/OwnerPrivate/DaysRunningColumnTest.php | New, 17 tests (below). |
| tests/Feature/OwnerPrivate/ProfitOneDayMTest.php:223, tests/Feature/RtsProjection/AnalystRtsTest.php:165 | Needles updated from the old hand-typed spacing to the generated `{ id:'x', label:'y', sort:… }` format. |
| tests/Feature/OwnerPrivate/SharedPagesBasePinTest.php:18 | Re-pinned both renders (see below). |

## Days running: inclusive

`days_running` = whole days from `anchor_first_date` (the date the current primary item became primary; the same date the row's numbers start from and the "computed since" line shows) to the end date of the selected range, **counting both days**: since Nov 3, viewing to Nov 9 = 7. If the range ends after today, it counts to today. `null` (shown as `—`) when the row has no since date, or that date is after the range end.

- It is added when the item-summary response goes out, not stored in the cache, so payloads cached before this change also get it, and the "to today" cap stays current.
- Sortable: the header sorts by `row.days_running` (sort key `days_running`); conditional formatting can use it (it falls through `cellValueFor`'s default).
- The TOTAL row leaves it blank (the existing catch-all blank cell).
- Note: the campaigns expand's "Days running" counts the other way (today − first_started, today not counted). Not changed.

Visibility: catalog position 1, so with nothing saved it is the first column, visible for CEO, hidden for Marketing / Marketing - OIC until turned on. With the saved `owner_private_cols` (production), the existing rule appends it at the end of the saved order, visible for CEO, hidden for the two roles until the owner ticks it; the owner can drag it next to the page name. No migration.

## Columns in only one list (before)

Compared the JS list in private.blade.php (50 columns) with `CATALOG['owner_private']` (57):

- Only in the JS list: none.
- Only in the catalog: `category`, `stock`, `incoming`, `units_per_day`, `doi`, `order_qty`, `lifecycle` — the /item row columns (/item reads the same `owner_private` settings). Kept, marked `item_only`, so the settings page still shows them and /owner/private still leaves them out.
- Same order for the 50 shared ids. Labels differ on 8 (settings label vs page header), both kept: `tcpr` (TCPR (Pending Rate) / TCPR), `np_per_order` (NP/O (1D) / NP/O), `np_per_order_3d|7d|1m` (NP/O (3D) / NP/O(3D) …), `claude_action|claude_reason` (… (CEO) / without), `breakeven_cpp` (Breakeven CPP / `this._breakevenLabel()` → `label_fn`).
- Claude/CEO note columns stay out of the non-CEO page source (`ceo_only`), as before.

Column ids are unchanged, so saved `owner_private_cols` and `owner_private_col_format` keep working.

## S3: campaigns (noted, not fixed)

The campaigns columns are duplicated more than the page summary was: `CATALOG['campaigns']` (OwnerColumnSettingsController.php:71) plus three JS copies of `defaultCampaignsCols()` — resources/views/owner/private.blade.php:3341, resources/views/item/index.blade.php:2789, resources/views/ads_manager/campaigns.blade.php:450 — and the copies already differ (the /owner/private and /item copies have no `video_plays_3s` / `video_thruplays` / `video_p50`; the Campaigns Manager copy has no `ad_rdt` / `ad_*` delivery columns). Not trivial, so left as is.

Also outside scope: /item has its own copy of the page summary JS list (resources/views/item/index.blade.php:1645). It drops unknown ids, so `days_running` is simply not on /item.

## Render pins

`SharedPagesBasePinTest` re-pinned after diffing base vs new renders (CRLF/root/CSRF-normalised): /owner/private differs only by the `days_running` cell, its id in the injected order, its `defaultCols` entry and comment, and whitespace in the generated list (a space-stripped diff shows nothing else); the column settings page differs only by `days_running` in the catalog, order and default-visible JSON and the extra `sort`/`align`/`minw` keys in the catalog JSON.

## Tests

- New: DaysRunningColumnTest, 17 tests — the endpoint value for a page with a since date (7 over Oct 3–9 viewing Oct 5–9; 1 for a page that switched on the last day), a cached row without a since date (`null`), the count rule (8 cases), the page cell, role visibility (no saved config, saved config before the column, role turned on), the settings catalog being the single list, the rendered page list equal to the catalog (same ids, order, header, sort, align, minw) for CEO and without the CEO-only columns for Marketing, the settings page listing every column, and a new entry in the single list reaching the page list.
- Full suite at d50d030c: 4894 tests, 2 failures (ExampleTest::test_the_application_returns_a_successful_response, ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order — both known), 6 skipped.
- Full suite on this branch (dc084262): 4911 tests (4894 + the 17 new), 2 failures, the same two known ones, 6 skipped. No flaky failures came up in either run (AstraAddressRulesTest S_26_18 / S_26_23, BigBroadcastTest S_167_1 all passed).
- Note on the known ItemTableFitScriptTest failure: it reads the `owner_private` block out of OwnerColumnSettingsController.php with a regex. At base it found 57 ids where it expects 45; now that block is `OwnerPrivateColumns::COLUMNS`, so it finds 0. It fails at the same assertion (tests/Feature/Item/ItemTableFitScriptTest.php:690). Whoever fixes it should read app/Support/OwnerPrivateColumns.php (or `CATALOG` itself) and add widths for `days_running` and the other columns it is missing.

## Final sha

The tip of the branch is the commit that adds this file; the code is at dc084262 (feature 314a8535, re-pin dc084262).
