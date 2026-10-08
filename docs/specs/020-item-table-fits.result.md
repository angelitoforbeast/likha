# Result: spec 020 The item table fits the screen, one column per supplier

> Committed beside the spec: names no people and no decision ids.

**Status:** partial: part 1 done
**Date:** 2026-10-08
**Branch / PR:** `feat/020-item-table-fits` (no PR, no push; the reviewer reviews the branch)
**Preview or run link:** n/a (no dev server and no browser in this run)

## Summary

Part 1 is built: the SUPPLIERS group of the CEO's table with suppliers now has one column per supplier of the
Finance → Supply list, numbered by position in id order ("1 · Kelly"), and each cell holds only that
supplier's quote (price and MOQ, an edit control), or its last PO cost with a "PO" tag and a quiet plus, or a
quiet plus. A supplier with a quote and a PO gets a green dot and a "Last PO" line in the cell's card; an item
with no quote and no PO gets one small warning mark by its name instead of the red band. The logic that can
be wrong without anyone seeing it (the columns, the cell, the warning rule, the form's supplier) lives in
`public/js/item-table-fit.js` as pure functions, which the suite runs through `node`; the Alpine templates
only call them. The PO-suppliers endpoint gained `supplier_id` per row so a PO is matched to its column by
id. Part 2 (the fit, the "+N columns" panel, the two sets, Prof.% as one column, the number formats) is not
started: the table is still as wide as before and still scrolls sideways.

## Case table

Part 1. "node" = `tests/Feature/Item/ItemTableFitScriptTest.php` (pure functions through node), "group" =
`tests/Feature/Item/SuppliersGroupTest.php` (render and text pins), "columns" =
`tests/Feature/Item/SupplierColumnsTest.php` (endpoints). Output lines of the last run of each file:
node `OK (25 tests, 154 assertions)`, group `OK (49 tests, 2216 assertions)`, columns `OK (4 tests, 24 assertions)`,
`WorklistTest` `OK (4 tests, 32 assertions)`, `SharedPagesBasePinTest` `OK (2 tests, 8 assertions)`,
`ItemPageTest` `OK (45 tests, 943 assertions)`.

| Case | Test | Result |
|---|---|---|
| S-34.1 | node `test_S_34_1_three_suppliers_give_three_numbered_columns_with_the_full_name`; group `test_S_34_1_layout_old_draws_one_header_per_supplier_of_the_list` (the loop, no "Supplier 1/2/3", "Others", PO sub-header or fixed 3 or 4) | pass |
| S-34.2 | columns `test_S_34_2_a_supplier_added_on_the_supply_finance_page_is_in_the_quotes_answer_with_its_id`; node `test_S_34_2_a_fourth_supplier_is_the_fourth_column` | pass (the feature half passed before any change: characterisation; it turns red if the quotes answer stops listing every supplier with its id) |
| S-34.3 | node `test_S_34_3_columns_are_numbered_by_position_in_id_order` (data rows: numbers, strings) | pass |
| S-34.4 | node `test_S_34_4_the_header_is_the_number_and_the_first_word_and_the_title_the_full_name`; group `test_S_34_4_the_header_binds_the_name_as_text_and_cuts_a_long_word` | pass; the cut of a 120-letter word on screen is a browser case |
| S-34.5 | node `test_S_34_5_no_supplier_gives_no_column_and_a_group_that_is_never_zero_wide`; group `test_S_34_5_no_colspan_is_ever_zero_and_an_empty_list_links_to_the_supply_page` | pass |
| S-34.6 | node `test_S_34_6_a_quote_of_an_unknown_supplier_adds_no_column_and_no_warning` | pass |
| S-35.1 | node `test_S_35_1_a_cell_holds_only_that_suppliers_price_and_moq`; group `test_S_35_1_a_cell_shows_no_supplier_name` | pass |
| S-35.2 | node `test_S_35_2_the_plus_of_a_column_opens_the_form_on_that_supplier`; the existing save tests (`test_S_14_7`, `test_S_16_5`, `QuotePhotoTest`) | pass; opening the form and seeing the cell change is a browser case |
| S-35.3 | group `test_S_35_3_a_filled_cell_has_an_edit_control_and_the_edit_card_can_remove` | pass; hover, focus and tap are a browser case |
| S-35.4 | group `test_S_35_4_the_cell_form_has_no_supplier_control`; node `test_S_35_4_the_forms_supplier_is_always_the_columns_supplier` | pass |
| S-35.5 | node `test_S_35_5_a_missing_price_is_a_dash_a_zero_price_shows_and_a_missing_moq_is_left_out` | pass |
| S-35.6 | node `test_S_35_6_cheapest_is_the_servers_flag` (five data rows, one with the flag deliberately against the prices); group `test_S_35_6_the_templates_read_the_servers_cheapest_flag_and_compare_no_price`; the existing `test_S_14_5`, `test_S_14_6` | pass |
| S-36.1 | node `test_S_36_1_a_quote_with_a_po_keeps_the_quote_price_and_gets_the_dot_and_the_po_line`; group `test_S_36_1_a_po_shows_as_a_dot_with_a_label_and_as_a_line_in_the_card` | pass; the look of the dot and the card is a browser case |
| S-36.2 | node `test_S_36_2_a_po_without_a_quote_shows_the_po_cost_with_a_tag_and_is_never_cheapest`; the markup of the tag and the plus in group `test_S_36_1…` | pass; touch visibility is a browser case |
| S-36.3 | node `test_S_36_3_no_po_or_a_zero_cost_line_gives_no_dot_and_no_tag`; columns `test_S_36_3_a_discount_or_zero_cost_line_is_not_a_po_of_that_supplier` | pass |
| S-36.4 | columns `test_S_36_4_po_rows_carry_the_supplier_id_and_keep_their_other_keys`; node `test_S_36_4_the_po_is_matched_to_its_column_by_supplier_id_not_by_name` | pass |
| S-36.5 | columns `test_S_36_5_the_po_row_of_a_supplier_is_its_latest_by_order_date_then_line` | pass |
| S-37.1 | node `test_S_37_1_S_37_2_S_37_4_the_warning_is_for_an_item_with_no_quote_and_no_po` (row "S-37.1"); group `test_S_37_1_S_37_3_one_small_warning_mark_replaces_the_red_band_and_waits_for_both_lists` | pass; the look is a browser case |
| S-37.2 | node, the same test (three rows "S-37.2") | pass |
| S-37.3 | group `test_S_37_1_S_37_3_…` and `test_S_15_10_the_cells_and_the_warning_are_bound_to_the_loaded_state` | pass |
| S-37.4 | `WorklistTest::test_S_37_4_the_items_of_the_need_a_supplier_list_are_the_items_with_no_quote_and_no_po_row` (the chip's count and list against the two answers the page reads, same data); the existing `WorklistTest::test_ceo_gets_items_classified_into_the_four_lists`; node row "S-37.4" | pass (passed when written: characterisation of two existing rules; it turns red if the chip's list and the mark's rule drift apart on this data) |
| S-37.5 | none | browser, not run |
| S-42.1 | the existing `ItemPageTest` pin of `_table_old.blade.php` and group `test_S_23_1`, `test_S_23_2` (unchanged) | pass |
| S-42.2 | the existing group `test_S_19_2_default_layout_for_the_ceo_is_identical_to_the_base`, `test_S_18_4_marketing_renders_of_the_old_and_default_layout_are_identical_to_the_base` (the eight hashes, unchanged) | pass |
| S-42.3 | group `test_S_24_1_non_ceo_views_get_the_base_old_view_for_every_layout_word` (marker list extended with the Part 1 names, each with a positive control in the CEO render), `test_S_24_2_the_script_does_not_call_the_loaders_for_non_ceo_views` | pass for the Part 1 markers; the Part 2 names are added in run 2 |
| S-42.4 | `tests/Feature/OwnerPrivate/SharedPagesBasePinTest::test_S_42_4_the_shared_owner_pages_render_byte_for_byte_as_at_the_base` (two data rows) | pass; hashes captured in the test-only commit 2f327f6, before any product file changed |

Part 2 (S-38.1 to S-41.3, S-42.5, S-42.6, rows F1 to F15): not built, not run.

## Story changes

Part 1. Each in `qa/stories.md`, in place. Five entries were not in the spec's list (the S-13 title, S-15.9, S-15.10, S-19.3, S-21.1); reading the file showed
they name things this spec removes (said in each line).

- **S-13 title** — changed. Old: "S-13 The CEO sees Supplier 1, 2, 3 and PO beside each item (P1)". New: "S-13 The CEO sees one column per supplier beside each item (P1)". Reason: Title: one column per supplier of the list, no PO column (spec 020). Not in the spec's list; the title named the old columns.
- **S-14 title** — changed. Old: "S-14 Supplier 1 to 3 are the three cheapest quotes; the rest sit behind "+N" (P1)". New: "S-14 Quotes come back in the server's order with the `cheapest` flag (P1)". Reason: Title: no longer "the three cheapest quotes": the server order and the `cheapest` flag (spec 020).
- **S-13.1** — changed. Old Then: "the header has two rows: Page, Item and every other header span both rows; "SUPPLIERS" spans four columns; under it Supplier 1, Supplier 2, Supplier 3, PO in that order, right after Item and before the configurable columns; the four sub-headers have no sort handler and are not draggable" New Then: "the header has two rows: Page, Item and every other header span both rows; "SUPPLIERS" spans as many columns as the supplier list has (never 0); under it one sub-header per supplier of the list, "N · first word of the name" with the full name in the title, right after Item and before the configurable columns; the sub-headers come from one loop over the list, have no sort handler and are not draggable; there is no "Supplier 1/2/3" and no "PO" sub-header" Reason: Headers are "N · first word" per supplier of the list; no PO sub-header (spec 020).
- **S-13.2** — changed. Old Then: "four supplier cells (three quote cells and the PO cell) sit after the Item cell and before the configurable columns. The four columns are one cell spanning four for the placeholder, one spanning four for the red band, a loop over three slots and the PO cell" New Then: "one cell per supplier of the list sits after the Item cell and before the configurable columns: one cell spanning the group for the placeholder, one empty cell when the list has no supplier, and a loop over the list (one cell each). There is no PO cell and no red band" Reason: One cell per supplier; no PO cell (spec 020).
- **S-13.4** — changed. Old Then: "each full-width row (loading, empty, no-worklist result, no-category result, the expanded page block) spans four more columns than in the Old view, the TOTAL row still starts `<td>TOTAL</td>` and its next empty cell covers Item plus the four, and a page row and the repeated per-page header each have one cell spanning the four, so every row has the same number of columns" New Then: "each full-width row (loading, empty, no-worklist result, no-category result, the expanded page block) spans Page, Item, the supplier columns (the list's length, at least 1) and the other columns; the TOTAL row still starts `<td>TOTAL</td>` and its next empty cell covers Item plus the supplier columns; a page row and the repeated per-page header each have one cell spanning the supplier columns, so every row has the same number of columns" Reason: The added column count is the list's length (spec 020).
- **S-14.8** — retired. Old Then: "the helpers that take the first three and count the rest are the pinned text (first three; rest = count minus three, never negative) and they read the server's order and the `cheapest` flag instead of sorting or comparing prices themselves" Reason: Retired: the first-three and "rest" helpers are gone; a cell holds at most one quote (spec 020). The rule that the view reads the server's flag and compares no price is now S-35.6.
- **S-14.10** — retired. Old Then: "Supplier 1 to 3 are the three cheapest in order and Supplier 3 carries "+2"; exactly three quotes show no "+N"; "+N" lists every quote in server order" Reason: Retired: no "+N" list; one column per supplier (spec 020).
- **S-14.11** — retired. Old Then: "the PO cell shows the first of today's list over its cost and "+1"" Reason: Retired: no PO column; a supplier's PO shows in its own column (S-36) (spec 020).
- **S-15.1** — retired. Old Then: "one red cell spans the four sub-columns with "wala pang supplier" once and the "+ supplier quote" button inside; no grey "walang supplier" anywhere" Reason: Retired and replaced: the red band is replaced by the warning mark (S-37.1) (spec 020).
- **S-15.2** — changed. Old Then: "three "+" cells and the PO cell filled; no red band" New Then: "the cell of each supplier with a PO shows its PO cost in green with a "PO" tag and a quiet "+", the other suppliers' cells a quiet "+"; no warning mark" Reason: Cells follow the supplier's identity; no PO cell and no band (spec 020).
- **S-15.3** — changed. Old Then: "Supplier 1 shows name over price with MOQ, two "+" cells, a dash in PO, and the price is not marked cheapest" New Then: "that supplier's own column shows the price with MOQ under it and no name, the other columns a quiet "+", and the price is not marked cheapest" Reason: The cell is the supplier's own column; no name line in the cell (spec 020).
- **S-15.4** — changed. Old Then: "three cells cheapest first, only the cheapest price marked, the PO cell with name and cost" New Then: "each price sits in its supplier's column (not ordered by price), only the cheapest price is marked, and the supplier with the PO has a green dot on its cell" Reason: Cells follow the supplier's identity, not price rank; the PO is a dot (spec 020).
- **S-15.5** — changed. Old Then: "only what exists shows (a dash for no price, the typed 0 for 0), no reserved gap, and it is not marked" New Then: "only what exists shows (a dash for no price, "₱0.00" for 0), no reserved gap, and it is not marked" Reason: The typed 0 now reads "₱0.00" from the cell's own formatter; the dash stays (spec 020).
- **S-15.6** — changed. Old Then: "the text is cut with an ellipsis inside the cell, the row does not grow and nothing overlaps the next cell; the full values are in the card" New Then: "the header and the cell text are cut with an ellipsis inside their column, the row does not grow and nothing overlaps the next cell; the full name is in the header's title and the full values in the card" Reason: The name is in the header, not the cell (spec 020).
- **S-15.7** — retired. Old Then: ""walang running page" shows in the Item column and the red band shows once" Reason: Retired and replaced: "walang running page" still shows in the Item column; the band is the warning mark of S-37.1 (spec 020).
- **S-15.8** — changed. Old Then: "its page rows show below with one empty cell under the group and keep their own three-line RTS / DEL / INT cell; the TOTAL row stays last and lines up" New Then: "its page rows show below with one empty cell under the supplier columns (as wide as the list) and keep their own three-line RTS / DEL / INT cell; the TOTAL row stays last and lines up" Reason: The group is as wide as the supplier list (spec 020).
- **S-15.9** — changed. Old Then: "the four cells show a neutral placeholder and neither the red band nor the "+" cells; they appear only after both lists have answered. The placeholder is the visible grey text "hindi na-load" after a failed fetch (title: the full sentence) and "…" while loading" New Then: "the supplier columns show one neutral placeholder cell and neither the warning mark nor the "+" cells; they appear only after both lists have answered. The placeholder is the visible grey text "hindi na-load" after a failed fetch (title: the full sentence) and "…" while loading" Reason: Wording only: "the four cells" and "the red band" no longer exist (spec 020). Not in the spec's list; the file showed it names them.
- **S-15.10** — changed. Old Then: "the band and the "+" cells are bound to a loaded state that is set only after both fetches have answered successfully (pinned text)" New Then: "the placeholder, the supplier cells with their "+" and the warning mark are bound to a loaded state that is set only after both fetches have answered successfully (pinned text)" Reason: The band is the warning mark (spec 020). Not in the spec's list; the file showed it names the band.
- **S-16.7** — changed. Old Then: "it appears in the right cell without a reload and the form closes; edit from the card updates the cell (it may move to another cell); remove empties it; the chips' counts refresh as today" New Then: "the form opens with that supplier fixed (text, no dropdown); when the quote is saved it appears in that same column without a reload and the form closes; edit from the cell's ✎ updates the cell (it never moves to another column); remove, inside the edit card, empties it; the chips' counts refresh as today" Reason: "The right cell" is that supplier's own column; the "+" opens the form with the supplier fixed (spec 020).
- **S-18.1** — changed. Old Then: "the three responses are the same normalised output, the original table as they had it before, and contain none of: "SUPPLIERS", "Supplier 1", "Supplier 2", "Supplier 3", the new class names, the new helper names, either toolbar link between the two tables, or a seeded supplier name (the same case as S-24.1)" New Then: "the three responses are the same normalised output, the original table as they had it before, and contain none of: "SUPPLIERS", the `spl-` class names, the helper names (`splCols`, `splCell`, `splSpan`, `splNone` and the rest), the name of the script file and of its functions (`ItemTableFit`, `supplierColumns`, `supplierCell`, `noSupplier`, `formPreset`), "wala pang supplier", "Add a supplier in Finance", either toolbar link between the two tables, or a seeded supplier name, alone or as a numbered header (the same case as S-24.1)" Reason: The "must not appear" marker list gains the new names (spec 020).
- **S-19.3** — changed. Old Then: "it is the suppliers view; given the original table (`layout=original`) rendered for the CEO, when compared with the Old view of the commit before the suppliers view existed, then the only differences are its one toolbar link and the layout word its script keeps in the address (the same cases as S-22.1 and S-23.2)" New Then: "it is the suppliers view (the SUPPLIERS group over one header per supplier); given the original table (`layout=original`) rendered for the CEO, when compared with the Old view of the commit before the suppliers view existed, then the only differences are its one toolbar link and the layout word its script keeps in the address (the same cases as S-34.1 and S-23.2)" Reason: Its first half pointed at the retired S-22.1; it now points at S-34.1 (spec 020). Not in the spec's list.
- **S-20.1** — changed. Old Then: "every supplier name, price, MOQ, date, link and photo value is bound as text or as an attribute value through Alpine bindings, never as HTML, and the quote's note is not shown" New Then: "every supplier name, price, MOQ, date, link and photo value is bound as text or as an attribute value through Alpine bindings, never as HTML, in the cells, the cards and the header (the header binds the name as text, in its title and in its aria-label), and the quote's note is not shown" Reason: The supplier name is also bound as text in the header, its title and aria-label (spec 020).
- **S-21.1** — changed. Old Then: "the quotes loader and the PO-suppliers loader are each called once at start-up as in the Old view, and the new templates and helpers contain no fetch of their own" New Then: "the quotes loader and the PO-suppliers loader are each called once at start-up as in the Old view, and the new templates and helpers contain no fetch of their own; the only addresses in them are the photo page link and the link to the Supply Finance page" Reason: The table now links to the Supply Finance page (S-34.5) (spec 020). Not in the spec's list for Part 1.
- **S-22.1** — retired. Old Then: "the suppliers table renders (the SUPPLIERS header group, the suppliers-only styles and script members), exactly as `layout=suppliers` rendered it at the base apart from the toolbar link and the layout word the script keeps in the address" Reason: Retired and replaced: the whole-render hash of the suppliers table fails by design once the columns change; the route, the gate and the markers are in S-34.1 (spec 020).
- **S-24.1** — changed. Old Then: "all responses of a viewer are identical to each other, the route passes the view exactly the data of that viewer's Old view at the base (whose render is pinned), and none contains a suppliers marker (the header words, the `spl-` names, the helper names, either toolbar link, `layout=original`, a seeded supplier name)" New Then: "all responses of a viewer are identical to each other, the route passes the view exactly the data of that viewer's Old view at the base (whose render is pinned), and none contains a suppliers marker (the header word, the `spl-` names, the helper names, the script file and its function names, "wala pang supplier", "Add a supplier in Finance", either toolbar link, `layout=original`, a seeded supplier name alone or as a numbered header)" Reason: The "must not appear" marker list gains the new names (spec 020).

Still to do in run 2 (Part 2): S-13.6, S-19.4, S-17.3, S-17.5, S-17.6, S-19.7 (changed), S-13.7, S-17.7,
S-19.8 (retired, folded into S-38.11), and the Part 2 names in S-18.1 and S-24.1.

## Done-when checklist

Part 1
- [x] Cases S-34.1 to S-37.4 pass, each with a test named after it; S-37.5 and the browser halves are listed
      below; the guard cases S-42.1 to S-42.4 pass. Evidence: the case table and the suite line under Tests.
- [x] The changed and retired cases of slices 017 and 019 that Part 1 touches are updated in `qa/stories.md`
      and their tests, each under "Story changes" with the old and the new Then.
- [x] An adversarial review of the Part 1 diff by a separate reviewing agent: see Tests (who, findings, what
      was fixed). No blocker, no major.

Part 2
- [ ] Cases S-38.1 to S-41.3 and S-42.5, S-42.6: not started.
- [ ] The test that every catalog column id has a minimum width and a place in the drop order: not started.
- [ ] The adversarial review of the Part 2 diff: not started.

Both
- [x] `git diff 1c32cc1 --stat` lists only allowed files so far: `app/Http/Controllers/ItemController.php`,
      `resources/views/item/_suppliers_js.blade.php`, `_suppliers_style.blade.php`, `_table_suppliers.blade.php`,
      `public/js/item-table-fit.js`, files under `tests/` (including `tests/js/call.cjs`), `design/item-no-scroll/`,
      `qa/stories.md`, `TODO.md`, and the spec, result and plan. `routes/`, `composer.json`, `composer.lock`,
      `package.json`, migrations, `resources/views/owner/`, `index.blade.php` and `_table_old.blade.php`: no diff.
- [x] `php.bat -l` passes on every changed PHP file (`ItemController.php`, the five test classes);
      `node --check public/js/item-table-fit.js` passes.
- [x] The full suite before and after (Part 1): see Tests.
- [x] What the reviewer must look at in a browser: listed below for Part 1; Part 2's list follows in run 2.
- [ ] The result is filled in: for Part 1 only (Part 2's sections say "run 2").

## How to run

From the repository root (PHP 8.4, Node 24 on the path; nothing is installed with npm):

- Install once: `php composer.phar install --no-interaction`
- The full suite: `php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml`
- The node tests alone: `php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml tests/Feature/Item/ItemTableFitScriptTest.php`
  (add `--display-skipped` to see "skipped: node not found" when node is missing)
- The Part 1 pins: the same command with `tests/Feature/Item/SuppliersGroupTest.php`,
  `tests/Feature/Item/SupplierColumnsTest.php`, `tests/Feature/Item/WorklistTest.php`,
  `tests/Feature/OwnerPrivate/SharedPagesBasePinTest.php`
- The script's syntax: `node --check public/js/item-table-fit.js`

## Rulings

- Ruling: the script file is `public/js/item-table-fit.js`, loaded by one plain `<script src>` at the top of
  `_table_suppliers.blade.php` (in the body, before the table), with `?v=` taken from the file's md5 — the plan
  said the gate block in `<head>`, but that block's exact text is pinned by the existing `test_S_17_1`, and the
  partial is itself only included behind the suppliers gate, so `index.blade.php` has no diff at all; a plain
  script in the body still runs before Alpine, which is deferred — cost if wrong: if a browser ran Alpine
  before this tag, every supplier expression would throw; then the tag moves to `<head>` and that one pin is
  updated.
- Ruling: the file is a plain script that sets one name, `ItemTableFit`, and has no `module.exports` — the
  repository's `package.json` says `"type": "module"`, so node refuses to `require` a `.js` file under it as a
  classic script; the test runner (`tests/js/call.cjs`) loads it the way a page does, in its own context with
  `self` — cost if wrong: none for the browser; a different loader is a change to the runner only.
- Ruling: the pure functions return the cell's texts ready to show ("₱18.50", "MOQ 1000", "—", the "Last PO"
  line) with a small formatter of their own, not the page's `money()` — so the same text is tested in node and
  shown in the browser — cost if wrong: Part 2's money format helper replaces the formatter in one place.
- Ruling: remove sits in the edit form (the card the pencil opens) and no longer in the details card; the
  details card (hover, tap or keyboard on the price) shows the facts, the photo, the link and the "Last PO"
  line — the decision says "remove inside the edit card" — cost if wrong: one more button in the details card.
- Ruling: with no supplier in an answered list, the link "Add a supplier in Finance → Supply" is the one
  sub-header of the group and the item rows have one empty cell under it (120 px) — the spec says "one narrow
  column reads…"; a link repeated in every row would be the wall of text the owner did not want — cost if
  wrong: move the link into the cell.
- Ruling: a form opened on a quote that belongs to another supplier than the column is treated as "add" for the
  column's supplier (blank fields, no quote id) — cannot happen through the page, but if it did, carrying the
  other supplier's values in would overwrite the column's own quote — cost if wrong: none seen.
- Ruling: while a remove from the form has not answered, Save and remove are both disabled through the form's
  existing `saving` flag — the review showed a remove and a save could otherwise cross — cost if wrong: Cancel
  and Esc are also inert for that moment, as they already are during a save.
- Ruling: `/owner/column-settings` is pinned through `/owner/column-settings/owner-private` — the address
  itself only redirects there; that section is the page that edits the shared column setting — cost if wrong:
  add the other three sections to the same test (one line each).
- Ruling: the supplier column is 76 px wide in Part 1 (the brief's 1920 width) and PAGE and ITEM keep their
  widths — the fit of Part 2 sets every width from the measured box — cost if wrong: none after Part 2.
- Ruling: the PO tag text is 11 px, as every new sub-line — the spec's 11 px floor — cost if wrong: one number.
- Ruling: the "skipped: node not found" path was proven once by hand (the probe pointed at a binary that does
  not exist, run, reverted; output under Tests), not by a permanent test — a permanent test would need a seam
  in the test class only to test the test — cost if wrong: the message could rot unnoticed.
- Ruling: five story entries outside the spec's list were changed (the S-13 title, S-15.9 and S-15.10
  wording, S-19.3's test, S-21.1's allowed addresses) — the spec says to follow the file where its list is
  wrong — cost if wrong: wording only.

## Catalog columns not named in the spec

n/a in Part 1 (run 2).

## Deferred minors

All in `TODO.md` under "020 … Part 1", each with its reason: the mark and the chip read a zero-cost or
zero-quantity PO line differently (the base's two rules, kept); the server's `cheapest` flag counts a quote
whose supplier has no column; a PO row without a supplier id; the peso formatter is not hardened against
inputs the server never sends; the script's address is computed from the file on each render and a missing
file fails loudly; the script file is public (logic only); copied test helpers; text pins do not run the page's
script; focus, card placement, the header cut and touch are browser cases.

## Merge danger

Two-way door. Blast radius: only the CEO's table with suppliers (`/item?layout=old` or `layout=suppliers` in
the CEO view); every other render is byte for byte the base (the eight pinned hashes, the `_table_old` pin and
the two shared-page pins pass unedited). The one server change is additive (`supplier_id` on each PO-suppliers
row; the other readers ignore it). No migration, no route, no setting, no dependency. One new static file:
`public/js/item-table-fit.js` must reach the server with the release; if it is missing, the table with
suppliers answers with an error instead of drawing (the other layouts are not affected), and if it is served
but blocked in the browser the supplier cells do not draw. Revert: revert the branch's commits; nothing is
stored that a revert leaves behind. Do not release Part 1 alone as "the table fits": it does not fit yet.

## What the reviewer must look at in a browser

Not seen in a browser in this run (no dev server, no browser). Addresses: the CEO view
`/item?start_date=…&end_date=…&layout=old` (and `&layout=suppliers`); the Marketing view: the same address as a
Marketing user, and as the CEO with `&view_as=marketing`. Widths for Part 1: any desktop width (it still
scrolls sideways), and 390 px for the touch cases.

How sure the Part 1 layout is without a browser: the structure is sure (every template tag is balanced, every
Alpine expression of the group parses as script, the page's inline script parses, rows and header add up in
every state by the colspan test), but the look is not — the widths, the pencil over a long price, the dot's
place and the card's place were written from the brief's numbers and never drawn.

| Case | Look for |
|---|---|
| S-34.1 | Three headers under SUPPLIERS: "1 · KELLY", "2 · ALBEE", "3 · HELEN" (the page's header style is uppercase), the full name on hover |
| S-34.2 | Add a supplier on Finance → Supply, reload the item page: a fourth column |
| S-34.4 (the cut) | A supplier whose first word is very long: the header is cut with an ellipsis, the column stays 76 px |
| S-34.5 | Hard to see with real data (needs an empty supplier list): the single header with the link; before the lists answer, "…" |
| S-35.1 | Cells show only price and MOQ; no supplier name in any row |
| S-35.2 | "+" in supplier 2's column opens a form headed "2 · full name" with no dropdown; save 40 / 300: the cell shows "₱40.00", "MOQ 300" |
| S-35.3 | Hover, Tab and (at 390 px) tap on a filled cell: the pencil shows; the form changes price, MOQ, link, photo; "✕ Tanggalin" asks, then the cell empties without a reload |
| S-36.1 | A supplier with a quote and a PO: quote price, green dot top left (title on hover), the card's "Last PO ₱…, date, PO number" line |
| S-36.2 | A supplier with a PO only: green cost, "PO" tag under it, a "+" top right on hover (always visible at 390 px) |
| S-37.1 | An item with no quote and no PO: one small red-on-pink "!" at the right of the item cell, title "wala pang supplier", no red band; "walang running page" still red |
| S-37.5 | Save the first quote of a marked item: the mark goes, the "Need a supplier" chip count drops by one |
| S-42.6 (Part 1 share) | Add, edit, remove a quote with link and photo; the five chips; expand an item (the page rows have one empty cell under the supplier columns); TOTAL lines up; Esc and a click outside close the card |
| Focus | After Save, Cancel or remove, keyboard focus is back in the cell |
| Marketing | No SUPPLIERS group, no "+", no mark; the page looks as before |

## Conflicts with CLAUDE.md

- The kit names `backend-developer` and `frontend-developer` agents for the build; this session has no such
  agent types (only the reviewer and the story writer are installed), so the main session wrote the code and
  `skeptic-reviewer` reviewed it. The review is still by a separate agent.
- Red before green: tasks 2 and 3 have a red run (below). In task 4 the view was changed first and the
  existing pins went red (12 of 42 tests in `SuppliersGroupTest`), then the pins were rewritten and the new
  ones added; the new pin tests were not seen red on their own. The one later fix (the remove guard) was
  red first.

## Tests

Full suite, plain PHPUnit:
- Before (the base, in this worktree after the one composer install): `Tests: 631, Assertions: 8375, Errors: 1, Failures: 1, Skipped: 3.`
  The error is `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`
  (needs an untracked file), the failure is `ExampleTest::test_the_application_returns_a_successful_response`.
  As the spec expected.
- After Part 1 (commit b7d10fd): `Tests: 670, Assertions: 8986, Errors: 1, Failures: 1, Skipped: 3.` The same one error and one failure, no new one.
- Node tests: 25 run, 0 skipped (node v24.13.1). The skip path, proven once by hand with the probe pointed at
  a missing binary: `Tests: 25, Assertions: 0, Skipped: 25.` with `skipped: node not found` printed 25 times.

Red runs (one line per slice):
- Task 2: `SupplierColumnsTest::test_S_36_4_po_rows_carry_the_supplier_id_and_keep_their_other_keys` — `ErrorException: Undefined array key "supplier_id"`.
- Task 3: `ItemTableFitScriptTest::test_S_34_1_three_suppliers_give_three_numbered_columns_with_the_full_name` — `Error: Cannot find module '…/public/js/item-table-fit.js'`.
- Task 4: 12 existing tests of `SuppliersGroupTest` red after the view change (`Tests: 42, Assertions: 740, Failures: 12.`), then rewritten; see Conflicts.
- Fix: `SuppliersGroupTest::test_S_35_3_a_filled_cell_has_an_edit_control_and_the_edit_card_can_remove` — `wala o wala sa pagkakasunod: if (this.quoteForm.saving) return;`.

One more check that is not a test: one render of the CEO's table with suppliers was written to a scratch file
and read by a small node script — the inline script parses, 287 `<template>` tags open and 287 close, and all
133 Alpine expressions of the supplier group parse.

### The Part 1 review

Who: the `skeptic-reviewer` agent, adversarial depth, on `1c32cc1..675d601` (the Part 1 diff before the fix
wave). Verdict: no blocker, no major. The fix wave after it (commit b7d10fd) was not re-reviewed, since no
finding was a major; it is inside the diff the Part 2 review will read.

Spec axis

| Finding | Severity | Outcome |
|---|---|---|
| S-37.4 partial: "the marked items are the items in that list" was only proven as a rule, not on shared data; the two rules differ for a PO line with cost 0 or quantity 0 | minor | Fixed in part: `WorklistTest::test_S_37_4…` now compares the chip's list with the mark's rule on the same data. The two rare differences are the base's, kept by decision, recorded in `TODO.md` and proposed below |
| No test named S-37.4 at the feature level | minor | Fixed (the same test) |
| The script tag is in the body, not in `<head>` as the plan said | note | Recorded as a ruling |
| Unasked-for scope | none found | |

Correctness axis

| Finding | Severity | Outcome |
|---|---|---|
| `_table_suppliers.blade.php` (the form's Save): not disabled while a remove is in flight | minor | Fixed: one busy flag for Save and remove, pinned in `test_S_35_3` |
| The result file was still the blank template; no red-run lines | minor | Fixed: this file |
| `md5_file()` of a missing script file is an error for this view | minor | Accepted (`TODO.md`); named under Merge danger |
| `peso()` on 1e21, on −0.004 and on a non-numeric price | minor | Accepted (`TODO.md`): the server never sends them |
| The server's `cheapest` flag counts a quote whose supplier has no column | minor | Accepted (`TODO.md`) |
| `noSupplier` counts a PO row without a supplier id | minor | Accepted (`TODO.md`) |
| The static file is readable when logged out | minor | Accepted (`TODO.md`): logic only |
| Copied test helpers | minor | Accepted (`TODO.md`), proposed below |
| The "node not found" path had never been run | minor | Done once by hand (above) |

What the reviewer tried and found closed: a supplier name, a price or a new marker in a non-CEO render or
fetch (three viewers, three layout words, odd parameter forms); overwriting another supplier's quote from a
cell (the form's supplier always comes from the column; the save never sends a quote id); markup in a name, a
link, an order number or a date (text bindings only; links through the http guard); rows that do not add up to
the header (no answer, one failed fetch, empty list, 1 and 17 suppliers); the byte pins (the eight hashes and
the `_table_old` pin untouched; the two new hashes come from a commit that touches no product file).

Declined to judge by the reviewer: everything that needs a browser (focus after a save, card placement, the
header cut, row heights, the look with 17 suppliers, touch); the full suite; a line-by-line comparison of every
changed Then with the spec's table.

## Open questions

None.

## Process suggestions

- Time per task (wall clock of this machine): first commit 1 min; reading and the plan 4 min; task 1 (base
  pins, stories) 1 min; task 2 (endpoint) 1 min; task 3 (script, runner, node tests) 3 min; task 4 (the view,
  the pins, the stories) 10 min; the review 6 min; the fix wave and the records 3 min; the result 5 min.
- A helper script written through a shell here-document lost its backslashes and wrote junk into
  `qa/stories.md` once (caught at the next check, the file restored from the last commit). Evidence: the row
  check printed "Not run" after "rows 26". Suggestion for the spec template: "write helper scripts as files,
  not here-documents".
- The spec's S-42.4 names `/owner/column-settings`, which only redirects. Suggestion: name the section page.
- The kit names developer agents that are not installed in this clone. Suggestion: install them, or say in
  the start prompt that the main session builds.

## Proposed tasks

- One rule for "has a PO" in the chip and in the mark — today a free-sample line (cost 0) and a zero-quantity
  line are read differently by the worklist and by the PO-suppliers answer — normal.
- Keep a quote's note when the quote is edited from a form that has no note field — the save sets the note to
  nothing whenever the form sends none, and neither this table's form nor the base's has a note field (seen by
  the review; same at the base) — normal.
- `view_as[]=x` on `/item` — the review expects an error page, not a leak (not run; not in this diff) — low.
- Move the copied test helpers (`normalise()`, `supplier()`, `quote()`, `po()`) into the shared test cases — low.

## Suggested next steps

Run 2 on the word "continue": Part 2 as planned (tasks 7 to 12). Do not release Part 1 on its own.
