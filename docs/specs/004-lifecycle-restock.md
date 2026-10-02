# Spec 004: Restock target by item lifecycle on /item

Handoff: `handoff/004-lifecycle-restock/HANDOFF.md`. Builds on spec 003 (`docs/specs/003-stock-doi-category.md`).

## Threat model

- **Untrusted:** the `/item/stock` query params (`start_date`, `end_date`, `view_as`, already validated by 003), the body of `POST /jnt/supply/setting-kv` and `POST /item/supply-settings` (numbers).
- **Trusted:** repo, migrations, config, the CEO session, the existing `supply_settings` / `supply_item_settings` rows.
- Writes stay CEO-only. No new route.

## 1. Lifecycle, shared with /jnt/supply

- `JntSupplyController::classifyLifecycle()` and `lifecycleBadge()` move to `App\Support\ItemLifecycle::classify()` / `::badge()`, unchanged line for line. The two private methods stay and only delegate, so every `/jnt/supply` call site is untouched.
- A characterisation test calls `JntSupplyController::classifyLifecycle` by reflection on a fixed table of inputs (every branch and boundary) **before** the move, and the same table runs against both the controller and `ItemLifecycle` after it.
- `ItemLifecycle` also holds the default thresholds `/jnt/supply` uses when its request has no params: recent window 14, new 30, long-running 90, scale 1.5, decline 0.5. `/item` always uses these defaults (it has no lifecycle params). The `supply_settings` rows `lifecycle_window_days`, `scaling_ratio`, … are not read by `/jnt/supply` today, so `/item` doesn't read them either.

## 2. Lifecycle inputs on /item (as-of = `/item` end date)

Same definitions as `/jnt/supply`, per base key (`ItemBaseKey::key`), counting every `macro_output` row (no STATUS filter, as `/jnt/supply`):

- `recent` = units with `ts_date` in [end − 13, end]; `prev` = units in [end − 27, end − 14]. One grouped query on the `ts_date` range with two `SUM(CASE …)`.
- `first` = `MIN(ts_date)` per `ITEM_NAME`, `ts_date <= end`. **Same full-table grouped scan as `/jnt/supply`'s step 5** (no `ITEM_NAME` index exists); the `<= end` bound can't change any result (an item whose first order is after the as-of date has no recent/prev units and is Dormant either way).
- `recentVel = round(recent / 14, 4)`, `prevVel = round(prev / 14, 4)`, `daysRunning = first ? diffInDays(first, end) : 9999`, then `ItemLifecycle::classify`.
- `lifecycle_override` (the lowest-id `supply_item_settings` row for the base key, 003's rule) wins when set; `lifecycle_auto = false` then.

## 3. Velocity

- 14-day velocity: 003's rule, unchanged (cancelled excluded, divisor = days since first order in the window, 1–14).
- 7-day velocity (Scaling only): units in [end − 6, end], cancelled excluded, **÷ 7**. Folded into 003's demand query as a `SUM(CASE WHEN ts_date >= end − 6 …)`, no new query.

## 4. Palugit

Defaults in `supply_settings`, group `item_palugit`, `int`, seeded by a migration only where missing:

| key | default |
|---|---|
| `palugit_new` | 3 |
| `palugit_scaling` | 14 |
| `palugit_consistent` | 10 |
| `palugit_active` | 7 |
| `palugit_declining` | 0 |
| `palugit_lugi` | 3 |

- Edited through the existing generic editor on `/jnt/supply/config` ("Other settings" lists every group that isn't a class group) and its route `POST /jnt/supply/setting-kv` (CEO-only already). That route gains one check: for keys in group `item_palugit`, the value must be an integer 0–255 (422 otherwise). Other keys behave exactly as before.
- Missing rows or table → the defaults above in code.
- **Per-item override:** new nullable `supply_item_settings.palugit_override` (unsignedTinyInteger, additive). `POST /item/supply-settings` writes it together with `safety_days` (003 behaviour for `safety_days` and `lead_time_days` unchanged, so `/jnt/supply` sees the same values as before); `safety_days` blank/absent → `palugit_override = NULL` ("balik sa lifecycle"), `safety_days` untouched. `/item` ignores `safety_days` from now on; existing rows have `palugit_override = NULL`, so they use the lifecycle default until the CEO sets one on `/item`.
- Precedence for the palugit of a set: `palugit_override` → lifecycle default (normal set) or `palugit_lugi` (lugi set).

## 5. Result sets per base key

Top level: `name`, `variants`, `hold_units`, `incoming`, `stock_raw`, `stock`, `stock_needs_count`, `lead`, `palugit_override`, `category_id`, `category`, `lifecycle`, `lifecycle_label`, `lifecycle_auto`, `gated`, `normal`, `lugi`. The per-set fields (`units_per_day`, `safety`, `doi`, `order_qty`, `order_by`, `colour`) leave the top level; the only reader is `/item`, and 003's tests read `normal.*` instead.

Each set: `units_per_day`, `palugit`, `doi`, `doi_note`, `order_qty`, `order_by`, `colour`.

| lifecycle | `normal` velocity / palugit | `lugi` | `gated` |
|---|---|---|---|
| new | 14-day / `palugit_new` | = normal | false |
| scaling | 7-day / `palugit_scaling` | 14-day / `palugit_lugi` | true |
| consistent | 14-day / `palugit_consistent` | 14-day / `palugit_lugi` | true |
| active | 14-day / `palugit_active` | = normal | false |
| declining | 14-day / `palugit_declining` | = normal | false |
| phasing_out, dormant | HOLD only | = normal | false |

Per set, with v = its velocity, P = its palugit, L = lead:

- Normal lifecycles: `order_qty = max(0, ceil(HOLD + v × (L + P) − incoming − stock))`; `doi = round((stock + incoming − HOLD) ÷ v, 1)` computed exactly like 003 (units × days ÷ units); colour red `< L`, amber `< L + P`, green; `order_by` as 003. v = 0 → 003's no-demand rule (doi/colour/order_by null).
- Phasing Out / Dormant: `order_qty = max(0, ceil(HOLD − incoming − stock))` (v treated as 0: no lead, no palugit cover), `doi = null`, `doi_note = 'walang_benta'`, `colour = 'grey'`, `order_by = null`. `palugit = null`.
- Near-zero: 0 < v < 0.5 (any other lifecycle) → `doi_note = 'halos_walang_benta'`, `colour = 'grey'`; `doi` and `order_by` still computed (sorting), `order_qty` by the formula.
- `stock_ready = false` → as 003: order_qty/doi/order_by/colour null in both sets.

## 6. Page (/item)

- Set choice per item row: `S.gated && A.proj_pct_7d != null && A.proj_pct_7d <= 0 ? S.lugi : S.normal` (`A` = that item row's aggregate, as the PROF.%(7D) cell shows it). Hold-only rows have no PROF.%(7D) → normal.
- LIFECYCLE column (`lifecycle`): catalog + `DEFAULT_VISIBLE` (CEO), `defaultCols()`, item-row cell (badge = `lifecycle_label`, plus " · lugi" when the lugi set is chosen, "(manual)" marker when `lifecycle_auto` is false), blank page-row cell, sort by label. One-line Taglish tooltip per lifecycle saying what it means and the palugit it uses.
- BENTA/ARAW, DOI, the "lead N · palugit M" line, I-ORDER and the order-by line read the chosen set; DOI shows "walang benta" / "halos walang benta" in grey for the notes. Sorting on these columns reads the chosen set.
- CEO palugit editor: the palugit field may be left blank = "balik sa default ng lifecycle" (sends no `safety_days`).
- Category: `category` leaves `DEFAULT_VISIBLE`; a data migration adds `category` to the saved `owner_private` column config's `hidden` list and removes it from every `visible_by_role` list (no-op when nothing is saved). The Category selector shows only while the CATEGORY column is visible; when hidden the filter resets to Lahat.
- New values use `x-text` only.

## 7. Tests (expected values typed from the handoff §5)

- `StockEndpointTest` (or a new `LifecycleStockTest`): each §5 row at `GET /item/stock`, the DOI example (16.7 amber), the `lugi` set for Scaling and Consistent, `gated`, `lifecycle_override` precedence, `palugit_override` precedence (170), the near-zero note, Phasing Out / Dormant note and qty, and lifecycle inputs counting cancelled orders.
- `JntSupplyLifecycleTest`: the characterisation table.
- `ItemEditTest`: `palugit_override` written / cleared; `setting-kv`: Marketing 403 with nothing written, `item_palugit` 0–255 table (256, −1, "x", 2.5 → 422), a non-palugit key still accepts its old range.
- Migration test: the column, the seeded rows (insert-only-missing), the column-config migration.
- `ItemPageTest`: LIFECYCLE registered and rendered, set chosen from `proj_pct_7d`, the two texts, CATEGORY not default-visible and the selector tied to it.
