# Spec 020: The item table fits the screen, one column per supplier

**Project:** Likha, the business operations app (orders, ads reports, items and sourcing, J&T shipments).
**Stack:** Laravel 12, PHP 8.4 (Laravel Herd locally), Blade + Alpine.js and Tailwind from a CDN; tests are PHPUnit on in-memory sqlite; there is no JavaScript test runner (this spec adds a small one through `node`, see Tests).
**Shape:** change · **Weight:** architectural (the column model of one view, a new pure script file, one additive endpoint field) · **Risk tier:** high (the same gate decides whether supplier names and buying prices are rendered; the column setting is shared with another page)

> Committed with the work as `docs/specs/020-item-table-fits.md`. Names no people and no decision
> ids: "the owner" decided, "the reviewer" checks and merges.

---

## Why

The owner's item table for the CEO view (`/item?...&layout=old`, "Table with suppliers", built by
specs 017 and 019) is about 3,500 px wide and scrolls sideways, and its SUPPLIERS group shows "the
three cheapest quotes" with the supplier's name repeated in every row and a red band where there is
none. He looked at it and decided two things. First, the whole table must fit the screen with no
sideways scroll; his everyday screen is a laptop. Second, the supplier columns are his suppliers,
not a ranking: one column per supplier he keeps in Finance → Supply, numbered like that list
(supplier 1 is the first one he added), each cell only that supplier's price with a plus when empty
and an edit; a supplier he adds on the Supply Finance page becomes the next column. He approved the
drawn design in `design/item-no-scroll/` (the brief and the pictures at 1366 and 1920 px).

## Design contract

`design/item-no-scroll/brief.md` with `comp-1366.png`, `comp-1920.png` and `comp-1920-columns.png`
(the html files beside them are the exact markup and styles of the pictures; every price, item and
number in them is invented). Build to the brief's look (sections 0, 2, 3, 4, 6, 8). Where this spec
and the brief differ, the spec wins; the known differences:

- the brief's two fixed sizes (1920 and 1366) are replaced by one fit computed from the real width
  of the table's box (decision "The fit");
- the panel's third set "Mine" is not built;
- the brief's table for 4 to 10 suppliers at 1920 was computed with the wider 1920 widths; with the
  single set of minimum widths of this spec more columns stay at 1920, which is intended.

## Stories

Add a slice at the end of `qa/stories.md`, heading `## Slice 020 – The item table fits the screen`,
stories S-34 to S-42 (the ids S-22 to S-33 are in use on another branch; do not reuse them). "The
suppliers table" is the CEO view's render for `layout=old` or `layout=suppliers`. "The base" is
develop at 1c32cc1. Test tags: **unit-js** = a pure function run through `node`; **feature** = the
rendered HTML or an endpoint in PHPUnit; **pin** = a text pin on a view file; **browser** = the
reviewer checks it in a browser after release (list it for the reviewer, do not claim it);
**owner check** = listed under `## Owner checks`.

### Part 1: the supplier columns

**S-34 One column per supplier of the Finance list (P1)**
As the CEO, I want a column for each supplier I keep in Finance → Supply, so that the supplier
number I know is the number on the table.
Independent test: with three suppliers the table has three supplier columns headed "1 · Kelly",
"2 · Albee", "3 · Helen"; add a fourth on the Finance page and reload: a fourth column appears.

| Case | Given / When / Then | Test |
|---|---|---|
| S-34.1 | Given three suppliers created as ids 4, 9, 12, when the table draws, then the group header spans three columns headed "1 · <first word of the name>", "2 · …", "3 · …", each with its full name in the title. Header and cells loop over the list: no "Supplier 1/2/3" words, no "Others", no "PO" sub-header, no fixed 3 or 4 | unit-js + pin on the loop |
| S-34.2 | Given a fourth supplier added through the Supply Finance page's own store route, when the item page's quotes answer is requested, then it lists four suppliers with their ids, and the columns function gives a fourth column "4 · Name": no code change needed | feature + unit-js |
| S-34.3 | Given ids 7 "Zed", 3 "Amy", 12 "Kim", sent as numbers in one run and as strings in the next, when the columns are built, then the order is 3, 7, 12 and the numbers are the positions 1, 2, 3 (never the raw ids, never alphabetical). The quote form's own list stays alphabetical | unit-js |
| S-34.4 | Given a one-word name, a name with leading spaces, an empty name, "Ñandú Trading 金龙", two suppliers with the same first word, and `<img src=x onerror=alert(1)>`, when the headers are built, then the header uses the first word (the number alone for an empty name), the title holds the full name, equal first words get different numbers, and every value is bound as text. A 120-letter first word is cut inside the header and does not widen the column | unit-js, pin (text binding), browser (the cut) |
| S-34.5 | Given no supplier in the list, or the list not yet answered, when the table draws, then there is no zero-width group and no error: one narrow column reads "Add a supplier in Finance → Supply" (a link to the Supply Finance page) when the answered list is empty, the neutral placeholder of slice 017 while it has not answered, and no "wala pang supplier" mark before the answer | unit-js + feature (a colspan is never 0) |
| S-34.6 | Given a quote whose supplier id is not in the list (the table has no foreign key), when the row draws, then there is no error and no new column, and the item is not marked "wala pang supplier" (it has a quote, as the "Need a supplier" chip counts it) | unit-js |

**S-35 A supplier cell shows only a price, a plus and an edit (P1)**
As the CEO, I want each cell to be just that supplier's price with a plus or an edit, so that I can
compare at a glance.
Independent test: an item has a quote from the first supplier only; that cell shows the price and
MOQ and the other cells a quiet "+".

| Case | Given / When / Then | Test |
|---|---|---|
| S-35.1 | Given supplier 1 quoted ₱18.50 with MOQ 1000 for an item, when the row draws, then that cell shows "₱18.50" and "MOQ 1000", every other supplier's cell shows only a quiet "+", and no supplier name appears inside any cell | unit-js + pin |
| S-35.2 | Given an empty cell in supplier 2's column, when its "+" is used, then the add form opens with supplier 2 already chosen; after saving price 40 and MOQ 300 the cell shows "₱40.00" and "MOQ 300" | unit-js (the form's preset), the existing quote save tests, browser |
| S-35.3 | Given a filled cell, when it is hovered, focused with the keyboard or tapped, then an edit control appears; in the edit card price, MOQ, link and photo can be changed and the quote removed (with the existing confirmation); the cell updates without a reload | pin on the edit and remove calls, browser |
| S-35.4 | Given the form opened from a cell, when it renders, then the supplier is fixed (shown as text, no dropdown), because the save is an update-or-create on item plus supplier and a changeable dropdown could overwrite another supplier's quote | pin (no select in the cell form) + unit-js |
| S-35.5 | Given a quote with no price and MOQ 500, one priced 0, and one with no MOQ, when the row draws, then the first shows a dash and "MOQ 500", the second "₱0.00", the third the price alone, and none is marked cheapest | unit-js |
| S-35.6 | Given quotes 15, 15 and 22, then both 15s are marked cheapest; given one price only, or prices of 0 and none, then nothing is marked; given supplier 1 quoted 20 and supplier 2 has only a PO cost of 15, then nothing is marked (a PO cost never counts). The cell reads the server's `cheapest` flag and does not compare prices itself | unit-js, the existing S-14.5 and S-14.6 tests |

**S-36 Last PO cost: a green dot, or a green cost with "PO" (P1)**
As the CEO, I want to see which supplier I last bought from without a PO column, so that I keep
that fact in the new layout.
Independent test: a supplier has a quote and a PO for an item; the cell shows the quote price, a
green dot, and the PO line in its hover card.

| Case | Given / When / Then | Test |
|---|---|---|
| S-36.1 | Given a supplier with a quote and a PO for the item, when the row draws, then the quote is the only price in the cell, a small green dot sits at its top left, and the hover or tap card has a line "Last PO ₱17.80, date, PO number". The dot has a title and an aria-label, so it is not colour only | unit-js, pin, browser |
| S-36.2 | Given a supplier with a PO and no quote, when the row draws, then the cell shows the PO unit cost in the PO green with a "PO" tag, it is never marked cheapest, and a quiet "+" also sits in the cell (always visible on touch) so a quote can still be added | unit-js, browser |
| S-36.3 | Given a supplier with no PO for the item (or only a discount or zero-cost line), when the row draws, then there is no dot and no tag | unit-js + feature on the PO-suppliers endpoint |
| S-36.4 | Given two suppliers with the same name, only one of which has a PO, when the page matches PO to column, then the match is by supplier id: the PO-suppliers endpoint's rows carry `supplier_id` as a new field, and its existing keys and values are unchanged for every other reader | feature (new field + the existing assertions) + unit-js |
| S-36.5 | Given several POs from one supplier for the item, when the cell draws, then it shows the latest by order date (then line id): its date and PO number in the card | feature |

**S-37 An item with no supplier at all gets one small warning mark (P1)**
As the CEO, I want a quiet mark by an item that has no supplier, so that the table is not a wall of
red.
Independent test: an item with no quote and no PO shows a small mark by its name with the title
"wala pang supplier", and no full-width band.

| Case | Given / When / Then | Test |
|---|---|---|
| S-37.1 | Given an item with no quote and no PO from any supplier, when the lists have loaded, then one small warning mark sits by the item name with title and aria-label "wala pang supplier", the supplier cells show their "+", and there is no red band across the cells. "walang running page" still shows in red in the item column | unit-js (the rule), pin (no band markup), browser |
| S-37.2 | Given an item with a quote only, or a PO only, or a quote without a price, when the row draws, then there is no mark | unit-js |
| S-37.3 | Given the quote or PO list has not answered or a fetch failed, when the row draws, then there is no mark; the neutral placeholder rule of S-15.9 and S-15.10 still holds | pin on the loaded state |
| S-37.4 | Given the "Need a supplier" chip, when its count and list are compared with the marks on the same data, then the chip keeps its meaning and count, and the marked items are the items in that list | feature (the existing worklist test) + unit-js rule |
| S-37.5 | Given a marked item, when its first quote is saved, then the mark disappears without a reload and the chip count goes down by one | browser |

### Part 2: the fit

**S-38 The table fits the screen: no sideways scroll at laptop width (P1)**
As the CEO, I want the whole table to fit my laptop screen, so that I never scroll sideways to read
a row.
Independent test: open the suppliers table at 1366 px; the table's scroll box is not wider than its
visible width, and a "+12 columns" button shows how many columns are one step away.

| Case | Given / When / Then | Test |
|---|---|---|
| S-38.1 | Given three suppliers and the Sourcing set, when the fit runs for the rows F1 to F4 of the fit table, then 11, 12, 14 and 17 columns are shown and the spare width is never negative | unit-js F1–F4 |
| S-38.2 | Given the Sourcing set at 1366 and 4, 6 and 8 suppliers, then F5 to F7 hold: columns leave one by one in the fixed order, and I-ORDER, DOI, ITEM VAL. (CEO), PROF.PROFIT and PROF.% are the last to go | unit-js F5–F7 |
| S-38.3 | Given the box is exactly as wide as the shown columns need (F8), then they fit; one pixel narrower, one more column leaves | unit-js F7 vs F8 |
| S-38.4 | Given a column hidden in the server settings (ADSPENT in F9), then it shows in no set and is not counted in "+N". Given the Sales set (F10, F11), then the six stock columns are excluded and the rest follow the same drop order | unit-js F9–F11 |
| S-38.5 | Given a viewport of 1279 px, then all the set's columns show and the table scrolls sideways as today, the item column sticky (F12); given 1280 px, the fit runs and nothing scrolls (F13); on a 390 × 844 phone the page scrolls both ways as today | unit-js F12–F13, browser at 390 |
| S-38.6 | Given 17 suppliers at 1366 (F14), or none (F15), then the identity and supplier columns are never dropped: with 17 the other columns go and the table scrolls, with none the fit still works. The same input gives the same output whatever order the set and drop lists are written in | unit-js F14–F15 |
| S-38.7 | Given the real page at 1366, 1440, 1536 and 1920 px with real data, when it has loaded, then the scroll box's `scrollWidth` equals its `clientWidth` and no cell has content wider than the cell | browser |
| S-38.8 | Given the window is resized from 1920 to 1366 and back with no reload, then the shown columns and the "+N" count update each time; before the lists answer the table does not jump more than once when the supplier count arrives | browser |
| S-38.9 | Given the fitted table, when the expanded page rows, the TOTAL row, the loading row and the empty rows draw, then they span exactly the shown columns plus the identity columns plus the supplier columns, and every row has the same number of cells | feature or pin (colspans follow the shown count) + browser |
| S-38.10 | Given amounts 99,999.99, 100,000, 2,270,000 and −1,200, when this table formats them, then the first stays as is, the second has no centavos, the TOTAL row shows "₱2.27M" with the full value in its title, and the last reads "−₱1,200.00". Only this table is affected: the shared `money()` output of the other views is not. No sub-line is under 11 px | unit-js, pin on the styles, the existing byte pins |
| S-38.11 | owner check: on his own laptop the whole table fits with no sideways scroll and reads well | owner check |

**S-39 "+N columns" shows what moved away and lets me bring one back (P2)**
As the CEO, I want to see which columns are one step away and bring one back, so that nothing
disappears silently.
Independent test: at 1366 the button reads "+12 columns"; its panel lists those 12 columns.

| Case | Given / When / Then | Test |
|---|---|---|
| S-39.1 | Given F1, when the page draws, then the button reads "+12 columns" (the columns away by the fit plus the ones the set excludes; server-hidden and CEO-forced-hidden columns are never counted). Its panel lists each away column with the width it needs, the shown columns, how many pixels are used of how many, a link to the existing column settings page, and a note that supplier columns follow the Finance list with a "+ Add Supplier" link to the Supply Finance page. The button has `aria-expanded`; Esc closes the panel | unit-js (count), pin, browser |
| S-39.2 | Given a column whose minimum width is not more than the spare width, when it is turned on, then it shows in its place and the "+N" count goes down | unit-js |
| S-39.3 | Given a column that needs 56 px with 19 px free, when it is turned on, then it is refused with the reason "needs 56 px, 19 px free"; nothing else moves | unit-js |
| S-39.4 | Given a shown column turned off in the panel, when the refused one is tried again, then it is accepted (the freed width counts) | unit-js |
| S-39.5 | Given a column turned on or off in the panel, when the page is reloaded, then it is back to the set's own fit (not remembered). No request is sent and the server setting is untouched | pin + browser |

**S-40 Two column sets, on top of the server settings (P1)**
As the CEO, I want a Sourcing set and a Sales set I switch with one tap, so that each job shows its
own columns.
Independent test: a fresh browser opens on "Sourcing"; tap "Sales", reload: still "Sales".

| Case | Given / When / Then | Test |
|---|---|---|
| S-40.1 | Given a fresh browser, then the page opens on "Sourcing" with "Sales" one tap away; after a tap and a reload the last set is remembered in this browser | unit-js (read and write of the stored value) + browser |
| S-40.2 | Given the stored set value is junk ("Mine", an empty string, markup), then the page opens on "Sourcing" with no error | unit-js |
| S-40.3 | Given a column hidden on the column settings page, then it stays hidden in both sets, and nothing in switching or fitting writes the server setting (the stored setting is unchanged after load and switch) | feature + pin (the switch code does not call the save) |
| S-40.4 | Given the saved column order, then the sets decide only which columns are on offer, and they show in the saved order | unit-js |
| S-40.5 | Given a header is dragged while only some columns are shown, when the order is saved, then every catalog id is still in it: the away and hidden ones keep their places, and the four Prof.% ids and the three RTS ids are intact. The shared order is not scrambled for the other page that reads it | unit-js, pin on the save call |

**S-41 Prof.% is one column with a period switch (P2)**
As the CEO, I want one Prof.% column with a 1M, 7D, 3D, 1D switch, so that four columns of width
become one.
Independent test: the header shows "PROF.%" with four period buttons; the cells show the 1M value
until another is tapped.

| Case | Given / When / Then | Test |
|---|---|---|
| S-41.1 | Given the page opens, then one "PROF.%" column shows with 1M active, and item rows and the TOTAL row show that period's value ("—" for a period with no data, negatives with the page's own colour rules) | unit-js + pin |
| S-41.2 | Given 7D is tapped, then every cell shows the 7D value and sorting by this column sorts by 7D (the four existing fields). A tap on a period does not trigger the header's own sort and sends no request | unit-js, pin (the click stops), browser |
| S-41.3 | Given the saved order and the settings page, then they still see the four ids and saving writes all four. If the server setting hides some periods, the switch offers only the visible ones; if all four are hidden the column is gone | unit-js + feature (the settings page unchanged) |

**S-42 Nothing outside the CEO's table with suppliers changes (P1)**
As the owner, I want every other view to stay exactly as it is, so that this change cannot hurt
Marketing or the shared pages.
Independent test: the existing byte pins of the original table, the default layout and every
non-CEO render pass without being edited.

| Case | Given / When / Then | Test |
|---|---|---|
| S-42.1 | Given the original table (`layout=original`) and the file `_table_old.blade.php`, then the existing pins pass unchanged | the existing tests |
| S-42.2 | Given the CEO default layout and every Marketing, Marketing-OIC and CEO-as-marketing render, then the existing hash pins pass unchanged (so the new script and styles sit behind the suppliers gate) | the existing tests |
| S-42.3 | Given those non-CEO viewers on all three layout words, then no new marker appears (the "+N columns" button, "Sourcing", "Sales", the pure function names, the new class names, "wala pang supplier", a seeded supplier name) and the page does not call the quote loaders | feature: the existing marker tests, extended |
| S-42.4 | Given `/owner/private` and `/owner/column-settings`, then each renders byte for byte as at the base: capture the base hashes in the test-only commit before changing anything | feature (new pins) |
| S-42.5 | Given the suppliers table, then the quotes and PO-suppliers routes are each fetched once at load as before, and the panel, the sets and the period switch send no request | pin: S-21.1 extended |
| S-42.6 | Given the promises of slices 017 and 019 that this spec does not replace, then each still holds: add, edit and remove a quote with link and photo, the five chips and counts, sorting, the TOTAL row, expand-all and page rows, Change and Copy, "walang running page", the date range kept by the two layout links, the Marketing refusals | the existing tests + browser |

### Cases of slices 017 and 019 this spec changes

Update them in `qa/stories.md` and in their tests, each listed under "Story changes" with the old
and the new Then. If reading the file shows this list is wrong for a case, follow the file and say
so.

| Existing id | What happens | Reason |
|---|---|---|
| S-13.1, S-13.2 | Changed | Headers are "N · first word" per supplier of the list; one cell per supplier; no PO sub-header or PO cell |
| S-13.4, S-15.8 | Changed | The added column count is the list's length; TOTAL, loading, empty and expanded rows follow the shown columns |
| S-13.6, S-19.4 | Changed | The columns loop is the fitted set |
| S-13.7, S-17.7, S-19.8 | Retired | Owner checks folded into S-38.11 |
| S-14 title | Changed | No longer "the three cheapest quotes": the server order and the `cheapest` flag (S-14.1 to S-14.7 and S-14.9 stay; other views read them) |
| S-14.8, S-14.10, S-14.11 | Retired | The first-three and "rest" helpers, the "+N" list and the PO column are gone (a cell holds at most one quote: the table is unique on item plus supplier) |
| S-15.1, S-15.7 | Retired and replaced | The red band is replaced by the warning mark (S-37) |
| S-15.2 to S-15.6 | Changed | Cells follow the supplier's identity, not price rank; no name line in the cell; the dash for no price stays |
| S-16.7 | Changed | "The right cell" is that supplier's own column; the "+" opens the form with the supplier fixed |
| S-17.3 | Changed | Row height about 50 px; names that wrap to three lines about 58 to 60 px |
| S-17.5, S-17.6 | Changed | At 1280 px and wider nothing scrolls sideways; the sticky item column and its opaque strip apply below 1280; the pinned text follows |
| S-18.1, S-24.1 | Changed | The "must not appear" marker list gains the new names |
| S-19.7 | Changed | Dragging works within the shown set; Prof.% is one column |
| S-20.1 | Changed | The supplier name is also bound as text in the header, its title and aria-label |
| S-22.1 | Retired and replaced | The whole-render hash of the suppliers table for the CEO must fail by design; it is replaced by the markers of S-34 to S-41. Do not "update the hash" |

Every other case of slices 017 and 019 stays and must stay green, in particular the pins of the
original table, the default layout and every non-CEO render (the proof of S-42).

## The pure functions and the fit table

The logic that can be wrong without anyone seeing it lives in one plain script file with no Blade
tags, loaded only inside the suppliers gate, written so that `node` can load it (no DOM, no Alpine,
no globals read). Propose its place and how it is loaded in the plan (a static file needs a version
in its address so a release is not served stale). One PHPUnit class runs the tables through `node`
and must say "skipped: node not found" visibly when node is missing; a skipped table is "not run",
never "passed".

| Function | Inputs | Outputs |
|---|---|---|
| the fit | the measured width of the scroll box; the window width; the identity minimum (PAGE + ITEM); the supplier count; the supplier column minimum; the active set's columns in display order (after the server-hidden and CEO-forced-hidden ones are removed and Prof.% is merged to one), each with id and minimum width; the drop order (the first leaves first) | mode ("fit" or "scroll"); the shown ids in display order; the ids moved away, in the order they left; used; spare; whether it scrolls |
| turn on | the same input, the result, an id | accepted only when the id's minimum width is at most the spare; otherwise refused with a reason naming both numbers; nothing else moves |
| the supplier columns | rows of id and name (ids as number or string) | the rows sorted by numeric id, each with position, id, short name, full name and header "N · short" |
| the supplier cell | one item's quotes and PO rows, a supplier id | kind (quote, po or empty), price, MOQ, cheapest, the PO dot and its line |
| the order to save | the saved full order and the shown order after a drag | the full order to save, every catalog id kept |
| Prof.% | an aggregate row and a period | the value and the sort key |
| the money format | a number, and whether it is the TOTAL row | the text of S-38.10 and the full value for the title |

The fit rule: start from every column of the active set; while the sum of their minimum widths
exceeds the box width minus the identity minimum minus (supplier count × supplier minimum), remove
the column that comes first in the drop order. Equal width fits. Identity and supplier columns are
never removed. A window narrower than 1280 px gives scroll mode: every set column shown, nothing
moved away. If identity plus suppliers alone exceed the box, nothing else is shown and it scrolls.
Spare width is handed out by the layout, not the fit (first the identity and supplier columns up to
the brief's 1920 widths, the rest to DOI, or the plainest rule that gives the pictures at 1366 and
1920; say which you chose).

Minimum widths (px): identity 264 (PAGE 168 + ITEM 96); a supplier column 72; PROMO 72, PRICE 58,
NP/O(1M) 64, ADSPENT 76, ORDERS (1D) 56, PROF.PROFIT(1D) 78, ITEM VAL. 66, ITEM VAL. (CEO) 66,
CPP 56, SET RTS% 66, RTS / DEL / INT 104, TCPR 52, BREAKEVEN CPP (5%) 74, PROF.PROFIT 80, PROF.%
94, HOLD 48, ACTION 96, STOCK 52, PAPARATING 76, BENTA/ARAW 58, DOI 74, I-ORDER 68, LIFECYCLE 98.

Drop order (the first leaves first): PROMO, SET RTS%, BREAKEVEN CPP (5%), ACTION, ITEM VAL., PRICE,
RTS / DEL / INT, TCPR, HOLD, CPP, ORDERS (1D), NP/O(1M), ADSPENT, PROF.PROFIT(1D), BENTA/ARAW,
PAPARATING, STOCK, LIFECYCLE, I-ORDER, DOI, ITEM VAL. (CEO), PROF.PROFIT, PROF.%. The catalog has
more columns than these (the ones the owner hides in the settings today, and the forced-hidden text
columns): every catalog id must have a minimum width and a place in the drop order, and a test
asserts that none is missing. Put the ones not named here before PROMO, in catalog order, with a
minimum width that honestly holds their content at 11 px; list them in the result.

Sets: "Sourcing" is every column except PROMO, PRICE, SET RTS%, BREAKEVEN CPP (5%), ACTION and
ITEM VAL. (17 columns with the owner's settings of today, 1,200 px). "Sales" is every column except
STOCK, PAPARATING, BENTA/ARAW, DOI, I-ORDER and LIFECYCLE.

Fit table (Sourcing unless said; the box is the measured width, given here as viewport − 47 only to
name the rows). Expected values come from this table, never recomputed the way the code does it. If
a row cannot be reproduced from the rule, the widths and the drop order above, do not adjust it
silently: say which row and why in the result.

| Row | Viewport (box) | Suppliers | Shown | Moved away by the fit, in order | Spare | "+N" |
|---|---|---|---|---|---|---|
| F1 | 1366 (1319) | 3 | 11 | RTS/DEL/INT, TCPR, HOLD, CPP, ORDERS (1D), NP/O(1M) | 19 | 12 |
| F2 | 1440 (1393) | 3 | 12 | RTS/DEL/INT, TCPR, HOLD, CPP, ORDERS (1D) | 29 | 11 |
| F3 | 1536 (1489) | 3 | 14 | RTS/DEL/INT, TCPR, HOLD | 13 | 9 |
| F4 | 1920 (1873) | 3 | 17 | none | 193 | 6 |
| F5 | 1366 | 4 | 10 | F1's plus ADSPENT | 23 | 13 |
| F6 | 1366 | 6 | 8 | through BENTA/ARAW | 15 | 15 |
| F7 | 1366 | 8 | 5 (I-ORDER, DOI, ITEM VAL. (CEO), PROF.PROFIT, PROF.%) | through STOCK and LIFECYCLE | 97 | 18 |
| F8 | 1367 (1320) | 8 | 6 (F7's plus LIFECYCLE: 480 fits exactly) | through STOCK | 0 | 17 |
| F9 | 1366, ADSPENT hidden by the server | 3 | 11 (F1's with NP/O(1M) kept) | RTS/DEL/INT, TCPR, HOLD, CPP, ORDERS (1D) | 31 | 11 |
| F10 | 1366, Sales | 3 | 12 (NP/O, ADSPENT, ORDERS, PROF.PROFIT(1D), ITEM VAL. (CEO), CPP, RTS/DEL/INT, TCPR, PROF.PROFIT, PROF.%, PRICE, HOLD) | PROMO, SET RTS%, BREAKEVEN CPP, ACTION, ITEM VAL. | 7 | 11 |
| F11 | 1920, Sales | 3 | 17 | none | 187 | 6 |
| F12 | 1279 (1232) | 3 | 17 | none (scroll mode) | n/a | 6 |
| F13 | 1280 (1233) | 3 | 10 | F1's plus ADSPENT | 9 | 13 |
| F14 | 1366 | 17 | 0, scrolls | all | n/a | all |
| F15 | 1366 | 0 | 15 | RTS/DEL/INT, TCPR | 11 | 8 |

**Tests:** one failing test per case first, named with its case id; a table row is one data row of
its case's test. Cases that pin unchanged behaviour are characterisation tests: report their green
run and what would turn them red. `browser` cases are not yours to pass: list each under "What the
reviewer must look at in a browser" in the result, with the address and what to look for.

## Constraints

**Decisions already made** (settled with the owner or by the reviewer; don't reopen them unless
something is actually broken):

| Topic | Decision |
|---|---|
| Supplier columns | One per row of the `suppliers` table, numbered by position in id order, header "N · first word of the name", full name in the title. No "Others" column, no PO column, no supplier picker in the cell. A supplier added on the Supply Finance page is the next column with no code change. Decided by the owner |
| A cell | Price and MOQ of that supplier's quote; a quiet plus when empty (opens the add form with the supplier fixed); edit on hover, focus or tap, remove inside the edit card; a dash for a quote without a price; cheapest marked from the server's flag. Decided by the owner ("plus and edit only") |
| Last PO cost | The green dot with the line in the hover card; the green cost with a "PO" tag when there is no quote, with a quiet plus beside it. Decided by the owner (the dot) and the reviewer (the plus) |
| No supplier | One small warning mark by the item's name, no red band. The chip "Need a supplier" keeps its rule and count. Decided by the owner (he approved the picture) |
| The fit | Computed from the measured width of the table's box with one set of minimum widths and one drop order (above), from 1280 px of window width up; below that the table scrolls as today. The reviewer's decision: his laptop's real width is not known (1366, 1440 and 1536 are all common), so two fixed sizes would miss it |
| Sets | "Sourcing" (default) and "Sales", remembered per browser; they choose which columns are on offer, the order is the saved order; they never write the server setting. No "Mine". The owner approved the picture; the rest is the reviewer's decision |
| Panel | A column turned on or off in the panel lasts for the page visit and is not remembered; turning one on is refused when it does not fit. The reviewer's decision: a remembered choice needs a rule for when it stops fitting |
| Prof.% | One column with a 1M / 7D / 3D / 1D switch, 1M first; the saved order and the settings page still see four ids. Approved by the owner |
| Number formats | The brief's formats, for this table only, through their own helper; the shared `money()` is not changed. The reviewer's decision |
| The shared setting | The column setting row is shared with `/owner/private`. Nothing in this spec changes what is stored there except a drag in this table, and that save must keep every catalog id in place (S-40.5) |
| Scope of the change | Only the suppliers table for the CEO view. `_table_old.blade.php` is not edited. Every edit to the shared `index.blade.php` script or styles sits behind the existing suppliers gate or in the suppliers partials, so the other renders stay byte for byte |
| The endpoint | The PO-suppliers endpoint gains `supplier_id` per row, nothing else changes; say in the plan who can call it today and keep that |

**Threat model.** Untrusted: every request parameter including `layout` and `view_as`; supplier
names, links and notes (always bound as text); what the browser stored (the set name). Trusted:
files in this repository, config, the column setting written by the settings page. Risk tier high:
the gate decides whether supplier names and buying prices are rendered, and a wrong save scrambles a
setting another page reads. A major needs a one-line realistic scenario. Fix loops stop after two;
a remaining security major goes to the reviewer.

**Known traps**
- A duplicate key in the Alpine object literal silently wins: overriding `initCols`, `saveCols` or
  a helper for this view must not change them for the other views.
- `saveCols()` posts the shown columns as the whole order today; with a fitted set that would push
  every away column to the end for everyone, and the browser-stored fallback order has the same
  problem.
- Quote `supplier_id` is a number in one place and a string in another: sort and match consistently.
- The quotes answer sends the supplier list alphabetical: sort a copy.
- The existing suppliers styles carry fixed widths with `!important` (284, 150, 118, 104 px).
- Alpine and Tailwind come from a CDN without a pinned version; use nothing newer than what the
  page already uses.

**Rules**
- Don't start other Claude Code sessions; use subagents inside this session, in the foreground.
- Talk only to the reviewer, through the result file.
- No `Co-Authored-By`, `Claude-Session` or "Generated with Claude Code" lines in commit messages.
- Code comments explain the reason itself, in Taglish like the files around them; no names, no
  decision labels, no spec citations.
- No commands that start with an environment variable. Never open, read or create an environment
  file. Downloads: only the one composer install. Nothing is installed with npm.
- Change only what this spec needs; suggestions go into the result under "Proposed tasks".
- The byte pin of `_table_old.blade.php` and the pinned hashes of the default layout, the original
  table and the non-CEO renders are never edited.
- Reviews by a separate reviewing agent report two axes, never merged: Spec (missing or partial
  cases, unasked-for scope, each quoting the spec line) and Correctness (`file:L<n>: problem. fix.`
  with a severity), ending with what it declined to judge.

## Budget

- Attempts: at most 2 tries at the same step; then stop and report what you tried and what you need.
- Size: large job, two runs of at most two hours each (Part 1, then Part 2), a commit after every
  task. If a part is turning out bigger than a run, stop at the last green commit and report.

## Done when

Part 1
- [ ] Cases S-34.1 to S-37.4 pass (S-37.5 and the `browser` halves are listed for the reviewer),
      each with a test named after it; the guard cases S-42.1 to S-42.4 pass.
- [ ] The changed and retired cases of slices 017 and 019 that Part 1 touches are updated in
      `qa/stories.md` and their tests, each under "Story changes" with the old and the new Then.
- [ ] An adversarial review of the Part 1 diff by a separate reviewing agent (try to get a supplier
      name, a price or a new marker rendered for a non-CEO viewer; try to overwrite another
      supplier's quote from a cell; try markup in a supplier name): who, findings, what was fixed.

Part 2
- [ ] Cases S-38.1 to S-41.3 and S-42.5, S-42.6 pass, each with a test named after it, every row
      F1 to F15 as a data row; the owner check is listed under `## Owner checks`.
- [ ] A test asserts every catalog column id has a minimum width and a place in the drop order.
- [ ] An adversarial review of the Part 2 diff (the save after a drag, the stored set name, the
      gate around every new block, a width at which a row's cells do not add up).

Both
- [ ] `git diff 1c32cc1 --stat` lists only: `app/Http/Controllers/ItemController.php`, files under
      `resources/views/item/` except `_table_old.blade.php`, the one new script file, files under
      `tests/`, `design/item-no-scroll/`, `qa/stories.md`, `TODO.md` if findings were accepted, and
      this spec, its result and its plan. `routes/`, `composer.json`, `composer.lock`,
      `package.json`, every migration and every file under `resources/views/owner/` show no diff.
- [ ] `php.bat -l` passes on every changed PHP file, and `node --check` on the script file.
- [ ] The full suite (plain PHPUnit) before and after: no new failures. On the base in a fresh
      worktree expect Tests 631 with 1 error and 1 failure (`ImportStartTest`'s case that needs an
      untracked file, and the old `ExampleTest`); if your count differs, report it as you find it.
      Both summaries in the result, and the count of node tests run (not skipped).
- [ ] The result lists what the reviewer must look at in a browser after release: the addresses
      for the CEO view and the Marketing view, the widths to try, and per `browser` case what to
      look for; and says in one sentence per part how sure the layout is without a browser.
- [ ] The result (`docs/specs/020-item-table-fits.result.md`) is filled in.

## Out of scope

The original table and its file; the default layout; the Marketing view; `/owner/private` and the
column settings page; a "Mine" set; remembering panel choices; designs for seven or more suppliers
(price-only cells, a pivot); deleting suppliers; the phone layout beyond "scrolls as today";
deploying; pushing.

## Report back

Fill in `docs/specs/020-item-table-fits.result.md` from its template and commit it with the work,
including every ruling you made (`Ruling: <decision> — <why> — <cost if wrong>`), then end the run
with the one line named in the start prompt. No PR and no push: the reviewer reviews the branch.
Under Merge danger say whether this is a one-way or two-way door, the blast radius and how to
revert. Under Process suggestions say how long each task took.
