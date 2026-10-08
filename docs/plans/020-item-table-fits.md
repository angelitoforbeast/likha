# Plan: spec 020 The item table fits the screen, one column per supplier

Spec: `docs/specs/020-item-table-fits.md`. Base: develop at 1c32cc1. Branch `feat/020-item-table-fits`.
Two runs: Part 1 (tasks 1 to 6, the supplier columns) and Part 2 (tasks 7 to 12, the fit).

## What the code looks like today (read before planning)

- **The gate.** `ItemController::index()` sets `$layoutSuppliers = ($layout === 'old' || $layout === 'suppliers') && $effectiveIsCEO`.
  `index.blade.php` has four blocks behind `@if(!empty($layoutSuppliers))`: the style include in `<head>`, the
  table include (`_table_suppliers` instead of `_table_old`), two lines in each quote loader (the loaded flags)
  and the script include (`_suppliers_js`). Nothing else in the page knows about the suppliers table.
- **The pins.** `SuppliersGroupTest::BASE` holds eight sha1 hashes (default and old layout for four viewers),
  `ItemPageTest` holds the sha1 of `_table_old.blade.php`, and `BASE_SUPPLIERS_CEO` holds the whole-render hash
  of the suppliers table (the one this spec retires).
- **The data.** `GET /item/quotes` answers `suppliers` (id, name, alphabetical) and `quotes` (item key to rows,
  each with `supplier_id` and the server's `cheapest` flag). `GET /item/suppliers` (the PO-suppliers endpoint)
  answers item key to rows of `supplier`, `unit_cost`, `order_date`, `order_no`: one row per supplier, the latest
  by order date then line id, only lines with `unit_cost > 0`. Both pass `checkAccess()` (CEO, Marketing - OIC,
  Marketing; anyone else gets 404) and both answer empty lists to every role but CEO. That stays.
- **The save.** `POST /item/quotes` is update-or-create on item key plus `supplier_id`, CEO only.

## Design

### The script file (proposal the spec asks for)

- Place: `public/js/item-table-fit.js`. A plain script, no Blade, no DOM, no Alpine, no global read. It ends with
  one export line: `module.exports` under node, `window.ItemTableFit` in the browser.
- Loaded by one `<script src>` tag inside the existing `@if(!empty($layoutSuppliers))` block in `<head>`, before
  Alpine starts (Alpine is `defer`). The address carries a version taken from the file's content
  (`?v=` plus the first ten characters of its md5), so a release is never served stale and the address only
  changes when the file does.
- The Alpine object calls it through thin methods in `_suppliers_js.blade.php` (`splCols`, `splCell`, `splSpan`,
  `splNone`); no method of the shared object literal is overridden, so the duplicate-key trap does not apply.

### The node tests

- `tests/js/call.cjs`: reads a JSON list of `{fn, args}` from stdin, calls the script's functions, prints the
  results as JSON. No assertion lives in it.
- `tests/Feature/Item/ItemTableFitScriptTest.php`: one PHPUnit class. Every test is named after its case, builds
  its calls, runs node once and asserts in PHP against values written from the spec. When `node --version` does
  not run, every test is marked skipped with the message "skipped: node not found", which PHPUnit prints in its
  summary; the result reports node tests run and skipped separately.

### Part 1: the supplier columns

- Pure functions: `supplierColumns(rows)`, `supplierCell(quotes, poRows, supplierId)`, `noSupplier(quotes, poRows)`,
  `groupSpan(count)`, `formSupplierId(supplierId)`, and a small peso formatter the cell uses.
- Header: the SUPPLIERS group header spans `splSpan()`; the second header row loops over `splCols()` with the
  header bound as text and the full name in the title and aria-label. When there is no column it holds one cell:
  the link "Add a supplier in Finance → Supply" once the list answered empty, the neutral placeholder before.
- Item row: one placeholder cell while the two lists have not both answered (as in slice 017); otherwise one cell
  per supplier, matched by supplier id (numeric compare on both sides). A cell is a quote (price, MOQ, an edit
  control shown on hover, focus or touch, a green dot when that supplier also has a PO), a PO only (green cost,
  "PO" tag, a quiet plus) or empty (a quiet plus). No supplier name inside a cell.
- Cards: the details card (hover or tap) shows the supplier's number and name, the quote facts, photo, link and
  the "Last PO" line. The edit card is the existing form with the supplier shown as text (no `<select>`), and a
  remove button when it edits an existing quote. The form's supplier id always comes from the column.
- The warning mark: one small mark in the item cell when both lists answered and the item has no quote and no PO.
  The red band and its markup go.
- Colspans: `cols.length + 6` becomes `cols.length + 2 + splSpan()`; the TOTAL row's empty cell and the page
  rows' cell follow `splSpan()`. `splSpan()` is never 0.
- Endpoint: each PO-suppliers row gains `supplier_id` (integer). Nothing else in the answer changes.
- Widths in Part 1: a supplier column is 76 px (the brief's 1920 width); the PAGE and ITEM widths stay as they
  are until the fit of Part 2 sets every width.

### Part 2: the fit (outline, detailed when run 2 starts)

- Pure functions: `fit`, `turnOn`, `orderToSave`, `profPct`, `tableMoney`, the set read and write, with the
  catalog's minimum widths and drop order as data in the script file.
- The suppliers view keeps its own shown-columns list (`fitCols`) next to the shared `cols`; the suppliers
  partial loops over the fitted list. `initCols` and `saveCols` are not redefined: the drag in this table calls
  a save of its own that sends `orderToSave(saved order, shown order)`.
- A `<colgroup>` with computed widths, `table-layout:fixed` from 1280 px of window width; a ResizeObserver on
  the scroll box; the "+N columns" panel, the two sets, Prof.% as one column, the table's own money format.

## Tasks

| # | Task | Side | Tier | Cases |
|---|---|---|---|---|
| 1 | Test-only: base pins of `/owner/private` and `/owner/column-settings`; slice 020 added to `qa/stories.md` | backend | low | S-42.4 |
| 2 | `supplier_id` on the PO-suppliers endpoint | backend | medium | S-36.3 (feature), S-36.4 (feature), S-36.5, S-34.2 (feature) |
| 3 | The script file with the Part 1 functions, the node runner and the node test class | frontend | high | unit-js halves of S-34.1 to S-34.6, S-35.1, S-35.2, S-35.4 to S-35.6, S-36.1 to S-36.4, S-37.1, S-37.2, S-37.4 |
| 4 | The supplier columns in the view: header, cells, cards, form, warning mark, colspans, styles; the changed tests and stories of slices 017 and 019 | frontend | high | pin halves of S-34.1, S-34.4, S-34.5, S-35.1, S-35.3, S-35.4, S-36.1, S-37.1, S-37.3; S-37.4 (feature); S-42.1 to S-42.3; changed S-13.1, S-13.2, S-13.4, S-15.8, S-15.2 to S-15.6, S-15.10, S-16.1, S-16.6, S-16.7, S-17.4, S-18.1, S-20.1, S-21.1, S-24.1; retired S-14.8, S-14.10, S-14.11, S-15.1, S-15.7, S-22.1 |
| 5 | Adversarial review of the Part 1 diff by `skeptic-reviewer`, fix loop (at most two) | both | high | the review item of Done when |
| 6 | Result: status "partial: part 1 done", case table, story changes, suite line | docs | low | |
| 7 | Part 2 functions and the fit table F1 to F15 | frontend | high | S-38.1 to S-38.6, S-38.10, S-39.1 to S-39.4, S-40.1, S-40.2, S-40.4, S-40.5, S-41.1 to S-41.3 (unit-js) |
| 8 | The fitted table: colgroup, shown columns, colspans, sticky rule below 1280, the table's money format | frontend | high | S-38.5, S-38.9, S-38.10 (pins), changed S-13.6, S-17.3, S-17.5, S-17.6, S-19.4 |
| 9 | Sets and the "+N columns" panel | frontend | high | S-39.1, S-39.5, S-40.1, S-40.3 |
| 10 | The save after a drag; Prof.% as one column | frontend | high | S-40.5, S-41.1 to S-41.3, changed S-19.7 |
| 11 | Guards and the last review | both | high | S-42.3 extended, S-42.5, S-42.6, the review item |
| 12 | Full suite, result, owner check | docs | low | S-38.11 listed |

`browser` cases and S-37.5 are listed for the reviewer in the result; none is claimed.

Tasks run one after the other in this session (they share the suppliers partials, so none is parallel-safe).

## Risks the plan answers

- **A non-CEO render changes.** Every edit to `index.blade.php` is inside an existing `layoutSuppliers` block;
  the eight base hashes and the `_table_old` pin run after every task.
- **A cell overwrites another supplier's quote.** The form has no supplier control; its supplier id is set from
  the column on open, and the server validates it exists.
- **Markup in a supplier name.** Header, title, aria-label and cards bind text only; the existing test that walks
  every attribute of the group is extended to the new bindings.
- **Number and string ids.** The pure functions compare ids as numbers on both sides; the node tests send both.

## Open questions

None. The spec leaves the script's place and loading to the plan; both are stated above.
