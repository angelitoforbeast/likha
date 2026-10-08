# Result: spec 017 Suppliers group on the item table

> Committed beside the spec: names no people and no decision ids.

**Status:** done (built, reviewed, full suite run; **nothing was seen in a browser**: every `browser` case and the three owner checks are open)
**Date:** 2026-10-08
**Branch / PR:** `feat/017-item-suppliers-group` (base `develop` at `90b900f`), no PR, not pushed
**Preview or run link:** n/a (this worktree cannot run the app: no environment file, no database)

## Summary

`/item?layout=suppliers` is a new view of the item page for the effective CEO view only. One server
boolean (`$layoutSuppliers`: the exact string and the effective CEO view) decides everything: the
table partial `_table_suppliers.blade.php` (a copy of the Old view's partial with a SUPPLIERS group
of four columns after Item), a style block and a script block that are rendered for this view
only, and one link each way in the toolbar. Any other request with `layout=suppliers` gets the Old
view byte for byte. The quotes endpoint now orders quotes cheapest first (no price and 0 last, ties
by id) in PHP and marks `cheapest`; the browser only takes the first three and counts the rest.
Details, add, edit and remove live in one card per cell, placed with `position:fixed`; the add /
edit form is the page's existing form and functions. `_table_old.blade.php` is not edited, and the
Old view and the default layout are pinned by hash to the base commit.

## Case table

All server tests are in `tests/Feature/Item/SuppliersGroupTest.php` unless a class is named. Final
run of that file: `OK (34 tests, 839 assertions)`. Kinds: **new** = red before the code;
**char** = characterisation (green before the code, pins today's behaviour); **absence** = asserts
something is not there; **text** = reads markup or script text and does not run the script.

| Case | Test | Kind | Result |
|---|---|---|---|
| S-13.1 | `test_S_13_1_header_has_two_rows_with_suppliers_over_four_plain_subheaders` | new, text | pass |
| S-13.2 | `test_S_13_2_item_row_has_four_supplier_cells_after_the_item_cell` | new, text | pass |
| S-13.3 | `test_S_13_3_item_cell_no_longer_holds_the_supplier_stack` | new, absence | pass |
| S-13.4 | `test_S_13_4_full_width_rows_and_total_span_four_more_columns` | new, text | pass |
| S-13.5 | `test_S_13_5_only_the_exact_string_suppliers_selects_the_suppliers_view` | new (errored on the missing view variable, not an assertion; two rows added one task later are char) | pass |
| S-13.6 | none: browser check | browser | not run |
| S-13.7 | owner check | owner | not run |
| S-14.1 | `test_S_14_1_quotes_come_back_cheapest_first` | green on sqlite before the code (sqlite already gives this order); centavo variant added later | pass |
| S-14.2 | `test_S_14_2_a_quote_without_a_price_is_last` | new | pass |
| S-14.3 | `test_S_14_3_equal_prices_keep_the_lower_id_first_every_time` | green on sqlite before the code | pass |
| S-14.4 | `test_S_14_4_a_zero_price_is_ordered_last_and_never_cheapest` | new | pass |
| S-14.5 | `test_S_14_5_every_quote_at_the_lowest_price_is_cheapest` | new | pass |
| S-14.6 | `test_S_14_6_no_cheapest_with_one_priced_quote_or_none` | new | pass |
| S-14.7 | `test_S_14_7_the_save_answer_is_already_in_the_new_order` | new (red on the missing flag; the order half was already green) | pass |
| S-14.8 | `test_S_14_8_script_helpers_take_the_first_three_and_count_the_rest` | new, text | pass |
| S-14.9 | `test_S_14_9_the_default_layout_and_the_photo_page_still_list_every_quote` | char | pass |
| S-14.10, S-14.11 | none: browser check | browser | not run |
| S-15.1 to S-15.9 | none: browser check | browser | not run |
| S-15.10 | `test_S_15_10_band_and_plus_cells_are_bound_to_the_loaded_state` | new, text | pass |
| S-16.1 | `test_S_16_1_every_new_control_stops_the_click_from_reaching_the_row` | new, text | pass |
| S-16.2 | `test_S_16_2_a_marketing_user_cannot_save_or_delete_a_quote` | char | pass |
| S-16.3 | `QuoteHistoryTest::test_changes_to_price_moq_or_link_write_the_old_values` (existing, unchanged) | char | pass (`OK (2 tests, 27 assertions)`) |
| S-16.4 | `test_S_16_4_deleting_a_removed_quote_again_answers_ok_with_the_current_list` | char | pass |
| S-16.5 | `test_S_16_5_validation_is_as_today_and_a_rejected_save_writes_nothing` | char | pass |
| S-16.6 | `test_S_16_6_the_form_opens_for_one_row_only` | new, text | pass |
| S-16.7, S-16.8 | none: browser check | browser | not run |
| S-17.1 | `test_S_17_1_the_suppliers_styles_are_rendered_only_for_the_suppliers_view` | new, text | pass |
| S-17.2 | `test_S_17_2_rts_del_int_is_one_line_on_item_rows_only` | new, text | pass |
| S-17.3 to S-17.6 | none: browser check (S-17.4 also has the text pin `test_S_17_4_an_open_form_is_not_closed_by_a_click_or_another_card`, which passes) | browser | not run |
| S-17.7 | owner check | owner | not run |
| S-18.1 | `test_S_18_1_non_ceo_views_get_the_old_view_with_no_suppliers_markers` | new, absence with a positive control | pass |
| S-18.2 | `test_S_18_2_the_script_does_not_call_the_loaders_for_those_requests` | absence (green from the start; the CEO control proves the markers) | pass |
| S-18.3 | `test_S_18_3_marketing_roles_get_empty_quote_and_supplier_lists` | char, absence | pass |
| S-18.4 | `test_S_18_4_marketing_renders_of_the_old_and_default_layout_are_identical_to_the_base` | char | pass |
| S-19.1 | `ItemPageTest::test_old_table_partial_is_byte_identical_to_the_base_commit` (existing, unchanged) | char | pass (`ItemPageTest`: `OK (45 tests, 943 assertions)`) |
| S-19.2 | `test_S_19_2_default_layout_for_the_ceo_is_identical_to_the_base` | char | pass |
| S-19.3 | `test_S_19_3_old_view_for_the_ceo_differs_only_by_the_toolbar_link` | new | pass |
| S-19.4 | `test_S_19_4_everything_the_old_table_has_is_present` | char (green from the start: the partial began as a byte copy) | pass |
| S-19.5 | `WorklistTest::test_ceo_gets_items_classified_into_the_four_lists` (existing, unchanged) | char | pass (`OK (3 tests, 26 assertions)`) |
| S-19.6 | `test_S_19_6_the_suppliers_files_use_no_x_html_and_no_inner_html` | new, absence | pass |
| S-19.7 | none: browser check | browser | not run |
| S-19.8 | owner check | owner | not run |
| S-20.1 | `test_S_20_1_supplier_values_are_bound_as_text_and_the_note_is_not_shown` | new, text, absence | pass |
| S-20.2 | `test_S_20_2_a_link_is_rendered_only_through_the_http_guard` | new, text | pass |
| S-20.3 | none: browser check | browser | not run |
| S-21.1 | `test_S_21_1_each_loader_is_called_once_and_the_new_templates_fetch_nothing` | new, text | pass |
| S-21.2 | none: browser check | browser | not run |

`qa/stories.md`: "Slice 017: 36 passed, 0 failed, 0 blocked, 0 skipped, 24 not run" (36 server
cases; 21 browser cases and 3 owner checks).

**Red lines, watched before the code of each slice**

| Slice (commit) | Test | First failure line |
|---|---|---|
| Quote order and flag (`2062382`) | `test_S_14_6_…` | `Failed asserting that an array has the key 'cheapest'.` (also red: S-14.2 order `Nil, Beta, Gamma, Acme`; S-14.4 order `Nil, Zero, Beta, Acme`; S-14.5, S-14.7 missing key) |
| Switch and gate (`9b85386`) | `test_S_19_3_…` | `Failed asserting that 0 is identical to 1.` (the link was not there; S-18.1: `Marketing: iba ang layout=suppliers sa layout=old`; S-13.5: `ErrorException: Undefined array key "layoutSuppliers"`) |
| Table structure (`347576f`) | `test_S_13_1_…`, `_13_2_`, `_13_3_` | `wala ang: <table class="spl-table">` |
| | `test_S_13_4_…` | `Failed asserting that 5 is identical to 0.` |
| | `test_S_14_8_…`, `test_S_15_10_…` | `Failed asserting that 0 is identical to 1.` |
| | `test_S_19_6_…`, `test_S_21_1_…` | `Failed asserting that file "…/_suppliers_js.blade.php" exists.` |
| Card and form (`7b87851`) | `test_S_16_1_…` | `Failed asserting that 10 is equal to 42 or is greater than 42.` (the literal was then corrected to the 40 controls that were built) |
| | `test_S_16_6_…` | the pinned open condition was not in the render |
| | `test_S_20_1_…` | `Failed asserting that an array has the key ':aria-label'.` |
| | `test_S_20_2_…` | `Failed asserting that an array is not empty.` |
| Fix 1 (`217d6f6`) | `test_S_17_4_…` | `…does not contain "if (mode !== 'form' && this.splCard.mode === 'form' && this.splLive()) return;"` |
| Fix 2 (`ab4f397`) | `test_S_17_4_…` | `wala ang: splForm(name, q, cell, el){` |
| Styles (`37aefb2`) | `test_S_17_1_…`, `test_S_17_2_…` | `Failed asserting that 0 is identical to 1.` |

Not watched red, said plainly: the Save button's `:disabled` pin in `test_S_17_4` (`df4d07f`) was
written with the one-attribute fix; the text it pins did not exist before, but I did not run it red.
Pins added to existing tests for text that already existed (the cell half of the form's open test,
`s.name` in the allow-list, the card's style line, the photo-popup guards, two rows of S-13.5) are
characterisation.

**What would turn the characterisation tests red:** S-18.4 / S-19.2: any byte reaching the default
layout or a non-CEO render (an indented Blade directive is enough). S-19.1: any edit of
`_table_old.blade.php`. S-14.9: the default layout's or the photo page's quote list being filtered,
or the endpoint dropping a quote. S-16.2 / S-18.3: a Marketing role getting past the gate or
receiving any quote field. S-16.4: a second delete erroring. S-16.5: a changed validation limit or
a rejected request writing. S-16.3 / S-19.5: a change to the quote history or the worklist's lists.

## Story changes

1. **S-13.2** — sentence added: the four columns are one cell spanning four for the placeholder,
   one spanning four for the red band, a loop over the three slots and the PO cell. Reason: the
   templating allows one root element per condition; the row always has four columns after Item
   (S-13.4 counts every row kind).
2. **S-17.4 and S-17.5** — sentence added: a card that holds the open add or edit form closes on
   Cancel, Esc or a successful save, not on a click or tap elsewhere. Built stricter in the same
   direction after review: while a form is open, or a save has not answered, another card or
   another form does not open either (Cancel, Esc or Save first). S-17.4's Test column also names
   the text pin.
3. **S-15.9** — the placeholder after a failed fetch is the visible grey text "hindi na-load"
   (title: the full sentence); while loading it is "…".

**Amendment 017-1, applied:** (1) prefix `spl-` / `spl`, every rule under `.spl-table`; (2) eight
hashes in the test-only commit `10b8451`, the first after "go", no fixture files; (3) `layoutOld`
stays true, Claude action columns hidden as in the Old view, labels "🏷 Suppliers view" and
"🗂 Old view" with the proposed titles, the address keeps `layout=suppliers`; (4) the form rule
above; (5) the placeholder texts; (6) the reading of "four cells"; (7) `view_as` as an array left
alone and listed under Proposed tasks, `layout[]=suppliers` has its row in S-13.5; (8) the review
rhythm. Both additions to "Done when" are in the checklist below.

## Done-when checklist

- [x] Every `server` case of S-13 to S-21 passes, each with a test named after it; every `browser`
      case is in `qa/stories.md` as "Not run · browser"; the owner checks are listed — case table
      above; `qa/stories.md` slice 017 and `## Owner checks` (S-13.7, S-17.7, S-19.8).
- [x] A red line for every feat slice, watched before the code; characterisation and absence cases
      reported as such — tables above. **One exception to "no test commit lands after the code it
      pins":** `b60f8c6` (`test:`) adds a centavo variant to S-14.1 after the sort was built, from
      a review finding; it is a characterisation variant and was green when written.
- [x] `git diff 90b900f --stat -- resources/views/item/_table_old.blade.php` is empty (run on
      `df4d07f`: no output); the existing hash test passes unedited (`ItemPageTest`:
      `OK (45 tests, 943 assertions)`; no existing test file is in the diff).
- [x] Default layout for the CEO and for Marketing, and the Old view for Marketing, identical to
      the base (S-18.4, S-19.2 pass against hashes taken on the base in `10b8451`); the Old view
      for the CEO differs by the one link only (S-19.3 passes).
- [x] `git grep -n "x-html" -- resources/views/item`: **four lines, all comments that were already
      there on the base** ("walang x-html" in `_agg_cells`, `_il_expand`, `_table_order`,
      `_table_sales`); no attribute use, and none in the new files. `innerHTML`: no line in the
      three new files.
- [x] `git diff 90b900f --stat` lists only allowed paths: `app/Http/Controllers/ItemController.php`,
      five files under `resources/views/item/` (`index`, `_agg_cells`, `_table_suppliers`,
      `_suppliers_js`, `_suppliers_style`; `_table_old` not among them),
      `tests/Feature/Item/SuppliersGroupTest.php`, `qa/stories.md`, `design/item-suppliers/` (six
      files), `TODO.md`, the spec, its result and its plan. `git diff 90b900f --stat -- routes
      composer.json composer.lock database`: no output.
- [x] `php.bat -l`: "No syntax errors detected" for `ItemController.php` and
      `SuppliersGroupTest.php` (the only changed PHP files).
- [x] Full suite, plain PHPUnit. Before (base `90b900f`): `Tests: 589, Assertions: 6575, Errors: 1,
      Failures: 1, Skipped: 3.` After (`df4d07f`): `Tests: 623, Assertions: 7417, Errors: 1,
      Failures: 1, Skipped: 3.` The same two red tests both times
      (`ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`,
      missing `storage/app/credentials.json`; `ExampleTest`, 302 instead of 200). 623 − 589 = the
      34 new tests. No new failure.
- [x] Adversarial review by a separate reviewing agent — see "Tests", review table.
- [x] What could not be checked without a browser, and what to look at first — section below.
- [x] The result is filled in.
- [x] (Amendment) Exact addresses and, per browser case, what to look at — section below.
- [x] (Amendment) `git diff --no-index --stat resources/views/item/_table_old.blade.php
      resources/views/item/_table_suppliers.blade.php`: **1 file changed, 243 insertions(+),
      81 deletions(−)**. The same command without `--stat` shows every line the copy changed.

## How to run

```
"C:/Users/Forbeast/.config/herd/bin/php.bat" "C:/Users/Forbeast/.config/herd/bin/composer.phar" install --no-interaction --working-dir=<this worktree>
"C:/Users/Forbeast/.config/herd/bin/php.bat" vendor/phpunit/phpunit/phpunit
"C:/Users/Forbeast/.config/herd/bin/php.bat" vendor/phpunit/phpunit/phpunit tests/Feature/Item/SuppliersGroupTest.php
```

In the app: `/item?layout=suppliers` as the CEO. No migration, no new setting, no asset build (the
page's styles and script are inline).

## Rulings

- Ruling: the design contract was copied unchanged — `brief.md` has no name of a person, assistant
  or decision id; the six files had the same sha1 as their sources — cost if wrong: a name is
  committed.
- Ruling: `layout` is exact after the framework's trimming: `layout=suppliers%20` selects the view,
  exactly as `layout=old%20` selects the Old view today — input trimming is global middleware and
  the gate is still the server boolean — cost if wrong: none for access; one more spelling works.
- Ruling: quotes are sorted in PHP inside `quoteRows()`, the query's `orderBy('price')` is left in
  place — the same result on sqlite, MySQL and PostgreSQL without relying on null or tie order —
  cost if wrong: one redundant `ORDER BY`.
- Ruling: a negative stored price (not possible through the form, `min:0`) is treated like 0: last
  and never cheapest — one rule, "a price above 0" — cost if wrong: none reachable.
- Ruling: a MOQ of 0 is shown as "MOQ 0" (the Old view hides it) — S-15.5 says a typed value shows;
  an empty field is stored as null and shows nothing — cost if wrong: one extra label on quotes
  with a typed 0.
- Ruling: while a form is open, or a save has not answered, no other card and no other form opens;
  the person presses Cancel, Esc or Save first — the approved rule is that typed values are never
  thrown away by a click, and the existing save function clears whichever form is open when its
  answer arrives — cost if wrong: one extra Cancel before adding a quote elsewhere (the Old view
  simply replaces the form).
- Ruling: the card opens on hover (devices that hover), and on click, tap, Enter or Space on the
  name, "+N" or PO name; it does not open on keyboard focus alone — one card that also holds the
  form must not open and close while tabbing through a row — cost if wrong: one key press more
  than the brief's `:focus-within`.
- Ruling: a hover card closes when the table scrolls; a pinned card and the form follow the cell on
  scroll and on window resize — a fixed card cannot scroll with its cell by itself — cost if
  wrong: a card to reopen after a scroll.
- Ruling: the "+N" list card shows name, price, MOQ, edit and remove per quote (the design's list
  card), not link, photo, earlier price or date — cost if wrong: for a fourth or later quote those
  four details are not visible in this view; listed under the owner check S-19.8.
- Ruling: Remove closes its card only when the list really got shorter (not when the confirm is
  declined or the delete fails) — cost if wrong: see `TODO.md`.
- Ruling: the Save buttons in the card are disabled while a save is in flight — a second answer
  would clear a form opened in between — cost if wrong: none.
- Ruling: the hover and focus handlers sit on an inner `div.spl-cell`; the supplier `td` has no
  padding and the div carries it — so the whole cell width hovers and the fixed inner width
  (117 px, PO 103 px) keeps a long name from widening the column — cost if wrong: a few pixels
  above and below the content do not hover in a taller row.
- Ruling: deviations from the comp's values, each small: the name beside "+N" gets
  `calc(100% − 30px)` (about 71 px, comp 78) so a two-digit chip fits; `min-width … !important` on
  the Page / Item headers because their copied inline `min-width` would win; the item name is
  clamped to three lines and has its full text in a `title`; the Item cells wrap; the one-line
  RTS / DEL / INT uses equal auto columns so one or two members also fill the cell; a 16 px mask
  to the left of the sticky cells; the card has a maximum height with its own scroll — cost if
  wrong: visual only, each one rule in `_suppliers_style.blade.php`.
- Ruling: page rows keep their current padding (the comp's `.pagerow` padding was not taken) — the
  spec says page rows keep their look — cost if wrong: 1 px of row height.
- Ruling: the copied comment beside the expanded block's colspan was corrected to match `+ 6` —
  a wrong number beside the code it explains — cost if wrong: none.
- Ruling: `test_S_13_3` was narrowed in the card commit: "dati" and the form's fields are forbidden
  in the item cell and the Item cell (what the case says), no longer in the whole row, because the
  card and form now live in the group's cells — cost if wrong: none; the case's Then is unchanged.
- Ruling: the developers and the reviewer ran as subagents of this session; the kit's developer
  agent types were not offered to this session, so general agents were told to read and follow
  `.claude/agents/backend-developer.md` / `frontend-developer.md`; `skeptic-reviewer` was used as
  is. The styles task (medium) was built on the stronger model because nothing could be seen in a
  browser — cost if wrong: none for the code.
- Ruling: the last re-check of the second fix loop was done inside the final adversarial pass, not
  as its own review — the pass was about to read the same files — cost if wrong: none found; the
  pass re-checked it first and it held.
- Ruling: throwaway tests were written, run and deleted, nothing committed: in the plan run (Blade
  rendering, render stability) and in the build (the rendered suppliers view's script passed
  `node --check`, exit 0, after the card, after each fix) — the page's script cannot be run here,
  a syntax check is the least that can be shown — cost if wrong: none.

## Deferred minors

Accepted with reasons in `TODO.md` under "017" (fourteen entries). The ones worth knowing first:

- The two list endpoints answer `ok: true` with empty lists when their query throws, so a failing
  query would show the red band, not "hindi na-load" (trusted path; endpoints kept as they are).
- The two toolbar links keep the current query on a plain click only, not on "open in new tab".
- Switching to the Marketing view from the suppliers view and back lands in the Old view.
- `:has()` is used for the "+N" clearance (Firefox before 121).
- The text-pin tests do not run JavaScript; three ordering tests pass on sqlite without the sort.

Fixed in the final wave instead of deferred: prices with centavos in S-14.1, a stale test comment,
a spacing slip in the copy, Save pressed twice.

## Merge danger

**Two-way door.** No migration, no route, no dependency, no setting, no data written differently.

- Blast radius for everyone who does not open `layout=suppliers`: (1) the CEO's Old view has one
  more toolbar link; (2) every reader of `/item/quotes` (default layout, Old view, photo page) gets
  the same quotes with a new `cheapest` field and a changed place only for quotes without a price,
  priced 0 or tied. Marketing views are byte for byte the base (hash tests).
- Blast radius of the new view: the CEO's session only. If the cells or the card misbehave in a
  real browser, the Old view is one click away and unchanged.
- The one thing to look at before the owner sees it: this was never drawn by a browser. A script
  error inside the suppliers-only block would break the suppliers view (not the others: the block
  is not rendered for them).
- Revert: revert the merge commit. Nothing persists.

## Not checked without a browser

Nothing here was drawn. The tests read markup and script text; the rendered script passed a syntax
check only. Alpine comes unpinned from the CDN (`alpinejs@3.x.x`), so template clean-up is as the
served version does it.

**Addresses to open (as the CEO unless said):**

1. `/item?layout=old` — the Old view; the toolbar has "🏷 Suppliers view" after "✨ New view".
   Click it: the address becomes `…layout=suppliers` with the range and chip kept.
2. `/item?layout=suppliers` — the suppliers view. After the first load the address still says
   `layout=suppliers`. "🗂 Old view" leads back with the query kept.
3. `/item?layout=suppliers&view_as=marketing` — must be the Old view as Marketing sees it: no
   SUPPLIERS header, no supplier name, no "Suppliers view" link; view source has no `spl-`.
   The same as a Marketing and a Marketing - OIC account with `/item?layout=suppliers`.

**Look at first (the likeliest to be wrong, in this order):**

1. The console on load of address 2: no script error; rows draw; the cells go from "…" to data.
2. Remove the third quote of an item with exactly three: slot 3 shows only "+", no leftover name.
3. Scroll down: the two header rows stay together (row 1 must be 22 px), SUPPLIERS over its four.
4. Scroll sideways at 1366: the item column stays, opaque on every row kind; nothing shows in the
   16 px strip to its left.
5. Open a card on the last rows and in the PO cell: not cut, above the header and TOTAL.
6. Type in the add form, then click another name, "+", the table, scroll: the form stays.

**Per browser case:**

| Case | What to look at |
|---|---|
| S-13.6 | Hide and reorder columns in column settings and by dragging: the group stays right after Item; header and body line up; TOTAL lines up |
| S-14.10 | An item with five quotes: three cheapest in order, "+2" on Supplier 3; exactly three quotes: no chip; the "+N" card lists all five in the same order |
| S-14.11 | An item with two PO suppliers: first name over its cost, "+1"; the PO card lists both |
| S-15.1 | No quote, no PO: one red cell across four, "wala pang supplier" once, the button inside |
| S-15.2 | PO only: three "+" cells, PO filled, no red band |
| S-15.3 | One quote: name over price and MOQ, two "+", dash in PO, price not underlined |
| S-15.4 | Three quotes and a PO: cheapest first, only the cheapest heavy and underlined (ties: both) |
| S-15.5 | No MOQ: price alone, no gap; no price: grey dash; price 0: "₱0.00", last, not marked |
| S-15.6 | A 120-character name; price 99999999 with MOQ 100000000: cut with "…", row height unchanged, nothing over the next cell; full values in the card |
| S-15.7 | No running page and no supplier: red "walang running page" in Item, orange row, one red band |
| S-15.8 | Expand an item: page rows have one empty cell under the group and their own three-line RTS / DEL / INT; widths do not jump; TOTAL last |
| S-15.9 | Block `/item/quotes` (or `/item/suppliers`) in the network tools and reload: every row shows grey "hindi na-load", no red band, no "+"; throttled: "…" until both answered |
| S-16.7 | "+" → save: the quote appears in the right cell, the form closes, chip counts refresh; edit a price so it moves cells; remove empties the cell |
| S-16.8 | Click every control (cell, name, "+", "+N", edit, remove, link, photo, Change, Copy, fields, file picker): page rows neither open nor close. Force a failed save: alert, form and values stay, cells unchanged |
| S-17.3 | Row height 49 to 51 px at 1366 × 768; a very long item name: at most three lines, about 60 px, column not wider. Check rows with two-line cells (cogs, DOI lead line, I-order) |
| S-17.4 | Hover a row and Tab into it: Change and Copy appear and work; Esc closes a card and returns focus; Esc on a form closes it |
| S-17.5 | Touch or 390 px: Change and Copy always visible; tap a name opens, tap elsewhere closes (check iPhone Safari: a tap on empty table area); no sticky column under 768 px; a tap elsewhere does not close a form |
| S-17.6 | Sideways scroll at 1366: sticky column opaque on item, hover, no-page, page, expanded, editing, per-page header and TOTAL rows, under the header corner; cards near the bottom and right edge |
| S-19.7 | Each chip in both views: same items and counts; column settings, sorting and header dragging for every existing column |
| S-20.3 | Quotes named `<img src=x onerror=alert(1)>`, with quotes, `&`, `<`, backslash, "Ñandú Trading 金龙", an emoji; links `javascript:alert(1)`, `data:text/html,x`, ` JaVaScRiPt:x`: shown as typed in cell, card, title; no alert; no link |
| S-21.2 | Network tab: hover, tap and "+N" send nothing; save and delete send the one POST and the worklist reload |

Owner checks (S-13.7, S-17.7, S-19.8): his real data and screen; for S-19.8 also the "+N" list
card's shorter rows (ruling above).

## Conflicts with CLAUDE.md

None in the spec or the amendment. Slips against the start prompt's command rule, each without
effect: one `cd <worktree> && …` of mine in the plan run, and one `cd /tmp && …` by a subagent in a
failed attempt to append the stories. The kit names `backend-developer` / `frontend-developer`
agents; they were not available as agent types in this session (see Rulings).

## Tests

`tests/Feature/Item/SuppliersGroupTest.php`: 34 tests, 839 assertions, green. Endpoint tests are
real requests on in-memory sqlite with literal expectations. Render tests read the rendered page
or the partial's source; they prove the markup and script text and do not run the script. Full
suite summaries: done-when list.

**Reviews** (all by the separate `skeptic-reviewer` agent; findings and what was done):

| After | Depth | Verdict | Findings → outcome |
|---|---|---|---|
| Quote order and flag | adversarial | approve | no major. Minors: three ordering tests cannot fail on sqlite (→ `TODO.md`), no centavo fixture (→ fixed, `b60f8c6`), stale comment (→ fixed) |
| Switch and gate | adversarial | pass | no major. Could not build a request that leaks. Minors: links drop the query on new-tab opens (→ `TODO.md`), Marketing toggle lands in the Old view (→ `TODO.md`), missing rows in S-13.5 (→ added) |
| Table structure | adversarial | pass | no major. Minors: endpoints answer `ok:true` after a swallowed error (→ `TODO.md`, proposed task), MOQ 0 (→ ruling), stale comment (→ fixed), vacuous markers in S-18.1 (→ one marker list with a positive control), missing pins for the price and MOQ rules (→ added in S-20.1) |
| Card and form | adversarial | request changes | **one major**: opening another card threw away an open form's typed values. No XSS, leak, extra request or click-through found. → fix loop 1 (`217d6f6`) |
| Fix loop 1, re-check | adversarial | one major left | the form openers ("+", ✎) still replaced an open form, and a save in flight cleared a form opened meanwhile → fix loop 2 (`ab4f397`), re-checked in the final pass: holds |
| Styles | standard | approve | no major. Minors: `:has()` (→ `TODO.md`), 71 px instead of 78 (→ ruling), page-row padding (→ ruling), a spacing slip (→ fixed) |
| Whole diff: role gate, endpoint, every place supplier text is printed | adversarial | no blocker, no major | gate: one server boolean, nine guarded blocks, one renderer of the view, no other include of the new partials; endpoint: order, flag, callers and non-CEO answers hold; text: only `x-text` and bound attributes, links only through `safeLink`, style string from numbers only. Minors: Save twice (→ fixed, `df4d07f`), list card's fewer fields (→ ruling and owner check), the rest → `TODO.md` |

Two fix loops in total, both on the same major; none open.

## Open questions

None. The eight questions of the plan run were answered by amendment 017-1 (applied, see "Story
changes").

## Process suggestions

- The kit's developer agents were not offered as agent types in this worktree session although
  their files are in `.claude/agents/`; only `skeptic-reviewer`, `story-writer` and
  `browser-checker` were. Evidence: the first developer call failed with "Agent type
  'backend-developer' not found".
- A review that is handed a diff range should also be handed the developer's red lines; three
  reviews "declined to judge" the red runs until they were pasted into the brief.
- For a view drawn in the browser, a script syntax check of the rendered page (`node --check`)
  would be a cheap standing test; here it was only a throwaway. It needs no dependency but does
  need Node on the machine that runs the suite.

## Proposed tasks

- Answer `ok: false` from `/item/quotes` and `/item/suppliers` when their query throws — the
  suppliers view would then say "hindi na-load" instead of "wala pang supplier" — normal.
- Guard `saveQuote()` against a second call while saving, for the Old view and the photo page too —
  a double click sends two POSTs — low.
- Reject or ignore a non-string `view_as` in `ItemController::index` — `view_as[]=x` errors today
  (read from the code, not run) — low.
- Pin the Alpine version loaded on `/item` — the page takes whatever `3.x.x` the CDN serves —
  normal.
- The comment on `tr.page-col-header > th` in the page's styles says it overrides the sticky header
  rule; those cells are in `tbody` and were never sticky — low.

## Suggested next steps

1. Open the three addresses on a preview and walk the "look at first" list, then the browser cases.
2. If the view draws, show it to the owner beside the Old view (owner checks S-13.7, S-17.7,
   S-19.8).
3. Replacing the Old view is a later spec, after he has used this one.

## Fix list 1

**Item 1: the sticky item column was not opaque over its full width when scrolled sideways.**
Fixed in `0f1de93`. Not seen in a browser.

**Cause, from reading.**

- The scroll area has side padding: `#scroll { flex:1; overflow:auto; padding:0 16px; … }`
  (`resources/views/item/index.blade.php:25`). The table's card has no side margin, so that padding
  is the only thing to the left of the first column.
- A sticky cell's `left:0` was measured inside that padding: the cells stayed where the table
  starts, 16 px in from the scroll area's edge. The strip to their left belongs to no cell, so the
  columns that scroll underneath pass through it on their way out.
- The only cover for that strip was `box-shadow:-16px 0 0 #f1f5f9` on each sticky cell
  (`_suppliers_style.blade.php`, the old lines 169 to 188): paint outside the cell's box, one per
  cell, not a box of its own. It was present on every row kind's rule, so a missing row kind is not
  the cause; the browser simply did not paint it over the whole strip. Why it left about 8 px
  cannot be told by reading (a cell's outer shadow in a table is not something layout guarantees),
  and the fix does not depend on the answer.
- This replaces the ruling above that mentions "a 16 px mask to the left of the sticky cells".

**The change** (two product files, suppliers view only, 768 px and wider only):

- `_table_suppliers.blade.php`: the scroll area of this view carries a class,
  `<div id="scroll" class="spl-scroll" …>`.
- `_suppliers_style.blade.php`, inside `@media (min-width:768px)`:
  `.spl-scroll { padding-left:0 !important; }` (the `!important` is there because the page's rule
  is an id selector and every rule of this file must be a `.spl-` selector). With no padding on
  the left, a sticky cell at `left:0` is at the scroll area's own edge: there is no strip, and the
  cover is the cell itself with its opaque background.
- Every `-16px` shadow is removed; the 1 px line at the column's right edge stays.
- Two backgrounds that were left to the page's own rules are now also written on the sticky cell:
  the editing row (`#eff6ff`) and TOTAL's first cell (`#f1f5f9`).
- Row kinds, each sticky at `left:0` with an opaque background: the header's corner cell (one
  cell with `rowspan="2"`, so it is the corner of both header rows; `#1e293b` from the page's
  `thead th`), item row and its hover, the no-running-page tint and its hover, page rows and their
  hover, the expanded page row, the editing row, the repeated per-page header, TOTAL.
- The expanded page block is one cell across all columns with no sticky part: it scrolls as a
  whole, as before. With the padding gone there is no strip beside it either; its own content does
  pass under the position of the item column, because it is not a column of the table.
- Visible side effect at 768 px and wider: the table now starts at the window's left edge (the
  16 px gap on the left is gone; the right one stays). Below 768 px nothing changes: the rule and
  all sticky rules are inside the media block. No other view carries the class or the rule.
- One small difference to know: on hover, TOTAL's first cell keeps `#f1f5f9` while the rest of the
  TOTAL row takes the page's hover colour.

**The test** (a text pin: it reads the stylesheet and the markup, it runs no browser):
`SuppliersGroupTest::test_S_17_6_the_sticky_item_column_sits_at_the_scroll_edge_and_is_opaque_on_every_row_kind`.
It pins the class on the scroll area (once, and not in the default layout or the Old view), the
rule's text inside the 768 px block and nowhere before it, that no `position:sticky` and no
`-16px` exists outside or anywhere, the exact sticky and background rule for each row kind listed
above, the page's own header colour and TOTAL rule it relies on, and that the two body rows with a
first-column cell carry the class. Red first: `Failed asserting that 0 is identical to 1.` (the
class was not on the scroll area). `qa/stories.md`: S-17.6 names the pin in its Test column and
stays "Not run · browser".

**Suite, plain PHPUnit, after the change (`0f1de93`):** `Tests: 624, Assertions: 7451, Errors: 1,
Failures: 1, Skipped: 3.` The same two red tests as on the base (`ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`
and `ExampleTest`); 624 = 623 + the one new test. `SuppliersGroupTest`: `OK (35 tests, 873
assertions)`; `ItemPageTest` (unedited, including the Old table's hash pin): `OK (45 tests, 943
assertions)`. The hash tests of the default layout and the Marketing renders (S-18.4, S-19.2,
S-19.3) pass unchanged; `_table_old.blade.php` and `index.blade.php` are not in this commit.

**How sure, and what to look at:** fairly sure, not certain: the strip can only exist where the
scroll area has padding and that padding is now zero, but I could not see it drawn; please scroll
sideways at 768 px and wider and look at the left edge on an item row, an expanded page (its row,
its repeated header and the block below), the header corner while also scrolled down, and the
TOTAL row, and confirm that the table starting flush at the window's left edge is acceptable.
