# Result: spec 019 The Old view shows the suppliers table

**Status:** done
**Date:** 2026-10-08
**Branch / PR:** `feat/019-old-view-suppliers-table` (base `develop` at `5370bdc`); no PR and no push, as the spec says
**Preview or run link:** n/a (not run in a browser; see "Tests")

## Summary

For the CEO view, `/item?layout=old` now renders the suppliers table of spec 017, and
`layout=suppliers` renders the same bytes while the page keeps `layout=old` in the address. The
original table is untouched and reachable by the CEO view at `layout=original`, with one toolbar
link each way ("Original table" on the suppliers table, "Table with suppliers" on the original).
The change is three controller lines (the same two view variables, no third one) and two small
blocks of `index.blade.php`: the two links, and the address word. Every viewer who is not the CEO
view gets the original table for all three words, byte for byte what `layout=old` gave them
before; the default layout is unchanged for everyone.

How the words map (after the framework's trimming):

| `layout` | CEO view: `layoutOld` / `layoutSuppliers` | CEO view gets | Other viewers: `layoutOld` / `layoutSuppliers` | Other viewers get |
|---|---|---|---|---|
| `old` | true / true | the suppliers table, link "Original table", address stays `layout=old` | true / false | the original table, as before |
| `suppliers` | true / true | the same bytes as `old`; address becomes `layout=old` | true / false | the same bytes as `old` |
| `original` | true / false | the original table, link "Table with suppliers", address stays `layout=original` | true / false | the same bytes as `old` |
| anything else, empty, missing, an array | false / false | the default layout | false / false | the default layout |

## Case table

All in `tests/Feature/Item/SuppliersGroupTest.php` unless another class is named. Run on
`71b137c`: `OK (154 tests, 3282 assertions)` for `tests/Feature/Item`; each test below is a `✔` in
the `--testdox` run of the file (`OK (42 tests, 1797 assertions)`).

| Spec case | Final id | Test | Result |
|---|---|---|---|
| A.1 | S-22.1 | `test_S_22_1_layout_old_is_the_suppliers_table_for_the_ceo_view` | pass |
| A.2 | S-22.2 | `test_S_22_2_layout_suppliers_is_the_same_view_and_the_address_stays_layout_old` | pass |
| A.3 | S-22.3 | `test_S_22_3_any_other_layout_value_is_the_default_layout_of_the_base_for_the_ceo` (the route) with the existing `test_S_19_2_default_layout_for_the_ceo_is_identical_to_the_base` (the pinned render) | pass, characterisation |
| A.4 | S-22.4 | `test_S_22_4_the_old_view_of_the_ceo_has_the_original_table_link_only` | pass |
| A.5 | S-22.5 | owner check, under `## Owner checks` in `qa/stories.md` | not run, manual |
| B.1 | S-23.1 | `test_S_23_1_layout_original_is_the_original_table_for_the_ceo_view` | pass |
| B.2 | S-23.2 | `test_S_23_2_the_original_table_of_the_ceo_differs_from_the_base_old_view_by_two_strings` | pass |
| B.3 | S-23.3 | existing `ItemPageTest::test_old_table_partial_is_byte_identical_to_the_base_commit`, unchanged: `OK (1 test, 1 assertion)` | pass, characterisation |
| C.1 | S-24.1 | `test_S_24_1_non_ceo_views_get_the_base_old_view_for_every_layout_word` (the route) with the existing `test_S_18_4_marketing_renders_of_the_old_and_default_layout_are_identical_to_the_base` (the pinned renders) | pass; the comparison with the base is characterisation |
| C.2 | S-24.2 | `test_S_24_2_the_script_does_not_call_the_loaders_for_non_ceo_views` | pass, characterisation |
| C.3 | S-24.3 | `test_S_24_3_the_default_layout_of_non_ceo_views_is_the_base` (the route) with the existing `test_S_18_4_…` | pass, characterisation |
| C.4 | S-24.4 | `test_S_24_4_only_the_exact_word_original_after_trimming_selects_the_original_table` | pass |
| S-13.5 (changed) | S-13.5 | `test_S_13_5_only_the_exact_layout_words_select_a_view` | pass |

**Red run before the code** (the test file on the base code, `Tests: 42, Assertions: 860,
Failures: 8`), first failure line of each:

| Test | First failure line |
|---|---|
| S-13.5 | `/item?layout=old` — `Failed asserting that two arrays are identical.` (the route gave `[true, false]`) |
| S-22.1 | `Failed asserting that 0 is identical to 1.` (no "Original table" link in the render) |
| S-22.2 | `iba ang layout=suppliers sa layout=old` — `Failed asserting that false is true.` |
| S-22.4 | `Failed asserting that 0 is identical to 1.` (no "Original table" link) |
| S-23.1 | `Failed asserting that two arrays are identical.` (`layout=original` selected the default layout) |
| S-23.2 | `Failed asserting that 0 is identical to 1.` (no "Table with suppliers" link) |
| S-24.1 | `CEO: SUPPLIERS` — the marker was not in the CEO's `layout=old` body |
| S-24.4 | `CEO: /item?layout=original%20` — `Failed asserting that two arrays are identical.` |

**Characterisation tests** (green before and after) and what turns them red:

- S-22.3: red if any value other than the three words sets `layoutOld` or `layoutSuppliers` for the
  CEO, or if such a request's body differs from the body of `/item`. `test_S_19_2` is red if the
  default render for the CEO moves by one byte.
- S-23.3: red if `_table_old.blade.php` is edited.
- S-24.1 (base comparison): red if the route passes a non-CEO viewer any of the six view values
  differently from the pinned render (`isCEO`, `isMarketingOIC`, `viewAs`, `effectiveIsCEO`,
  `layoutOld` true, `layoutSuppliers` false), if the three words give a viewer different bodies,
  or if a marker appears. `test_S_18_4` is red if a non-CEO render with those values moves by one
  byte. The marker half of S-24.1 was red before the code only because the markers were not yet in
  the CEO's `layout=old`.
- S-24.2: red if a non-CEO body for any of the three words holds `this.loadItemSuppliers(),` or
  `this.loadItemQuotes(),`, or if the CEO's two views stop holding each exactly once.
- S-24.3: red if a non-word value sets `layoutOld` for a non-CEO viewer or changes their body.

**The strings that differ, pinned in the test file** (B.2 and A.1):

- The CEO's original table versus the Old view of the CEO at the commit before spec 017
  (`old.ceo`, `b8395ba9…`): exactly two added strings, each once. (1) `SUPPLIERS_TABLE_LINK`: the
  five-line `<a href="?layout=old" …>🏷 Table with suppliers</a>` anchor with its title and style.
  (2) `ORIGINAL_ADDRESS`: the comment line `// Orihinal na table — dapat manatili sa URL; kung
  hindi, gagawin itong ?layout=old (table na may suppliers) ng unang load.` and the line
  `qsObj.layout = 'original';`, right after `if (this.layoutOld) qsObj.layout = 'old';`.
- The CEO's suppliers table versus `layout=suppliers` at `5370bdc` (`6f616f87…`, taken on the base
  with the test file's own render and normalise, and reproduced by the reviewing agent from
  `git show 5370bdc:…`): the link `🗂 Old view` (title "Back to the original table", to
  `layout=old`) is replaced by `ORIGINAL_LINK` (`🗂 Original table`, to `layout=original`), and the
  two lines `// Suppliers view — dapat manatili sa URL; …` / `qsObj.layout = 'suppliers';` are
  gone. Nothing else.

## Story changes

| Case | Old Then | New Then | Reason |
|---|---|---|---|
| S-13.5 | `layout=suppliers` renders the suppliers view; `layout=old` renders the Old view and anything else the default layout (only the exact strings select) | `layout=old` and `layout=suppliers` both select the suppliers view, `layout=original` selects the original table and anything else the default layout; with `view_as=marketing` the three words select the original table without the suppliers group | the spec changes which word selects which view. Test renamed to `test_S_13_5_only_the_exact_layout_words_select_a_view` |
| S-18.1 | for `/item?layout=suppliers`, the response is the Old view exactly as `layout=old` gives it, with none of the markers, the link to the suppliers view included | for `layout=old`, `layout=suppliers` and `layout=original`, the three responses are the same output, the original table as before, with none of the markers, either toolbar link included | three addresses now. It is the same case as S-24.1, so the row points to that test and `test_S_18_1_…` is gone (one test per behaviour) |
| S-18.2 | given those three requests, the script does not call the loaders | unchanged Then, for the three addresses of each viewer | same case as S-24.2; the row points to that test and `test_S_18_2_…` is gone |
| S-19.3 | the Old view rendered for the CEO differs from the base only by the one toolbar link to the suppliers view | the Old view (`layout=old`) for the CEO is the suppliers view; the original table (`layout=original`) for the CEO differs from the Old view before spec 017 only by its one toolbar link and the layout word its script keeps in the address | the comparison moves to `layout=original`. Both halves are the cases S-22.1 and S-23.2, so the row points to those two tests and `test_S_19_3_…` is gone |
| S-22.1 (spec A.1) | "exactly as `layout=suppliers` rendered it before this spec apart from the toolbar links" | "… apart from the toolbar link and the layout word the script keeps in the address" | A.2 requires the address line of the suppliers view to go, so A.1 cannot hold as written; both differences are pinned |
| S-24.1 (spec C.1) | the three viewers, three words | adds `view_as=ceo` and `view_as=CEO` sent by Marketing and Marketing - OIC, and `view_as=MARKETING`, `view_as=marketing%20` for the CEO account | spec review: no route test went red if the role check behind `view_as` regressed |
| S-24.4 (spec C.4) | trailing space, `Original`, `ORIGINAL`, an array | adds a leading space, `originals`, a keyed array, and `layout` sent twice (the last value wins) | missed edge cases |

The owner checks S-13.7, S-17.7 and S-19.8 still name `/item?layout=suppliers`; that address still
opens the same view, so their text is unchanged. S-22.5 is added under `## Owner checks`.

## Done-when checklist

- [x] Cases A.1 to C.4 pass under their final ids, each with a test named after it, except the
      owner check. Evidence: the case table; S-22.5 is under `## Owner checks`. Note: S-23.3 is the
      existing byte-pin test, which is not named after the case (ruling 4).
- [x] The changed cases of slice 017 are updated in `qa/stories.md` and their tests, each listed
      under "Story changes". Evidence: the table above; `git diff 5370bdc -- qa/stories.md`.
- [x] `git diff 5370bdc --stat` lists only the allowed files. Evidence, at `71b137c`:
      `TODO.md`, `app/Http/Controllers/ItemController.php`,
      `docs/plans/019-old-view-suppliers-table.md`, `docs/specs/019-old-view-suppliers-table.md`,
      `docs/specs/019-old-view-suppliers-table.result.md`, `qa/stories.md`,
      `resources/views/item/index.blade.php`, `tests/Feature/Item/SuppliersGroupTest.php` (8 files).
      `git diff 5370bdc --stat -- resources/views/item/_table_old.blade.php
      resources/views/item/_table_suppliers.blade.php resources/views/item/_suppliers_style.blade.php
      resources/views/item/_suppliers_js.blade.php routes composer.json composer.lock database`
      prints nothing.
- [x] The pinned hashes of the default layout (all viewers) and of the Old view for the three
      non-CEO viewers are unchanged and still pass. Evidence: the only `BASE` line in the diff is
      `old.ceo`, where the trailing comment changed and the hash did not; `test_S_18_4`:
      `OK (1 test, 6 assertions)`, `test_S_19_2`: `OK (1 test, 1 assertion)`.
- [x] `php.bat -l` passes on every changed PHP file. Evidence: "No syntax errors detected" for
      `app/Http/Controllers/ItemController.php` and `tests/Feature/Item/SuppliersGroupTest.php`.
- [x] The full suite before and after, no new failures.
      Before (`5370bdc`, this worktree after the one composer install):
      `Tests: 624, Assertions: 7451, Errors: 1, Failures: 1, Skipped: 3.`
      After (`71b137c`): `Tests: 631, Assertions: 8375, Errors: 1, Failures: 1, Skipped: 3.`
      The same two in both: `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`
      (needs an untracked file) and `ExampleTest::test_the_application_returns_a_successful_response`
      (302). 631 = 624 − 3 (the S-18.1, S-18.2, S-19.3 tests) + 10 new.
- [x] The gate change was reviewed by a separate reviewing agent at adversarial depth. See "Tests",
      the review table.
- [x] The addresses to open after release are listed below.
- [x] This result is filled in.

## How to run

From the repository root, with PHP 8.4 (locally `C:/Users/Forbeast/.config/herd/bin/php.bat`):

```
php composer.phar install --no-interaction
php vendor/phpunit/phpunit/phpunit tests/Feature/Item/SuppliersGroupTest.php
php vendor/phpunit/phpunit/phpunit tests/Feature/Item
php vendor/phpunit/phpunit/phpunit
```

No migration, no asset build (no JS or CSS file changed; the Blade file is compiled by the app),
no new setting.

## Rulings

1. Ruling: no third view variable; "the CEO's original table" is `$effectiveIsCEO && $layoutOld &&
   !$layoutSuppliers` in the Blade file — the spec asks for one gate boolean and three controller
   lines, and the toolbar block already had that `@if` / `@else` — cost if wrong: a later view
   that sets `layoutOld` without `layoutSuppliers` for the CEO view would also get the
   "Table with suppliers" link and the `original` address word.
2. Ruling: the two links keep an icon before the text ("🗂 Original table", "🏷 Table with
   suppliers") — the spec says "in the style of the links beside it", and every link beside them
   has one — cost if wrong: two characters to remove in one file and two test constants.
3. Ruling: A.1 is read as "apart from the toolbar link and the address word" — A.2 requires the
   suppliers view's own address line to go — cost if wrong: none for behaviour; the case text.
4. Ruling: B.3 is proven by the existing `ItemPageTest` byte-pin test, not by a second test named
   S-23.3 — the project's test rule forbids a second test for a proven behaviour, and slice 017
   did the same for S-19.5 — cost if wrong: one three-line test to add.
5. Ruling: "identical to the base" for route requests is proven in two steps (the route passes
   the six values of the pinned render; the pinned render tests hold the hashes) — the pinned
   hashes are of direct renders with fixed page and fee data, so a route body cannot have the same
   hash, and a direct render after a request in the same test differs from the pin — cost if
   wrong: a difference that depends on something outside those six values and the layout would not
   be caught; the three-bodies-identical assertion covers the layout words.
6. Ruling: the tests `test_S_18_1_…`, `test_S_18_2_…` and `test_S_19_3_…` are removed and their
   rows point to the S-24.1, S-24.2, S-22.1 and S-23.2 tests — the changed cases became the same
   cases as the new ones — cost if wrong: the ids are no longer in a test name; the rows say where.
7. Ruling: no developer subagent; the main session wrote the tests and the code, and the
   reviewing agent reviewed — this session has no `backend-developer` or `frontend-developer`
   agent, and the start prompt names this session as the developer — cost if wrong: none for the
   code; the red and green runs are in this file.
8. Ruling: the rendered script comment `// Lumang table view — panatilihin ang ?layout=old sa URL.`
   and the two "Suppliers view" comments inside the suppliers-only loader blocks are left as they
   are — the first is inside every non-CEO render's pinned hash, the others are in blocks the spec
   says not to change — cost if wrong: wording only.

## Deferred minors

Recorded in `TODO.md` under "019":

- A CEO on `layout=original` who switches to the Marketing view and back lands on the table with
  suppliers (the Marketing render keeps `layout=old`, as it must to stay byte-identical).
- The two links keep the current query on a plain click only, like the two they replace.
- Two script comments of the suppliers table still say "Suppliers view" (blocks not to be changed).
- The route tests prove "byte for byte the base" in two steps (ruling 5).

## Merge danger

Two-way door. No migration, no data written, no route, role or middleware change. Blast radius if
wrong: the `/item` page only. The worst case is the one the tests and the review were aimed at: a
viewer who is not the CEO view getting the suppliers table; the gate still needs
`$effectiveIsCEO`, and no input was found that passes it. The expected visible change: the CEO's
bookmarked `layout=old` now opens the table with suppliers (the purpose of the spec). Revert: the
squash commit on `develop` (or the three controller lines and the two Blade blocks); nothing else
to undo. `tests/Feature/Item/SuppliersGroupTest.php` is the only file both this branch and a later
spec on the suppliers table would touch.

## Addresses to open after release

Replace `…` with the usual query (dates and so on); each can also be opened bare.

**CEO view (logged in as the CEO)**

| Address | Must show |
|---|---|
| `/item?layout=old` | the table with the SUPPLIERS group (Supplier 1, 2, 3, PO); toolbar: "✨ New view" and "🗂 Original table", no "Suppliers view"; the address stays `layout=old` after loading |
| `/item?layout=suppliers` | the same table; after loading the address reads `layout=old` |
| `/item?layout=original` | the table as before spec 017, supplier lines stacked in the Item cell; toolbar: "✨ New view" and "🏷 Table with suppliers"; the address stays `layout=original` |
| click "Original table", then "Table with suppliers" | each opens the other table and keeps the date range and the other query values |
| `/item` and `/item?layout=OLD` | the default layout, unchanged ("🗂 Old view" in the toolbar, which opens the table with suppliers) |
| `/item?layout=original`, switch to the Marketing view, switch back | lands on the table with suppliers (known, see Deferred minors) |

**Marketing view (a Marketing or Marketing - OIC user, or the CEO with `&view_as=marketing`)**

| Address | Must show |
|---|---|
| `/item?layout=old` | the original table as Marketing has it today: no SUPPLIERS group, no supplier names or prices, no "Original table" or "Table with suppliers" link |
| `/item?layout=suppliers` | exactly the same page |
| `/item?layout=original` | exactly the same page; after loading the address reads `layout=old` |
| `/item?layout=old&view_as=ceo` (as a Marketing user) | exactly the same page |
| `/item` | the default layout, unchanged |
| view source of any of the above, search `spl-`, `SUPPLIERS`, `original` | no match for `spl-`, `SUPPLIERS`, `layout=original` or `'original'` |

## Conflicts with CLAUDE.md

- The kit names `backend-developer` and `frontend-developer` agents for the build; this session
  has neither, and the start prompt makes this session the developer (ruling 7).
- The kit asks for one test named after each case and for no second test of a proven behaviour;
  for S-23.3 these conflict. The kit's test rule was followed (ruling 4).
- The kit's `/ship` step (push, PR) is not run: the spec says no PR and no push.

## Tests

- **Covered:** the word → view mapping for the CEO view, the CEO with `view_as=marketing`,
  Marketing and Marketing - OIC (S-13.5, S-24.1, S-24.4); the CEO's two tables against base hashes
  with the differing strings pinned (S-22.1, S-23.2); both links as whole strings (S-22.4, S-23.1);
  the address word of each view (S-22.2, S-23.1); the absence of every suppliers marker and of
  both links for non-CEO viewers on nine addresses per viewer, each marker first proven present
  for the CEO (S-24.1); the loaders (S-24.2); the default layout (S-22.3, S-24.3).
- **Not covered by a test:** what the links and the address rewrite do when clicked or run (the
  project has no JavaScript runner; only the rendered text is pinned), and the owner check S-22.5.
- **Browser check:** skipped. This worktree has no running app with data, and no environment file
  may be created here. The addresses above are the manual check.
- **Latest result:** `tests/Feature/Item`: `OK (154 tests, 3282 assertions)`. Full suite:
  `Tests: 631, Assertions: 8375, Errors: 1, Failures: 1, Skipped: 3.` (the two known ones).

**Reviews** (both by the `skeptic-reviewer` agent, a separate agent inside this session):

| Review | Verdict | Findings | What was done |
|---|---|---|---|
| Spec review of the spec and plan, before code | ok, with changes to the test plan; no input found that breaks the planned gate | (1) no route test with a non-CEO role sending `view_as=ceo`; (2) "identical to the base" proven on two flags only; (3) add an empty `layout` and `layout` sent twice; (4) use `!empty()` / `empty()` in Blade; (5) A.1 contradicts A.2 on the address line; (6) the Marketing round trip from `layout=original`; (7) the controller comment and the test's `old.ceo` comments must follow; (8) leave the rendered comment at the `layout=old` line | (1) to (4) and (7) built in; (5) ruling 3 and a story change; (6) accepted in `TODO.md`; (8) left alone |
| Adversarial review of `5370bdc..bea35c2` | no blocker, no major; the gate holds | It ran a throwaway probe (deleted, tree clean) as Marketing and Marketing - OIC with 24 query variants each (tab, LF, CRLF, NBSP, zero-width space, BOM, null byte, `%FF`, casing, arrays, nested arrays, repeated parameters, `view_as=ceo` variants, injected `isCEO=1` and `layoutSuppliers=1`, `LAYOUT=`), a JSON body on GET and a POST (405): every 200 was byte-identical to that viewer's `layout=old` or default body, `layoutSuppliers` false, no marker. The CEO with odd `view_as=marketing` variants matched the base preview. It reproduced `BASE_SUPPLIERS_CEO`, `old.marketing`, `old.ceo_as_marketing` and `default.ceo` from `git show 5370bdc:…`. Minors: duplicate pinned-hash assertions in three new tests; the non-CEO half of S-13.5 repeated S-24.1; S-24.3 had no body comparison; stories and result not yet written; the removed S-18.1, S-18.2, S-19.3 test names; the Marketing round trip; `view_as[]=` answering 500 on the base | duplicates removed; S-24.3 compares bodies; stories and result written; removed names recorded under "Story changes"; round trip in `TODO.md`; the 500 is under "Proposed tasks". No fix loop was needed (no major) |

## Open questions

None.

## Process suggestions

- Say in the spec whether a case that an existing test already proves needs a new test named
  after it — evidence: B.3 and the done-when line "each with a test named after it" pull against
  the kit's "no second test for a proven behaviour" (ruling 4).
- Give the base hash of a view that a spec re-homes (here the suppliers view at `5370bdc`) in the
  spec — evidence: it had to be taken with a throwaway test on the base before any code.
- Note for test writers on this page: a direct render after a GET in the same test does not match
  the pinned hashes — evidence: `default.ceo` came out as `d294736d…` in S-22.3 until the render
  was moved out of the test.

## Proposed tasks

- `view_as[]=…` on `/item` answers 500 — an array is cast to a string before the gate
  (`ItemController.php:69`), on the base too; nothing leaks, but any logged-in viewer can trigger
  an error page — normal.
- Remove the original table, `layout=original` and the two links once the owner no longer needs
  the fallback — the spec keeps it "for a while" — low.
- Reword the two "Suppliers view" script comments and the slice 017 owner checks to "the table
  with suppliers" when that table is next opened for a change — one name for the view — low.

## Suggested next steps

1. Review the branch (`git diff 5370bdc..feat/019-old-view-suppliers-table`), then merge to
   `develop`.
2. After release, open the addresses above as the CEO and as a Marketing user.
3. Ask the owner for S-22.5 (his usual Old view link), together with the open checks of slice 017.
