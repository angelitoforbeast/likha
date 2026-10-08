# Result: spec 017 Suppliers group on the item table

> Committed beside the spec: names no people and no decision ids. This is the state after the
> **plan run**: no product code and no test exists yet. Sections that can only be filled after the
> build say so.

**Status:** partial (plan only, waiting for "go" and the answers under "Open questions")
**Date:** 2026-10-08
**Branch / PR:** `feat/017-item-suppliers-group` (base `develop` at `90b900f`), no PR, not pushed
**Preview or run link:** n/a (this worktree cannot run the app: no environment file, no database)

## Summary

Plan only. The plan is `docs/plans/017-item-suppliers-group.md`: a third value of the existing
layout switch (`layout=suppliers`, effective CEO view only, decided by one server boolean), a new
table partial that starts as a copy of the Old view's, a suppliers-only style block and script
block, and the quote order and `cheapest` flag decided in the one controller method that feeds all
three quote endpoints. The Old view's partial is not edited. A throwaway test (deleted, not
committed) measured that a suppliers-only Blade block written at column 0 leaves no byte in the
other views, so the Old view and the default layout can stay byte for byte. Seven tasks; eight
questions below.

## Case table

Not yet: no test is written in the plan run. The plan (section 4) assigns every `server` case of
S-13 to S-21 to a task; the table is filled in when the tests exist.

## Story changes

None so far. The slice is added to `qa/stories.md` in the first task after "go". Open question 6
is about how one case (S-13.2) is read; if the answer changes the case, it is listed here.

## Done-when checklist

Not yet (plan run). Evidence so far for the items that have a "before" side:

- [ ] Every `server` case passes; `browser` cases and owner checks listed — not started.
- [ ] Red line for every feat slice — not started.
- [ ] `_table_old.blade.php` has no diff — true now (`git status` clean apart from the docs).
- [ ] Renders identical to the base — not started; the base renders are pinned in task T1.
- [ ] No `x-html`, no `innerHTML` in new files — not started.
- [ ] Diff lists only the allowed paths — so far: `docs/specs/017-…md`, its result, its plan and
      `design/item-suppliers/` (six files).
- [ ] `php.bat -l` on changed PHP files — none changed yet.
- [ ] Full suite before and after — **before** (base `90b900f`, this worktree, after the one
      `composer install --no-interaction` from the unchanged lock file, no diff on
      `composer.json` / `composer.lock`): `Tests: 589, Assertions: 6575, Errors: 1, Failures: 1,
      Skipped: 3.` The error is
      `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`
      (`storage/app/credentials.json` does not exist) and the failure is `ExampleTest` (302 instead
      of 200), as the spec says. After: not yet.
- [ ] Adversarial review by a separate reviewing agent — not started.
- [ ] What could not be checked without a browser — a first list is in the plan, section 6.
- [ ] The result is filled in — plan state only.

## How to run

From this worktree, after the one install (already done here):

```
"C:/Users/Forbeast/.config/herd/bin/php.bat" "C:/Users/Forbeast/.config/herd/bin/composer.phar" install --no-interaction --working-dir=<this worktree>
"C:/Users/Forbeast/.config/herd/bin/php.bat" vendor/phpunit/phpunit/phpunit
"C:/Users/Forbeast/.config/herd/bin/php.bat" vendor/phpunit/phpunit/phpunit tests/Feature/Item/SuppliersGroupTest.php
```

(The third command works once the build has added the file.)

## Rulings

- Ruling: the design contract was copied unchanged — `brief.md` was searched for names of people,
  assistants and decision ids before the copy and has none, so no line was reworded; the six files
  have the same sha1 as their sources — cost if wrong: a name is committed with the work.
- Ruling: the class and helper prefix is `spl-` / `spl`, not the first choice `sg-` — `sg-` is
  inside the existing class `msg-tbody`, so "the new class names are absent from the Marketing
  view" could not be asserted with it — cost if wrong: none, a rename (see open question 1).
- Ruling: a throwaway test file was written, run and deleted during planning, to measure how Blade
  renders a false block and whether the page's render is stable — the plan's central claim (the
  other views stay byte for byte) needed a measurement, not a guess — cost if wrong: none, nothing
  of it is committed.
- Ruling: all new tests go in `tests/Feature/Item/SuppliersGroupTest.php`, also the HTTP ones — the
  spec names that one class — cost if wrong: one long test class instead of two.

## Deferred minors

None yet (no review has run).

## Merge danger

To be written after the build. As planned: a two-way door. The Old view and the default layout
are unchanged byte for byte except one toolbar link in the Old view for the CEO; the one behaviour
change every reader of `/item/quotes` sees is the order of quotes without a price, priced 0 or
tied, and a new `cheapest` field. No migration, no route, no dependency.

## Not checked without a browser

Nothing is built yet. What the build will not be able to check here, from the plan (section 6):
the real row height, the two header rows while scrolling, the sticky item column (including the
16 px side padding of the scroll area), the card's placement near the bottom and right edges, hover
versus tap, the keyboard path through the card, and every `browser` case of the stories.

## Conflicts with CLAUDE.md

None in the spec. One slip of mine in this run: one command (the base suite run) was started as
`cd <worktree> && php.bat vendor/phpunit/phpunit/phpunit`, against the start prompt's "never
`cd ... && ...`". It changed nothing; every later command used the worktree as the working folder
or `git -C`.

## Tests

None written yet. Base suite summary: see the done-when list. The plan (sections 2.7 and 5) says
how the base renders are pinned and what each kind of test proves.

## Open questions

Each has my recommended answer and what I build if there is no answer. The plan is written under
the recommended answers.

1. **Class and helper names.** The comp's class names are short and generic (`c1`, `c2`, `q`,
   `sc`, `rp`, `rdt`, `grp`, `h1`, `h2`, `up`). I plan to keep the comp's rules and values but
   prefix every new class with `spl-` and every new script member with `spl` (`splReady`,
   `splTop3`, `splRest`, `splNone`, `splLoaded`), and to scope every rule under `.spl-table`.
   Reason: S-18.1 must assert that "the new class names" and "the new helper names" are absent
   from the Marketing view, and `q` or `sc` cannot be asserted absent from a 340 KB page; the
   prefix was checked to occur nowhere in the item views today.
   *Recommended:* yes, prefix. *Without an answer:* I build with the prefix.

2. **How "identical to the base commit" is pinned (S-18.4, S-19.2, S-19.3).** I plan to pin the
   sha1 of the normalised render (CRLF to LF, application root and CSRF token replaced by fixed
   words) of the default layout and the Old view, each for four viewers (CEO, CEO viewing as
   Marketing, Marketing, Marketing - OIC): eight values, written in a test-only commit that is the
   first commit after "go", before any product code, so they can be checked against the base by
   checking out that commit. The alternative is to commit the eight renders as fixture files
   (about 340 KB each) so a failure shows a diff instead of two hashes.
   *Recommended:* hashes (a failure then says which of the eight renders changed, not where; the
   place is found with `git diff` on the views). *Without an answer:* hashes.

3. **`layoutOld` stays true in the suppliers view.** The controller sets `layoutSuppliers` (exact
   string and effective CEO view) and keeps `layoutOld` true for `layout=suppliers`, so the page
   takes every Old-view branch it has today and only the table partial is swapped; no existing
   `layoutOld` check is edited, and a non-CEO request falls to the Old view by construction.
   Consequences to confirm: (a) the Claude action columns are hidden in the suppliers view exactly
   as in the Old view (the copied table has no cell for them); (b) the suppliers view's toolbar
   shows "✨ New view" (existing, to the default layout) and a new "🗂 Old view" (title "Back to
   the original table"); the Old view gets "🏷 Suppliers view" (title "Open the table with
   suppliers and prices side by side"); (c) after the first load the address bar keeps
   `layout=suppliers` (one suppliers-only line in `load()`).
   *Recommended:* yes to all three, with these labels. *Without an answer:* I build this.

4. **An open quote form and a click elsewhere.** The spec's card rule says the card closes on Esc
   and on a click or tap elsewhere. When the card holds the add / edit form with typed values, a
   stray click elsewhere would throw the values away; today the form closes only on Cancel or a
   successful save.
   *Recommended:* a card that shows a quote, the "+N" list or the PO list closes on Esc and on a
   click or tap elsewhere; a card that holds the open form closes on Cancel, on Esc and after a
   successful save, and **not** on a click elsewhere. *Without an answer:* I build the
   recommendation and add the difference to S-17.4 / S-17.5 under "Story changes".

5. **The neutral placeholder.** I plan: while the two lists are loading, the four columns are one
   cell with a grey "…" (title "Loading suppliers…"); after a failed fetch, one cell with a grey
   "—" and the title "Hindi na-load ang listahan ng supplier. I-refresh ang page."; no retry
   control (the lists load once at start-up, as today, so a page reload is the retry). "Answered
   successfully" means: the HTTP answer is ok, its `ok` is true and the list is present.
   *Recommended:* as described. *Without an answer:* I build this.

6. **How S-13.2 reads "four supplier cells".** Alpine's `x-if` takes exactly one root element, so
   four `<td>`s cannot sit under one condition. The item-row template will hold, after the Item
   cell and before the configurable columns, in this order: one `colspan="4"` cell for the
   placeholder, one `colspan="4"` cell for the red band, a loop over the three slots
   (`[0, 1, 2]`) that draws one quote cell each, and the PO cell. At any moment the row has
   exactly four columns there. The test for S-13.2 would assert the three-slot loop and the PO
   cell in that position, and the test for S-13.4 that every row kind adds up to the same count.
   *Recommended:* accept this reading. *Without an answer:* I build it and record the reading
   under "Story changes".

7. **`view_as` as an array (a gap no case covers).** `ItemController::index` casts `view_as` to a
   string; by reading the code (not run), a request with `view_as[]=x` raises an "Array to string
   conversion" error today, for every layout. It leaks nothing and gives no access, and the spec
   says no other change to the page.
   *Recommended:* leave it, list it under "Proposed tasks". *Without an answer:* I leave it.
   `layout[]=suppliers` is different: it is compared with `===`, selects the default layout
   without an error, and gets a row in the test for S-13.5.

8. **Review rhythm.** The plan has four high-tier tasks (the endpoint, the gate, the table's
   structure, the cells and the card) and one medium (styles). By the workflow each gets its own
   review after the developer, and the spec asks for one adversarial review of the role gate, the
   endpoint and every place supplier text is printed. I plan: an adversarial review after each of
   the four high tasks, a standard one after the styles, and one last adversarial pass over the
   whole diff for the three topics the spec names.
   *Recommended:* as described. *Without an answer:* I do this.

## Process suggestions

- The start prompt could say whether a throwaway test during the plan run is allowed (the 016 plan
  did the same). Evidence: this plan's key claim needed one, see Rulings.

## Proposed tasks

- Reject or ignore a non-string `view_as` in `ItemController::index` — a request with
  `view_as[]=x` errors today (read from the code, not run) — low.

## Suggested next steps

Answer the eight questions and say "go"; the build then starts with task T1 (stories and the
test-only commit that pins the base renders).
