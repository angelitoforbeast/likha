# Spec 003: Item value on item rows, stock gauge, DOI, order quantity, category

Handoff: `handoff/003-stock-doi-category/HANDOFF.md`. Branch `feat/stock-doi-category` from `f20b200`. Risk tier: medium.

## Verified facts (2026-10-02, code read only)

- **Indexes the new queries use:** `macro_output.ts_date` (`macro_output_ts_date_index`), `macro_output.waybill` (`2025_08_04_000129`, `->index()`), `macro_output.STATUS` (`idx_macro_output_status`), `from_jnts.waybill_number` (`idx_from_jnts_waybill` and `from_jnts_waybill_number_index`), `from_jnts.submission_time` (`idx_from_jnts_submission_time`, DATETIME since `2026_01_23_221803`), `supply_order_items.item_key`. So "units that left" can be computed from indexed columns, and no schema change on existing tables is needed.
- **`supply_item_settings.lead_time_days` and `safety_days` are `unsignedTinyInteger`** (`2026_04_23_000002`), so MySQL stores at most **255**. The handoff's 0–365 would make a save of 256–365 fail with a 500 error in strict mode. That's a real conflict; see question Q1.
- `app_settings` is `key` (unique) / `value` (text) / timestamps, so the START row is a plain `updateOrInsert`.
- The column catalog `owner_private` is shared with `/owner/private`. Its view drops ids it doesn't define (`private.blade.php:2017`), so new catalog ids only show on `/item` and in the Columns settings list (and the conditional-format editor).
- `cogs` lookup on page rows: `OwnerPrivateController:1894-1929` (latest `date <= end_date`, keyed by `ItemAliasResolver::canonicalKey(raw name)`; `cogs_ceo` the same, no fallback).

## Questions for Mira (need answers with the go)

- **Q1. Lead/safety range.** The columns hold 0–255, not 0–365. Proposal: validate **0–255**, no schema change (the handoff says additive only). The alternative is a new migration that widens two existing columns, which changes an existing table.
- **Q2. `from_jnts` rows with `submission_time` NULL.** Proposal: they don't count as "left" (they can't be dated). Mira can check how many there are on production: `SELECT COUNT(*) FROM from_jnts WHERE submission_time IS NULL;`.

## Design

### Shared base-key helper (new)

`app/Support/ItemBaseKey.php`, used by all new code:
- `parse(string $name): array{qty:int, base:string, key:string}`: the `N x` / `N ×` prefix gives qty (minimum 1); `base` is the name without the prefix, original case, trimmed; `key` is lowercase with spaces collapsed.
- `key(string $name): string`, the same key as `ItemSupplierQuote::keyFor` and JS `supKey`. A unit test pins the equality, like 001's.

The four existing copies are not touched.

### `GET /item/stock?start_date&end_date&view_as` (`ItemController::stock`, logic in `app/Services/ItemStockService.php`)

- Route in the existing `/item` group: `->name('item.stock')`. Gate: `checkAccess()` (CEO, Marketing - OIC, Marketing).
- Dates: the same `$valid` check and defaults as `worklist()`. `end` is the selected end date.
- **START:** `app_settings.item_stock_start`. If the table or row is missing, the endpoint returns `stock_ready: false` and nulls for stock, DOI, order qty and order-by.

Inputs, each one grouped query, with the results folded into base keys in PHP over the grouped rows only:

1. **HOLD units:** `HoldService::liveHoldQuery($start, $end)` grouped by `ITEM_NAME`, then `groupUnitsByBaseItem`. This is exactly the `/item/worklist` HOLD.
2. **Units per day:** `macro_output`, `ts_date BETWEEN end-13 AND end`, not CANNOT PROCEED/ODZ (the same STATUS expression as `liveHoldQuery`), `GROUP BY ITEM_NAME` → `COUNT(*)`, `MIN(ts_date)`.
   - units = Σ count × qty
   - first = MIN over the base's variants
   - days = (end − first) + 1, clamped to 1..14
   - u/day = units ÷ days
   - Check against example A: first order 09-19, end 10-02 → 14 days.
3. **Left since START:**
   ```sql
   SELECT mo.ITEM_NAME AS item_name, COUNT(*) AS cnt
   FROM macro_output mo
   JOIN (SELECT DISTINCT fj.waybill_number FROM from_jnts fj
         WHERE fj.submission_time >= :start
           AND NOT EXISTS (SELECT 1 FROM from_jnts fj2
                           WHERE fj2.waybill_number = fj.waybill_number
                             AND fj2.submission_time < :start)) s
     ON s.waybill_number = mo.waybill
   WHERE <not cancelled>
   GROUP BY mo.ITEM_NAME
   ```
   - The waybill is dated by its earliest J&T record. A waybill whose first record is before START is excluded.
   - **Date column: `from_jnts.submission_time`.** It's when J&T took the parcel, which is when it physically left our stock. It's indexed and DATETIME. `signingtime` is the delivery date (null while in transit), and `created_at` is our upload time, not the event.
4. **Received since START:** `supply_order_items` join `supply_orders`, `counted_at >= START 00:00`, `received_qty` not null, `unit_cost >= 0` (a discount line isn't stock), `SUM(received_qty)` grouped by `item_key` → `ItemBaseKey::key`.
5. **Incoming:** lines of POs with status `ordered`/`delivered`, `ordered_qty > 0`, `unit_cost >= 0`, Σ max(0, ordered − received). This is the 001 "open PO" definition, keyed by `item_key` → `ItemBaseKey::key`.
6. **Lead/safety:** `supply_item_settings`, keyed by `ItemBaseKey::key(item_name)`. Defaults 7 / 3. If several rows share a key, the lowest id wins.
7. **Category:** `item_category_assignments` join `item_categories`.

Per base key, stock and the derived numbers:
- `stock_raw = received − left`
- `stock = max(0, stock_raw)`
- `stock_needs_count = stock_raw < 0`

**Order qty and DOI use the shown `stock`, not the raw value.** A negative raw value means stock existed before START. Using it would add phantom units to the order.

- `order_qty = max(0, ceil(hold + upd × (lead + safety) − incoming − stock))`
- `doi = round((stock + incoming − hold) / upd, 1)`, or `null` when upd = 0
- `order_by`:
  - `null` when upd = 0
  - `"now"` when doi < lead
  - otherwise `end + floor(doi − lead)` days (Y-m-d)
- `colour`:
  - `null` when upd = 0
  - `red` when doi < lead
  - `amber` when doi < lead + safety
  - `green` otherwise

Comparisons use the unrounded DOI.

Keys returned: the union of keys with any HOLD, demand, left, received or incoming. Each key carries `name` (base, original case) and `variants` (raw names seen, for the tooltip).

**Item value (`values` map in the same response).**
- Raw names: distinct `ITEM_NAME` in `macro_output` with `ts_date` in [start, end]. This is the same universe as the item rows; hold-only items are included.
- Each raw name maps (by `lower(trim(name))`, the `itemGroups()` key) to `item_value`, the latest `cogs` row with `date <= end` by `ItemAliasResolver::canonicalKey`.
- `item_value_ceo` is the same rule on `cogs_ceo`. It's **only present when role = CEO and `view_as = ceo`**, and the key is absent otherwise (data-layer gate).
- This duplicates the page-row lookup rule in the new service. `OwnerPrivateController` is not touched (legacy rule).

Response:
```
{ ok, start, end_date, stock_ready,
  categories: [{id, name}],
  items: { key: {name, variants, hold_units, units_per_day, incoming, stock_raw, stock, stock_needs_count,
                 lead, safety, doi, order_qty, order_by, colour, category_id, category} },
  values: { lower_raw_name: {item_value, item_value_ceo?} } }
```

### Inline edits (CEO only; `checkAccess()` + role ≠ CEO → 403, nothing written; `throttle:60,1`; CSRF header as the page already sends)

- **`POST /item/category`** `{item_name, category_id?, new_category?}`
  - `item_name` required|string|max:255
  - `category_id` nullable|integer|exists:item_categories,id
  - `new_category` nullable|string|min:1|max:60 (trimmed; empty after trim → 422)
  - Effects:
    - A new name is matched case-insensitively against existing categories; otherwise it's created with `sort_order = max + 1`.
    - An assignment is upserted by `ItemBaseKey::key(item_name)` with `updated_by`.
    - Both empty → the assignment is removed ("Walang category").
  - Returns `{ok, categories, category_id}`.
- **`POST /item/supply-settings`** `{item_name, lead_time_days, safety_days}`
  - `lead_time_days` and `safety_days`: required|integer|min:0|max:255 (Q1)
  - Effects:
    - Base name = `ItemBaseKey::parse(item_name)['base']` (original case, as `/jnt/supply` keys it).
    - It updates **every** `supply_item_settings` row whose `ItemBaseKey::key(item_name)` matches. Their stored `item_name` is kept, so `/jnt/supply` reads the same row.
    - If no row matches, it inserts one with the base name.
  - Returns `{ok}`.
- After a save the page re-fetches `/item/stock`, so the numbers are always computed by the server.

### Migrations (additive)

1. `create_item_categories_table`: id, name (unique, 60), sort_order (unsigned int), timestamps; seeds the 8 categories in handoff order (1..8). It's idempotent: insert only names that aren't there yet.
2. `create_item_category_assignments_table`: id, item_key (unique), category_id (FK → item_categories, restrict on delete), updated_by (nullable unsigned bigint), timestamps.
3. `seed_item_stock_start_setting`: `app_settings` `item_stock_start` = today (Asia/Manila) when the key is missing; `down()` deletes that key only.

Reads are guarded with `Schema::hasTable` and fall back to empty values.

### `items:suggest-categories [--apply]` (`app/Console/Commands/SuggestItemCategories.php`, `config/item_categories.php`)

- **Config:** `['rules' => [category name => [keywords…]]]` in the handoff's order.
- **Matching:** a case-insensitive `str_contains` on `' ' . lower(base name) . ' '`, so `"car "` matches "TOY CAR". First match wins.
- **Items considered:**
  - distinct `ITEM_NAME` from `macro_output` with `ts_date >= today − 90`
  - plus `supply_order_items.item_name`
  - plus `item_supplier_quotes.item_name`
  - all folded by `ItemBaseKey`
- **Dry run:**
  - one line per base: `<base> → <category>`
  - `[may category na: X]` when already assigned
  - then a `Walang tugma (N):` list
- **`--apply`:** inserts only for keys with no assignment and never updates an existing one. It prints the count written.
- Unmatched items are never set to "Iba pa". A category from the rules that's missing from `item_categories` → that item is skipped and the command warns.

### Page (`resources/views/item/index.blade.php`, `_agg_cells.blade.php`)

**Columns.** Ids `category`, `stock`, `incoming`, `units_per_day`, `doi`, `order_qty`, with labels CATEGORY, STOCK, PAPARATING, BENTA/ARAW, DOI, I-ORDER. They're registered in four places:
- `CATALOG` and `DEFAULT_VISIBLE` (owner_private)
- `defaultCols()`
- `_agg_cells` (item-row cells)
- a blank page-row cell

Sorting is through new `_itemSortValue` cases that read the stock map by `supKey`.

**Item-row ITEM VAL.**
- `_agg_cells` gets `item_val` and `item_val_ceo` cells bound to `itemValue(row.item_name)` / `itemValueCeo(row.item_name)`. A missing value shows a red "—" like the page row.
- The `item_val_ceo` cell is wrapped in `@if($effectiveIsCEO)`.
- Both ids leave the catch-all list. Sorting is through `case 'item_value'` / `'item_value_ceo'`.

**Cells (all `x-text`, one-line `title` tooltips in plain Taglish):**
- **STOCK:** the number, plus a "bilangin" tag when `stock_needs_count` (title "may stock na hindi nabilang bago ang <START>").
- **PAPARATING:** open PO units.
- **BENTA/ARAW:** one decimal.
- **DOI:**
  - "19.0 araw" in red, amber or green
  - "kulang 5 araw" when negative (absolute value, one decimal, a trailing ".0" dropped)
  - "—" when there's no demand
  - under the number, small text "lead 7 · palugit 3"; for the CEO it's clickable and opens two number inputs + Save
- **I-ORDER:**
  - the qty, and under it "bago mag <date>", "ngayon na" or "—"
- **CATEGORY:**
  - everyone: the name, or "—"
  - CEO: a `<select>` with "— wala —", each category, and "+ bagong category", which opens a small text input + Save
- Variant tooltip on base numbers: "para sa lahat ng variant: 1 x …, 2 x …" when the base has more than one variant.

**Load.** `loadStock()` starts alongside `loadHold()`/`loadWorklist()` before the `item-summary` fetch, with a stale-response request counter like `loadWorklist`. It never blocks the table.

**Category filter.** A `<select>` next to the Sourcing chips, **for every role that sees `/item`** (the chips stay CEO-only). Options: Lahat, each category, Walang category. `itemGroups()` adds `if (!this.categoryKeep(u.name)) continue;` after `worklistKeep`, so the two are AND-ed. The empty-table row covers this filter too.

## Threat model

- **Untrusted:** POST bodies (`item_name`, `category_id`, `new_category`, lead/safety), the endpoint's query parameters (dates validated; `view_as` whitelisted), and item names from `macro_output` / PO lines when rendered (`x-text` only, never `x-html`).
- **Trusted:** repo, config keyword rules, the CEO session, existing schemas.
- All SQL is parameterised.

## Tests (sqlite in memory, `tests/Feature/Item/*`)

- **`ItemTestCase`:** `from_jnts` gets a nullable `submission_time` column (string; the sqlite comparison of `Y-m-d H:i:s` is lexicographic). `migrationPaths()` adds the `app_settings` migration and the three new ones.
- **`StockEndpointTest`:**
  - examples A, B, C, D (expected values typed from the handoff)
  - the `values` map: item value for a hold-only item; `item_value_ceo` absent for Marketing and for CEO `view_as=marketing`
  - a waybill whose first J&T record is before START is not "left"
  - `stock_ready: false` without the START row
  - Marketing gets 200 with the same numbers
- **`ItemEditTest`:**
  - Marketing → 403 on both POSTs, and no rows are written
  - validation: lead 256 / −1 / "x", unknown category id, `new_category` of 61 chars or blank → 422
  - a new name reuses an existing category case-insensitively
  - clearing removes the assignment
  - a settings write updates the existing "Glow Tape" row (case kept) and inserts the base name when missing
- **`SuggestCategoriesTest`:**
  - dry-run output lines and the unmatched list; nothing written
  - `--apply` writes only unassigned items and leaves an existing assignment unchanged
- **`ItemBaseKeyTest`** (unit): `key()` equals `ItemSupplierQuote::keyFor` on a table of names; `parse()` qty.
- **`ItemPageTest`:**
  - the six ids are in `defaultCols()` and in `CATALOG`
  - the `_agg_cells` cells for the six ids
  - item-row `itemValue(row.item_name)` binding
  - the category `<select>` present for CEO and Marketing
  - CEO-only edit controls (`saveCategory(`, `saveSupplySettings(`) are absent in the Marketing render
  - `itemValueCeo(` is absent in the Marketing render
  - `loadStock()` comes before the `item-summary` fetch
  - no `x-html` added
