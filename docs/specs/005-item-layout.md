# Spec 005: /item layout that fits the screen, restock decision first

Handoff: `handoff/005-item-layout/HANDOFF.md`. Branch `feat/item-layout`, cut from `feat/lifecycle-restock` at `762d4ae`.

## 1. What changes

`/item` gets a new default layout: one row per item with 11 composite columns that fit at 1,366 px without horizontal scroll, plain Taglish sentences for the restock decision, and an expanded block (›) that holds everything else. `?layout=old` renders today's table unchanged. No formula, query or endpoint number changes.

## 2. Threat model

- **Untrusted:** item, page, supplier and quote text typed by staff (rendered with `x-text` / `:title` only, never `x-html`); query params `layout`, dates, `list`, `view_as`, `item`.
- **Trusted:** repo, config, the CEO session, the saved column config.
- `layout` is whitelisted server-side: only the exact string `old` selects the old table; anything else renders the new layout.

## 3. Structure

- `ItemController::index` passes `layoutOld = ($request->query('layout') === 'old')`.
- `resources/views/item/index.blade.php` keeps the head, toolbar, chips, modals and the whole Alpine component. The table block (today's `<!-- Scroll area -->` … `</div>`, lines 663–1528) moves **verbatim** to `item/_table_old.blade.php`; the new table is `item/_table_new.blade.php`. `@if($layoutOld) @include('item._table_old') @else @include('item._table_new') @endif`.
- The Alpine component gains `layoutOld` (from Blade) and new helpers. Existing helpers used by the old table (`tot`, `doiText`, `orderByText`, `doiColour`, `leadLine`, `lifecycleBadgeText`, `lifecycleStyle`, `md`, `fmtDate`, `itemGroups`' HOLD sort, `saveCols`) keep their behaviour; new behaviour goes into new helpers, or behind `layoutOld`.
- `load()` keeps `layout=old` in the URL when it's set. A toolbar link switches views: "Lumang view" (new → old) and "Bagong view" (old → new).

## 4. Column visibility (no change to `/owner/private`)

The composite columns map onto the existing `owner_private` catalog ids, the same way the RTS/DEL/INT cell already merges three ids (`mergeRtsTrio`, `members`). A composite shows when at least one of its member ids is visible for the role; each line inside it shows only when its own id is visible. No catalog entry, no new config key, no migration. The CEO grants per role on `/owner/column-settings` exactly as today.

| New column | Member ids (line by line) | Header sort key |
|---|---|---|
| ITEM | always shown | `item_name` |
| LIFECYCLE | `lifecycle` | `lifecycle` |
| STOCK / PAPARATING | `stock`, `incoming` | `stock` |
| BENTA/ARAW | `units_per_day` | `units_per_day` |
| AABOT PA? | `doi` | `doi` |
| I-ORDER | `order_qty` (qty, pill, reason); cost line also needs `item_val` or `item_val_ceo` | `order_qty` |
| KITA NGAYON | `proj_prof_1d`, `orders_1d` | `projected_profit_last_day` |
| KITA % | `proj_pct_1d`, `proj_pct_3d`, `proj_pct_7d`, `proj_pct` (1M) | `proj_pct_last_7d` |
| ADS | `adspent`; line 2 `cpp`, `breakeven_cpp` | `adspent` |
| ACTION | `action` | `action_at` |
| › | always shown | — |

Expanded block fields follow their own ids: `promo`, `price`, `np_per_order*`, `per_order`, `rts_set`, `jnt_*`, `tcpr`, `proj_profit`, `proj_prof_3d/7d`, `orders`, `proceed`, `pcpp`, `item_val`, `item_val_ceo`, `ship`, `cod_fee`, `hold`, `category`. The saved column **order** isn't used by the new layout (its order is fixed); drag-to-reorder stays in the old view only, so the new layout never writes the shared order.

## 5. Main row

| Column | Content |
|---|---|
| ITEM | Sticky left. Photo, name, chip "Naka-hold: 1,761" (`row.hold`). CEO only: supplier/PO line, quote lines with inline add/edit/delete, "⚠ wala pang supplier" (as today). "⚠ walang running page" peach row kept; worklist extra info kept. Change and Copy move to the expanded block. Below 1,440 px LIFECYCLE shows here under the name. |
| LIFECYCLE | 🆕 Bago · 📈 Lumalaki · ✅ Stable · 🔄 Aktibo · 📉 Bumababa · 🚫 Itinitigil · 💤 Tulog, mapped from `S.lifecycle` (the server's English `lifecycle_label` stays for `/jnt/supply` and the old view). Text tag "· lugi" when the lugi set is chosen. "(manual)" with its 004 tooltip. Neutral pastel badges; Bumababa and Itinitigil are not red. |
| STOCK / PAPARATING | "Stock 40 · Paparating 200" (wraps to two lines). `stock_needs_count` → grey "Hindi pa nabibilang" instead of the stock number. Stock not ready → "—". |
| BENTA/ARAW | `units_per_day`, one decimal. Tooltip "average ng huling N araw" with N = the set's new `velocity_days` (7 when Lumalaki uses the 7-day figure). |
| AABOT PA? | One state per row (§6). Line 2: "Dating sa 7 araw + 3 araw reserba (Lumalaki)"; HOLD-only lifecycles: "Dating sa 7 araw · HOLD lang". CEO: a ✎ button opens today's lead/palugit editor (same save, same validation). |
| I-ORDER | Line 1 bold "Umorder 3,299 pcs". Line 2 pill (§7). Line 3 CEO only: "≈ ₱98,970". Tooltip: the reason line (§7). |
| KITA NGAYON | `A.projected_profit_last_day` as signed money; line 2 "84 orders" (`A.orders_last_day`). Blue ▲ for ≥ 0, orange ▼ for < 0. |
| KITA % | 2×2 grid 1D · 3D / 7D · 1M from `A.proj_pct_1d/3d/7d` and `A.proj_pct`: "1D ▲15.8%", blue ≥ 0, orange < 0, grey "—" for null. No fills. |
| ADS | `A.adspent`; line 2 "CPP ₱17.91 · BE ₱52.48" (§8 for BE). |
| ACTION | Latest action note among the item's pages (by `action_at`), truncated, page name in the tooltip; editing stays per page in the expanded block. "—" when none. |
| › | Button (keyboard reachable) toggling the expanded block; also on hold-only items, so Change/Copy stay reachable. Row click keeps toggling, as today. |

## 6. AABOT PA? states (from the chosen set `P = stockSet(name)`, no new formula)

Checked in this order:

1. Stock data not loaded / not ready / no `S`: grey "—".
2. `S.stock_needs_count`: grey "Hindi pa alam — bilangin muna ang stock" (the shown stock is 0 but the real stock isn't known).
3. `P.doi_note === 'walang_benta'`: grey italic "💤 Walang benta". `'halos_walang_benta'`: grey italic "🐢 Halos walang benta". `P.doi == null` with `units_per_day == 0`: grey italic "💤 Walang benta".
4. `P.doi < 0`: red "⚠ Kulang: X araw na benta ang naka-hold", X = |doi|.
5. `0 ≤ P.doi < lead`: red "⚠ Mauubos bago dumating".
6. `lead ≤ P.doi < lead + palugit`: amber "Malapit na: X araw".
7. otherwise: teal "✓ Sapat: X araw"; X > 365 → "✓ Sapat: mahigit 1 taon".

Steps 4–7 follow the server's `colour` (red / amber / green), split by the sign of `doi`; the page doesn't recompute thresholds. Day format: whole days when |X| ≥ 10 (rounded), one decimal below 10 (Mira's answer 1).

## 7. I-ORDER

- Line 1: "Umorder N pcs" from `P.order_qty`; "—" when null.
- Pill, first match wins: `stock_needs_count` → "Bilangin muna ang stock" (grey) · qty 0 → "Hindi pa kailangan" (teal) · CEO view and no supplier (Supply Finance) and no quote → "Hanap muna ng supplier" (amber) · `order_by === 'now'` → "⚠ ngayon na" (red) · a date → "bago Okt 24" (neutral) · else none.
- Line 3 (CEO view only): "≈ ₱98,970" = qty × puhunan bawat piraso, where puhunan = ITEM VAL. (CEO) when viewing as CEO and it exists, else ITEM VAL., divided by the row's "N x" count (see open question 2). Hidden when qty is 0 or no value exists.
- Reason line (tooltip and expanded block): "1,538 para sa 10 araw + 1,761 naka-hold − 0 paparating − 0 stock", i.e. ⌈v × (lead + palugit)⌉ for (lead + palugit) days, HOLD, incoming, stock. HOLD-only lifecycles: "1,761 naka-hold − 0 paparating − 0 stock (HOLD lang)". The text explains the server's `order_qty`; the page doesn't use it to compute anything.

## 8. ADS line 2: breakeven

Breakeven CPP exists per page only today (`breakevenCppFor(row)`). At item level: one page with a value → "BE ₱52.48"; several pages → "BE ₱48.10–₱55.20" (lowest–highest of the page values); none → omitted. No new formula (open question 3).

## 9. Expanded block (›)

One full-width cell under the item row, wrapping content, no horizontal scroll:

- **Item section** (definition-list grid, `repeat(auto-fill, minmax(150px, 1fr))`): Puhunan bawat piraso: ₱30 (+ "CEO: ₱34" only when it differs, CEO view only); RTS/DEL/INT (aggregate); TCPR; PROF.PROFIT (period total); NP/O(1M); CATEGORY when visible (CEO select as today); the I-ORDER reason line; Change / Add photo; Copy. Below 1,366 px, ACTION; below 1,100 px, also STOCK / PAPARATING, BENTA/ARAW, KITA NGAYON and ADS (they leave the card).
- **Pages** (one card per page row, in the current page sort): page name (breakdown link when `is_range`), item names in the page, mixed-primary and back-filled warnings; a wrapping grid of every visible page field with its current formatting minus fills (signed text, ▲/▼ for profit fields); the ✎ buttons for Set RTS%, Promo, Item Val., Item Val. (CEO, CEO view) and Action open the same modals as today; a "Campaigns ›" toggle that renders `owner._private_expand_inline` under the card. The panel's markup is shared with `/owner/private` and stays unchanged; inside /item's new layout it sits in a wrapper class only the new layout uses (e.g. `.il-camp`), and CSS scoped to that class makes the campaigns / ad sets / ads table fit with **no horizontal scroll** (fixed table layout at 100% width, `min-width` overrides, wrapping cells; a vertical inner scroll is allowed). Mira's answer 6. If it truly can't fit without changing the shared markup, the build stops at that step and reports what it would take.

## 10. Colour and type

Red only with ⚠ and a word (Kulang, Mauubos bago dumating, ngayon na). Amber: Malapit na, Hanap muna ng supplier. Teal ✓: Sapat, Hindi pa kailangan. Blue ▲ (#1d4ed8) for kita, orange ▼ (#c2410c) for lugi. Grey (#64748b, not lighter, for AA) for no data. The HOLD chip is neutral (slate). Body 12–13 px, sub-lines ≥ 11 px. The new layout applies no conditional-formatting or `pbStyle` fills (decision "no full-cell fills"); the old view keeps them.

## 11. Sort, TOTAL, toolbar, formats

- **Default sort (new layout only, no header sort chosen):** rank 0 = Kulang/Mauubos with a supplier or quote; 1 = Kulang/Mauubos without; 2 = Hindi pa nabibilang; 3 = Malapit na; 4 = Sapat; 5 = no sales / no data. Ties by HOLD descending. Marketing has no supplier data, so ranks 0 and 1 merge. The old view keeps HOLD descending. Header sorts use the sort keys in §4 through the existing `_itemSortValue`.
- **TOTAL (nakikita):** `aggOf` over the page rows of the item groups currently shown (item checkbox filter, sourcing chip and category applied). Cells: Naka-hold sum, KITA NGAYON, KITA %, ADS (adspent, CPP). The old view's TOTAL is unchanged.
- **Chip:** "I-order na" → "Handa nang i-order (may supplier)"; same key and logic.
- **Toolbar:** `#nav` wraps onto two rows (title, search, dates / buttons) so nothing is clipped at 1,366 px; "Expand all / Hide all" gets a fixed width. Applies to both views (it's above the table).
- **Formats (new helpers):** money "−₱534.71" (U+2212, sign before ₱); dates "Okt 24" (Ene Peb Mar Abr May Hun Hul Ago Set Okt Nob Dis); thousands separators kept.

## 12. Widths

The new layout's `#scroll` side padding is 8 px. Usable table width = viewport − 17 px scrollbar − 16 px padding. `table-layout: fixed`; ITEM takes the remainder.

| Column | ≥ 1,440 px | 1,366–1,439 px | 1,100–1,365 px |
|---|---|---|---|
| ITEM (rest) | ≥ 255 | ≥ 293 | ≥ 247 |
| LIFECYCLE | 112 | under item name | under item name |
| STOCK / PAPARATING | 104 | 104 | 104 |
| BENTA/ARAW | 72 | 72 | 72 |
| AABOT PA? | 188 | 188 | 170 |
| I-ORDER | 150 | 150 | 136 |
| KITA NGAYON | 96 | 96 | 96 |
| KITA % | 150 (2×2) | 150 (2×2) | 96 (stacked) |
| ADS | 128 | 128 | 110 |
| ACTION | 116 | 116 | in expanded block |
| › | 36 | 36 | 36 |
| **Fixed sum** | 1,152 | 1,040 | 820 |
| **At the narrowest width** | 1,440 → table 1,407, ITEM 255 | 1,366 → table 1,333, ITEM 293 | 1,100 → table 1,067, ITEM 247 |

Below 1,100 px: cards (CSS on the same markup): header row hidden; each item is a card with name + lifecycle, the AABOT PA? and I-ORDER sentences, KITA %, and the › button; the other cells move into the expanded block.

## 13. Data

Reuse `/item/stock`, `item-summary`, `/item/data`, the worklist and supplier/quote loads. One additive field: each restock set (`normal`, `lugi`) in `GET /item/stock` gains `velocity_days` (the day count behind `units_per_day`: 7 when Scaling's 7-day figure wins, else the 14-day window's day count, 1–14). No number changes; no new query.

## 13a. Mira's answers (go, 2026-10-02)

1. Day rounding: follow the rule — whole days when ≥ 10 ("Kulang: 12 araw…", "Sapat: 30 araw"), one decimal below 10.
2. Cost line uses qty × (item value ÷ N of the row's "N x" prefix); "Puhunan bawat piraso" is per piece.
3. Item-row BE: one page → its value; several → lowest–highest; none → omitted.
4. 1,100–1,365 px: ACTION moves into the expanded block, KITA % stacks.
5. No conditional-formatting or `pbStyle` fills in the new layout; the old view keeps them.
6. Campaigns panel: no horizontal scroll inside it either; wrap/stack with CSS scoped to /item's new layout; shared markup unchanged (§9).
7. Uncounted stock: grey "Hindi pa alam — bilangin muna ang stock"; for Marketing the two Kulang/Mauubos sort groups merge.
8. Pill order: Bilangin muna → Hindi pa kailangan → Hanap muna ng supplier → ngayon na → bago <date>.

## 14. Tests

- `GET /item/stock`: `velocity_days` table (Scaling 7-day wins → 7; Scaling 14-day wins → 14; Active → 14; a 5-day-old item → 5; HOLD-only → the 14-day count), added to `LifecycleStockTest`'s existing tables.
- `GET /item` (HTTP, CEO): default renders the new layout; `?layout=old` renders the old table; `?layout=OLD` / `?layout=x` render the new one.
- `ItemPageTest` markup (CEO and Marketing renders): the 11 headers; AABOT state texts; pill texts; "Umorder "; the lifecycle labels; "Handa nang i-order (may supplier)"; "TOTAL (nakikita)"; "Lumang view"; old render keeps today's table markers (`@include('item._agg_cells')` output, "Drag headers to reorder", `TOTAL`); CEO-only content absent for Marketing (quote editor, supplier line, "≈ ₱", ✎ lead/palugit, "CEO: ₱", "Hanap muna ng supplier"); no `x-html`. Existing `ItemPageTest` assertions stay green (the old table's strings live in `_table_old`, which the old render includes; tests that assert old-cell strings render the old layout).
- **Not covered (no JS runner, no browser):** the state, pill, sort-rank, cost, TOTAL and formatter logic at runtime, breakpoints, and the card layout. Markup assertions only prove the code and texts are present.
