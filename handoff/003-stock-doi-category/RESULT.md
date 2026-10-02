# Result 003: Item value on item rows, stock gauge, days of inventory, order quantity, category

Status: **done, waiting for Mira's review of branch `feat/stock-doi-category`** (cut from `develop` at `f20b200`). There's no PR because `gh` isn't installed, so this file stands in for the PR body. Nothing was pushed, merged or deployed.

## Summary

**ITEM VAL. on item rows**
- Every item row reads ITEM VAL. from `cogs` directly. The rule is the same as page rows: latest `date <= end_date`, keyed by `ItemAliasResolver`. It's included for hold-only items and for items with a running page but no orders in range.
- ITEM VAL. (CEO) comes from `cogs_ceo`, only for the CEO viewing as CEO. It's gated in the data layer (the key is absent from the response) and in the UI (`@if($effectiveIsCEO)`).
- Page rows are unchanged.

**Six new columns** on the item rows:
- CATEGORY
- STOCK, with the "bilangin" marker
- PAPARATING (open PO units)
- BENTA/ARAW
- DOI (red, amber or green; "kulang N araw"; "—" when there's no demand), with "lead N · palugit M" under it
- I-ORDER, with "bago mag <date>", "ngayon na" or "—" under it

How they behave:
- They're registered in the column catalog the existing way.
- They're visible to the CEO by default; Marketing roles opt in on the Columns page.
- They're sortable.
- Every value is per base item and repeats on each variant row, with the tooltip "para sa lahat ng variant: 1 x …, 2 x …".
- Each value has a one-line Taglish tooltip.

**`GET /item/stock`**
- It returns per base key: `hold_units`, `units_per_day`, `incoming`, `stock_raw`, `stock`, `stock_needs_count`, `lead`, `safety`, `doi`, `order_qty`, `order_by`, `colour`, `category`.
- It also returns the item-value map and the category list.
- Same role gate as `/item`.
- It starts in parallel with the other `/item` loads, before `item-summary`, so it never delays the table.

**CEO inline edits**
- Category: a select with "— wala —", each category, and "+ bagong category".
- Lead/palugit days.
- Two new POST routes, CEO-only in the data layer and the UI, validated, `throttle:60,1`, with CSRF. After a save the page re-fetches `/item/stock`.

**Category filter** (all roles)
- Options: Lahat, each category, Walang category.
- It's AND-ed with the Sourcing chip.
- All columns, warnings and actions stay.

**`items:suggest-categories`**
- Dry run by default.
- `--apply` fills only items with no category.
- Keyword rules are in `config/item_categories.php`.

**Unchanged:** `/jnt/supply`, Supply Finance, `HoldService`, `OwnerPrivateController`, the `item-summary` output, page rows, Lahat and the Marketing view (apart from the new columns and filter).

## Done-when evidence

| Done-when | Evidence |
|---|---|
| Plan sent to Mira, her "go" received before code | Plan was the final message of the first run. Commits before the "go": `6956416` (handoff files) and `801aab2` (spec and plan docs). Mira's go and answers: lead/safety 0–255; NULL `submission_time` isn't "left". The first code commit is `4b6d9e0`, after the go. |
| `artisan test`: no new failures; A–D, CEO-only gate, validation, dry-run and `--apply` tests | `php.bat artisan test` → `Tests: 1 failed, 3 skipped, 141 passed (1401 assertions)`. The only failure is the known baseline `Tests\Feature\ExampleTest > the application returns a successful response` ("Expected response status code [200] but received 302"). The 3 skipped are the Boardroom live tests (off by design). Coverage is listed below the table. |
| `php -l` clean; `npm run build` OK | `php.bat -l` on all 19 changed PHP files (app, config, migrations, routes, tests) → "No syntax errors detected" for each. `npm run build` → `vite v6.3.5 … ✓ built in 2.21s`. |
| `ItemPageTest` assertions: columns, item-row ITEM VAL., filter, CEO-only edit controls | `ItemPageTest` has 6 tests and 74 assertions. Details below the table. |
| skeptic-reviewer ran; findings handled | Six reviews and two re-checks, all at standard depth (sonnet). Two majors, both fixed (`da08af8`) and confirmed by the re-check. Everything else is fixed or accepted in `TODO.md`. See "Review findings". |
| RESULT filled; SQL for EXPLAIN; commits on branch; clean tree | This file. The SQL is below. 15 commits `6956416..` on `feat/stock-doi-category` (the last one adds this RESULT.md and TODO.md); `git status` is clean after the final commit. |

**Coverage for the test row:**
- `StockEndpointTest`, 21 tests:
  - worked examples A, B, C and D, with values typed from the handoff
  - every ruling: floored stock in the order quantity, discount lines, a PO counted before START, `delivered` vs `counted`, cancelled orders and NULL `submission_time` not counted as "left", lead/safety from settings (lowest id wins), rounded-DOI boundaries
  - the earliest-J&T-record rule, `stock_ready:false`
  - item values, including an item with no orders in range
  - the CEO gate on `item_value_ceo`, a category-only item, Marketing 200, unknown role 404
- `ItemEditTest`, 11 tests:
  - Marketing and Marketing - OIC get 403 on both POSTs and nothing is written
  - a table of 422s: lead/safety 256, −1, "x" and missing; unknown category id; 61-character name; id plus name together; 191-character name; key over 190 after lowercasing
  - case-insensitive category reuse, a new category gets `sort_order` 9, clearing, a blank name counts as absent
  - every matching settings row is updated with its name kept, and an insert when no row matches
- `SuggestCategoriesTest`, 4 tests:
  - the dry run prints matches and the unmatched list and writes nothing
  - `--apply` never overwrites an existing assignment
  - keys over 190 characters and missing rule categories are skipped with a warning
- `StockMigrationsTest` 3, `ItemBaseKeyTest` 2.

**`ItemPageTest` assertions:**
- The six ids are in `defaultCols()` and in `OwnerColumnSettingsController::CATALOG['owner_private']`.
- The six `col.id==='…'` item-row cells are present.
- `itemValue(row.item_name)` is bound in the item-row ITEM VAL. cell.
- `loadStock()` comes before the `item-summary` fetch.
- The `x-model="categoryFilter"` select and "Walang category" are in both the CEO and Marketing renders.
- `categoryKeep(u.name)` comes after `worklistKeep(u.name, u.hold)`.
- In the CEO render only: `saveCategory(`, `saveSupplySettings(`, `+ bagong category`, `openStockEdit(row.item_name` and `itemValueCeo(row.item_name)`.
- The render has 0 `x-html`.

**Red runs** (first failure line per slice, as the developers reported them):
- T1:
  - `ItemBaseKeyTest`: `Class "App\Support\ItemBaseKey" not found`
  - `StockMigrationsTest`: `FileNotFoundException … 2026_10_02_100000_create_item_categories_table.php`
- T2:
  - `StockEndpointTest::example_a…`: "Expected response status code [200] but received 404."
  - The developer drafted the service before the tests, so this red run only proves the route was missing. The review pinned every ruling with tests in the fix loop.
- T2 fix: `test_doi_is_exact_then_rounded…`: `-'amber' +'red'`.
- T3:
  - `ItemBaseKeyTest`: `2 x` (keyFor `''` vs `'2 x'`)
  - `ItemEditTest`: "received 404"
- T3 fix:
  - 422 table: "Expected [422] but received 200"
  - blank-name test: "Expected [200] but received 422"
- T4: `CommandNotFoundException: The command "items:suggest-categories" does not exist.`
- T5: `ItemPageTest > stock columns are registered…`: markup had no `id:'category'`.
- T5 fix:
  - values: `Undefined array key "page only fan"`
  - category-only: `Trying to access array offset on null`
  - suggest: printed `Na-save: 7`, expected 6
- T6: `ItemPageTest > category filter…`: no `x-model="categoryFilter"`.
- T6 fix: an `ItemPageTest` markup assertion. The first line was truncated in the runner output.

## Fix list 1 (Mira, verifier findings)

Commit `cde134d`: "fix: plain tooltips for lead/palugit, order-by date and DOI colours (003 fix list 1)". Only `resources/views/item/_agg_cells.blade.php` and `tests/Feature/Item/ItemPageTest.php` changed.

1. **DOI cell, "lead N · palugit M" line**
   - Every role gets `title="Lead = ilang araw bago dumating ang order; palugit = dagdag na araw na reserba"`.
   - The CEO's edit button reads "Lead = … reserba — i-click para palitan (para sa lahat ng variant ng item na ito)".
2. **I-ORDER cell, "bago mag <date>" / "ngayon na" line:** `title="Huling araw na pwedeng umorder para hindi maubusan, base sa lead time"`.
3. **DOI tooltip:** now ends with "Pula: mauubos bago dumating ang order. Dilaw: malapit na. Berde: ok pa."
4. **`ItemPageTest::test_doi_lead_and_order_by_lines_have_plain_tooltips`:**
   - All three texts are in the CEO and Marketing renders.
   - "… — i-click para palitan" is in the CEO render only.
   - Red first: it failed with `assertStringContainsString` on the missing lead text before the Blade change.

Outputs:
- `php.bat artisan test --filter=ItemPageTest` → `Tests: 7 passed (82 assertions)`.
- Full suite `php.bat artisan test` → `Tests: 1 failed, 3 skipped, 142 passed (1409 assertions)`.
  - The only failure is the known baseline `Tests\Feature\ExampleTest > the application returns a successful response`: "Expected response status code [200] but received 302."
  - The 3 skipped are the Boardroom live tests (off by design).
- `php.bat -l tests/Feature/Item/ItemPageTest.php` → `No syntax errors detected in tests/Feature/Item/ItemPageTest.php`. This is the only changed PHP file; the Blade partial isn't plain PHP.
- `npm run build` → `vite v6.3.5 … ✓ built in 2.38s` (bundle unchanged: `app-DNxiirP_.js 35.32 kB`).

## Endpoint SQL (for EXPLAIN on production)

The example uses `/item/stock?start_date=2026-09-01&end_date=2026-10-02`, with START `2026-09-25`.

```sql
-- 1. HOLD units (HoldService::liveHoldQuery, unchanged; same as /item/data and /item/worklist)
EXPLAIN select mo.`ITEM_NAME` as item_name, COUNT(*) as hold_count
from `macro_output` as `mo`
left join `from_jnts` as `fj` on `fj`.`waybill_number` = `mo`.`waybill`
where `fj`.`waybill_number` is null
  and NULLIF(TRIM(mo.waybill), '') IS NOT NULL
  and (mo.`STATUS` IS NULL OR LOWER(REPLACE(REPLACE(TRIM(mo.`STATUS`), ' ', ''), '_', '')) NOT IN ('cannotproceed','odz'))
  and `mo`.`ts_date` between '2026-09-01' and '2026-10-02'
group by mo.`ITEM_NAME`;

-- 2. Units per day (14 days ending at end_date)
EXPLAIN select mo.`ITEM_NAME` as item_name, COUNT(*) as cnt, MIN(mo.ts_date) as first_date
from `macro_output` as `mo`
where `mo`.`ts_date` between '2026-09-19' and '2026-10-02'
  and (mo.`STATUS` IS NULL OR LOWER(REPLACE(REPLACE(TRIM(mo.`STATUS`), ' ', ''), '_', '')) NOT IN ('cannotproceed','odz'))
group by mo.`ITEM_NAME`;

-- 3. Units that left since START (waybill dated by its earliest J&T submission_time)
EXPLAIN SELECT mo.`ITEM_NAME` AS item_name, COUNT(*) AS cnt
FROM macro_output mo
JOIN (SELECT DISTINCT fj.waybill_number FROM from_jnts fj
      WHERE fj.submission_time >= '2026-09-25 00:00:00'
        AND NOT EXISTS (SELECT 1 FROM from_jnts fj2
                        WHERE fj2.waybill_number = fj.waybill_number
                          AND fj2.submission_time < '2026-09-25 00:00:00')) s
  ON s.waybill_number = mo.waybill
WHERE (mo.`STATUS` IS NULL OR LOWER(REPLACE(REPLACE(TRIM(mo.`STATUS`), ' ', ''), '_', '')) NOT IN ('cannotproceed','odz'))
GROUP BY mo.`ITEM_NAME`;

-- 4. Received since START
EXPLAIN select i.item_key as item_key, SUM(i.received_qty) as qty
from `supply_order_items` as `i`
inner join `supply_orders` as `o` on `o`.`id` = `i`.`supply_order_id`
where `o`.`counted_at` >= '2026-09-25 00:00:00' and `i`.`received_qty` is not null and `i`.`unit_cost` >= 0
group by `i`.`item_key`;

-- 5. Incoming (open POs)
EXPLAIN select i.item_key as item_key,
       SUM(CASE WHEN i.ordered_qty > COALESCE(i.received_qty, 0) THEN i.ordered_qty - COALESCE(i.received_qty, 0) ELSE 0 END) as qty
from `supply_order_items` as `i`
inner join `supply_orders` as `o` on `o`.`id` = `i`.`supply_order_id`
where `o`.`status` in ('ordered','delivered') and `i`.`ordered_qty` > 0 and `i`.`unit_cost` >= 0
group by `i`.`item_key`;

-- 6. Item-value names in range
EXPLAIN select distinct mo.`ITEM_NAME` as item_name
from `macro_output` as `mo`
where `mo`.`ts_date` between '2026-09-01' and '2026-10-02'
  and NULLIF(TRIM(mo.`ITEM_NAME`), '') IS NOT NULL;

-- 7. Cost rows (also supply their item names); cogs_ceo only for the CEO viewing as CEO
EXPLAIN select item_name, unit_cost from cogs where date <= '2026-10-02' order by date desc;
EXPLAIN select item_name, unit_cost from cogs_ceo where date <= '2026-10-02' order by date desc;
```

Small reads that aren't worth an EXPLAIN:
- `app_settings` where `key = 'item_stock_start'`
- `supply_item_settings` order by id
- `item_category_assignments` join `item_categories`
- `item_categories` order by `sort_order`, `id`

What to look for:
- Query 3: a range on `idx_from_jnts_submission_time`, `idx_from_jnts_waybill` for the `NOT EXISTS`, and the `macro_output.waybill` index for the join.
- Queries 1, 2 and 6: a range on `macro_output_ts_date_index`.
- Query 3's derived set grows with time since START. Moving START forward (a stock count) would shrink it.

## Files changed

- **New:**
  - `app/Support/ItemBaseKey.php`
  - `app/Services/ItemStockService.php`
  - `app/Models/ItemCategory.php`, `app/Models/ItemCategoryAssignment.php`
  - `app/Console/Commands/SuggestItemCategories.php`
  - `config/item_categories.php`
  - three migrations (below)
  - tests: `tests/Unit/ItemBaseKeyTest.php`; and `StockMigrationsTest.php`, `StockEndpointTest.php`, `ItemEditTest.php`, `SuggestCategoriesTest.php` under `tests/Feature/Item/`
- **Changed:**
  - `app/Http/Controllers/ItemController.php`: `stock`, `categorySave`, `supplySettingsSave`
  - `routes/web.php`: three routes in the existing `/item` group
  - `app/Http/Controllers/OwnerColumnSettingsController.php`: `CATALOG` and `DEFAULT_VISIBLE`, six ids
  - `resources/views/item/index.blade.php`: columns, `loadStock()`, helpers, sort cases, filter, CEO editors, empty-category row
  - `resources/views/item/_agg_cells.blade.php`: item-row cells for ITEM VAL., ITEM VAL. (CEO) and the six columns
  - `tests/Feature/Item/ItemTestCase.php`: `from_jnts.submission_time`, migration paths, `shipped($waybill, $submittedAt)`
  - `tests/Feature/Item/ItemPageTest.php`
- **Docs and records:** `TODO.md`, `docs/specs/003-stock-doi-category.md`, `docs/plans/003-stock-doi-category.md`, `.claude/agent-memory/skeptic-reviewer/repo_weak_spots.md`, `handoff/003-stock-doi-category/*`, `handoff/README.md`

## Migrations

All three are additive. No existing column or table is changed or dropped.

1. `2026_10_02_100000_create_item_categories_table.php`
   - Creates `item_categories`: id, name string(60) unique, sort_order, timestamps.
   - Seeds the 8 categories in the handoff order (sort_order 1–8), inserting only names that aren't there yet.
   - `down()` drops the table.
2. `2026_10_02_100100_create_item_category_assignments_table.php`
   - Creates `item_category_assignments`: id, item_key string(190) unique (the base key), category_id FK → `item_categories` (restrict on delete), updated_by nullable, timestamps.
   - `down()` drops the table.
3. `2026_10_02_100200_seed_item_stock_start_setting.php`
   - Inserts `app_settings` key `item_stock_start` = the date the migration runs (Asia/Manila), only if the key is missing.
   - `down()` deletes only that key.

## Amendments applied

None. No "Amendment 003-K" message was received.

## Rulings

- Ruling: lead/safety days are validated 0–255, with no migration on the existing columns. **Mira's decision.** Why: `supply_item_settings.lead_time_days` and `safety_days` are `unsignedTinyInteger`, so 256–365 would fail with a 500 error in MySQL strict mode. Cost if wrong: a lead time over 255 days can't be entered.
- Ruling: `from_jnts` rows with NULL `submission_time` don't count as "left". **Mira's decision; she'll run the count on production before deploy.** Why: they can't be dated. Cost if wrong: units shipped on such rows are missing from "left", so stock reads high for those items.
- Ruling: the "left" date column is `from_jnts.submission_time`, the earliest per waybill. Why: it's when J&T took the parcel, so when it physically left our stock, and it's an indexed DATETIME. `signingtime` is the delivery date (null in transit), and `created_at` is our upload time. Cost if wrong: stock moves on a different day than the parcel left.
- Ruling: the order quantity and DOI use the stock shown after the 0 floor, not the negative raw value. Why: a negative raw value means stock existed before START, so using it would order phantom units. Cost if wrong: the order quantity is lower than it should be for an item whose old stock really is gone; the "bilangin" marker flags exactly those items.
- Ruling: discount lines (`unit_cost < 0`) aren't counted in received or incoming, the same as 001's PO-line definition. Cost if wrong: none found; a discount line isn't goods.
- Ruling: incoming = Σ max(0, ordered − received) on `ordered`/`delivered` POs. This is 001's open quantity, which the handoff says to match, and it equals `ordered_qty` while `received_qty` is null. Cost if wrong: none for any data the UI can produce today.
- Ruling: BENTA/ARAW counts days inclusively: (end − first order) + 1, clamped to 1–14. Why: it matches example A (09-19 → 10-02 = 14 days). Cost if wrong: an off-by-one in the divisor for new items.
- Ruling: the DOI is computed as (stock + incoming − HOLD) × days ÷ units, then rounded to one decimal. The rounded value decides the colour, "ngayon na" and the order-by date. Why: it avoids float noise (a true 30 coming out as 29.999…), and staff see the same number that decides the colour. Cost if wrong: a DOI of 6.95–6.99 with lead 7 shows amber instead of red.
- Ruling: when there's no demand (units/day = 0), the DOI, colour and order-by are all "—"/null, and the order quantity is just HOLD − incoming − stock (floored at 0). Why: example C. Cost if wrong: none found.
- Ruling: lead/safety writes update every `supply_item_settings` row whose base key matches (ignoring case and spaces) and keep each stored `item_name`. With no match, they insert the base name in its original case, as `/jnt/supply` derives it. Reads take the lowest id. Cost if wrong: `/jnt/supply` and `/item` could read different rows only if two differently-cased rows already exist, and the write keeps both in step.
- Ruling: the category POST contract:
  - `category_id` → assign
  - otherwise a non-blank `new_category` → reuse the existing one (ignoring case) or create it
  - otherwise → clear ("— wala —")
  - both sent → 422
  - Why: Laravel turns a blank field into null, so "blank → 422" and "blank = clear" couldn't both hold.
  - Cost if wrong: none; the UI never sends a blank name.
- Ruling: category `item_name` is capped at 190, with a second check after lowercasing. Why: `item_key` is a 190-character column. Cost if wrong: a name of 191–255 characters can't get a category. No such name is likely.
- Ruling: ITEM VAL. comes back in the same `/item/stock` response, as a `values` map keyed by `lower(trim(raw name))`. Its names are the order names in range plus the raw `cogs`/`cogs_ceo` names (`cogs_ceo` only for the CEO). The `cogs` lookup rule is copied into the new service, and `OwnerPrivateController` is untouched. Why: one new endpoint, and the legacy rule. Cost if wrong: an item that is only an alias name with no orders shows "—" (in `TODO.md`).
- Ruling: the category filter is visible to every role that sees `/item`; the Sourcing chips stay CEO-only. Why: viewing categories is for everyone (handoff). Cost if wrong: one `@if` to hide it.
- Ruling: the six columns are in the shared `owner_private` catalog with "(/item)" in their settings label. `/owner/private` drops ids it doesn't know, so it doesn't show them. Cost if wrong: the settings page lists six ids that only matter on `/item`.
- Ruling: the keyword match pads the base name with spaces (`' ' . name . ' '`), so the rule "car " matches "TOY CAR" but not "CARD HOLDER". Cost if wrong: none; tested.
- Ruling: all tasks ran one after another on this branch, with no worktrees, because `git worktree` isn't on the handoff allowlist. Reviews ran in parallel with the next developer, read-only on fixed commits.
- Ruling: the browser check was skipped, because running the app reads the local `.env` database (handoff §7). Cost if wrong: Alpine runtime issues (select state, inline editors, layout) surface in Busing's review instead of here.
- Ruling: I fixed one spacing nit in `ItemStockService` myself (`a6b899e`). Everything else was done by the kit's developers.

## Review findings

`skeptic-reviewer`, standard depth (sonnet), one review per task plus scoped re-checks. Two majors in total, both fixed. No security or data-loss major, so nothing was escalated.

### Spec

- **major (T5)** The item-row ITEM VAL. only covered names with orders in range. An item with a running page and a `cogs` row but no orders showed a red "—". **Fixed** in `da08af8` (cogs/cogs_ceo names added, with a test). The re-check confirmed it.
- **major (T5)** An item that only had a category was missing from `/item/stock`, so its category looked unsaved. **Fixed** in `da08af8` (assignment keys added to the key union, with a test). The re-check confirmed it.
- **minor (T1)** `ItemBaseKey::key()` differed from `keyFor` on prefix-only names ("2 x"). **Fixed** in `498412a`.
- **minor (T1)** `item_key` is 190 characters but the POST allowed 255. **Fixed** in `498412a`/`e1c9acf`.
- **minor (T3)** The blank-name contract was ambiguous. **Fixed** in `e1c9acf` (ruling above).
- **minor (T2)** Incoming subtracts received, which the handoff doesn't mention. **Kept** as a ruling (it's 001's definition).

### Correctness

- **minor (T2)** Float noise and rounding in the DOI. **Fixed** in `a5c4d3b` (exact-then-rounded, boundary tests).
- **minor (T2)** The rulings had no tests. **Fixed** in `a5c4d3b` (nine ruling tests).
- **minor (T3)** The settings test used one row; a duplicate category name could cause a 500. **Fixed** in `e1c9acf` (two-row test, `QueryException` fallback to re-read or 422).
- **minor (T4)** `--apply` threw on keys over 190. **Fixed** in `da08af8`.
- **minor (T5)** I-ORDER showed "0" when stock wasn't ready. **Fixed** in `da08af8`.
- **minor (T6)** Four inline-edit problems, all **fixed** in `f9ba085`:
  - a blank lead or palugit was sent as 0
  - the empty-category row was hidden for non-CEO viewers under `?list=`
  - the select kept an unsaved choice after an error or Cancel
  - Enter skipped the `saving` guard
- **Accepted with reasons in `TODO.md`:**
  - no JS test runner for the page logic
  - the `QueryException` fallbacks are never executed by a test (MySQL race, CEO-only)
  - the alias-only ITEM VAL. gap
  - substring keyword misfires (trusted rules, dry run first)
  - `--apply` without a transaction (idempotent)
  - stale numbers while the date range reloads
  - nulls sort first (existing comparator)
  - the listed edge tests
  - the Manila-midnight flake
  - the duplicated key regex

### Declined to judge

- **Real MySQL/pgsql behaviour** of the raw SQL, the index choices, savepoints and collation-based unique conflicts: the suite runs on sqlite only. EXPLAIN on production is in Mira's deploy steps below.
- **Alpine runtime behaviour in a browser:** no browser or JS runner (see Rulings).
- **The time zone of `from_jnts.submission_time` at the START midnight boundary:** depends on production data.

## How Mira can preview it locally without touching a real database

Not the page: running the app uses the local `.env` database. Safe checks:
- `/c/Users/Forbeast/.config/herd/bin/php.bat artisan test --filter=Item` covers the endpoint numbers (worked examples A–D), the POST gates, the command and the page markup.
- `/c/Users/Forbeast/.config/herd/bin/php.bat artisan route:list --path=item` shows `item.stock`, `item.category.save` and `item.supply-settings.save`.

## Deploy notes for Mira

1. Before deploy, run `SELECT COUNT(*) FROM from_jnts WHERE submission_time IS NULL;` (your NULL-`submission_time` ruling).
2. `php artisan migrate --force`. It runs the three migrations above. **The migration date becomes START**: stock counts from that day. Deploy on the day Busing wants the gauge to start.
3. `php artisan view:clear` (Blade changes). `php artisan config:clear` if config is cached (new `config/item_categories.php`).
4. `npm run build`, only if production builds assets on deploy. The Vite bundle hash didn't change, because the page JS is inline in Blade.
5. No `item-summary` cache version bump: its output is unchanged.
6. EXPLAIN the queries in "Endpoint SQL" on production.
7. Category proposals, dry run first: `php artisan items:suggest-categories`. Review the list with Busing, then `php artisan items:suggest-categories --apply`. It only fills items that have no category.
8. Marketing roles see the six columns only after the CEO ticks them on Columns (`/owner/column-settings`).

## Merge danger

- **Two-way door.**
  - The code is additive: new routes, a new service and command, new columns that are opt-in for Marketing, and a new filter.
  - The migrations only create two new tables and one `app_settings` row.
  - No existing column, table or endpoint output changes.
- **Blast radius:**
  - `/item`: a seventh request (`/item/stock`) on load, the new columns and the filter.
  - `/owner/column-settings`: six more ids in the owner_private list.
  - `supply_item_settings`: rows are written by the CEO's lead/palugit edits, and `/jnt/supply` reads the same rows. Those edits change the hidden lead/safety used by `/jnt/supply`'s recommendation. That's intended, because they're the same settings.
- **Revert:**
  1. Revert the branch's commits (or the squash commit).
  2. `php artisan migrate:rollback --step=3`. This drops `item_category_assignments` and `item_categories` (all assigned categories are lost) and deletes the `item_stock_start` row.
  3. `view:clear`.
  4. Lead/safety values written to `supply_item_settings` stay, since they predate this feature's schema. Restore them from a backup if needed.
- **One-way part:** START. If the migration is rolled back and run again later, START moves to the new date, and the stock gauge restarts from zero.

## Suggestions for Busing

- **A physical stock count** (out of scope here) would replace "zero muna" and the "bilangin" marker with a real starting number, and keep the "left" query small by moving START forward.
- **One shared base-key helper for all five copies** (`keyFor`, `HoldService::itemKey`, `SupplyFinanceController::itemKey`, JS `supKey`, and the new `ItemBaseKey`).
- **Consider showing `/jnt/supply`'s settings** (lead/safety) there too, now that `/item` writes them.
