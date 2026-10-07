# Spec 006: /item simple "To order" view, in English

Source: the task brief for this work (not kept in the repository). Base `develop` at `d9606c8`. Risk tier: **medium** (internal
page, view-only, Old view is the fallback). Side: frontend only; no PHP, route, query or formula change is planned.

## 1. Threat model

- **Untrusted:** item, page, supplier and quote text typed by staff; query params (`layout`, `tab`, `list`,
  `view_as`, dates). All of it reaches the page only through `x-text` / `:title` / `:alt`; no `x-html`.
  `tab` is read as an allow-list (`sales` or default), never echoed.
- **Trusted:** repo, config, the CEO session, the owner's conditional-format rules
  (`owner_private_col_format`, edited only by the CEO on `/owner/column-settings`).

## 2. What exists (005) and what replaces it

- `_table_new.blade.php` (11 columns) and `_il_expand.blade.php` are replaced. `_table_new` is deleted; two new
  partials take its place: `_table_order.blade.php` (To order) and `_table_sales.blade.php` (Sales & Profit). The
  details panel stays in `_il_expand.blade.php`, rewritten in English. `_il_lifecycle.blade.php` becomes the Trend
  cell.
- `_table_old.blade.php` (Old view) is **not touched** (byte-identical; the test pins it).
- Reused, unchanged: `/item/stock` (`stockFor`, `stockSet`, `stockIsLugi`, `baseProfitPct7`), `itemGroups()`,
  `aggOf`, `suppliersFor`, `quotesFor`, `ilPieceCost`, `ilBeRange`, `cellFormatStyle` / `_evalRules`,
  quote and lead/buffer editors, photo, copy, campaigns panel (`.il-camp`).
- Data source for the owner's colour rules: `window.__COL_FORMAT__` (already passed by `ItemController::index`
  from `loadColFormat('owner_private')`), ids `proj_pct_1d`, `proj_pct_3d`, `proj_pct_7d`, `proj_pct` (1 month).
  **No change outside /item is needed.**

## 3. Views and tabs

- Two tabs above the table: **To order** (default) and **Sales & Profit**. The choice lives in the URL as
  `?tab=sales` (absent = To order), kept by `load()` and the Old-view link like `list` and `layout`.
- Toolbar link **Old view** (`?layout=old`), and **New view** from the old page. Old view renders `_table_old`
  as today.
- Both views share `itemGroups()` (filters, chips, category), the › details and the expand state.

## 4. To order (CEO view; Marketing differences in §7)

| Column | Content |
|---|---|
| **Item** | photo, name, "1,761 on hold" (sub-line). Peach row when the item has no running page. |
| **Next step** | one state, first match wins (§4.1) + a short reason sub-line (§4.2) |
| **Qty to order** | "3,299 pcs" bold, grey "—" at 0 or no data. CEO sub-line "≈ ₱38,335" (§4.3) |
| **Days left** | §4.4 |
| **Profit (7 days)** | §4.5 |
| **Trend** | 🆕 New · 📈 Growing · ✅ Steady · 🔄 Active · 📉 Slowing · 🚫 Stopping · 💤 Stopped; small "losing money" tag when `stockIsLugi`; "(manual)" with its tooltip when `lifecycle_auto` is false |
| **›** | details toggle |

### 4.1 Next step (S = `stockFor`, P = `stockSet`)

| # | When | Text | Tone |
|---|---|---|---|
| 0 | stock not loaded, not ready, or no S/P | "—" | grey |
| 1 | `S.stock_needs_count` | 🟠 "Count the stock first" | orange |
| 2 | lifecycle `phasing_out` / `dormant`, or `P.units_per_day < 0.5` | ⚪ "Barely selling" | grey |
| 3 | `P.order_qty === 0` | 🟢 "OK" | green |
| 4 | CEO view, no PO supplier and no quote | 🔴 "Find a supplier" | red |
| 5 | `P.order_by === 'now'` | 🔴 "Order from <supplier> now" / "Order now" | red |
| 6 | otherwise | 🟡 "Order from <supplier> by Oct 24" / "Order by Oct 24" | yellow |

Supplier = the quote with the lowest non-null price, else the first `suppliersFor()` entry (latest PO; the
endpoint orders by `order_date` desc). Names appear only in CEO view (§7). Dates: English short month, "Oct 24".

### 4.2 Reason sub-line (one short line; the full reason goes to details)

- Count the stock first: "count is below zero".
- order_qty > 0 and buffer is null (Phasing Out / Dormant): "only the orders waiting".
- order_qty > 0 otherwise: "1,761 waiting + 10 days of sales" (hold, then lead + buffer days); hold 0:
  "10 days of sales".
- order_qty 0: incoming > 0 → "2,000 arriving"; else "enough stock".

### 4.3 Qty cost line (CEO only)

price = cheapest quote's price when any quote has a price; else `ilPieceCost(name)` (item value ÷ N for "N x").
"≈ ₱" + whole pesos of qty × price. If the qty is below that quote's MOQ, append " (min 3,000)". No price or qty
0 → no line.

### 4.4 Days left (whole days)

- no stock data, `stock_needs_count`, `doi_note` set (no sales or barely selling), or `doi` null → grey "—"
  (tooltip says why).
- `doi < 0` → red "Short N days", N = round(−doi) (= (hold − stock − incoming) ÷ sales a day, server's number).
- else N = round(doi): "N days" red when `P.colour === 'red'` (< lead), amber when `'amber'` (< lead + buffer),
  green when `'green'`; > 365 → green "1 year+"; under 1 → "<1 day".

### 4.5 Profit (7 days)

- % = `baseProfitPct7(name)` (Σ 7-day projected profit ÷ Σ 7-day gross sales over all variants of the base item,
  as 004). ₱ = the same Σ 7-day projected profit (new small helper beside it, same cache).
- "▲ 19.9%" / "▼ −3.2%" with "₱18,400" / "−₱1,200" under it (whole pesos).
- Fill: the owner's rule for `proj_pct_7d` via `_evalRules`. The matched rule's colour is used as a **light
  tint** (`color-mix(in srgb, <rule colour> 22%, white)`), dark text; the owner's rules use full-strength
  colours (seeded: < 5 red, < 10 orange, < 20 cyan, ≥ 20 green). No rule saved for the id → default green
  ≥ 15, yellow 0–15, red < 0. No profit data → grey "—", no fill.

## 5. Sales & Profit

| Column | Content |
|---|---|
| **Item** | as To order |
| **Orders today** | `G.agg.orders_last_day` |
| **Profit today** | ▲/▼ `ilMoneyKita(G.agg.projected_profit_last_day)` |
| **Profit %** | four sub-columns Today / 3 days / 7 days / 1 month: `G.agg.proj_pct_1d/3d/7d/proj_pct`, each ▲/▼ with the tint of the owner's rule for `proj_pct_1d/3d/7d/proj_pct` (default as §4.5) |
| **Ad spend** | `G.agg.adspent` |
| **Cost per order** | `G.agg.cpp`, sub-line "break-even ₱52" (`ilBeRange`) |
| **›** | same details |

Per item row (as 005), not per base item. TOTAL: "Total (shown)" over `itemGroups()` pages as 005: orders,
profit today, the four %, ad spend, CPP.

## 6. Shared rules

- **Sort.** Default: Next step urgency red → orange → yellow → green → grey ("—" last), then hold descending.
  Header sorts on every column of both views (`_itemSortValue` gains `il_next`, `il_profit7`; existing keys for
  the rest; Profit % sub-headers sort by their window). Old view keeps hold descending.
- **TOTAL (To order).** "To order now: 48 items · 31,250 pcs · ≈ ₱1,204,000" over shown rows whose Next step is
  red; "≈ ₱" only in CEO view, summing rows that have a cost line.
- **Chips.** All · Need a supplier · Has a quote, not ordered · Ready to order · Ordered, waiting (same keys
  `lahat/hanapan/may_quote/i_order/naka_order`, same counts).
- **Details (›),** English: sales a day (with "average of the last N days"), stock / incoming, lead time + buffer
  (✎ CEO), the full order-qty reason, lifecycle detail, supplier and quote lines with the inline add / edit /
  delete (CEO), item cost per piece (+ CEO value if different), category (when visible; CEO select), Change
  photo, Copy, the sourcing-list info (CEO), "N running pages" / "No running ad", then the per-page cards and
  the campaigns panel exactly as 005 fit them.
- **English sweep.** Every string the page shows outside `_table_old.blade.php`: toolbar, chips, category
  filter, empty rows, loading / error messages, alerts, tooltips, modals (action note, edit row, photo), and the
  JS helpers that feed them. Old-view-only helpers (`doiText`, `orderByText`, `lifecycleTip`, `leadLine`, …)
  stay as they are, because `_table_old` uses them.
- **Column grants.** Both views are fixed sets. Existing role grants from `/owner/column-settings` still act as a
  data gate: a column whose source ids are all hidden for the role is not rendered (e.g. Profit (7 days) needs
  `proj_pct_7d`, Ad spend needs `adspent`). No new toggle, no config change.

## 7. Marketing view (Marketing role, or CEO with 👁 View: Marketing)

No supplier names ("Order now" / "Order by Oct 24"), no "Find a supplier" state, no "≈ ₱" lines or ₱ in TOTAL,
no ✎ editors, no quote lines, no "CEO value". Gated in Blade (`@if($effectiveIsCEO)`) and in JS
(`effectiveIsCeo`); the endpoints already return empty supplier / quote data to non-CEO roles.

## 8. Width budget at 1,366 px

Usable table width at a 1,366 px window: about **1,333 px** (scrollbar and page padding, as 005 measured).

| To order | px | Sales & Profit | px |
|---|---|---|---|
| Item | flex, min 300 (gets ≈ 537) | Item | flex, min 300 (gets ≈ 511) |
| Next step | 250 | Orders today | 90 |
| Qty to order | 130 | Profit today | 120 |
| Days left | 120 | Profit % (4 × 84) | 336 |
| Profit (7 days) | 130 | Ad spend | 110 |
| Trend | 130 | Cost per order | 130 |
| › | 36 | › | 36 |
| **Fixed sum** | **796** (+ 300 = 1,096) | **Fixed sum** | **822** (+ 300 = 1,122) |

- ≥ 1,366 px: both fit with ≥ 200 px spare; no horizontal scroll. At 1,920 px the Item column takes the rest.
- 1,100–1,365 px: Item min 260, Profit % sub-columns 72 px → To order 1,056, Sales 1,034 (fits ≈ 1,067).
- < 1,100 px: one card per item. To order card: name + hold, Next step + reason, Qty, Days left, Profit (7 days),
  Trend, ›. Sales card: name, Orders / Profit today, the four % in a 2×2 grid, Ad spend, Cost per order, ›.
- Text: one line per cell plus at most one sub-line; nothing under 11 px; long item names wrap (no ellipsis).

## 9. Testing (seams)

`ItemPageTest` renders `/item` as CEO and as Marketing and asserts markup: the tabs, both header sets in order
with sort keys and one tooltip each, the Next step / Days left / Trend texts, the qty and TOTAL texts, the five
chip labels, header tooltips, English strings present and the replaced Taglish strings absent from the default
render, CEO-only content absent for Marketing, no `x-html`, `_table_old` byte-identical to `d9606c8` and still
rendered for `?layout=old`. 005's new-layout assertions are replaced by these; every Old-view assertion stays.
No JS runner and no browser: runtime choices (state picked, numbers, colours, sort order, widths) are covered by
markup only, and the result notes say so.

## 10. Out of scope

`/owner/private`, `/owner/column-settings`, `/jnt/supply`, Supply Finance, PO data, `item-summary`, every formula,
new metrics, removing the Old view.
