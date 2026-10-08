# Design brief: SUPPLIERS column group on the item summary table

Format note: the `impeccable` skill was not available in this session, so this is the plain brief format (job, direction, sections, states, responsive, constraints, open decisions), not an impeccable `shape` brief. No `PRODUCT.md` was written (the project is an existing app; nothing to initialise).

Surface: `/item?layout=old` (CEO view), the original table. Comp: `comp-a.html` (after) and `comp-before.html` (before), same ten items. Screenshots: `comp-a.png`, `comp-before.png`, `comp-a-hover.png` (row 1 in its hover state, the first supplier card open).

Evidence limit: the live pages could not be opened. The browser tab was sent to the login page (the Chrome profile was not signed in) and I did not sign in. Everything below comes from the repo. The comp uses invented supplier names (Supplier A to Q), invented prices and numbers, placeholder photos, and loads nothing from likhaaitech.com.

## 1. Job and audience

The CEO uses this table daily to decide what to source and order. The ITEM column is where he reads "do I have a supplier, at what price, and what do I do next". Today it answers that with a stack of sentences in a 150 to 350 px cell; he cannot compare suppliers and sees about six items per screen. He asked for: Supplier 1 | Supplier 2 | Supplier 3 with prices beside them, and a compact table like owner/private.

## 2. Verdict on the current ITEM column (plain words)

1. It does six jobs in one cell (page status, PO history, quotes, warnings, three buttons), so rows are about 107 to 190 px tall (comp measurement: 107 for one quote, about 187 for five) and only about six items fit on a screen.
2. The same fact is said twice in two styles ("walang supplier" grey, "wala pang supplier" red) and two different sources (PO suppliers and quotes) share one format, so prices read as sentences and cannot be compared across suppliers.
3. Five to eight always-on small buttons per row (edit, remove, + supplier quote, Change, Copy) compete with the data the eye is looking for.

## 3. Facts from the repo (file:line, paths under `resources/views/item/` unless stated)

How the page is built
- `/item` route: `routes/web.php:1055` to `ItemController@index`. `layout=old` is the exact string at `app/Http/Controllers/ItemController.php:87`; `index.blade.php:830-831` includes `_table_old.blade.php`.
- Rows are Alpine templates, not Blade loops: one `<tbody>` per entry via `x-for` over `displayRows()` (`_table_old:83-84`). The item row is `_table_old:88-219`: column 1 (header "Page") = chevron, photo, name, HOLD badge (`:92-136`); column 2 (header "Item") = the stack (`:137-213`); then the `cols` loop (`:214-218`, cells in `_agg_cells.blade.php`). The same two columns hold the page name and item name on page rows (`:240-310`), so the "Item" header serves both.
- Header is a single row: `_table_old:5-47`; "Page" th `:8-15` (min 110), "Item" th `:17-24` (min 160); `thead th` is `position:sticky; top:0; z-index:30` (`index.blade.php:35-44`).

Supplier data (the real states for the comp)
- Quotes are stored in `item_supplier_quotes`, one row per item and supplier: `database/migrations/2026_09_27_100000_create_item_supplier_quotes_table.php:24-35` (unique on `item_key + supplier_id`, `:35`). There is no cap on the number of quotes per item; the controller upserts per supplier (`ItemController.php:470-488`).
- `price` and `moq` are both nullable (migration `:29-30`; validation `ItemController.php:451-452`). A quote with no price renders "—" (`_table_old:168`); no MOQ renders nothing (`:169`).
- All quotes are returned, ordered by price (`ItemController.php:702`), as `{id, supplier_id, supplier, price, moq, link, note, updated_at, prev_price, prev_date, photo_url}` (`:723-735`). Null-price ordering depends on the database (the code supports pgsql and mysql, `:56-57`), so the new view must sort nulls last itself.
- The supplier name comes from the `suppliers` table (`ItemController.php:726`); `_table_old:167` prints it with no truncation, so a long name wraps in a flex line.
- Client side: `loadItemQuotes()` `index.blade.php:3202-3209` fills `itemQuotes`; `quotesFor(name)` `:3210`; `supKey()` `:3194`.
- CEO only: the endpoint returns empty lists for other roles (`ItemController.php:426`), and the markup is wrapped in `@if($effectiveIsCEO)` (`_table_old:145` to `:203`). `$effectiveIsCEO = $isCEO && $viewAs === 'ceo'` (`ItemController.php:70`); in JS `effectiveIsCeo` (`index.blade.php:1394`).
- PO suppliers are a second source, `suppliersFor()` (`index.blade.php:3197-3200`), shown as "factory name + unit cost" joined by " · " (`_table_old:149-157`) with the PO date and number only in the tooltip (`:152`).
- Warnings today: grey italic "walang supplier" when there is no PO supplier (`_table_old:158-160`); red "wala pang supplier" only when there is no quote and no PO supplier (`:182-184`). With nothing at all, both show: that is the "twice". "walang running page" `:142-144`; "N running page(s)" `:138-141`.
- Quote controls: edit `openQuote()` (`index.blade.php:3853`), remove `deleteQuote()` (`:3884`), save `saveQuote()` (`:3858`). The add/edit form renders inline in the cell (`_table_old:185-199`), one open form at a time (`quoteForm`, `index.blade.php:1451`). Extras on a quote: photo thumbnail (`:173-176`), link (`:177`), "dati" previous price and date (`:170-171`), updated date and MOQ in the tooltip (`:167`).
- Change / Copy: `_table_old:204-212`; `copyItem()` `index.blade.php:3913`.
- Sourcing chips: `index.blade.php:787-808`, labels `:1464-1468` ("Need a supplier", "Has a quote, not ordered", "Ready to order", "Ordered, waiting", plus "All"). With a chip selected, extra lines appear under the item name (`_table_old:112-135`: "kabuuan", "Kulang N — i-order na", and a three-line naka-order block).
- Row click toggles the pages (`_table_old:90-91`); the existing quote block stops that with `@click.stop` (`:164`). Every new control must do the same.

Other things that stack today (they matter for row height)
- RTS / DEL / INT is a nested 3-row table: `_agg_cells.blade.php:72-107`; the merged column is built at `index.blade.php:1663-1674` (min 135, `members` toggles per line).
- Smaller stacked sub-lines: Item Val. "cogs" (`_agg_cells:129`), Stock "bilangin" (`:192`), DOI lead line (`:213-241`), I-order "order by" (`:253`). Each is a two-line cell, which fits a 47 px row; I left them alone.

Sticky
- The old table has no sticky first column. Only the header (top) and TOTAL (bottom) are sticky (`index.blade.php:35-36`, `:54-55`). A left-sticky item column exists only in the new layout (`index.blade.php:345`, `.il-table tbody td.il-item`).
- The old markup does allow one: the table sits in `#scroll` (`overflow:auto`, `index.blade.php:25`), and item rows have opaque backgrounds (`:62-65`). It needs an explicit column width, an opaque background on every row type (page rows, no-page tint, hover tint, TOTAL), and a higher z-index on the header cell (40, above the 30 of the sticky header). Done in the comp.

Hover precedent
- The only hover-reveal rule in the item view is `.cell-edit-icon` (opacity .5 to 1 on `tr:hover`, `index.blade.php:152-159`) plus native `title` tooltips (`_table_old:152, 167`). I could not look at the owner/private page itself, and a search of `owner/private.blade.php` found no other hover-only note pattern (it carries the same `.cell-edit-icon` rule at `:123-130`). The hover behaviour below follows the `.cell-edit-icon` precedent; check it against what he sees there. ASSUMED.

## 4. Selected direction

An indigo-and-slate table that is still his table: same dark toolbar, same chips, same dark header, same row tints, same colours and meanings. The change is that the stack leaves the ITEM cell and becomes a SUPPLIERS group that reads like a price comparison: name over price, three suppliers side by side, cheapest first, details on hover. The row drops to one 49 to 51 px line. Nothing is removed; every control moves to a place a hover, a focus or a tap reaches.

Changes from the office's starting recommendation, and why
1. Added a fourth narrow sub-column "PO" to the group. The page shows PO suppliers (factory + unit cost) separately from quotes (`_table_old:149-161`); dropping them would remove something he reads. Empty PO shows a faint dash, so the grey "walang supplier" line is no longer needed.
2. "wala pang supplier" is said once: one red merged cell across all four sub-columns (the existing `.null-warn` wash `#fef2f2` = "missing data", same meaning) with the existing "+ supplier quote" button inside it. When there is any quote or PO supplier, the cells show data and no warning appears (same rule as `_table_old:182`). The Need a supplier state reads as one red band across the group.
3. MOQ stays visible (small, dark slate `#475569`), not hover-only. Price times MOQ is the order decision; he can overrule. The date, "dati" price, link, photo thumbnail and the edit/remove controls go to the hover card.
4. Supplier 1 to 3 are cheapest first (the order the server already returns). The cheapest is marked by weight only: price 800 weight with a light-blue underline, shown only when two or more quotes have a price (ties are all marked). No new colour. This also means Supplier 1 is normally the cheapest, so the header tooltip should say "cheapest first".
5. More than three: the third cell gets a "+N" chip and a list card with all quotes (edit, remove, + supplier quote). The existing expand arrow cannot do this: it expands pages, not quotes (`toggleItemExpand`, `index.blade.php:3110`). It needs new Alpine state (one open list at a time).
6. The "N running page(s)" text stays in the Item column (not beside the name). Column 1 is the sticky anchor and must stay narrow; the Item column is still needed for the page rows' item name. The warning "walang running page" stays visible, bold red, plus the orange row tint.
7. The add form cannot stay inline in a 118 px cell. Keep the same `quoteForm` state and fields (`index.blade.php:1451`), and render the form in the same hover card, in edit mode, anchored to the clicked cell (select, price, MOQ, link, photo, Save, Cancel). No change to the save logic.

## 5. Layout, exact values (all reuse the page's own CSS; numbers are from `comp-a.html`)

Header: two rows. Row 1 (22 px, 10 px type): "PAGE" and "ITEM" and every numeric header with `rowspan=2`; "SUPPLIERS" `colspan=4`, label colour `#a5b4fc` (the toolbar's Columns-link colour), letter-spacing .12em, bottom border `1px #475569`. Row 2: Supplier 1, Supplier 2, Supplier 3, PO, `top:22px`, 10.5 px, left aligned. All th keep `background:#1e293b; color:#94a3b8` and the existing sort/drag handlers. The four new sub-headers are not sortable (open decision, see section 9).

Widths (px)
| Column | Before | After |
|---|---|---|
| PAGE (item cell: chevron, photo, name, HOLD) | about 250 in comp | 284, sticky left |
| ITEM | 150 to 350, content-driven (351 in the comp because of the long name) | 150 |
| Supplier 1, 2, 3 | none | 118 each (354) |
| PO | none | 104 |
| RTS / DEL / INT | 135 | 135 (same width, one line) |

Item cell (column 1): photo 30 px (was 34), name 12.5 px/800 (existing `.item-name`), HOLD pill 10.5 px (existing `.item-hold`), name max width 150 so a long name wraps to two or three lines (that row grows to about 60 px: the REFLECTIVE STICKER row). Row padding `7px 8px` (was `7px 10px`). Keep the 2 px indigo top border between items.

Supplier cell: line 1 supplier name 11.5 px / 700 `#0f172a`, ellipsis (full name in the card); line 2 price 12 px / 600 `#1d4ed8` (the existing quote-price blue), then "MOQ 1000" 10 px `#475569`. The old `#94a3b8` for MOQ fails contrast (about 2.6:1 on white), so it is darker. A quote with no price shows a grey "—" on line 2; no MOQ shows nothing. PO cell: name over unit cost 12 px / 700 `#065f46` (the existing PO-cost green); the PO date and number sit in the title and the card; more than one PO supplier: first one plus a "+N" chip.

Empty supplier cell: a 24 x 24 dashed-border button with "+" (`aria-label="+ supplier quote (Supplier N)"`, title "+ supplier quote"); hover turns it solid blue like the existing `.btn-set:hover`.

RTS / DEL / INT: one line, three equal slots `12.5% | 80.1% | 7.4%`, 11.5 px, tabular numbers, RTS 700 and DEL/INT 600 as today, `—` in `#cbd5e1` when empty. The labels live in the header ("RTS / DEL / INT", same order) and in each value's tooltip together with the count: "RTS 12.5% (120)". The three "RTS", "DEL", "INT" micro labels and the count column of the nested table go; the counts are now hover-only (same rule he chose for note lines). It must keep honouring `col.members` (render only the members he has checked).

Row height: before about 107 to 190 px (comp: 107, 123, 155, 187); after 49 to 51 px for every state except a three-line item name (60 px). On a 1080 px screen with browser chrome that is about 6 rows before and about 15 after (estimate from the comp).

Net width and first screen (estimates from the comp; the real column widths come from column settings and I could not open the live table)
- The group adds 458 px (3 x 118 + 104). The item identity block goes from about 600 px in the comp's worst case (about 480 in a typical row without a very long supplier name; estimate) to 434 px (284 + 150), so about 120 to 170 px is won back. Net: the Item + Supplier identity block grows from about 480 to 600 px to 892 px, a net of about +290 to +410 px.
- At 1366 px (content width about 1334): numeric columns visible before about 7 to 8 (Adspent to Prof.%), after about 5 (Adspent to /Order, as in the screenshot). At 1920 px (about 1888): about 3 fewer columns than before. The table was already about 3,400 px wide with the default column set (sum of `defaultCols` min widths, `index.blade.php:1558-1607`), so he scrolls sideways today as well; the sticky first column keeps the item in view while he does.
- Levers if he finds this too wide, each about 25 to 55 px: ITEM column 118 with the "walang running page" warning wrapping to two lines (-32), supplier cells 108 (-30), PO 96 (-8).
- The SUPPLIERS group is not sticky: it would pin about 600 px on a 1366 px screen. It scrolls away; the item name stays.

Colours and meanings (unchanged): header `#1e293b/#94a3b8`; item rows `#eef2ff` with `#c7d2fe` borders; no running page `#fff7ed` row tint and `#b91c1c` text; chips `#4f46e5` selected, white/`#cbd5e1` otherwise; HOLD pill `#ffedd5/#7c2d12`; profit cells keep the page's `#ff0000` / `#00ff00` backgrounds (`pbStyle`, `index.blade.php:2419-2423`). Red = missing or wrong, green = good/PO cost, blue = quote price; nothing new.

## 6. Interaction

- Hover card (mouse): resting a pointer on a supplier cell shows a 262 px card below it with the full supplier name, price and MOQ ("walang MOQ" when empty), "dati" price and date, updated date, link, photo thumbnail, and the edit (existing title "I-edit ang quote") and remove (existing title "Tanggalin ang quote") buttons. The card overlaps its cell so the pointer can move into it. The last three rows open the card upward so it does not hide under the sticky TOTAL row (class `up`); in the real table compute this from space available, or use fixed positioning.
- Keyboard: the supplier name is a `<button>` (`aria-haspopup`). Tab reaches it; `:focus-within` shows the card; Tab goes on to edit, remove, link inside the card; Esc closes. The "+" and "+N" are buttons with labels. Change and Copy sit in the Item cell at `opacity:0` but stay focusable and appear on `tr:focus-within`, so a keyboard user sees them when they arrive.
- Touch: `@media (hover:none)` shows Change and Copy permanently (the row still fits, about 49 px); tapping a supplier name toggles the card open (the `open` class in the comp; Alpine `x-data` in the build), and tapping elsewhere or Esc closes it. Focus rings are `2px solid #2563eb`.
- Change / Copy: row hover or focus reveals them under the "N running page" text (opacity .12 s). Same classes (`.item-photo-btn`, `.item-copy-btn`), same actions.
- Every new button needs `@click.stop`, otherwise a click toggles the row's pages (`_table_old:90-91`).
- Editing a price can change the order (cheapest first), so a quote may jump to another cell after saving. That is expected; say it in the build's tooltip on the "Supplier 1" header.

## 7. States (comp row in brackets)

| State | What shows |
|---|---|
| No quote, no PO (LIGHT SOCKET, CAR HOLDER) | one red merged cell "⚠ wala pang supplier" + "+ supplier quote" |
| One quote (USB MINI BULB) | name/price in Supplier 1, two "+" cells, PO dash |
| Three quotes + PO (GLOW TAPE, LED STRIP) | three filled cells, cheapest in heavy price, PO name/cost |
| More than three (AIR PUMP, five quotes) | three cells, "+2" chip on Supplier 3, list card |
| Quote without MOQ (GLOW TAPE Supplier C, NAIL CARE PEN Supplier B, AIR PUMP Supplier H) | price only |
| Quote without price (AIR FRESHENER Supplier K) | "—", sorted last, never marked cheapest |
| Long supplier name (AIR PUMP) | ellipsis in the cell, full name in the card |
| Tie for cheapest (NANO TAPE) | both prices heavy |
| PO only, no quote (REFLECTIVE STICKER, two PO suppliers) | three "+" cells, PO name + "+1" chip; no red band, same as today's rule |
| Quote(s) with a previous price (GLOW TAPE Supplier A) | "dati" in the card |
| No running page (NAIL CARE PEN, CAR HOLDER) | orange tint, red "⚠ walang running page" |
| Both missing (CAR HOLDER) | orange tint plus the red supplier band |
| Expanded item (USB MINI BULB) | page row below; the four supplier columns are one empty cell (colspan 4) |
| Loading / error | unchanged: the existing rows at `_table_old:51-74`; add the new colspan |
| Sourcing chip selected | extra lines under the name stay as they are (`_table_old:112-135`); the "naka-order" block makes that row about 62 px, acceptable only under that chip |

## 8. Scope, boundaries, what must not change

- CEO only. Non-CEO and the Marketing view must get none of it: wrap the group header cells, row cells, the repeated per-page header (`_table_old:230-238`) and TOTAL cell in `@if($effectiveIsCEO)`, and make every `colspan="cols.length + 2"` (`_table_old:52, 60, 66, 71, 756`) become `cols.length + {{ $effectiveIsCEO ? 6 : 2 }}`. That is the same pattern the supplier block uses today (`:145` to `:203`). Supplier names must never be in the Marketing HTML.
- Keep: every existing column and its sort, drag and settings; the toolbar; the five chips and their counts; the TOTAL row (its empty cell becomes `colspan` 5 for CEO); HOLD badge; the expand arrow; photo click; quote add, edit, remove, link, photo; "wala pang supplier"; "walang running page"; Change; Copy; the chip extra lines; the English/Taglish labels as they are.
- Do not touch the new (non-old) layout, or the page's data loading and saving code beyond moving where the quote form renders.
- No skip link anywhere.
- Page rows (expanded) keep their current look; they only gain the empty colspan cell and the sticky first column.
- Brand assets: none. Photos in the comp are gradient placeholders; supplier names are invented.

## 9. Open decisions for the owner (three; recommendation each)

1. PO suppliers: keep them in a narrow fourth column "PO" (as built), or fold them into the Supplier 1 to 3 cells, or show them on hover only. Recommend: keep the PO column. It is data he sees today, and folding it in mixes "asked for a price" with "actually ordered".
2. Width: accept the SUPPLIERS group as built (about +290 to +410 px at the front of the table, sticky item column), or use the narrower variant (ITEM 118, suppliers 108, PO 96, about -70 px). Recommend: accept as built; if he scrolls sideways a lot, take the narrower variant before anything else.
3. Order of Supplier 1 to 3: cheapest first (as built; editing a price can move a quote to another column) or fixed by date added (stays put, but the cheapest can sit in column 3). Recommend: cheapest first, because the comparison is what the group is for.

(Settled by me, he can overrule: MOQ stays visible; sub-columns are not sortable. A "sort by cheapest" would reuse `sb()` and is a later change.)

## 10. Beat the incumbent (the current ITEM column)

1. Height: 107 to 190 px per row, six items per screen. Answered by one 49 to 51 px row (about 15 per screen).
2. Said twice, mixed sources: answered by one red band, a separate PO column, quotes in columns.
3. Always-on controls: answered by hover/focus reveal with touch fallback, and a hover card that holds the detail.

## 11. Constraints for the builder

- Tailwind CDN, Alpine, Blade; the page styles are mostly its own CSS block (`index.blade.php:10` onward). Reuse them; the comp's added CSS is the block after the "item-suppliers change" comment in `comp-a.html`, and is meant to be pasted into `index.blade.php` next to them.
- Header z-index: sticky top 30, sticky-left corner 40, sticky-left body 6, TOTAL 20 and its first cell 25.
- Colour contrast: price blue `#1d4ed8` on `#eef2ff` is about 6:1; MOQ `#475569` about 6:1; the red `#b91c1c` on `#fef2f2` about 5.9:1. `#cbd5e1` is only used for empty dashes (decorative, as the page already does).
- Fixed widths on the supplier cells (`min-width = width = max-width`) so the ellipsis works inside a `white-space:nowrap` table (`index.blade.php:103-107`).

## 12. What I could not check

- The live item page and the owner/private page (not signed in): real column widths, real data, the owner/private hover pattern, real rows per screen. All px figures for the real table are estimates from CSS and the comp; the comp shows 14 of the roughly 33 columns.
- The comp's Tailwind CDN link was not needed to render (the page's own CSS carries the look), so I could not compare the effect of the CDN's reset on live row heights beyond including its main line-height rule.
- Touch behaviour on a real touch screen and screen-reader announcements (comp only; Esc and focus behaviour were written, not user-tested).
