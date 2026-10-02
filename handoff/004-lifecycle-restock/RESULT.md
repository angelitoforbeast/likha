# Result 004: Restock target by item lifecycle on /item

Status: **done, waiting for Mira's review of branch `feat/lifecycle-restock`** (cut from `develop` at `88cc0bc`). There's no PR, so this file stands in for the PR body. Nothing was pushed, merged or deployed.

## Summary

**Lifecycle, shared with `/jnt/supply`**
- `classifyLifecycle` and `lifecycleBadge` moved word for word into `App\Support\ItemLifecycle` (`classify`, `badge`, plus the default thresholds 14 / 30 / 90 / 1.5 / 0.5).
- `JntSupplyController` keeps both private methods; they now only call `ItemLifecycle`. Every `/jnt/supply` call site and request default is unchanged.
- A characterisation table pinned the old private method before the move (`5628d5b`). After the move, the same table passes against the controller and against `ItemLifecycle` (`317825f`). `git diff 5628d5b 317825f` shows the two method bodies replaced by calls, and `ItemLifecycle` holds the same lines.

**`GET /item/stock`, per base key**
- New fields: `lifecycle`, `lifecycle_label`, `lifecycle_auto`, `gated` (true only for Scaling and Consistent), `palugit_override`, and the sets `normal` and `lugi`.
- Each set has `units_per_day`, `palugit`, `doi`, `doi_note` (`walang_benta` / `halos_walang_benta` / null), `order_qty`, `order_by` and `colour` (`red` / `amber` / `green` / `grey` / null).
- The per-set fields left the top level (`units_per_day`, `safety`, `doi`, `order_qty`, `order_by`, `colour`). `/item` is their only reader, and 003's tests now read `normal.*`.
- Lifecycle inputs match `/jnt/supply`, with as-of = the `/item` end date:
  - recent units = days end−13…end; previous units = days end−27…end−14
  - every `macro_output` row counts (no STATUS filter)
  - `round(units/14, 4)`
  - `daysRunning` from the first order date (9999 with none)
  - `lifecycle_override` wins when it's set
- Velocity: 003's 14-day rule. For Scaling's `normal` set only: the larger of units in the last 7 days ÷ 7 (cancelled orders excluded) and the 14-day velocity (Amendment 004-1).
- Palugit per set: the per-item `palugit_override` wins in both sets; otherwise the lifecycle default (`normal`) or `palugit_lugi` (`lugi`). Phasing Out and Dormant are HOLD-based even with an override.
- Phasing Out / Dormant:
  - `order_qty = max(0, ceil(HOLD − paparating − stock))`
  - grey "walang benta"
  - order-by "—"
- Near-zero sellers (0 < v < 0.5): grey "halos walang benta". The order quantity still follows the formula.

**Settings**
- Palugit defaults are six `supply_settings` rows in group `item_palugit`. They're edited in the existing generic editor on `/jnt/supply/config` under "Other settings".
- `POST /jnt/supply/setting-kv` (already CEO-only) adds one check: an `item_palugit` key accepts only a whole number from 0 to 255. Every other key behaves as before.
- Per-item palugit: the new nullable `supply_item_settings.palugit_override`.
  - `POST /item/supply-settings` writes it together with `safety_days`.
  - A blank palugit clears only the override.
  - Lead and `safety_days` are written as in 003, so `/jnt/supply` sees the same values.

**`/item` page**
- LIFECYCLE column:
  - registered in `CATALOG` and `DEFAULT_VISIBLE` (CEO), `defaultCols()`, the item-row cell and sort-by-label
  - opt-in for Marketing, like 003's columns
  - badge colours match `/jnt/supply`; the badge adds " · lugi" when the lugi set is chosen and "(manual)" for an override
  - one-line Taglish tooltip per lifecycle, with its palugit
- Set choice: one combined profit figure per base item (formula below). BENTA/ARAW, DOI, the "lead N · palugit M" line ("HOLD lang" when there's no palugit), I-ORDER, its order-by line, the I-ORDER tooltip and sorting all read the chosen set.
- CEO palugit editor:
  - it's pre-filled from `palugit_override`
  - blank means "default ng lifecycle", and the page then sends no `safety_days`
- CATEGORY:
  - removed from `DEFAULT_VISIBLE`
  - a data migration hides it in the saved column config and removes it from every role's visible list
  - the Category selector shows only while the column is visible; when the column is hidden, the filter resets to Lahat and is ignored
  - tables, data, routes and the command stay

**Combined profit formula (Mira's answer 2)**, `baseProfitPct7(name)` in `resources/views/item/index.blade.php`:
1. Group every page row in `this.rows` by trimmed, lowercased `item_name`, the same grouping `itemGroups` uses. Each group is one item row.
2. Build its aggregate `A = aggOf(rows)`, the same object the PROF.%(7D) cell shows (`A.proj_pct_7d = projected_profit_last_7d / gross_sales_last_7d × 100`).
3. Leave out any item row with `A.projected_profit_last_7d == null` or `A.gross_sales_last_7d <= 0`.
4. For each base key (`supKey`): `basePct = Σ A.projected_profit_last_7d ÷ Σ A.gross_sales_last_7d × 100`. When no row is left, it's `null` (no gate).
5. The `lugi` set is shown when `S.gated && basePct != null && basePct <= 0`; otherwise `normal`.

The result is memoised per `this.rows` array. That array is replaced on every item-summary load, so a new date range rebuilds it. It uses every page row, not the checkbox-filtered subset, so every variant row of a base shows the same set. What's not tested is under "Review findings".

**Unchanged:** `/jnt/supply` output, Supply Finance, PO data, `HoldService`, `item-summary`.

## Done-when evidence

| Done-when | Evidence |
|---|---|
| Plan sent to Mira, her "go" received before code | The plan was the final message of the first run. Commits before the go: `21e2cbd` (handoff files) and `4d003c2` (spec and plan). Mira's go came with answers 1–5, recorded in the spec (`3d2de0c`). The first code commit is `5628d5b`, after the go. |
| `artisan test`: no new failures; §5 rows, DOI example, `lugi` set, override precedence, settings gate and validation, `/jnt/supply` lifecycle unchanged | Final `php.bat artisan test --compact` (after Amendment 1 and fix list 1): `Tests: 1 failed, 3 skipped, 210 passed (1709 assertions)`; before them it was 210 passed (1689 assertions). The only failure is the known baseline `Tests\Feature\ExampleTest > the application returns a successful response` ("Expected response status code [200] but received 302."). The 3 skipped are the Boardroom live tests. Coverage is below the table. |
| `php -l` clean; `npm run build` OK | `php.bat -l` on all 15 changed PHP files (`ItemController`, `JntSupplyController`, `OwnerColumnSettingsController`, `ItemStockService`, `ItemLifecycle`, the 3 migrations and 7 test files) → "No syntax errors detected" for each. `npm run build` → `vite v6.3.5 … ✓ built in 2.62s`, then again after the final fix (`✓ built in 2.30s`). The bundle is unchanged (`app-DNxiirP_.js 35.32 kB`), because the page JS is inline in Blade. |
| Markup assertions: LIFECYCLE column, set from PROF.%(7D), "walang benta" / "halos walang benta", CATEGORY hidden and the selector tied to it | `ItemPageTest`, 11 tests, all green. Details below the table. |
| skeptic-reviewer ran; findings handled | Four reviews at standard depth (sonnet), one per task. No blocker and no major. Two T4 minors fixed in `938e7e1`; the rest are accepted in `TODO.md`. See "Review findings". |
| RESULT filled; SQL for EXPLAIN; commits on branch; clean tree | This file. The SQL is below. The commits `21e2cbd..` are all on `feat/lifecycle-restock`; the last one adds this file, `TODO.md` and the reviewer memory. `git status` is clean after it. |

**Coverage for the test row:**
- **`LifecycleStockTest`**, 7 tests, all through `GET /item/stock`:
  - every handoff §5 row with typed values (lead 7, stock 0, HOLD 50, v 10):

    | Case | Order qty |
    |---|---|
    | New | 150 |
    | Active | 190 |
    | Consistent | 220 |
    | Scaling, v7 15 | 365 |
    | Scaling `lugi` | 150 |
    | Consistent `lugi` | 150 |
    | Declining | 120 |
    | Phasing Out / Dormant | 50, `walang_benta`, grey |
    | Consistent with `palugit_override` 5 | 170, and its `lugi` set also uses 5 |

  - the DOI example: Scaling, stock 300, v7 15 → `16.7` `amber`
  - 0.3/day with HOLD 12 → `halos_walang_benta`, with the formula quantity
  - natural classification of all seven lifecycles from real `macro_output` rows
  - `lifecycle_override` precedence (`lifecycle_auto` false)
  - cancelled orders count for the lifecycle but not for velocity
  - an item missing from the cached first-date map is classified from its window date
  - a palugit default read from `supply_settings`
- **`JntSupplyLifecycleTest`** (unit), 51 cases:
  - 17 classify rows covering every branch and boundary (30/31 days, 89/90 days, exactly ×1.5 and ×0.5, priorities)
  - 8 badge rows
  - both run against `JntSupplyController`'s private methods (by reflection) and against `ItemLifecycle`
  - plus a test that pins the constants
- **`ItemEditTest`** (2 new tests):
  - `setting-kv`: Marketing and Marketing - OIC get 403 and nothing is written; the CEO gets 200
  - `setting-kv` 422s for an `item_palugit` key: 256, −1, "x", 2.5; 0 and 255 pass; a non-palugit key keeps its old range (300 is accepted)
  - `/item/supply-settings` writes `safety_days` and `palugit_override` together; a blank palugit clears only the override
- **`StockMigrationsTest`** (6 new tests):
  - the column is nullable
  - the six rows are seeded, and existing rows are never overwritten
  - the column-config migration: a saved config gets `category` hidden and removed from the role lists, it's idempotent, and `down()` works
  - with nothing saved, `category` comes out hidden
- **`StockEndpointTest`**: 003's 21 tests now read `normal.*`. Three expectations changed by design, each commented in the file:
  - `example_c`'s LAMP item is now Phasing Out: grey, `walang_benta`, order quantity still 12
  - `example_a` asserts `[lead, normal.palugit] = [7, 3]` (New)
  - the settings test now proves `safety_days` is ignored

**`ItemPageTest` assertions:**
- `lifecycle` is in `CATALOG`, `DEFAULT_VISIBLE` and `defaultCols()`, and the `col.id==='lifecycle'` item-row cell is present.
- `stockSet(row.item_name)` is bound in the BENTA/ARAW, DOI and I-ORDER cells.
- `baseProfitPct7` reads `projected_profit_last_7d` and `gross_sales_last_7d`, with `S.gated && pct != null && pct <= 0` and `this.stockIsLugi(name) ? S.lugi : S.normal`.
- The texts "walang benta" and "halos walang benta" are present, plus both I-ORDER tooltips (normal, and "HOLD lang").
- `category` is not in `DEFAULT_VISIBLE`; the selector wrapper has `x-show="categoryColVisible()"`, the reset expression `if (!this.categoryColVisible()) this.categoryFilter = '';` is present, and so is the guarded empty row.
- "blank = default ng lifecycle" is in the CEO render only.
- The render has 0 `x-html`.

**Red runs** (first failure line per slice, as the developers reported them):
- **T1:** the characterisation test was green first, by design: it pins existing behaviour (25 passed on the untouched code). Then `Class "App\Support\ItemLifecycle" not found`.
- **T2:**
  - `palugit override column is nullable…`: "Failed asserting that false is true"
  - seed: expected 6 rows, actual `[]`
  - column-config migration: "Failed to open stream" on the missing migration file
  - `setting-kv`: "Expected response status code [422] but received 200" for 256
  - `supplySettingsSave`: `palugit_override` 0 where 5 was expected
- **T3:**
  - `Undefined array key "lifecycle"` / `"normal"` on all 7 new tests
  - after the code, one fixture fix: Scaling gave 350, not 365, because the fixture held 35 units instead of 50
- **T4:** `lifecycle column is registered…`: "Failed asserting that an array contains 'lifecycle'". The two other markup tests failed on their missing strings; PHPUnit printed the whole page instead of a one-line message.
- **Fix wave:**
  - the category test failed on the missing reset expression
  - `order tooltip is hold only…` failed on the HOLD-only text

## Endpoint SQL (for EXPLAIN on production)

The example uses end date `2026-10-02`. Bindings are written inline.

```sql
-- NEW 1. Lifecycle window (every row, no STATUS filter — same as /jnt/supply steps 6 and 7)
EXPLAIN SELECT mo.`ITEM_NAME` AS item_name,
       SUM(CASE WHEN mo.ts_date >= '2026-09-19' THEN 1 ELSE 0 END) AS recent,
       SUM(CASE WHEN mo.ts_date <= '2026-09-18' THEN 1 ELSE 0 END) AS prev,
       MIN(mo.ts_date) AS window_first
FROM `macro_output` AS `mo`
WHERE `mo`.`ts_date` BETWEEN '2026-09-05' AND '2026-10-02'
GROUP BY mo.`ITEM_NAME`;

-- NEW 2. First order date — full grouped scan, same as /jnt/supply step 5 (there's no ITEM_NAME index).
--        Cached 12 h under item_stock:first_dates:v1:<host>:<end_date>; runs only on a cache miss.
EXPLAIN SELECT mo.`ITEM_NAME` AS item_name, MIN(mo.ts_date) AS first_date
FROM `macro_output` AS `mo`
WHERE `mo`.`ts_date` <= '2026-10-02'
GROUP BY mo.`ITEM_NAME`;

-- CHANGED 3. 003's demand query, now with cnt7 (last 7 days)
EXPLAIN SELECT mo.`ITEM_NAME` AS item_name, COUNT(*) AS cnt, MIN(mo.ts_date) AS first_date,
       SUM(CASE WHEN mo.ts_date >= '2026-09-26' THEN 1 ELSE 0 END) AS cnt7
FROM `macro_output` AS `mo`
WHERE `mo`.`ts_date` BETWEEN '2026-09-19' AND '2026-10-02'
  AND (mo.`STATUS` IS NULL OR LOWER(REPLACE(REPLACE(TRIM(mo.`STATUS`), ' ', ''), '_', '')) NOT IN ('cannotproceed','odz'))
GROUP BY mo.`ITEM_NAME`;
```

Small reads that aren't worth an EXPLAIN:
- `select key, value from supply_settings where key in ('palugit_new', …, 'palugit_lugi')`
- `supply_item_settings` now also selects `lifecycle_override` and `palugit_override`

Per request, the endpoint adds about 3 queries: the window query, the palugit read, and the first-date scan on a cache miss. It also adds three schema checks (`hasTable('supply_settings')` and two `hasColumn`), as the project's schema-guard convention requires. The rest of 003's queries are unchanged (see 003's RESULT).

What to look for:
- Queries 1 and 3: a range on `macro_output_ts_date_index`.
- Query 2: a full scan, or a full index scan of the ts_date index plus a temporary table for the GROUP BY. Its time is roughly what `/jnt/supply` already spends on step 5. It's paid at most once per host and end date every 12 hours.
- If query 2 is slow even once, an index on `(ITEM_NAME, ts_date)` would make it a loose index scan. That's a schema change outside this handoff.

## Files changed

- **New:**
  - `app/Support/ItemLifecycle.php`
  - migrations `2026_10_02_100300_add_palugit_override_to_supply_item_settings.php`, `2026_10_02_100400_seed_item_palugit_settings.php`, `2026_10_02_100500_hide_category_column_owner_private.php`
  - `tests/Unit/JntSupplyLifecycleTest.php`
  - `tests/Feature/Item/LifecycleStockTest.php`
- **Changed:**
  - `app/Http/Controllers/JntSupplyController.php`: two private method bodies now delegate; `saveSupplySetting` has one `item_palugit` range check
  - `app/Services/ItemStockService.php`
  - `app/Http/Controllers/ItemController.php`: `supplySettingsSave`
  - `app/Http/Controllers/OwnerColumnSettingsController.php`: `CATALOG` adds `lifecycle`; `DEFAULT_VISIBLE` adds `lifecycle` and drops `category`
  - `resources/views/item/index.blade.php`, `resources/views/item/_agg_cells.blade.php`
  - `tests/Feature/Item/ItemTestCase.php`: migration paths, plus a `supply_settings` table mirroring its migration
  - `StockEndpointTest.php`, `ItemEditTest.php`, `StockMigrationsTest.php`, `ItemPageTest.php`
- **Docs and records:** `docs/specs/004-lifecycle-restock.md`, `docs/plans/004-lifecycle-restock.md`, `TODO.md`, `.claude/agent-memory/skeptic-reviewer/repo_weak_spots.md`, `handoff/004-lifecycle-restock/*`, `handoff/README.md`

## Migrations

All three are additive, with no change to an existing column or table definition.

1. `2026_10_02_100300_add_palugit_override_to_supply_item_settings.php`
   - Adds `supply_item_settings.palugit_override` (unsigned tinyint, nullable), guarded by `hasTable`/`hasColumn`.
   - `down()` drops the column. Any per-item palugits set on `/item` are lost; `safety_days` keeps the same values.
2. `2026_10_02_100400_seed_item_palugit_settings.php`
   - Inserts the missing keys among `palugit_new` 3, `palugit_scaling` 14, `palugit_consistent` 10, `palugit_active` 7, `palugit_declining` 0 and `palugit_lugi` 3.
   - Group `item_palugit`, `int`, sort 60–65, Taglish labels. It never overwrites a row.
   - `down()` deletes those six keys.
3. `2026_10_02_100500_hide_category_column_owner_private.php`
   - Data only, on `app_settings` key `owner_private_cols`.
   - Adds `category` to `hidden` (once) and removes it from every `visible_by_role` list.
   - With no saved row, it does nothing.
   - `down()` removes `category` from `hidden` only; role grants aren't restored.

## Amendments applied

- **Amendment 004-1**, saved word for word as `AMENDMENT-1.md` (`e639e28`). Scaling's `normal` set now uses max(7-day ÷ 7, 003's 14-day velocity); the `lugi` set keeps the 14-day velocity. Applied in `3a7b0f5`. Details are under "Amendment 1 and fix list 1".
- Mira's go came with answers 1–5, which are applied and recorded in the spec (`3d2de0c`) and below.

## Amendment 1 and fix list 1

**Amendment 004-1** (`3a7b0f5`, `fix: Scaling velocity is the larger of 7-day and 14-day (amendment 004-1)`)
- `ItemStockService`: a Scaling item's `normal` set uses v7 only when v7 > v14; otherwise it uses v14 (on a tie both give the same number). The DOI uses the winning velocity's own units and days, in the same exact-then-round style. The `lugi` set is unchanged (v14).
- New §5 row in `LifecycleStockTest` (SCALZ): Scaling, 7-day units 0, 14-day velocity 10, HOLD 50, stock 0, incoming 0, lead 7. Expected: `normal.units_per_day` 10, `normal.palugit` 14, `normal.order_qty` **260**, `normal.doi` −5.0, `lugi.order_qty` 150.
- The existing 365 case (SCALX: 7-day 15 > 14-day 10) still gives **365**.
- Red first: `SCALZ normal — Failed asserting that 50 is identical to 260.`
- Spec §3 and the §5 table updated to say max(7-day, 14-day).

**Fix list 1**
1. **"(manual)" tooltip** (`6da08db`): the LIFECYCLE cell's "(manual)" tag has `title="Ikaw ang nagtakda ng lifecycle na ito sa Supply page; hindi awtomatiko"`.
2. **DOI tooltip** (`6da08db`): the grey sentence now reads "Abo: walang benta, o mas mababa sa 0.5 kada araw." It replaces the earlier, vaguer "Abo: walang benta o halos walang benta." rather than repeating it.
3. **Near-zero test for a Scaling item** (`3a7b0f5`): a second row (SLOWS) in the existing near-zero test. The fixture is 3 units from 09-23 (v14 = 3 ÷ 10 = 0.3) plus 9 older units, so HOLD is 12; there are no units in the last 7 days, so v14 wins under the amendment. Expected values, typed in: `units_per_day` 0.3, `doi_note` `halos_walang_benta`, `colour` `grey`, `doi` −40.0, `order_by` `now`, `order_qty` **19** = ceil(12 + 0.3 × (7 + 14)) = ceil(18.3). This row was red before the amendment (`SLOWS — Failed asserting that 0 matches expected 0.3.`), because Scaling then used the 7-day velocity alone. The Active row (SLOW) still expects 17.
4. **Markup assertions** (`6da08db`): `ItemPageTest` asserts both tooltip texts in the CEO and Marketing renders, by extending `test_lifecycle_column_is_registered_and_rendered_on_item_rows` and `test_doi_lead_and_order_by_lines_have_plain_tooltips`. Red first on both missing texts.

**Outputs on the final tree:**
- `php.bat artisan test --compact` → `Tests: 1 failed, 3 skipped, 210 passed (1709 assertions)`. The only failure is the known baseline `Tests\Feature\ExampleTest > the application returns a successful response` ("Expected response status code [200] but received 302."). The 3 skipped are the Boardroom live tests.
- `php.bat -l` → "No syntax errors detected" in `app/Services/ItemStockService.php`, `tests/Feature/Item/LifecycleStockTest.php` and `tests/Feature/Item/ItemPageTest.php` (the only changed PHP files).
- `npm run build` → `vite v6.3.5 … ✓ built in 2.29s` (bundle unchanged, `app-DNxiirP_.js 35.32 kB`).

Nothing else changed.

## Rulings

- Ruling: the first-order date uses `/jnt/supply`'s query bounded to `ts_date <= end`, cached 12 h per host and end date (**Mira's answer 1**) — the bound can't change a lifecycle (an item first sold after the as-of date has no recent or previous units, so it's Dormant either way), and the cache keeps `/item` fast — cost if wrong: a stale map for up to 12 h. When a key is missing from the cached map but has rows in the window, its window date is used, so a brand-new item is still New.
- Ruling: one combined PROF.%(7D) per base item picks the set (**Mira's answer 2**, formula in the Summary) — every variant row shows the same set — cost if wrong: a profitable variant shares the lugi set with a losing one when the combined figure is ≤ 0.
- Ruling: `palugit_override` applies to both sets; Phasing Out and Dormant stay HOLD-based (**Mira's answer 3**) — an explicit CEO number for an item is the strongest signal — cost if wrong: a lugi item with a big override keeps that cover.
- Ruling: Phasing Out / Dormant order `max(0, ceil(HOLD − paparating − stock))` (**Mira's answer 4**) — read literally, "HOLD units" would re-order stock we already have — cost if wrong: none for §5's case (stock 0, paparating 0 → 50).
- Ruling: palugits entered on `/item` before this deploy start with no override (**Mira's answer 5**, see the deploy notes) — they can't be told apart from `safety_days` — cost if wrong: the CEO re-enters any that matter.
- Ruling: `/item` always uses `/jnt/supply`'s default thresholds (14 / 30 / 90 / 1.5 / 0.5), not the `lifecycle_*` rows in `supply_settings` — `/jnt/supply` doesn't read those rows either — cost if wrong: if someone changes the thresholds through `/jnt/supply`'s request params, the two pages differ for that view only.
- Ruling: lifecycle inputs count every order (no STATUS filter), while velocity excludes cancelled orders as in 003 — this matches each page's existing definition — cost if wrong: an item whose only recent orders were cancelled is classified as selling but has velocity 0, so its DOI shows "—".
- Ruling: Scaling's 7-day velocity is exactly units in the last 7 days ÷ 7, with no first-order clamp, and since **Amendment 004-1** the `normal` set uses the larger of that and the 14-day velocity — the decision's wording, then Mira's amendment — cost if wrong: none; an item that young is New, unless it has a manual override.
- Ruling: when the same base item is spelled with different case, `/item` merges the spellings into one lifecycle — `/item` is keyed by `ItemBaseKey` — cost if wrong: such an item can differ from `/jnt/supply`, which shows each spelling as its own row. Results are identical with one spelling.
- Ruling: a `lifecycle_override` that isn't a known lifecycle uses the Active palugit and the "— Unknown" badge — no crash on a bad row — cost if wrong: none; `/jnt/supply` validates overrides.
- Ruling: for near-zero sellers, `doi` and `order_by` are still computed (for sorting), but the colour is grey and the cell shows "halos walang benta". With `stock_ready=false`, `doi_note` stays and the numbers are null — the staff see the note, not a misleading day count — cost if wrong: none found.
- Ruling: the per-set fields were removed from the top level of `/item/stock`, and 003's tests read `normal.*` — `/item` is the only reader — cost if wrong: an outside reader of `/item/stock` would break; none was found in the repo.
- Ruling: the palugit defaults reuse the `supply_settings` store and `/jnt/supply/config`'s generic editor, with a 0–255 check added for `item_palugit` keys only — that matches the handoff's "reuse if generic" — cost if wrong: the CEO edits them on `/jnt/supply/config`, not on `/item`.
- Ruling: the test harness creates `supply_settings` with `Schema::create` mirroring `2026_04_24_000003` — that migration also touches `item_class_thresholds`, so it can't run on its own — cost if wrong: test-only drift if that schema changes.
- Ruling: all tasks ran one after another on this branch, with no worktrees — `git worktree` isn't on the allowlist — cost if wrong: none.
- Ruling: the browser check was skipped — running the app reads the local `.env` database (handoff §7) — cost if wrong: Alpine runtime issues surface in Busing's review instead of here (see "not tested" below).
- Ruling: the reviewers couldn't run git, so they read diffs I saved to files. I checked T1's verbatim move myself with `git diff 5628d5b 317825f`.

## Review findings

`skeptic-reviewer`, standard depth (sonnet), one review per task. No blocker and no major in any task, so there were no fix loops and nothing was escalated.

### Spec

- **minor (T4)** The spec says the filter resets to Lahat when CATEGORY is hidden; the code only ignored it. **Fixed** in `938e7e1`: `initCols` resets `categoryFilter`, and `initCols` is the only place `cols` is assigned.
- **minor (T4)** The I-ORDER tooltip described the full formula for HOLD-only items. **Fixed** in `938e7e1` with `orderTip()`.
- **minor (T4)** The lifecycle tooltips hard-code "≤30 araw", "≥90 araw" and "14 araw". **Accepted:** `/item` always uses those defaults, so the text is true there.
- **minor (T2)** The `item_palugit` rows appear on `/jnt/supply/config`. **Intended** (spec §4); noted in `TODO.md`.
- **minor (T1)** The `ItemLifecycle` constants and `/jnt/supply`'s request defaults are two copies of the same numbers. **Accepted** in `TODO.md`, because `/jnt/supply` must not change.

### Correctness

- T1: confirmed the move is verbatim (my own git diff), with every branch and boundary covered. `$hasOldOrders` is unused (as before). **Accepted** in `TODO.md`.
- T2: migrations are mysql/pgsql-safe and idempotent; the CEO gate and 422s are tested. The `owner_private_cols_default` snapshot isn't rewritten. **Accepted** in `TODO.md`.
- T3: lifecycle inputs match `/jnt/supply`, the cache fallback can't misclassify, and every §5 row is tested with typed values. **Accepted** in `TODO.md`:
  - the Host-keyed cache, per Mira's answer and the item-summary pattern
  - the v = 0 case for a Scaling item with no sales in the last 7 days (since resolved by Amendment 004-1)
  - the query-count note, corrected in the SQL section above
  - the listed edge tests
- T4: no page code reads the removed top-level fields; the memo is rebuilt when `this.rows` is replaced; sorting reads the chosen set.

**Not tested** (no JS runner in the repo; accepted in `TODO.md` like 003). Markup assertions only for:
- the `baseProfitPct7` maths: summing across variants, leaving out rows with no data, null → no gate, and the lugi choice at exactly ≤ 0
- memo invalidation
- the sort values for the set and lifecycle columns
- `doiText`, `doiColour`, `leadLine`, `lifecycleTip` and `orderTip` output
- the editor's pre-fill, and the payload without `safety_days`
- the category filter reset and ignore at runtime

All the numbers themselves are computed and tested in PHP.

### Declined to judge

- **Real MySQL/pgsql behaviour** of the SQL and migrations: the suite runs on sqlite only. EXPLAIN is in the deploy steps.
- **Alpine runtime behaviour in a browser:** no browser or JS runner was used.
- **Whether `/jnt/supply`'s full page output is byte-identical:** that page needs fee settings and the local DB. The proof is the delegation diff plus the characterisation table.

## Deploy notes for Mira

1. `php artisan migrate --force` runs the three migrations above. The palugit rows show up on `/jnt/supply/config` under "Other settings".
2. **Palugits entered on `/item` since 003 was deployed** (a few hours ago) start with no override. Those items use their lifecycle default until the CEO sets the palugit again on `/item` (Mira's answer 5). Their `safety_days` values stay, and `/jnt/supply` still uses them.
3. `php artisan view:clear` (Blade changes). No config changes.
4. `npm run build` only if production builds assets on deploy. The bundle didn't change.
5. No `item-summary` cache bump: its output is unchanged.
6. The first-date cache lives in the app cache store under `item_stock:first_dates:v1:*`, for 12 h. No action is needed. `php artisan cache:clear` drops it if ever needed.
7. EXPLAIN the three queries in "Endpoint SQL" on production. Query 2 is the one to time.
8. If Busing has granted CATEGORY to a Marketing role, the migration removes that grant (intended: hidden by default for every role). The CEO can turn it back on at `/owner/column-settings`. LIFECYCLE is opt-in for Marketing there, like 003's columns.

## Merge danger

- **Two-way door, mostly.**
  - The code adds fields and a column, and the `/item` numbers change by design: palugit by lifecycle.
  - Two migrations are additive. One is a small data edit to the saved column config.
- **Blast radius:**
  - `/item`: the stock columns' numbers, the new LIFECYCLE column, CATEGORY hidden.
  - `/item/stock`: response shape (the per-set fields moved into `normal` / `lugi`).
  - `/jnt/supply/config`: six new editable rows.
  - `POST /jnt/supply/setting-kv`: a range check on those rows only.
  - `/jnt/supply`: lifecycle code moved behind the same private methods; output unchanged.
  - `supply_item_settings`: `/item` palugit edits now also write `palugit_override`.
- **Revert:**
  1. Revert the branch's commits (or the squash commit).
  2. `php artisan migrate:rollback --step=3`. This drops `palugit_override` (per-item `/item` palugits are lost; `safety_days` remains), deletes the six palugit rows, and removes `category` from the hidden list. Role grants removed by the migration aren't restored; re-grant them at `/owner/column-settings` if needed.
  3. `view:clear`.
- **One-way part:** CATEGORY role grants removed by the migration (step 8 of the deploy notes).

## Suggestions for Busing

- **Point `/jnt/supply`'s lifecycle defaults at `ItemLifecycle`'s constants,** so the two pages can't drift. Its unused `lifecycle_*` rows in `supply_settings` could then drive both.
- **An index on `macro_output (ITEM_NAME, ts_date)`** would make the first-date query (here and on `/jnt/supply`) cheap. It's a schema change, so it needs its own handoff.
- **A small JS test runner** for the `/item` page logic (`baseProfitPct7`, the set choice, the DOI texts), if a new dev dependency is acceptable.
