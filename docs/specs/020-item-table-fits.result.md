# Result: spec 020 The item table fits the screen, one column per supplier

> Committed beside the spec: names no people and no decision ids.

**Status:** done (not seen in a browser: the browser cases and one owner check are open, listed below)
**Date:** 2026-10-08
**Branch / PR:** `feat/020-item-table-fits` (no PR, no push; the reviewer reviews the branch)
**Preview or run link:** n/a (no dev server and no browser in either run)

## Summary

The CEO's table with suppliers (`/item?...&layout=old` or `layout=suppliers`) now has one column per supplier
of the Finance → Supply list, numbered by position in id order, each cell holding only that supplier's quote
(or its last PO cost, or a quiet plus), with a small warning mark for an item that has no supplier at all.
From 1280 px of window width the table is laid out to the measured width of its scroll box: a fixed-layout
table with one `<colgroup>`, the columns that fit by one set of minimum widths and one drop order, the rest
counted on a "+N columns" button whose panel lists them and lets one be brought back for the visit. Two
column sets ("Sourcing", "Sales") are remembered per browser, Prof.% is one column with a 1M / 7D / 3D / 1D
switch, and this table has its own money format. Everything that can be wrong without anyone seeing it is a
pure function in `public/js/item-table-fit.js`, run by the suite through `node` (52 tests, the fit table F1 to
F15 as data rows); the Alpine templates and the methods in `_suppliers_js.blade.php` only call them. A header
drag saves the whole catalog order with the away and hidden columns in place; nothing else writes the shared
column setting, and every other render is byte for byte the base.

## Case table

"node" = `tests/Feature/Item/ItemTableFitScriptTest.php` (pure functions through node), "group" =
`tests/Feature/Item/SuppliersGroupTest.php` (render and text pins), "columns" =
`tests/Feature/Item/SupplierColumnsTest.php` (endpoints). Last run of each file: node
`OK (52 tests, 940 assertions)`, group `OK (55 tests, 3139 assertions)`, columns `OK (4 tests, 24 assertions)`,
`WorklistTest` `OK (4 tests, 32 assertions)`, `SharedPagesBasePinTest` `OK (2 tests, 8 assertions)`,
`ItemPageTest` `OK (45 tests, 943 assertions)`.

### Part 1

| Case | Test | Result |
|---|---|---|
| S-34.1 | node `test_S_34_1_three_suppliers_give_three_numbered_columns_with_the_full_name`; group `test_S_34_1_layout_old_draws_one_header_per_supplier_of_the_list` | pass |
| S-34.2 | columns `test_S_34_2_a_supplier_added_on_the_supply_finance_page_is_in_the_quotes_answer_with_its_id`; node `test_S_34_2_a_fourth_supplier_is_the_fourth_column` | pass (the feature half is a characterisation: red if the quotes answer stops listing every supplier with its id) |
| S-34.3 | node `test_S_34_3_columns_are_numbered_by_position_in_id_order` (rows: numbers, strings) | pass |
| S-34.4 | node `test_S_34_4_the_header_is_the_number_and_the_first_word_and_the_title_the_full_name`; group `test_S_34_4_the_header_binds_the_name_as_text_and_cuts_a_long_word` | pass; the cut on screen is a browser case |
| S-34.5 | node `test_S_34_5_no_supplier_gives_no_column_and_a_group_that_is_never_zero_wide`; group `test_S_34_5_no_colspan_is_ever_zero_and_an_empty_list_links_to_the_supply_page` | pass |
| S-34.6 | node `test_S_34_6_a_quote_of_an_unknown_supplier_adds_no_column_and_no_warning` | pass |
| S-35.1 | node `test_S_35_1_a_cell_holds_only_that_suppliers_price_and_moq`; group `test_S_35_1_a_cell_shows_no_supplier_name` | pass |
| S-35.2 | node `test_S_35_2_the_plus_of_a_column_opens_the_form_on_that_supplier`; the existing save tests (`test_S_14_7`, `test_S_16_5`, `QuotePhotoTest`) | pass; browser half open |
| S-35.3 | group `test_S_35_3_a_filled_cell_has_an_edit_control_and_the_edit_card_can_remove` | pass; browser half open |
| S-35.4 | group `test_S_35_4_the_cell_form_has_no_supplier_control`; node `test_S_35_4_the_forms_supplier_is_always_the_columns_supplier` | pass |
| S-35.5 | node `test_S_35_5_a_missing_price_is_a_dash_a_zero_price_shows_and_a_missing_moq_is_left_out` | pass |
| S-35.6 | node `test_S_35_6_cheapest_is_the_servers_flag` (five rows); group `test_S_35_6_the_templates_read_the_servers_cheapest_flag_and_compare_no_price`; the existing `test_S_14_5`, `test_S_14_6` | pass |
| S-36.1 | node `test_S_36_1_a_quote_with_a_po_keeps_the_quote_price_and_gets_the_dot_and_the_po_line`; group `test_S_36_1_a_po_shows_as_a_dot_with_a_label_and_as_a_line_in_the_card` | pass; browser half open |
| S-36.2 | node `test_S_36_2_a_po_without_a_quote_shows_the_po_cost_with_a_tag_and_is_never_cheapest` | pass; browser half open |
| S-36.3 | node `test_S_36_3_no_po_or_a_zero_cost_line_gives_no_dot_and_no_tag`; columns `test_S_36_3_a_discount_or_zero_cost_line_is_not_a_po_of_that_supplier` | pass |
| S-36.4 | columns `test_S_36_4_po_rows_carry_the_supplier_id_and_keep_their_other_keys`; node `test_S_36_4_the_po_is_matched_to_its_column_by_supplier_id_not_by_name` | pass |
| S-36.5 | columns `test_S_36_5_the_po_row_of_a_supplier_is_its_latest_by_order_date_then_line` | pass |
| S-37.1, S-37.2 | node `test_S_37_1_S_37_2_S_37_4_the_warning_is_for_an_item_with_no_quote_and_no_po` (five rows); group `test_S_37_1_S_37_3_one_small_warning_mark_replaces_the_red_band_and_waits_for_both_lists` | pass; the look is a browser case |
| S-37.3 | group `test_S_37_1_S_37_3_…`, `test_S_15_10_the_cells_and_the_warning_are_bound_to_the_loaded_state` | pass |
| S-37.4 | `WorklistTest::test_S_37_4_the_items_of_the_need_a_supplier_list_are_the_items_with_no_quote_and_no_po_row`; the existing `WorklistTest::test_ceo_gets_items_classified_into_the_four_lists` | pass (characterisation of two existing rules on the same data) |
| S-37.5 | none | browser, not run |

### Part 2

| Case | Test | Result |
|---|---|---|
| S-38.1 | node `test_S_38_1_three_suppliers_and_the_sourcing_set_fit_at_four_widths` — rows **F1** (11 shown, spare 19, +12), **F2** (12, 29, +11), **F3** (14, 13, +9), **F4** (17, 193, +6) | pass, every row with the away list in order |
| S-38.2 | node `test_S_38_2_columns_leave_one_by_one_and_the_core_is_last` — rows **F5** (10, 23, +13), **F6** (8, 15, +15), **F7** (5: I-ORDER, DOI, ITEM VAL. (CEO), PROF.PROFIT, PROF.%; 97; +18) | pass |
| S-38.3 | node `test_S_38_3_an_exact_fit_fits_and_one_pixel_less_drops_one_more` — **F8** (6 shown, spare 0, used 1320) against **F7** | pass |
| S-38.4 | node `test_S_38_4_a_server_hidden_column_is_in_no_set_and_sales_excludes_the_stock_columns` — rows **F9** (11, 31, +11), **F10** (the 12 named columns, 7, +11), **F11** (17, 187, +6) | pass |
| S-38.5 | node `test_S_38_5_below_1280_everything_shows_and_scrolls_and_from_1280_the_fit_runs` — **F12** (scroll mode, 17, +6), **F13** (10, 9, +13); group `test_S_17_6_…` (the sticky rule only from 768 to 1279 px) | pass; 390 px is a browser case |
| S-38.6 | node `test_S_38_6_identity_and_supplier_columns_never_leave` — **F14** (0 shown, scrolls, +23 = all), **F15** (15, 11, +8), and the same answer for the columns written in reverse | pass |
| S-38.7 | none | browser, not run |
| S-38.8 | none | browser, not run |
| S-38.9 | group `test_S_38_9_the_rows_follow_the_fitted_columns_through_one_colgroup`, `test_S_13_4_S_15_8_every_row_spans_page_item_the_supplier_columns_and_the_other_columns`; node `test_S_38_9_the_widths_add_up_to_the_box_when_it_fits_and_to_the_minimums_when_it_scrolls` | pass; browser half open |
| S-38.10 | node `test_S_38_10_this_tables_money_format` (11 values); group `test_S_38_10_this_table_has_its_own_money_format_and_no_text_under_11px`; the existing byte pins | pass |
| S-38.11 | owner check, listed in `qa/stories.md` under `## Owner checks` | not run |
| S-39.1 | node `test_S_39_1_the_count_is_the_columns_away_by_the_fit_plus_the_ones_the_set_excludes`; group `test_S_39_1_S_39_5_the_columns_button_and_its_panel` | pass; browser half open |
| S-39.2 | node `test_S_39_2_a_column_that_fits_the_spare_width_is_turned_on_in_its_place` | pass |
| S-39.3 | node `test_S_39_3_a_column_wider_than_the_spare_width_is_refused_with_both_numbers` ("needs 56 px, 19 px free") | pass |
| S-39.4 | node `test_S_39_4_turning_a_shown_column_off_frees_its_width_for_the_refused_one` | pass |
| S-39.5 | group `test_S_39_1_S_39_5_the_columns_button_and_its_panel` (nothing stored, no request) | pass; the reload is a browser case |
| S-40.1 | node `test_S_40_1_the_set_opens_on_sourcing_and_a_stored_sales_is_read_back`; group `test_S_40_1_S_40_3_the_set_is_remembered_in_the_browser_and_never_written_to_the_server` | pass; browser half open |
| S-40.2 | node `test_S_40_2_a_junk_stored_set_opens_on_sourcing` (14 junk values) | pass |
| S-40.3 | group `test_S_40_1_S_40_3_…` (the stored setting row is unchanged after the page is served; the switch code holds no save) | pass |
| S-40.4 | node `test_S_40_4_a_set_only_chooses_the_columns_and_they_show_in_the_saved_order` | pass |
| S-40.5 | node `test_S_40_5_the_order_saved_after_a_drag_keeps_every_catalog_id_in_place`; group `test_S_40_5_S_19_7_a_drag_moves_shown_columns_only_and_saves_the_whole_order` | pass |
| S-41.1 | node `test_S_41_1_one_prof_pct_column_opens_on_1m_and_shows_that_periods_value`; group `test_S_41_1_S_41_2_prof_pct_is_one_column_with_a_period_switch` | pass |
| S-41.2 | node `test_S_41_2_another_period_changes_the_value_and_the_sort_field`; group `test_S_41_1_S_41_2_…` (the click stops; no request) | pass; browser half open |
| S-41.3 | node `test_S_41_3_the_switch_offers_only_the_visible_periods_and_four_ids_stay_four`; `SharedPagesBasePinTest` (the settings page unchanged) | pass |
| S-42.1 | the existing `ItemPageTest` pin of `_table_old.blade.php`; group `test_S_23_1`, `test_S_23_2` | pass, unedited |
| S-42.2 | the existing group `test_S_19_2_…`, `test_S_18_4_…` (the eight hashes) | pass, unedited |
| S-42.3 | group `test_S_24_1_non_ceo_views_get_the_base_old_view_for_every_layout_word` (52 markers, each with a positive control in the CEO render, plus tripwires), `test_S_24_2_the_script_does_not_call_the_loaders_for_non_ceo_views` | pass |
| S-42.4 | `SharedPagesBasePinTest::test_S_42_4_the_shared_owner_pages_render_byte_for_byte_as_at_the_base` (two rows; hashes from the test-only commit 2f327f6) | pass |
| S-42.5 | group `test_S_21_1_S_42_5_each_loader_is_called_once_and_only_the_drag_save_sends_a_request` | pass |
| S-42.6 | the existing tests of slices 017 and 019 that this spec does not replace (all green in the suite) | pass; browser half open |
| catalog coverage | node `test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order` (the 44 ids read from the controller's catalog) | pass |

Every row F1 to F15 was reproduced from the rule, the widths and the drop order of the spec; none was
adjusted.

## Story changes

Each in `qa/stories.md`, in place. Five entries of Part 1 and one of Part 2 (S-21.1) were not in the spec's
list; reading the file showed they name things this spec removes or changes (said in each line).

### Part 1

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

### Part 2

- **S-13.6** — changed. Old Then: "the group stays right after Item and header and body cells line up" New Then: "the group stays right after Item, and header and body cells line up because every row loops over the same fitted columns and one `<colgroup>` sets each width" Reason: The columns loop is the fitted set (spec 020).
- **S-13.7** — retired. Old Then: "he can compare prices at a glance and accepts the width" Reason: Retired: owner check folded into S-38.11 (spec 020).
- **S-17.3** — changed. Old Then: "the row is 49 to 51 px; a very long item name wraps to at most three lines, about 60 px, and the sticky column does not widen" New Then: "the row is about 50 px; a very long item name wraps to at most three lines, about 58 to 60 px, and the column does not widen" Reason: Row height about 50 px; three-line names about 58 to 60 px (spec 020).
- **S-17.5** — changed. Old Then: "Change and Copy are always visible, a tap on a supplier name opens its card and a tap elsewhere closes it; the item column is sticky only at 768 px and wider. A card that holds the open add or edit form closes on Cancel, Esc or a successful save, not on a click or tap elsewhere" New Then: "Change and Copy, the edit pencil and the "+" of a PO-only cell are always visible, a tap on a cell's value opens its card and a tap elsewhere closes it; the item column is sticky only from 768 px to 1279 px (from 1280 px the table fits and nothing scrolls sideways). A card that holds the open add or edit form closes on Cancel, Esc or a successful save, not on a click or tap elsewhere" Reason: At 1280 px and wider nothing scrolls sideways; the sticky item column applies below 1280 (spec 020).
- **S-17.6** — changed. Old Then: "the item column stays, opaque on every row kind and under the header corner; a card opened on the last rows or in the PO cell is not cut by the scroll area or hidden by the TOTAL row" New Then: "the item column stays, opaque on every row kind and under the header corner; at 1280 px and wider there is no sideways scroll and no sticky column; a card opened on the last rows is not cut by the scroll area or hidden by the TOTAL row" Reason: The sticky item column and its opaque strip apply below 1280; the pinned text follows (spec 020).
- **S-17.7** — retired. Old Then: "he agrees the table is as compact as his owner/private table" Reason: Retired: owner check folded into S-38.11 (spec 020).
- **S-19.4** — changed. Old Then: "everything the Old view's table has is present: the configurable columns loop, HOLD, the expand arrow and page rows, TOTAL, the toolbar, the sourcing chips with their handler, and the worklist's extra lines under the item name" New Then: "everything the Old view's table has is present: the configurable columns loop (now over the fitted columns), HOLD, the expand arrow and page rows, TOTAL, the toolbar, the sourcing chips with their handler, and the worklist's extra lines under the item name" Reason: The columns loop is the fitted set (spec 020).
- **S-19.7** — changed. Old Then: "the same items and counts show; column settings, sorting and header dragging work for every existing column" New Then: "the same items and counts show; column settings and sorting work for every shown column; header dragging works within the shown columns and saves the whole order with the away and hidden columns in place; Prof.% is one column with a period switch" Reason: Dragging works within the shown set; Prof.% is one column (spec 020).
- **S-19.8** — retired. Old Then: "he finds nothing missing compared with the Old view" Reason: Retired: owner check folded into S-38.11 (spec 020).
- **S-21.1** — changed. Old Then: "the quotes loader and the PO-suppliers loader are each called once at start-up as in the Old view, and the new templates and helpers contain no fetch of their own; the only addresses in them are the photo page link and the link to the Supply Finance page" New Then: "the quotes loader and the PO-suppliers loader are each called once at start-up as in the Old view; the table's templates contain no request; the view's script sends one request only, the save of the column order after a header drag; the panel, the two sets, the Prof.% period switch and the fit send none" Reason: Extended by S-42.5: the drag in this table now saves the order itself (S-40.5) (spec 020).
- **S-18.1** — changed again (Part 2). The "must not appear" list gains: the "+N columns" button, the set control and its browser key (`item_col_set_v1`), the fit and panel names, the new pure function names. The words "Sourcing" and "Sales" themselves are not in the list: both are on every view already (the chips label "Sourcing:", the tab "Sales & Profit"). Reason: the marker list gains the new names (spec 020).
- **S-24.1** — changed again (Part 2). The "must not appear" list gains: the "+N columns" button, the set control and its browser key (`item_col_set_v1`), the fit and panel names, the new pure function names. The words "Sourcing" and "Sales" themselves are not in the list: both are on every view already (the chips label "Sourcing:", the tab "Sales & Profit"). Reason: the marker list gains the new names (spec 020).
- **Owner checks** — S-13.7, S-17.7 and S-19.8 removed from `## Owner checks` and replaced by S-38.11. Reason: folded into S-38.11 (spec 020).

## Done-when checklist

Part 1
- [x] Cases S-34.1 to S-37.4 pass, each with a test named after it; S-37.5 and the browser halves are listed
      below; the guard cases S-42.1 to S-42.4 pass. Evidence: the case table and the suite line.
- [x] The changed and retired cases of slices 017 and 019 that Part 1 touches are updated in `qa/stories.md`
      and their tests, each under "Story changes".
- [x] The adversarial review of the Part 1 diff: under Tests.

Part 2
- [x] Cases S-38.1 to S-41.3 and S-42.5, S-42.6 pass, each with a test named after it, every row F1 to F15 a
      data row (named in the case table); the owner check S-38.11 is listed under `## Owner checks` in
      `qa/stories.md` (it replaces the three retired owner checks of slice 017).
- [x] A test asserts every catalog column id has a minimum width and a place in the drop order
      (`test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`).
- [x] The adversarial review of the Part 2 diff, which also read the Part 1 fix wave: under Tests. One major,
      fixed and re-checked.

Both
- [x] `git diff 1c32cc1 --stat` lists only: `app/Http/Controllers/ItemController.php`;
      `resources/views/item/_agg_cells.blade.php`, `_suppliers_js.blade.php`, `_suppliers_style.blade.php`,
      `_table_suppliers.blade.php`; `public/js/item-table-fit.js`; files under `tests/`; `design/item-no-scroll/`;
      `qa/stories.md`; `TODO.md`; the spec, the result, the plan. `routes/`, `composer.json`, `composer.lock`,
      `package.json`, migrations, `resources/views/owner/`, `index.blade.php` and `_table_old.blade.php`: no diff
      (checked with `git diff 1c32cc1 --stat --` on those paths: empty).
- [x] `php.bat -l` passes on every changed PHP file; `node --check public/js/item-table-fit.js` passes.
- [x] The full suite before and after: under Tests. No new failure.
- [x] What the reviewer must look at in a browser: below, with one sentence per part.
- [x] The result is filled in.

## How to run

From the repository root (PHP 8.4, Node 24 on the path; nothing is installed with npm):

- Install once: `php composer.phar install --no-interaction`
- The full suite: `php vendor/phpunit/phpunit/phpunit --configuration phpunit.xml`
- The node tests alone: the same with `tests/Feature/Item/ItemTableFitScriptTest.php`
  (add `--display-skipped` to see "skipped: node not found" when node is missing)
- The pins: the same with `tests/Feature/Item/SuppliersGroupTest.php`, `tests/Feature/Item/SupplierColumnsTest.php`,
  `tests/Feature/Item/WorklistTest.php`, `tests/Feature/OwnerPrivate/SharedPagesBasePinTest.php`
- The script's syntax: `node --check public/js/item-table-fit.js`

## Rulings

Part 2

- Ruling: the fit runs on the scroll box (`#scroll.spl-scroll`), measured as its `clientWidth` minus its own
  padding and the card's border, minus 1 px; it runs once from `x-init`, then from a `ResizeObserver` on that
  box (a plain window `resize` listener when the browser has no observer) and from a watcher on the supplier
  list — the scroll box is sized by the window, not by the table, so the table changing cannot resize it, with
  one exception: the vertical scrollbar appearing or leaving when row heights change. Three things stop a
  loop: the scrollbar's gutter is always reserved from 1280 px (`scrollbar-gutter:stable`); an unchanged width
  does nothing; and a width that flips straight back to the wider one it just left (within 0.6 s, same window
  width) keeps the narrower fit, which fits both. `fitRun` is called by those events only, never from an
  Alpine effect, so what it writes cannot re-run it — cost if wrong: on a browser without `scrollbar-gutter`
  (Safari before 18.2) the guard alone holds; if both failed the table would flicker between two fits.
- Ruling: spare width goes first to PAGE (up to 196 px), ITEM (up to 108 px) and the supplier columns (up to
  76 px each, all the same), and the rest is spread evenly over the shown columns (the odd pixels to the first
  ones); with no other column shown, the rest goes to PAGE. This is the spec's "plainest rule that gives the
  pictures": at 1920 every column is a few pixels wider than at 1366, as drawn, and no single column balloons
  (the alternative "the rest to DOI" would make DOI 215 px wide at 1920 with today's settings) — cost if wrong:
  one function (`widths`), covered by one test; at 1366 the 19 spare pixels go to PAGE, not to DOI as drawn.
- Ruling: the 1 px taken off the measured width means a real 1366 px screen with a 15 px scrollbar gives a box
  of 1318, not the spec's 1319: the same columns as F1 with 18 px spare — `clientWidth` is a rounded number, and
  half a pixel too much would bring back a sideways scrollbar — cost if wrong: one more column could have fitted
  at an exact boundary.
- Ruling: the page hands the fit the width of the supplier group in columns, which is never below 1 (the
  placeholder column needs a width); so with an answered, empty supplier list the page fits 13 columns, while
  the fit function itself gives the spec's F15 (15 columns) for a count of 0. Before the lists answer the same
  one column is assumed, so the table re-fits once when the supplier count arrives — cost if wrong: two columns
  fewer in a state the owner cannot be in (he has three suppliers).
- Ruling: S-38.6 "whatever order the set and drop lists are written in" is read as: the answer does not depend
  on the order the columns are handed in (the shown list follows that order, the away list and the spare do
  not change), and columns at the same drop rank leave in id order; the drop order itself is an order by
  definition — cost if wrong: a test row.
- Ruling: a column not in the drop list leaves first; an id that is not in the catalog has no width — cost if
  wrong: the coverage test fails first, by design.
- Ruling: what is chosen in the panel is an ordered list replayed after every fit (off frees its width, on is
  accepted only if it fits, nothing else moves); switching the set clears it; nothing of it is stored. A choice
  that no longer fits after a resize is simply away again — the spec says "lasts for the page visit" and
  "refused when it does not fit" — cost if wrong: one method.
- Ruling: the panel lists every column that is on offer and not shown, also the ones the set excludes, and any
  of them can be turned on when it fits; in scroll mode (below 1280 px) a turn-on is always accepted — cost if
  wrong: none seen.
- Ruling: the "Columns: [Sourcing | Sales] [+N columns]" control is its own strip at the top of the suppliers
  partial, right under the chips strip, not on it — the chips strip is in the shared `index.blade.php`, which
  keeps no diff this way — cost if wrong: one more 34 px strip; moving it onto the chips strip is a gated block
  in the shared file.
- Ruling: `_agg_cells.blade.php` (shared with the original table) takes the name of the money formatter as an
  include variable with the old name as default (`{{ $moneyFn ?? 'money' }}(`), 14 places — the item rows of
  this table are drawn by that shared partial, and the other views must stay byte for byte, which the pins
  prove — cost if wrong: none for the other views; revert is the 14 names.
- Ruling: in TOTAL the "₱2.27M" form with the full value in the title is used for the sums (ad spend and the
  four profit totals); per-order values and CPP use the table's format without "M" — they are never millions —
  cost if wrong: one argument per cell.
- Ruling: the whole `<thead>` is sticky instead of each header cell — headers now wrap to two or three lines
  in narrow columns, so the first header row has no fixed height for the second row to sit under — cost if
  wrong: in a browser that does not stick a `<thead>` the header scrolls away (current Chrome, Edge, Firefox
  and Safari do).
- Ruling: the 11 px floor for the small lines of cells shared with other views (inline 9 to 10.5 px) is set by
  four attribute selectors in the suppliers styles, for this table's own rows only — the shared partial is not
  edited for it — cost if wrong: a small line stays small.
- Ruling: header labels may break after "." and "/" and before "(" (a zero-width space), with
  `overflow-wrap:anywhere` as the last resort — the brief's two-line headers — cost if wrong: an odd break in a
  long header.
- Ruling: below 1280 px every column is at its minimum width and the table is as wide as their sum, so it
  scrolls sideways with the item column sticky (768 to 1279 px), but it is narrower than the base's table at
  those widths — "scrolls as today" is kept as behaviour, not as pixel widths, since the fixed widths were to
  go — cost if wrong: a wider scroll-mode width set.
- Ruling: the Prof.% period is not remembered across a reload (it opens on 1M, or on the first period the
  settings leave visible); when the table is sorted by Prof.%, the sort follows the period — cost if wrong: one
  stored key.
- Ruling: after a drag the members of a merged column (the four Prof.% ids, the three RTS ids) keep their own
  order among themselves in the saved order, and ids that are not in the catalog are dropped from what is
  saved — the other page shows those ids as separate columns, and the fallback order comes from the browser —
  cost if wrong: none seen; 20,000 random cases in the review lost or moved nothing.
- Ruling: the words "Sourcing" and "Sales" are not "must not appear" markers — both are on every view at the
  base (the chips label "Sourcing:", the tab "Sales & Profit"); the control's label, its browser key and the
  function names are the markers — cost if wrong: none.
- Ruling: the colour rules of the column settings page reach the merged Prof.% cell through the active
  period's own catalog id — found by the review as a major, fixed — cost if wrong: a rule on one period not
  showing.

Part 1 (accepted by the reviewer with run 1)

- Ruling: the script file is `public/js/item-table-fit.js`, loaded by one plain `<script src>` at the top of
  `_table_suppliers.blade.php` with `?v=` from the file's md5 — the head block's text is pinned by an existing
  test; a plain script in the body still runs before the deferred Alpine — cost if wrong: move the tag.
- Ruling: the file is a plain script that sets one name, `ItemTableFit`; the test runner loads it the way a
  page does (the repository's `package.json` is `"type": "module"`) — cost if wrong: the runner only.
- Ruling: the pure functions return the cell's texts ready to show — the same text is tested and shown.
- Ruling: remove sits in the edit form; the details card shows facts, photo, link and the "Last PO" line.
- Ruling: with no supplier, the link "Add a supplier in Finance → Supply" is the one sub-header of the group.
- Ruling: a form opened on another supplier's quote is treated as "add" for the column's supplier.
- Ruling: while a remove has not answered, Save and remove are both disabled through the form's `saving` flag.
- Ruling: `/owner/column-settings` is pinned through `/owner/column-settings/owner-private` (the address
  itself only redirects there).
- Ruling: the "skipped: node not found" path was proven once by hand, not by a permanent test.

## Catalog columns not named in the spec

All sixteen leave before PROMO, in catalog order. Fifteen are hidden in the owner's settings or forced hidden
on this table today; widths were chosen to hold the header and the content at 11 px.

| Drop position | Column (catalog id) | Minimum width |
|---|---|---|
| 1 | Orders (`orders`) | 56 |
| 2 | Proceed (`proceed`) | 64 |
| 3 | P.CPP (`pcpp`) | 56 |
| 4 | /Order (`per_order`) | 60 |
| 5 | NP/O (`np_per_order`) | 64 |
| 6 | NP/O(3D) (`np_per_order_3d`) | 64 |
| 7 | NP/O(7D) (`np_per_order_7d`) | 64 |
| 8 | Prof.Profit(3D) (`proj_prof_3d`) | 78 |
| 9 | Prof.Profit(7D) (`proj_prof_7d`) | 78 |
| 10 | Ship (`ship`) | 52 |
| 11 | COD Fee (`cod_fee`) | 64 |
| 12 | Claude Action (`claude_action`) | 96 |
| 13 | Claude Reason (`claude_reason`) | 120 |
| 14 | CEO Action (`ceo_action`) | 96 |
| 15 | CEO Reason (`ceo_reason`) | 120 |
| 16 | Category (`category`) | 88 |

PROMO is position 17 and PROF.% (the four ids as one) is the last, 39. The three RTS ids share position 23
and 104 px; the four Prof.% ids share 94 px.

## Deferred minors

All in `TODO.md` under "020", each with its reason. Part 1: the mark and the chip read a zero-cost or
zero-quantity PO line differently (the base's two rules); the `cheapest` flag counts a quote whose supplier has
no column; a PO row without a supplier id; the peso formatter and inputs the server never sends; the script's
address computed per render; the script file is public (logic only); copied test helpers; text pins do not run
the page's script; browser cases. Part 2: the empty-list fit (ruling above); no "profit / gross" tooltip on
the merged Prof.% cell of page rows and TOTAL; a failed or out-of-order save of the column order is silent, as
with the page's own save; the flip-flop guard can hold a narrower fit; TOTAL rounding just around a million;
the page-side methods are text-pinned and were run once outside the suite; the campaign rows of an expanded
page keep the page's money format; content wider than a minimum-width cell is cut; browser cases.

## Merge danger

Two-way door. Blast radius: only the CEO's table with suppliers; every other render is byte for byte the base
(the eight pinned hashes, the `_table_old` pin and the two shared-page pins pass unedited, also after the
change to the shared `_agg_cells.blade.php`). One thing reaches stored data: a header drag in this table saves
the shared column order (`owner_private_cols`, read by `/owner/private` too). It sends the whole catalog
order with only the shown columns re-placed, and the server keeps `hidden` and the per-role lists as before;
if it were wrong, the order is repaired on the column settings page (or "reset to default" there), and no
other field can be touched. The server change is additive (`supplier_id` on PO-suppliers rows). No migration,
no route, no setting key, no dependency. One new static file, `public/js/item-table-fit.js`, must reach the
server with the release: without it the table with suppliers answers with an error (the other layouts are not
affected). Revert: revert the branch's commits; the browser keys (`item_col_set_v1`, the order copy) are
ignored by the old code. Because none of this was seen in a browser, look at it once on a real screen before
the owner does.

## What the reviewer must look at in a browser

Not seen in a browser in either run. Addresses: the CEO view `/item?start_date=…&end_date=…&layout=old` (and
`&layout=suppliers`); the Marketing view: the same address as a Marketing user, and as the CEO with
`&view_as=marketing`. Widths: 1366, 1440, 1536 and 1920 (the fit), 1280 and 1279 (the switch to scrolling),
1024 (sticky item column), 390 × 844 (phone).

How sure the layout is without a browser — Part 1: the structure is sure (templates balanced, every Alpine
expression parses, rows and header add up in every state), the look is not (the pencil over a long price, the
dot, the card's place were written from the brief's numbers and never drawn). Part 2: the arithmetic is sure
(which columns show, their widths summing exactly to the box, the saved order, proven in node, and the page's
own methods run once in node against a stand-in for the browser), but whether real content fits its
minimum-width cell, whether the measured box is the real one, and whether the header sticks are unseen.

| Case | Look for |
|---|---|
| S-38.7 | At 1366, 1440, 1536, 1920: no sideways scrollbar; in the console `scroll.scrollWidth === scroll.clientWidth` for `#scroll`; no cell with cut content that matters (RTS / DEL / INT at 104 px, PROF.% with its switch at 94 px, DOI, LIFECYCLE, a ₱ amount with six digits) |
| S-38.8 | Drag the window from 1920 to 1366 and back: the columns and "+N" follow each time, no flicker between two layouts; on load the table re-fits once when the supplier columns arrive |
| S-38.5 | At 1279: every set column, sideways scroll, item column sticky and opaque; at 1280: fits; at 390 × 844: scrolls both ways, panel usable |
| S-38.9 | Expand an item, expand a page: page rows, the repeated page header, the expanded block and TOTAL line up with the header at each width |
| S-38.10 | TOTAL: "₱2.27M"-style sums with the full value on hover; amounts from ₱100,000 without centavos; negatives "−₱…"; no text smaller than 11 px in the rows |
| S-39.1 | "+12 columns" at 1366 (with today's settings); the panel lists the 12 with widths, the shown ones, "N of M px used", the Column settings link, the Supply Finance link; Esc and a click outside close it |
| S-39.2 to S-39.4 | In the panel: a column that does not fit shows "…: needs 56 px, 19 px free" and nothing moves; hide ADSPENT, then ORDERS (1D) is accepted |
| S-39.5 | Reload: the panel's choices are gone; no request in the network tab while using the panel |
| S-40.1 | Fresh browser: "Sourcing"; tap "Sales", reload: still "Sales" |
| S-41.1, S-41.2 | One PROF.% header with 1M 7D 3D 1D; tap 7D: cells change, no sort, no request; sort by the column, switch period: the order follows; a colour rule on Prof.% from the column settings still colours page rows |
| S-40.5, S-19.7 | Drag a header at 1366; reload: the order holds; open `/owner/private`: its columns are where they were, apart from the two that swapped |
| Header | Scroll down: the two header rows stay at the top; long headers wrap to two lines |
| S-34.1 to S-37.5 (Part 1) | Three headers "1 · KELLY", "2 · ALBEE", "3 · HELEN" with the full name on hover; cells with only price and MOQ; "+" opens a form headed with the supplier, no dropdown; pencil on hover, focus and tap; "✕ Tanggalin" inside the form; green dot and "Last PO …" line; a PO-only cell in green with "PO"; one "!" mark for an item with no supplier, gone after its first quote, with the chip count one lower |
| S-42.6 | Add, edit, remove a quote with link and photo; the five chips; Change and Copy; "walang running page"; the two layout links keep the date range |
| Marketing | No SUPPLIERS group, no "Columns:" strip, no mark: the page as before |

## Conflicts with CLAUDE.md

- The kit names `backend-developer` and `frontend-developer` agents for the build; this session has no such
  agent types, so the main session wrote the code and `skeptic-reviewer` reviewed it (three review runs).
- Red before green: the pure functions of both parts and both review fixes of Part 2 were red first (lines
  under Tests). The view changes of task 4 (Part 1) and tasks 8 to 10 (Part 2) were made first, the existing
  pins went red (12 and 16 tests), and the pins were then rewritten and the new ones added; the new pin tests
  were not seen red on their own.
- The plan had tasks 8, 9 and 10 as three commits; they are one commit (b9f5251), because all three edit the
  same partials through one scripted change. Task 7 and the two fixes are their own commits.

## Tests

Full suite, plain PHPUnit:
- Before (the base 1c32cc1 in this worktree): `Tests: 631, Assertions: 8375, Errors: 1, Failures: 1, Skipped: 3.`
  The error is `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` (needs
  an untracked file); the failure is `ExampleTest::test_the_application_returns_a_successful_response`.
- After Part 1 (b7d10fd): `Tests: 670, Assertions: 8986, Errors: 1, Failures: 1, Skipped: 3.`
- After Part 2 (6f7a007): `Tests: 703, Assertions: 10695, Errors: 1, Failures: 1, Skipped: 3.` The same one error and one failure; no new one.
- After the merge with the base: the local branch develop (4c574f3, with spec 018) merged into this branch as b5ceef7. Conflicts only in `qa/stories.md` and `TODO.md`, where each side had appended its own section: both kept, develop's first (slice 018, the 018 section), then slice 020 and the 020 section; nothing else changed in either file, and the cases of slices 017 and 019 this spec changed stand as changed. No other file conflicted.
- After the merge with the base: the full suite, plain PHPUnit with the worktree's configuration: `Tests: 822, Assertions: 12969, Errors: 1, Failures: 1, Skipped: 3.` That is develop's 750 plus this branch's 72; the error is `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` and the failure is `ExampleTest::test_the_application_returns_a_successful_response`, as on the base; no new one.
- After the merge with the base: node tests 52 run, 0 skipped; the three skips are the three data rows of `BoardroomLiveSmokeTest::test_live_provider_answers_a_tiny_request`, as on the base.
- Node tests: 52 run, 0 skipped (node v24.13.1). The three skips of the suite are the base's. The skip path
  was proven once by hand in run 1: `Tests: 25, Assertions: 0, Skipped: 25.` with `skipped: node not found`.

Red runs:
- Part 1, task 2: `SupplierColumnsTest::test_S_36_4_…` — `ErrorException: Undefined array key "supplier_id"`.
- Part 1, task 3: `ItemTableFitScriptTest::test_S_34_1_…` — `Error: Cannot find module '…/public/js/item-table-fit.js'`.
- Part 1, fix: `SuppliersGroupTest::test_S_35_3_…` — `wala o wala sa pagkakasunod: if (this.quoteForm.saving) return;`.
- Part 2, task 7: `ItemTableFitScriptTest::test_S_38_1_… with data set "F1 1366"` — `layout: no function: layout`;
  then for merged columns in the saved order: `test_S_40_5_…` and `test_S_41_3_…` failing (`Tests: 52, Assertions: 918, Failures: 2.`).
- Part 2, tasks 8 to 10: 16 existing tests of `SuppliersGroupTest` red after the view change
  (`Tests: 49, Assertions: 1839, Failures: 16.`), then rewritten; see Conflicts.
- Part 2, fix 1: `ItemTableFitScriptTest::test_S_41_2_…` — `periodId: no function: periodId`; and
  `SuppliersGroupTest::test_S_41_1_S_41_2_…` — `Failed asserting that 0 is identical to 1.`
- Part 2, fix 2: `ItemTableFitScriptTest::test_S_40_5_…` — `Failed asserting that actual size 46 matches expected size 44.`

One check that is not a test, repeated after the last fix: a render of the CEO's table with suppliers written
to a scratch file and read by a node script — the inline script parses, 298 `<template>` tags open and 298
close, all 792 Alpine expressions of the bar and the table parse, every one of the 27 methods the table calls
exists on the page's component; then the component's own fit methods run against a stand-in for the browser:
with the default catalog and nothing hidden (35 columns after the two merges), three suppliers and a 1366 px window
with a 15 px scrollbar: box 1318, fit mode, 11 columns shown, "+24", 18 px spare, table 1318 px; "Sales": 12 shown,
6 px spare, and the browser key holds "Sales"; a junk set name: back to "Sourcing"; resized to 1920: box 1872,
19 shown, 40 px spare, table 1872 px; turning on Orders there: refused with "Orders: needs 56 px, 40 px free" and
nothing moved; the period switch to 7D: the column sorts by `proj_pct_last_7d`; a drag of the last shown
column to the front: one request, to the column settings save route, with 44 ids, all different, and the
dragged column first.

### The Part 2 review

Who: the `skeptic-reviewer` agent, adversarial depth, on `3267c74..b9f5251` plus the Part 1 fix wave b7d10fd.
One loop: the major was fixed (e04d021) and re-checked by the same kind of agent, scoped to the fix: "fixed",
no new finding. The second fix (6f7a007, a minor on an untrusted path) was not re-reviewed.

Spec axis

| Finding | Severity | Outcome |
|---|---|---|
| S-41.1 partial: on page rows the conditional-format rules of the four Prof.% ids were looked up under the merged column's id and never matched | major (the owner has a colour rule on a Prof.% period; in this table the page rows lose it) | Fixed test-first: the rule is looked up by the active period's catalog id (`fitRuleCol`, `periodId`); re-checked: fixed |
| F15 holds for the function, not for the page (the page passes a group width of at least 1) | minor | Ruling above; `TODO.md` |
| The result file was not filled in for Part 2 | minor | Fixed: this file |
| The merged Prof.% cell on page rows and TOTAL lost its "profit / gross" tooltip | minor | Accepted (`TODO.md`), proposed below |
| Unasked-for scope | none found | |

Correctness axis

| Finding | Severity | Outcome |
|---|---|---|
| No test that a rule on a Prof.% id reaches the merged cell | major | Fixed with the above (a node row and a pin) |
| Unknown strings from the browser's fallback order were kept in the posted order | minor, untrusted path | Fixed test-first: only catalog ids are saved |
| A failed order save is silent; two fast drags are unordered | minor | Accepted (`TODO.md`): same as the page's own save |
| The flip-flop guard also ignores a real widening within 0.6 s | minor | Accepted (`TODO.md`) |
| TOTAL: 999,999.50 shows "₱1,000,000"; 1,004,999 shows "₱1M" | minor | Accepted (`TODO.md`) |
| b7d10fd: if the delete request hangs, the form's buttons stay inert | minor | Accepted: as with the existing save |
| `WorklistTest::test_S_37_4…` re-implements the rule in PHP | minor | Accepted: it compares the two answers the page reads; the rule itself runs in node |
| The page-side methods (`fitSaveOrder` and the others) are only text-pinned | minor | Accepted (`TODO.md`); run once outside the suite (above) |
| No red-run lines for Part 2 were handed over | minor | Fixed: above |
| Outside the diff: the page's own `initCols` throws on a stored fallback order that is not a list | n/a | Proposed below |

What the reviewer tried and found closed: 20,000 random drags through `orderToSave` (saved orders full,
partial, empty, duplicated, junk; both sets; random hidden columns) — no catalog id lost, duplicated or moved
unless shown; the stored set name (only the two exact names pass; it reaches a comparison and a text binding
only); the gate (the three partials only behind `layoutSuppliers`; the formatter default of `_agg_cells` cannot
be replaced from another view; the pins unedited); 166,464 layouts (every box from 0 to 2600 px, eight supplier
counts, both sets, four window widths) — widths always sum to the box in fit mode, no zero or negative
column, no negative spare; F1 recomputed by hand; the resize loop (no reactive loop found; reasoned, not
executed); the busy flag of b7d10fd (cannot stick).

Declined to judge by the reviewer: everything that needs a browser (S-38.7, S-38.8, the measured box, content
wider than a minimum cell, the sticky column, the phone, the panel's focus); the Alpine methods as executed
behaviour; whether the owner has colour rules on Prof.% in production; `qa/stories.md` wording and the styles
line by line.

### The Part 1 review

Who: the `skeptic-reviewer` agent, adversarial depth, on `1c32cc1..675d601`. No blocker, no major. Fixed after
it (b7d10fd, now read by the Part 2 review): Save is blocked while a remove is in flight; S-37.4 got a test on
shared data. Accepted with reasons in `TODO.md`: the minors listed under Deferred minors. Tried and found
closed: a supplier name, a price or a new marker in a non-CEO render or fetch; overwriting another supplier's
quote from a cell; markup in a name, link, order number or date; rows that do not add up; the byte pins.

## Fix list 1 (after the first look in a browser)

Three points from the first look at the released table (desktop Chrome, a window about 2,134 px wide, a box of
2,082 px, three suppliers, the Sourcing set). Not seen in a browser by this work either: each cause was found
by reading and by running the page's own component in node (`tests/js/page.cjs`, new: it loads a render of the
table and the pure functions, stands in for the scroll box, the window, `ResizeObserver`, `$watch` and the
clock, runs steps and returns the fit state and the values of the markup's own bindings).

### 1. DOI text clipped

- Cause: three things together. The page's cells are `white-space:nowrap` (the shared `td` rule); this table
  hides what overflows a cell (`overflow:hidden`, added with the fixed layout); and the column is narrower than
  its text. "kulang 21.1 araw" is about 105 px at the row's 12.5 px bold, against a text width of 81 px at the
  box seen (the column was 94 px there: its 74 px minimum plus its share of the spare, 20 px) and 61 px at the
  minimum. So the spare rule did reach the cell; it was not enough, and nothing let the text wrap. The grey
  lead line was made worse by this work: the 11 px floor lifted it from 9 px to 11 px (about 95 px).
- Change (`_suppliers_style.blade.php`, and one class in `_agg_cells.blade.php` behind its existing
  `rdtOneLine` gate): every cell of this table's own rows wraps its text (`white-space:normal;
  overflow-wrap:anywhere`), also content that carries its own `nowrap`; item rows are 12 px (the brief's size);
  the DOI lead line is shown on row hover and keyboard focus, always on touch, and while its editor is open, as
  the brief says. The hidden overflow stays as the last guard for what cannot wrap (an input, a picture).
- The same pattern in the other columns, found by reading (all `nowrap` plus hidden overflow before the fix):
  LIFECYCLE's badge (its own inline `nowrap`; "Phasing out · lugi" is about 125 px against 85 at the minimum);
  I-ORDER's second line (the order-by date line at 11 px against 55 px); STOCK's "bilangin" (about 45 px
  against 39 px); the money columns at the minimum width, estimated from the font's digit widths: "₱99,999.99"
  about 64 px against 63 px in ADSPENT, and a five-digit loss "−₱99,999.99" about 72 px against 67 px in
  PROF.PROFIT and 65 px in PROF.PROFIT(1D) (at 12 px these are about 61 and 69 px; amounts from ₱100,000 have
  no centavos and fit); on page rows PROMO, ACTION and the SET RTS% note. PAPARATING, BENTA/ARAW, HOLD, TCPR,
  CPP and RTS / DEL / INT hold numbers that fit their minimum. All of them now wrap instead of being cut; a
  number with no space that is still too wide breaks onto a second line. Two cuts remain on purpose, each by its
  own case: a supplier header's long first word (S-34.4) and a supplier price too long for its cell (S-15.6),
  both with an ellipsis and the full value on hover or in the card; an item name is still held to three lines.
- Test: `SuppliersGroupTest::test_S_38_7_a_cell_wraps_its_text_instead_of_clipping_it` (the rules, the class
  only in this table, and the DOI width of 94 px at a 2,084 px box from the pure functions). First red line:
  `Failed asserting that '{{-- Styles ng suppliers view lang. …' contains ".spl-table > tbody > tr:not(.page-expand-row) > td { white-space:normal; overflow-wrap:anywhere; }"`.
- How sure: that text wraps instead of being cut is sure from the CSS. Unseen: how the wrapped cells look.
  Look at: DOI at 1366 ("kulang" over "N araw", as in the picture) and at the wide window (one or two lines);
  a row gets one line taller while it is hovered (the lead line appears); LIFECYCLE with "· lugi"; a five-digit
  amount with centavos in ADSPENT and a five-digit loss in PROF.PROFIT at 1366 (should stay on one line; if it
  breaks, those two minimums need 4 to 6 px more, which is the spec's to change).

### 2. "+8 columns" on the button, "One step away (6)" in the panel

- Cause: not a counting error. The button and the panel's heading read the same field of one fit, so they
  cannot differ at one moment, and with a setting like the owner's (twelve columns hidden by the setting, the
  four text columns forced hidden) the page's own component gives "+6", six listed, seventeen shown at a
  2,084 px box, with none of the sixteen hidden columns in either list (now a test). With those settings and
  three suppliers, "+8" is the answer of exactly one situation: a fit for a box of 1,524 to 1,575 px (two
  columns away by the fit plus the six of the set). So the button was read from a fit for a narrower box and
  the panel from a current one: this is point 3 seen from the other side.
- Change (`_table_suppliers.blade.php`): the button's number and the panel's heading are now the length of
  the very list the panel draws (`fitRes.away.length`), so number and list are one thing by construction.
- Test: `SuppliersGroupTest::test_S_39_1_the_button_and_the_panel_count_the_same_columns_under_a_setting_that_hides_both_kinds`
  (the page component on a render with that setting: at 2,134 "+6" and 17, at 1366 "+12" and 11, in Sales "+11"
  and 12; at every step the button, the heading and the list agree and no hidden column is counted). First red
  line: `Failed asserting that 0 is identical to 1.` (the markup pin; the numbers already agreed).
- How sure: sure that the count is the spec's number on such a setting; the reading of "+8" as a stale fit is
  an inference from the numbers, not something seen.

### 3. The table in the left 80 per cent after the panel was opened

- What reading gives: opening or closing the panel cannot change the measured width. The panel is absolutely
  positioned inside the bar, which is outside the measured scroll box; that box takes its width from the page
  body (a column flex container as wide as the window), not from its content; its vertical scrollbar's space is
  reserved from 1280 px; a column turned on or off for the visit changes which columns show, never the sum of
  the widths. The fit is not kept "for the panel" either.
- A real fault was found beside it. The guard against a flip-flopping width ignored a widening back to the
  width just left when it came within 0.6 s and the window width was unchanged, and nothing measured again
  afterwards. The fit of the narrower box then stayed: fewer columns, a bigger "+N", and a table drawn at the
  narrower width with blank space to its right. Run on the code before the fix: a box that goes 2,084 → 1,552 →
  2,084 within a tenth of a second ends with `box 1552, shown 15, table 1552px` in a 2,084 px box (74 per cent),
  and with the owner's settings the button reads "+8". What made the box change twice in the reviewer's session
  is not known (the browser tool is a candidate); the fault is real either way.
- Change (`_suppliers_js.blade.php`, `_table_suppliers.blade.php`): every width change is followed, also a
  return to the width just left; only three such returns in a row within 0.6 s count as a flip-flop, which then
  holds the narrower fit and measures again once after 1.5 s, at most twice, so it still cannot loop. A fitted
  table is `width:100%` of its box (the colgroup widths are the shares), so it cannot be drawn narrower than
  the box even on a stale measurement; a scrolling table keeps its pixel width. The box is measured again on
  every window resize and on every click of the panel's button (no change, nothing happens).
- Test: `SuppliersGroupTest::test_S_38_8_every_width_change_is_followed_and_the_panel_changes_no_width` (the
  page component: a page hidden at the first measure, the panel opened and closed with nothing changing, the
  narrow-and-back sequence, a narrow window, ten flips in a row and the one re-measure). First red line:
  `node: … ReferenceError: fitCheck is not defined`; the stale fit itself, on the old code, is the line quoted
  above.
- How sure: sure that a stale narrower fit can no longer stay and that a fitted table fills its box. Not sure
  that this was what the reviewer saw. Look at: open and close the panel several times at the wide window (the
  table keeps the full width, "+6"); drag the window narrower and wider quickly; if the blank space shows
  again, note what the panel's line says (pixels used of how many) at that moment.

### Suite after the fix list

Plain PHPUnit, the worktree's configuration: `Tests: 825, Assertions: 13137, Errors: 1, Failures: 1, Skipped: 3.`
The error and the failure are the base's two (`ImportStartTest`, `ExampleTest`); the three skips are the three
rows of `BoardroomLiveSmokeTest`. Node tests: the 52 of `ItemTableFitScriptTest` and the three new tests of
`SuppliersGroupTest` that run node all ran, none skipped. The pinned hashes of the other renders (the eight in
`SuppliersGroupTest`, the `_table_old` pin, the two shared pages) are unedited and green; `_agg_cells.blade.php`
changed by one gated class. Commits: cbad3da (point 1), 51f1aeb (point 2), 3d708af (point 3).

## Open questions

None.

## Process suggestions

- Time per task (this machine's clock). Run 1: first commit 1 min; reading and plan 4 min; task 1 1 min;
  task 2 1 min; task 3 3 min; task 4 10 min; review 6 min; fixes and records 3 min; result 5 min. Run 2:
  reading and design 4 min; task 7 (the pure functions and the fit table) 5 min; tasks 8 to 10 (the view, the
  pins, the stories) 12 min; the review 6 min; the two fixes and the re-check 5 min; the suite and the result
  8 min.
- A here-document lost its backslashes once in run 1; in run 2 every helper script was a file. One snippet
  still failed on a wrong anchor and one on a wrong occurrence count, both caught by the script's own check
  before writing. Suggestion: keep "helper scripts as files, each edit asserting how many times its anchor
  occurs" in the start prompt.
- The review found the one major by asking "who else reads the ids I merged". Suggestion for the spec
  template, for any spec that merges or renames ids: "list every reader of the old ids".
- The spec's S-42.3 lists "Sourcing" and "Sales" as words that must not appear for non-CEO viewers; both are on
  every view at the base. Suggestion: name the control, not the words.
- The spec's fit table gives the box as viewport − 47; a real box minus the 1 px safety is one less. Suggestion:
  say in the spec whether the safety pixel is wanted.

## Proposed tasks

- A browser test for this table (one headless run at four widths asserting `scrollWidth === clientWidth` and
  the "+N" count) — the layout is the one thing the suite cannot see — high.
- A tooltip for the merged Prof.% cell on page rows and TOTAL ("profit / gross" for the active period) — lost
  with the merge — normal.
- Tell the user when a column-order save fails, and send drags in order — silent today in both this table and
  the page's own save — normal.
- One rule for "has a PO" in the chip and in the mark (a free-sample line and a zero-quantity line are read
  differently) — normal.
- Keep a quote's note when the quote is edited from a form that has no note field (same at the base) — normal.
- The page's own `initCols` throws when the browser's stored fallback order is not a list and the server order
  is empty (seen by the review, outside this diff) — low.
- `view_as[]=x` on `/item`: the first review expects an error page, not a leak (not run; outside this diff) — low.
- Move the copied test helpers into the shared test cases — low.

## Suggested next steps

The reviewer's branch review, then a look in a browser at 1366 and 1920 with real data before the release;
the owner check S-38.11 after the release.
