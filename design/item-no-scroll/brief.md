# Design brief: item summary table with no horizontal scroll (CEO view, /item?layout=old)

Format note: the `impeccable` skill was not available in this session (Unknown skill), so this is the plain brief format, not an impeccable `shape` brief. No PRODUCT.md written. This version replaces the two earlier drafts: it contains both course changes (fixed suppliers; one column per supplier of the Supply Finance list, no "Others" and no PO column).

Files in this folder (static, invented prices, items and numbers; nothing loaded from likhaaitech.com):
- `comp-1920.html` / `comp-1920.png` : 1920 viewport, default "Sourcing" set, Columns control closed.
- `comp-1366.html` / `comp-1366.png` : 1366 viewport, same.
- `comp-1920-columns.png` / `comp-1366-columns.png` : the Columns control open (in the html: click "+6 columns" / "+12 columns", or open the file with `#columns`).
- `comp-1920-band.html` / `comp-1920-band.png` : same table, but with this morning's full-width red "wala pang supplier" band on the no-supplier rows, to set beside the quiet warning mark.
- Checks (headless Chrome, true viewport 1920 and 1366): the scroll box's `scrollWidth` equals its `clientWidth` (no sideways scroll), and no cell has content wider than the cell. Screenshots are 1920x900 and 1366x900. 12 rows at 50 px; at 1366 the item names LIGHT SOCKET, NAIL CARE PEN, REFLECTIVE STICKER and CAR HOLDER wrap to three lines (about 58 to 60 px).

## 0. The supplier columns (both course changes folded in)

Reading (the owner showed /finance/supply, "Suppliers & Payments": "the supplier number is based on this; another supplier is added there and becomes Supplier 4"):
- The SUPPLIERS group has **one column per supplier of the Supply Finance suppliers list**, numbered by the position in that list. Three today, so three columns; a supplier added with "+ Add Supplier" becomes the fourth column, no code change. There is no "Others" column, no PO column and no separate setting: the Supply Finance list is the setting.
- Headers: number and short name, "1 · Kelly", "2 · Albee", "3 · Helen" (first name in the header, full name in the hover title). In the comp the headers carry the real first names given for them; every price in the comp is invented.
- Order: the suppliers table has no order or number column (id, name, contact, terms, opening balance, notes, created_by, timestamps), so the number is the **position in id order** (1st, 2nd, 3rd), which is also the order they were added. It is not the raw id (a deleted supplier leaves no gap in the headers) and not the alphabetical order the finance page and the quote dropdown show. Ruling for the owner to confirm (decision 2).
- A cell shows only that supplier's price for the item and its MOQ. Empty = a quiet 20 px "+" circle (adds that supplier's price). Filled = an edit pencil on hover, focus or tap (remove sits inside the edit card). No supplier picker in the cell, no supplier name repeated in the rows. Cheapest of the row marked by weight (price 800 and a light-blue underline, only when two or more prices exist, ties both marked). A quote exists with no price: a quiet "—". `item_supplier_quotes` is unique on (item_key, supplier_id), so a cell holds at most one quote: no "second quote of the same supplier" case.
- Day-one picture (given by the office): supplier 2 has no quotes, so column 2 is a full column of quiet "+" cells, and that is how it is drawn. For that reason no row in the comp has all three prices; states drawn: Kelly + Helen (GLOW TAPE, AIR PUMP, LED STRIP, PHONE STAND), tie (NANO TAPE), cheapest in the third column (PHONE STAND), one price (USB MINI BULB), a quote without a price (AIR FRESHENER, Kelly), PO only (REFLECTIVE STICKER on Helen, HAIR CLIP on Kelly), no price at all (LIGHT SOCKET, NAIL CARE PEN, CAR HOLDER).
- Cell width: a price plus an MOQ needs 76 px at 1920 and 72 px at 1366 (price 12 px / 600 blue `#1d4ed8`; line 2 "MOQ 1000" 11 px `#475569`, up from 10 px because of the 11 px floor; room for ₱128.50 plus the pencil). The group is 228 px at 1920 and 216 px at 1366 for three suppliers (this morning: 458).
- "Needs a supplier": a row with no price and no PO in any column shows its quiet "+" cells and **one small red warning mark** at the right edge of the item cell (20 px circle `#fee2e2`, glyph `#b91c1c`, in the same x position on every row so the marks read as a column; `title` and `aria-label` "wala pang supplier"). No full-width red wash. The rule is today's (warn only when there is no quote and no PO). The sourcing chip "Need a supplier" is the filter for the same set and stays. Cost: "wala pang supplier" as words is only in the tooltip and the label; `comp-1920-band.png` shows the band for judging.
- **The PO supplier**: one column per supplier means no separate PO column. How a supplier's last PO unit cost appears, without a second value in the cell (decision 1; drawn in the comps):
  - a supplier with a quote and a PO: the quote stays the only value; a 6 px green dot at the cell's top left; the hover card (not drawn, same hover pattern as this morning's) adds a line "Last PO ₱17.80, date, PO number" (those facts are in the tooltip today);
  - a supplier with a PO and no quote: the cell shows the PO unit cost in the existing PO green `#065f46` with a small "PO" tag (one value, green already means PO cost);
  - no PO: nothing.
  Data: the PO side is keyed by supplier id in the item-level query (`ItemController.php:273-290`, `$po[$k]['suppliers'][$l->supplier_id]`, `unit_cost > 0`, joined to `suppliers` through `supply_orders.supplier_id`; the client reads it as `suppliersFor()`, `index.blade.php:3231-3234`), so each cell can find its supplier's PO.

### Where the columns come from (repo)

- Supply Finance suppliers: table `suppliers`, created in `database/migrations/2026_06_04_020000_create_supply_finance_tables.php:20`; model `app/Models/Supplier.php` (fillable: name, contact, terms, opening_balance, opening_balance_note, notes, created_by; `orders()`, `payments()`). The page `/finance/supply` is `SupplyFinanceController@index` (`routes/web.php:1155`, name `finance.supply.index`); "+ Add Supplier" posts to `storeSupplier` (`routes/web.php:1158`, `finance.supply.suppliers.store`), edits to `updateSupplier` (`:1159`). A new row there is all the item page needs.
- The item page's quote form already loads this list: `ItemController::quotes()` (`app/Http/Controllers/ItemController.php:428-444`, route `item.quotes`) returns `suppliers` = `Supplier::query()->orderBy('name')->get(['id','name'])` (`:436-437`) plus every quote; CEO only (`:431`). `loadItemQuotes()` (`index.blade.php:3236-3241`) puts it in `supplierList` (`index.blade.php:1472`) and the quotes in `itemQuotes` (`:1471`). Each quote row carries `supplier_id` (`ItemController.php:723-735`).
- Build, in words: sort a copy of `supplierList` by id (the dropdown can stay alphabetical) and number it by position; the SUPPLIERS group header `colspan` and every row's cells follow that list's length; the empty/loading/total colspans (`_table_suppliers.blade.php:64, 72, 78, 83, 918`) become `cols.length + 2 + n` (PAGE, ITEM, n suppliers); a cell reads the quote with its `supplier_id` instead of `quotesFor(name)[i]`. The quote form keeps picking from the same list, so no quote is left without a column.

### What happens to the no-scroll budget at 4, 5, 6 and more suppliers

Per supplier column: 76 px at 1920, 72 px at 1366. Identity block (PAGE + ITEM): 304 px at 1920, 264 px at 1366. Available: 1,873 and 1,319 px. Moving columns away is by one fixed order, last in the list leaves first (so the owner sees the same rule every time): optional columns, kept by priority: PROF.PROFIT(1D) (the page's own green/red colour rule), ADSPENT, NP/O(1M), ORDERS (1D), CPP, HOLD, TCPR, RTS / DEL / INT; the core (the sourcing block, ITEM VAL. (CEO), PROF.PROFIT, Prof.%) stays as long as it can. Computed from the widths in section 3 (the 3-supplier row is what the comps show):

| Suppliers | Group (1920 / 1366) | At 1920, one step away in addition to the six already away | At 1366, one step away in addition to the twelve already away |
|---|---|---|---|
| 3 (today) | 228 / 216 | nothing; 17 columns shown | nothing; 11 columns shown |
| 4 | 304 / 288 | RTS / DEL / INT | ADSPENT |
| 5 | 380 / 360 | + TCPR | + PROF.PROFIT(1D): only the core is left |
| 6 | 456 / 432 | + HOLD | + BENTA/ARAW (a core column leaves, my order: BENTA/ARAW, PAPARATING, STOCK, LIFECYCLE) |
| 7 | 532 / 504 | + CPP | + PAPARATING |
| 8 | 608 / 576 | + ORDERS (1D), NP/O(1M) | + STOCK and LIFECYCLE: the group is 44 % of the table, stop |
| 9 | 684 / 648 | + ADSPENT | - |
| 10 | 760 / 720 | + PROF.PROFIT(1D): only the core is left | - |

- Up to 6 suppliers, cells stay as designed at both widths. From 7 at 1366, and from about 8 at 1920 (a third of the screen for suppliers), the group needs another idea. First idea: price-only cells 56 px wide (the MOQ moves to the hover card, still one value per cell); that holds up to 6 suppliers at 1366 without moving core columns and up to 14 at 1920. Second idea past about 10 suppliers: the supplier columns leave the main row and the comparison becomes a supplier pivot in the item's expanded block, with only "cheapest price and its supplier number" in the row. I would not design either until a fourth supplier exists; they are named so the choice is known.
- Each column that goes one step away is counted in "+N columns" and listed in the panel, so nothing disappears silently at any count.

## 1. Facts

Sources: the live page (read-only, ONE new tab, no clicks, scrolled both ways, screenshots) and the repo at develop 1c32cc1. (live) = the page, (code) = the repo.

### 1.1 Columns in his saved order, left to right (live header row), widths now

Widths now are the code minimums: `index.blade.php:1580-1628` (`defaultCols()` `minw`) and `_suppliers_style.blade.php:16-24` (item and supplier cells). The live screenshot agrees in proportion (each measured column matched its `minw` within about 10 px, using the known 118 px supplier cell as the ruler), but I could not read pixel widths from the page (no script tool in this session).

| # | Header (live) | Group / merged | Width now |
|---|---|---|---|
| 1 | PAGE (chevron, photo, name, HOLD pill) | rowspan 2 | 284 |
| 2 | ITEM ("N running page", Change, Copy) | rowspan 2 | 150 |
| 3-6 | SUPPLIERS: Supplier 1, Supplier 2, Supplier 3, PO | merged group header, colspan 4, two header rows | 118, 118, 118, 104 |
| 7 | PROMO | | 90 |
| 8 | PRICE | | 85 |
| 9 | NP/O(1M) | | 80 |
| 10 | ADSPENT | | 90 |
| 11 | ORDERS (1D) | | 80 |
| 12 | PROF.PROFIT(1D) | | 105 |
| 13 | ITEM VAL. | | 80 |
| 14 | ITEM VAL. (CEO) | | 90 |
| 15 | CPP | | 75 |
| 16 | SET RTS% | | 110 |
| 17 | RTS / DEL / INT | merged column of three catalog ids (`mergeRtsTrio`, `index.blade.php:1685-1696`) | 135 |
| 18 | TCPR | | 65 |
| 19 | BREAKEVEN CPP (5%) | | 115 |
| 20 | PROF.PROFIT | | 95 |
| 21-24 | PROF.%(1M), (7D), (3D), (1D) | family of four | 75 each |
| 25 | HOLD | | 60 |
| 26 | ACTION | | 160 |
| 27 | STOCK | | 75 |
| 28 | PAPARATING | | 90 |
| 29 | BENTA/ARAW | | 90 |
| 30 | DOI | | 110 |
| 31 | I-ORDER | | 100 |
| 32 | LIFECYCLE | | 120 |

26 configurable columns (counting RTS / DEL / INT as one) plus PAGE, ITEM and the four supplier cells. Sum of the minimums: 284 + 150 + 458 + 2,400 = **3,292 px**; live, the scrollbar thumb is about 61 % of its track, so the real width is near **3,500 px** (the owner said 3,400 to 3,900). The viewport as the page reports it could not be read; from the screenshot proportions it is about 2,130 CSS px (inference, unconfirmed).
Already hidden in his settings (inferred from the catalog against the live header row; 12): Orders, Proceed, P.CPP, /Order, NP/O (1D), NP/O (3D), NP/O (7D), Prof.Profit(3D), Prof.Profit(7D), Ship, COD Fee, Category. The four Claude/CEO text columns are force-hidden on this layout (`index.blade.php:1649`). They stay hidden and stay in the Columns list.
Important fact for the default set: PROMO and PRICE are **empty on every item row** in the live table; SET RTS% and ACTION are also per-page only; BREAKEVEN CPP shows "—" on item rows (`_agg_cells.blade.php:25-27, 298-302`). They only carry values on the expanded page rows.

### 1.2 How columns are configured today (code; no controls clicked)

- "⚙ Columns" (`index.blade.php:718-722`) is a link to `/owner/column-settings` in a new tab. It saves to the server table `app_settings`, key `owner_private_cols`, JSON `{order, hidden, visible_by_role}` (`app/Http/Controllers/OwnerColumnSettingsController.php:21-26, 32, 652-700`). Global, one row, **shared with /owner/private**: hiding a column on /item hides it there too.
- Applied in `initCols()` (`index.blade.php:1632-1679`) from `window.__OWNER_PRIVATE_COLS__` (`:1346`); `hidden` removes, `order` sequences; legacy fallback localStorage `private_col_order_v1` (`:1657`).
- Drag to reorder: `colDragStart/colDrop` (`:1714-1732`); `saveCols()` (`:1698-1711`) posts only `order` (the controller keeps `hidden`, `OwnerColumnSettingsController.php:677-690`). Global as well.
- Per role: `visible_by_role` for "Marketing - OIC" and "Marketing" (`OwnerColumnSettingsController.php:42`); CEO sees all; `item_val_ceo` and the Claude/CEO columns are forced hidden in Marketing view (`index.blade.php:1645-1649`).
- Hiding without code: yes, in /owner/column-settings (global, in a second tab). **Saved column sets per purpose: none.** "New view" (`:684-697`) is the simple no-scroll layout he rejected; "Original table" / "Table with suppliers" (`:698-712`) switch `?layout=`; "Save Snapshot" / "Snapshots" (`:751-767`, `saveSnapshot()` `:1915`) freeze data, not columns; the Marketing / CEO toggle (`:619`) changes the role view.
- Nearest precedent for a set: `owner/_fit_to_width.blade.php:9-15, 72-75, 147-162`, the "☰ Actions" view of /owner/private: a browser-only button (localStorage `owActionsView`) that shows only some columns by CSS `data-col`, "without touching column settings". It is not included on /item (searched all views).

### 1.3 Families and derived columns (code)

- Prof.%: four ids (`index.blade.php:1596-1599`), cells identical except the field (`_agg_cells.blade.php:46-57`): one column with a period switch is possible.
- Prof.Profit (range, 1D, 3D, 7D; `:1590, 1600-1602`) and NP/O (1D, 3D, 7D, 1M; `:1592-1595`) are families too; he shows two Prof.Profit and one NP/O, so I built no switch for them (not adjacent in his order, different jobs).
- Item Val. and Item Val. (CEO): two different cogs (they differ on some rows live), so not merged.
- RTS / DEL / INT is already one merged column with `members`.
- HOLD at item level equals the HOLD pill in the item cell (`_agg_cells.blade.php:67-70`); the column is only needed for page rows.
- Derived: TCPR = 1 - proceed / orders (`_agg_cells.blade.php:23`); NP/O = profit / orders (`owner/private.blade.php:820-868`); Prof.% = profit / gross (`:873-878`).
- C is supported by the column model: `mergeRtsTrio` builds a synthetic column from several catalog ids with `members`, and `saveCols()` expands members back to real ids (`index.blade.php:1701`), so saved order and the Columns list are unaffected.

### 1.4 What /owner/private does about width (code; the live page was not opened)

- `owner/_fit_to_width.blade.php`, button "↔ Compact" (default on). Compact CSS: cell padding 2 px, body 11 px, headers 9.5 px wrapped to several lines, columns `width:1px` (shrink to content), Page and Item 115 px, Promo 100 px wrapping, action text clamped to 3 lines; Set RTS% "from / note", Item Val. "cogs / from / note" and the author/time line go into a hover box (`data-ow-tip`, `:42-45, 62-68, 261-300`). Then a CSS `zoom` factor (container width / natural width, re-measured by a ResizeObserver, `:94-206`) shrinks the whole card until it fits; the box is `overflow-x:hidden`. So it does **not** scroll sideways in compact mode; "↔ 100%" returns the scrolling layout. The inline expand panel still scrolls (`.expand-wrap{overflow-x:auto}`, `owner/private.blade.php:195`).
- Cost: the zoom shrinks 11 px text below 11 px (my estimate: factor about 0.9 at 1920 and about 0.6 at 1366, so 6 to 7 px text at 1366), against your 11 px floor. I took its two good ideas (note lines on hover; "☰ Actions" as a column subset) and rejected the zoom.

## 2. Direction chosen: A, then B, with C for Prof.% only

Result: 20 of his 26 columns on screen at 1920 and 14 at 1366 (with three suppliers), the rest behind "+6 columns" / "+12 columns".

- **A (every column as narrow as its content honestly needs): applied everywhere, not enough alone.** Item cell 284 -> 196 (HOLD pill under the name; the name wraps to two lines), ITEM 150 -> 108 / 96, supplier group 458 -> 228 / 216, two-line headers broken at "." or "/" (zero-width space, same trick as `hdr()` in `owner/private.blade.php:2487`; header letter-spacing .05em -> .02em so "PROF.PROFIT" fits at 11 px), cell padding 7x10 -> 5x6, RTS / DEL / INT 135 -> 112 (one line, three slots; 100 % written "100%"). Number formats: values up to ₱99,999.99 unchanged; from ₱100,000 no centavos; the TOTAL row shows ₱1M and up as "₱2.27M" (full value in the `title`); negatives as "−₱1,200.00" (the minus sign already used at `index.blade.php:3438`). Sub-lines under 11 px are raised to 11 px ("bilangin", "ngayon na", MOQ); the "cogs" sub-line under Item Val. goes to the tooltip (the page's own /owner/private compact does the same); the DOI "lead / palugit" link line goes to the row hover (the edit button stays, appears on `tr:hover` / `:focus-within`, like Change / Copy). Counted honestly with A only, in his full order, with three suppliers: 19 of his 26 columns fit at 1920 (through HOLD; ACTION and the whole stock block fall off, and that block is the sourcing work) and 12 at 1366 (through TCPR). The 26 columns need about 1,920 px even at these minimal widths, plus the 532 px identity and supplier block (2,450 px), against 1,873.
- **B (a default set, the rest one step away): chosen for what is left.** Default set "Sourcing" (the page opens on suppliers); a second set "Sales" one tap away on a segmented control above the table; the "+6 columns" / "+12 columns" button opens a panel listing each hidden column with the width it would take, a fit meter ("1,873 of 1,873 px used, screen is full"), a note that supplier columns follow Supply Finance with the "+ Add Supplier" link, the sets, the shown columns and the link to the existing "Column settings". Nothing disappears silently: the count is on the button, in his words. "Mine" in the panel is his own tick-list (optional).
- **C (a family as one column): Prof.% only (four columns -> one, 96 px at 1920, 94 at 1366, saves 204 / 206 px).** Header "PROF.%" with a segmented switch 1M | 7D | 3D | 1D (11 px, active `#60a5fa`, the same blue as `th.col-active`); every cell shows one value for the active period; sorting follows the switch. Without C the default could show only Prof.% 1M and the other three periods would be hidden.
- Why not zoom: breaks the 11 px floor. Why not a "simple" view: he rejected it; this keeps real columns, one value per cell, his headers and colours.

Default columns, by evidence
- His saved order: the profit block first (NP/O, ADSPENT, PROF.PROFIT(1D)), the stock block last.
- Values on item rows: the default keeps columns that have a value on item rows and sends the five that are empty there (PROMO, PRICE, SET RTS%, BREAKEVEN CPP, ACTION) one step away: hiding them costs the item rows nothing.
- The sourcing job: ITEM VAL. (CEO) (the cost to set against supplier prices), STOCK, PAPARATING, BENTA/ARAW, DOI, I-ORDER, LIFECYCLE, HOLD.
- The page's own colour rules: green/red on PROF.PROFIT, PROF.PROFIT(1D) and TOTAL (`pbStyle`, `index.blade.php:2445-2457`), the DOI colour, the brown HOLD: all stay.

## 3. Layout, exact widths (px)

Available width = viewport - 32 (the page's `#scroll` padding, 16 + 16) - 15 (vertical scrollbar) = **1,873 at 1920, 1,319 at 1366**. The table uses `table-layout:fixed` and a `<colgroup>`; the widths sum exactly to the available width. The live table is `table-layout:auto`, so the builder sets explicit widths (a `<col>` per column, or `width` / `max-width` on the `th` as `_suppliers_style.blade.php:16-24` already does).

| Column | Now | 1920 | 1366 | Default at 1920 | Default at 1366 |
|---|---|---|---|---|---|
| PAGE (item cell) | 284 | 196 | 168 | shown | shown |
| ITEM | 150 | 108 | 96 | shown | shown |
| 1 · Kelly | 118 | 76 | 72 | shown | shown |
| 2 · Albee | 118 | 76 | 72 | shown | shown |
| 3 · Helen | 118 | 76 | 72 | shown | shown |
| PO (old column) | 104 | gone (dot + hover) | gone | - | - |
| PROMO | 90 | 80 | 72 | one step away | one step away |
| PRICE | 85 | 62 | 58 | one step away | one step away |
| NP/O(1M) | 80 | 70 | 64 | shown | one step away |
| ADSPENT | 90 | 82 | 76 | shown | shown |
| ORDERS (1D) | 80 | 62 | 56 | shown | one step away |
| PROF.PROFIT(1D) | 105 | 84 | 78 | shown | shown |
| ITEM VAL. | 80 | 72 | 66 | one step away | one step away |
| ITEM VAL. (CEO) | 90 | 72 | 66 | shown | shown |
| CPP | 75 | 62 | 56 | shown | one step away |
| SET RTS% | 110 | 72 | 66 | one step away | one step away |
| RTS / DEL / INT | 135 | 112 | 104 | shown | one step away |
| TCPR | 65 | 56 | 52 | shown | one step away |
| BREAKEVEN CPP (5%) | 115 | 78 | 74 | one step away | one step away |
| PROF.PROFIT | 95 | 88 | 80 | shown | shown |
| PROF.% (1M / 7D / 3D / 1D as one) | 4 x 75 | 96 | 94 | shown | shown |
| HOLD | 60 | 52 | 48 | shown | one step away (the pill shows the same number) |
| ACTION | 160 | 120 | 96 | one step away | one step away |
| STOCK | 75 | 56 | 52 | shown | shown |
| PAPARATING | 90 | 80 | 76 | shown | shown |
| BENTA/ARAW | 90 | 62 | 58 | shown | shown |
| DOI | 110 | 131 (takes the spare 31) | 93 (takes the spare 19) | shown | shown |
| I-ORDER | 100 | 72 | 68 | shown | shown |
| LIFECYCLE | 120 | 104 | 98 | shown | shown |

Totals
- Before: 3,292 px minimum (about 3,500 live, scrolls).
- After: 1,873 (1920) and 1,319 (1366): the available width, no scroll.
- Default shown: at 1920, 17 columns beside PAGE, ITEM and the three suppliers (Prof.% counts as four, so 20 of his 26 saved columns); 6 one step away: PROMO, PRICE, ITEM VAL., SET RTS%, BREAKEVEN CPP (5%), ACTION ("+6 columns"). At 1366, 11 columns (14 of 26); 12 one step away: PROMO, PRICE, NP/O(1M), ORDERS (1D), ITEM VAL., CPP, SET RTS%, RTS / DEL / INT, TCPR, BREAKEVEN CPP (5%), HOLD, ACTION ("+12 columns"). Plus the 12 he already hid in settings.
- "Sales" set, computed and not rendered: at 1920 every column except the six of the stock block (STOCK, PAPARATING, BENTA/ARAW, DOI, I-ORDER, LIFECYCLE): 1,320 of 1,341 px; at 1366 NP/O, ADSPENT, ORDERS (1D), PROF.PROFIT(1D), ITEM VAL. (CEO), CPP, RTS / DEL / INT, TCPR, PROF.PROFIT, Prof.%, PRICE, HOLD: about 832 of 839 px. The expanded page rows keep showing whichever columns the active set has.

## 4. Look (unchanged meanings)

Same dark toolbar, header `#1e293b / #94a3b8` 11 px uppercase (letter-spacing .02em), chips `#4f46e5`, row tints `#eef2ff` / `#fff7ed`, profit cells `#00ff00` / `#ff0000`, HOLD pill, price blue, PO green. Red = missing or wrong, green = good (and PO cost, as today); no new meaning. Labels as they are, English and Taglish. New pieces use the page's indigo (`#4f46e5`, `#c7d2fe`, `#a5b4fc`): the "+N columns" button (dark like "⚙ Columns" with an indigo count), the segmented sets control, the fit meter, the Prof.% switch, the supplier-number headers.
Control position: on the sourcing-chips strip, right: "Columns: [Sourcing | Sales] [+6 columns ▾]". Open panel 372 px, right aligned; Esc closes; the button has `aria-expanded`.
Responsive: 1366 and wider = this layout. Below 1366 the table may scroll sideways as today, the item cell sticky left as built this morning. On a phone the page keeps its existing behaviour (scroll both ways); the panel becomes a bottom sheet; hover details (pencil, Change / Copy, DOI lead, PO line) show always (`@media (hover:none)`). Not drawn. At 125 % or 150 % Windows scaling a 1920 screen has a CSS viewport of 1536 or 1280: 1536 is between the two comps (about 1,455 px of room) and needs a third width class, not designed; 1280 uses the scrolling rule.
States: loading, error and empty rows unchanged (colspans as in section 0); expanded item: one empty cell spans the supplier columns; TOTAL row: first cell PAGE, then one cell spanning ITEM and the supplier columns.

## 5. Beat the incumbent (the table he has today: about 3,500 px, sideways scroll)

1. Sideways scroll: answered by 1,873 / 1,319 px, nothing off screen, hidden columns counted on a button and listed with widths.
2. A wall of red bands and supplier names repeated in every row: answered by named columns (name once, in the header, numbered like the Supply Finance list), quiet "+" cells and one small warning mark.
3. Sub-lines under 11 px, a 135 px RTS block, stacked cells, and five columns that are empty on every item row taking 500 px: answered by one value per cell, an 11 px floor, details on hover, and the empty ones one step away.

## 6. What changes in the code (for the builder)

- `initCols()` (`index.blade.php:1632-1679`): after the server `hidden` filter, filter by the active set (localStorage `item_col_set_v1`, default "Sourcing"; remember the last one per device); `hiddenCount` = catalog columns not in `cols` (not counting the ones hidden in settings or CEO-only).
- Prof.%: `mergeProfPct()` beside `mergeRtsTrio` (`:1685-1696`): a synthetic column with `members`, a period state (default 1M), sort key by period, cells from `_agg_cells.blade.php:46-57`.
- Supplier columns from `supplierList` (section 0), cells keyed by `supplier_id`. Helpers: `quotesFor()` (`:3210`), `supKey()` (`:3228`), `itemQuotes` (`:1471`), `quoteForm` (`:1451`), `openQuote()` (`:3853`), `deleteQuote()` (`:3884`), `saveQuote()` (`:3858`); every new button needs `@click.stop` (a row click toggles the pages). The edit form moves into the hover/edit card as this morning.
- Number formats: helpers beside `money()` / `md()` (`index.blade.php:2467-2468`), full value in `title`.
- CEO only: Non-CEO and Marketing views still get none of the supplier markup.
- Widths: `<colgroup>` or explicit `th` widths; `thead th` stays sticky (top 30).

## 7. Decisions that are the owner's (three), each with my recommendation and its cost

1. **How a supplier's last PO cost shows now that each supplier is a column** (no PO column). Recommend: a green dot on a quoted cell plus a line in the hover card ("Last PO ₱..., date, number"), and a green cost with a "PO" tag when there is a PO but no quote. Cost: the PO cost is one hover away when there is also a quote. Alternatives: hover card only (quietest, a PO-only supplier then looks empty and the row could wrongly look unsupplied), or a PO column back (68 px, which pushes RTS / DEL / INT and TCPR one step away at 1920).
2. **The numbering rule and the limit.** Recommend: the number is the position in id order (1st, 2nd, 3rd), not the raw id, so deleting a supplier leaves no gap; the headers renumber if one is deleted (cost: a supplier's "number" can change after a delete; the name beside it stays, and a supplier with quotes should not be deletable on the finance page). And accept that up to 6 suppliers the columns look as drawn, past that a new idea is needed (section 0, price-only cells first).
3. **What opens by default, and Prof.% as one column.** Recommend: "Sourcing" opens by default with "Sales" one tap away and the last set remembered, and Prof.% as one column with a 1M / 7D / 3D / 1D switch. Cost: on a 1366 laptop the first view lacks NP/O, ORDERS (1D), CPP, RTS / DEL / INT and TCPR (they show at 1920), and he sees one Prof.% period at a time.
Also for him to judge from the pictures (no extra decision): the quiet warning mark (`comp-1920.png`) against the red band (`comp-1920-band.png`).

## 8. What must not change

Toolbar and buttons, the five chips and their counts, the header look and drag-to-reorder, every sort, the TOTAL row, HOLD badge, expand arrow, photo click, add / edit / remove quote with link and photo, "walang running page" (red, bold, orange tint), Change, Copy, the CEO-only rule, English and Taglish labels, colours and their meanings, `/owner/private` and the column settings page (hiding there still works and is respected; the sets sit on top of it).

## 9. What I could not check

- Real pixel widths and the viewport the page reports: no script tool in this session; widths come from the code and the screenshot proportions; the live viewport (about 2,130 CSS px) is an inference.
- One screenshot of the right end of the live table timed out once (slow page); that end of the header row was read from the screenshot taken just before.
- The comps were rendered in headless Chrome with the machine's system font (Segoe UI); on another OS text widths differ by a few px. Headless Chrome draws no scrollbar, so the 15 px vertical scrollbar is accounted for in the widths but not drawn.
- Not rendered: expanded page rows, the hover and edit cards (supplier cell, PO line), the "Sales" set, the touch layout, a fourth supplier (the 4 to 10 supplier table above is computed from the widths, not drawn).
- The two supplier facts the office gave (id order, empty second column) come from its message, not from my own read of the database.
- The impeccable skill was not available (no `critique` / `audit`, no PRODUCT.md).
