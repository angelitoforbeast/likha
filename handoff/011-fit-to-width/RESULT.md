# Result 011: The owner private table always fits the screen width, with a switch back to full size

Status: **done, waiting for Mira's review of branch `feat/011-fit-to-width`**, cut from `develop` at `a3ef772`.
Nothing was pushed, merged or deployed, and there is no PR, so this file stands in for the PR body.
**Nothing here was seen in a browser** (no browser tools and no dev server in the allowed commands): the fit itself is unproven until Mira's browser check below.

## Plan

1. First commit: this handoff saved verbatim, this file, and the row in `handoff/README.md`.
2. Test first (`tests/Feature/OwnerPrivate/FitToWidthTest.php`): the main page carries the helper and the switch exactly once for the CEO and for Marketing, the out-of-scope pages do not, and the pure factor function is in the source with its guards.
3. One partial `resources/views/owner/_fit_to_width.blade.php` (switch, style, plain script): CSS `zoom` on the table's card with one factor = container width / natural table width (never above 1), remembered in localStorage; included once in the toolbar of `owner/private.blade.php`, plus one marker attribute on the card. The breakdown page is left alone if it needs special handling (D6).
4. Recompute hooks inside the partial: ResizeObserver on the scroll container and the table, MutationObserver on the table (Alpine changes), window resize; one measurement per animation frame, always taken at zoom 1 so the result does not depend on the previous factor (no loop). (Corrected after review: the first wording said "written only when the factor changes"; the zoom is written on every measurement, the no-loop property comes from what is measured and what the observers watch.)
5. `skeptic-reviewer` at standard depth with the two named checks, fixes or `TODO.md`, the full suite once, evidence per done-when item in this file; no push, no PR, no deploy.

## Summary

`/owner/private` now opens in **Fit** mode for every role: the white card that holds the main table (header, rows, TOTAL row, expanded campaigns panels, the small legend line under the table) is shrunk by one CSS `zoom` factor so the table is exactly as wide as the table area, and the table area has no sideways scrollbar. A new toolbar button right after "🔄 Refresh" shows `↔ Fit 68%` (the current factor) and toggles to `↔ 100%`, which is today's page unchanged. The choice is kept per browser in localStorage (`owFitMode`); anything other than the stored word `full`, or blocked storage, means Fit.

Five things for Mira to know:

1. **Not seen in a browser.** The tests only prove the markup and the source text of the script. The numbers in the checklist below decide whether this handoff is really done.
2. **The breakdown page is left alone (D6).** Its table is `table-layout:fixed; width:100%` with percent column widths, so it already fits the width and never scrolls sideways; a factor would always be 1. It would also need special handling: the table is created inside `template x-if` after the data loads (the helper looks its elements up once), the window is the scroller, and the header is sticky at `top:48px` under the nav. No change to that file.
3. **The "more / less" expanders in the Action and note cells shrink with the table.** D4 lists "+ more" expanders among the things that float; on this page they do not float, they unfold the text inside the cell. They cannot stay at normal size without taking them out of the table. The full text is readable at normal size through the ✎ editor or the 100% switch. Nothing else inside the table floats: no inputs, selects, popovers or dropdowns live in the table markup.
4. **The campaigns panel can still show its own small sideways scrollbar** inside an expanded row (`.expand-wrap{overflow-x:auto;max-width:100%}`, existing). The page and the table area have none. If Busing's "never" covers the inside of an expanded row, that is a follow-up (proposed task 2).
5. **In Fit mode the vertical scrollbar of the table area is always shown**, also when the rows fit the height. On purpose: a scrollbar that comes and goes changes the width and is how fit helpers start looping.

## Done-when evidence

**1. Branch from `a3ef772`, only this handoff's commits.** `git log --oneline a3ef772..HEAD` (before this file's own commit, which is the last one, `docs:`):

```
59e509a docs: accepted review findings and reviewer memory for handoff 011
76a73b2 fix: fit verify pass corrects from the applied width, failed measure leaves the switch at 100%
061dc31 fix: bounded verify pass after the fit factor is applied, switch shows 100% when the helper fails
bc8658c feat: fit-to-width switch for the owner private table (css zoom helper partial)
f720203 docs: handoff 011 fit-to-width, result skeleton and index row
```

**2. Section 5 tests exist and pass.** `tests/Feature/OwnerPrivate/FitToWidthTest.php`:

| Test | Proves |
|---|---|
| `test_owner_private_carries_the_fit_helper_and_switch_exactly_once` (data sets CEO, Marketing) | `id="owFitSwitch"`, `class="card" data-ow-fit>`, `function owFitFactor(` and the start marker each appear exactly once in `/owner/private` |
| `test_pages_out_of_scope_do_not_carry_the_fit_helper` | rendered breakdown and `/item` pages contain no `owFit` / `data-ow-fit`; a scan of every Blade view finds the include `owner._fit_to_width` in `owner/private.blade.php` only, once (this covers the Daily Summary and the snapshot pages, which are not rendered in the test) |
| `test_fit_factor_is_one_pure_function_with_its_guards` | the text of `owFitFactor` has its guards (`isFinite(c)`, `isFinite(n)`, `c <= 0`, `n <= 0`, `return 1`, `f >= 1`, `f > 0`) and no `document` / `window` / `localStorage`; the verify pass is bounded (`i < 3`, `break`, no `while`), goes through `owFitFactor(` and corrects from the applied width |

**Said plainly: no test executes the script.** These are source-level checks, as section 5 allows; a wrong division with the right guard strings would stay green.

`php.bat artisan test --filter FitToWidthTest`:

```
   PASS  Tests\Feature\OwnerPrivate\FitToWidthTest
  ✓ owner private carries the fit helper and switch exactly once with data set " c e o"                          0.21s
  ✓ owner private carries the fit helper and switch exactly once with data set " marketing"                      0.03s
  ✓ pages out of scope do not carry the fit helper                                                               0.06s
  ✓ fit factor is one pure function with its guards                                                              0.03s

  Tests:    4 passed (38 assertions)
```

Red runs (from the developer's reports): slice 1 `CEO: expected exactly one: id="owFitSwitch"` / `Failed asserting that 0 is identical to 1.`; fix loop 1 `missing marker: // fit-verify-start`; fix loop 2 `verify pass missing: (container / f) * tw / cw`.

**3. Whole suite, once.** `php.bat artisan test`, run on the tree of `76a73b2` (the last code commit; the two commits after it change only `TODO.md`, agent memory and handoff files):

```
   FAILED  Tests\Feature\ExampleTest > the application returns a successful response
  Expected response status code [200] but received 302.

  Tests:    1 failed, 3 skipped, 456 passed (4824 assertions)
  Duration: 53.27s
```

Base: 452 passed, 3 skipped, 1 failed. Now 456 passed (the 4 new), the same 3 skipped, the same 1 failed (`ExampleTest`). No new failure.

**4. Diff scope.** `git diff a3ef772..HEAD --stat`:

```
 .../frontend-developer/item_page_test_gotchas.md   |   2 +
 .../skeptic-reviewer/repo_weak_spots.md            |   4 +
 TODO.md                                            |  14 ++
 handoff/011-fit-to-width/HANDOFF.md                |  81 ++++++++++++
 handoff/011-fit-to-width/RESULT.md                 |  51 ++++++++
 handoff/README.md                                  |   1 +
 resources/views/owner/_fit_to_width.blade.php      | 145 +++++++++++++++++++++
 resources/views/owner/private.blade.php            |   5 +-
 tests/Feature/OwnerPrivate/FitToWidthTest.php      | 130 ++++++++++++++++++
```

`git diff a3ef772..HEAD --stat -- app routes database` prints nothing: no controller, route or database change. Beyond "views, the new partial, tests and handoff files" there are `TODO.md` and two agent-memory files, which CLAUDE.md requires (workflow steps 5 and 9). The change to `private.blade.php` is two touches: the `@include` after the Refresh button (outside every `@if`) and `data-ow-fit` on the card.

**5. Technique, triggers, floating elements, not-seen list:** the next sections.

**6. Browser checklist:** below.

**7. Reviewer:** ran at standard depth with the two named checks, plus a re-check of the fix; see "Review findings".

**8. Commits:** Conventional Commits; `git log a3ef772..HEAD -i -E --grep="co-authored|claude-session|generated with" --oneline` prints nothing; `git status --short` is empty after this file's commit.

## Technique and its costs

**CSS `zoom` on the card** (`<div class="card" data-ow-fit>`, the only child of `#scroll`), set inline by a plain script, no Alpine.

Each measurement, inside one animation frame so nothing is painted in between:

1. add `.ow-fit-on` to `#scroll` (`overflow-x:hidden; overflow-y:scroll`);
2. set the card's zoom to 1 and read the container width (`#scroll.clientWidth` minus its side padding) and the natural width (the larger of the card's and the table's `offsetWidth`; the card has `min-width:900px` and the table may be wider than the card);
3. `f = owFitFactor(container, natural)` = `container / natural` rounded **down** to 3 decimals, 1 when that is 1 or more, 1 for zero, negative or missing sizes, never below 0.001;
4. set the card's zoom to `f`. The card's own layout width then becomes `container / f`, which is at least the natural width, and the table is `width:100%` of the card: edge to edge;
5. verify pass, at most 3 times: if the table is still wider than the card (a shrink is not exactly linear: 1px borders, small text), recompute from the width actually applied and set again.

Why zoom and not a transform: zoom changes layout, so the sticky header and the sticky TOTAL row keep working against the same scroller, the scroll height is right without compensation, and click targets match what is drawn. A transform would need a compensated width and height and breaks `position:sticky` inside it. Everything that floats is outside the card, so nothing needs counter-scaling.

Costs:

- **Small text.** D3: no minimum. With every column on at 1366 px the text may be hard to read; the 100% switch is the way back. A browser minimum font size setting, if the user has one, makes text shrink less than the boxes; the verify pass then lowers the factor further.
- **Hairlines.** 1px cell borders are drawn at a fraction of a pixel; some may look uneven or thinner at certain factors.
- **Always-on vertical scrollbar in Fit mode** (see Summary 5).
- **Work per change.** Every change inside the table (data, column, expand, also a hover on a page or campaign link, which writes an inline style) re-runs the measurement once per frame: two zoom writes and a layout of the whole table. Not a loop, but possible stutter on a very large table (accepted minor, proposed task 1).
- **Browser support.** `zoom` is in Chrome, Edge, Safari and Firefox 126 or newer. Where `CSS.supports('zoom','0.5')` is false the switch shows `↔ 100%`, disabled, and the page is as today.
- **Scroll position.** Switching mode or a changed factor changes the height of the content; the vertical position can shift by the difference. Not seen in a browser.
- **Header drag-to-reorder** in Fit mode shows the drag image at the shrunk size (expected, not seen).

Failure paths (by source reading): storage blocked or holding anything but `full` gives Fit and the page works; if a measurement throws, the class and the zoom are removed, the switch shows `↔ 100%` and the next click tries Fit again; if init throws, the switch is disabled and the page is as today; the helper never touches Alpine or the page's data.

## Recompute triggers

All call `schedule()`, which collapses to one `measure()` per animation frame.

| D1 moment | Trigger wired | Where |
|---|---|---|
| First render | `schedule()` at the end of `init()` on `DOMContentLoaded`; while `html[x-cloak]` hides the page the sizes are 0 and the factor is 1, then the ResizeObserver fires when Alpine shows it | `_fit_to_width.blade.php` init |
| Data loaded or refreshed | MutationObserver on the table (`childList`, `subtree`, `characterData`) | init |
| Column shown or hidden (view-as toggle, Alpine bindings) | MutationObserver (`childList`; `attributes` filtered to `style`, `class` for `x-show`) | init |
| Row expanded or collapsed, more / less toggles | MutationObserver (same) and ResizeObserver on the table | init |
| Browser window resized | `window` `resize` and ResizeObserver on `#scroll` | init |
| Page zoom changed | `window` `resize` (the viewport's CSS width changes) and ResizeObserver on `#scroll` | init |
| Switch clicked | click listener: toggle, store, `schedule()` | init |

Why it cannot loop: the helper writes only to the card (zoom) and to `#scroll` (one class); the MutationObserver watches the table subtree, which is below both. The ResizeObserver may fire once after a factor change; the next measurement starts again from zoom 1 with the same inputs and gets the same factor, so nothing changes and it stops. The forced vertical scrollbar keeps the container width fixed. The verify pass is capped at 3.

## Floating elements

Checked by reading the source; none seen in a browser.

| Element | Where it lives | Under the zoom? |
|---|---|---|
| Action note editor (✎) | `position:fixed`, after `#scroll` closes; placed from the window size | No: normal size |
| Claude and CEO note editors | `owner/_claude_action_modal.blade.php`, `position:fixed`, after `#scroll` | No |
| Edit modal (RTS / Promo / COGS) and the other `.ow-modal-backdrop` modals | `position:fixed`, after `#scroll` | No |
| Item filter dropdown | toolbar | No |
| Date pickers (From, To, partial date; the ones in the edit modal) | toolbar and modals | No |
| Tooltips | native `title` attributes, drawn by the browser | No |
| "more / less" expanders in Action and note cells | inline text inside the cell, not floating | **Yes, shrink with the cell** (Summary 3) |
| Campaigns panel (expanded row) | a row of the table | Yes, as D1 says |

The editors are positioned from the window, not from the clicked cell, today as well; that is unchanged, so "in the right place" means the same place as in 100% mode.

## Browser checklist for Mira

Run as the CEO on `/owner/private` after deploy, then repeat lines 1 to 4 as a Marketing user. Windows 1366, 1536 and 1920 px wide, each with (a) the CEO's current columns and (b) every column switched on in the column settings.

In the console, in Fit mode:

```js
var d = document.documentElement, s = document.getElementById('scroll'),
    c = document.querySelector('[data-ow-fit]'), t = c.querySelector('table'),
    cs = getComputedStyle(s);
({ page:  d.scrollWidth <= d.clientWidth,
   box:   s.scrollWidth <= s.clientWidth,
   zoom:  c.style.zoom,
   tableRight: Math.round(t.getBoundingClientRect().right),
   boxRight:   Math.round(s.getBoundingClientRect().left + s.clientLeft + s.clientWidth - parseFloat(cs.paddingRight)) })
```

- [ ] 1. `page` and `box` are `true` at all three widths, for (a) and (b). No sideways scrollbar anywhere on the page.
- [ ] 2. `tableRight` equals `boxRight` within 1 px (the table ends where the white card ends, 16 px from the window edge), and the table starts 16 px from the left. When the table is naturally narrower than the area (few columns), `zoom` is empty, the switch says `↔ Fit 100%` and the table still spans the width.
- [ ] 3. The switch label matches `zoom` (for example `0.68` and `↔ Fit 68%`).
- [ ] 4. Scroll down: the dark header stays at the top and its columns stay aligned with the body; the TOTAL row stays at the bottom, aligned.
- [ ] 5. Click each ✎ (Action, Claude Action, Claude Reason, CEO Action, CEO Reason): the editor opens at normal size, fully on screen, in the same place as in 100% mode; save works and the cell updates.
- [ ] 6. Sort by a header, drag a header to reorder, expand one row and "Expand all", toggle "more / less", switch View: Marketing and back, press Refresh, change the dates: the table still fits afterwards (repeat the console check) and the label follows.
- [ ] 7. Resize the window slowly and change the page zoom (Ctrl + and Ctrl -): the table follows, no flicker, no jumping scrollbar, the CPU settles (no endless recompute; `c.style.zoom` stops changing).
- [ ] 8. Hover over page names and campaign links on a full table: no visible stutter (accepted minor).
- [ ] 9. Click the switch: `↔ 100%`, the sideways scrollbar is back and the page looks exactly as before this handoff (`c.style.zoom` is empty, `#scroll` has no `ow-fit-on` class). Reload: still 100%. Click again: Fit; reload: still Fit.
- [ ] 10. In a private window with storage blocked (or after `localStorage.setItem('owFitMode','x')`): the page opens in Fit and works.
- [ ] 11. Select text across a few cells and copy: works.
- [ ] 12. Expanded campaigns panel: note whether it shows its own sideways scrollbar (Summary 4) and whether that is acceptable.
- [ ] 13. First load: at most one visible jump from full size to the fitted size.

## Review findings

`skeptic-reviewer`, standard depth (medium tier), on `a3ef772..bc8658c`, then a re-check scoped to the fix (`bc8658c..061dc31`). Two loops used, the cap.

- **Named check 1** (no change to data, routes, permissions or what a role receives; 009 and 010 guarantees): **pass**. The diff has no file under `app/`, `routes/` or `database/`; the partial has no Blade variable, no role branch and no fetch.
- **Named check 2** (no loop; works when the script fails, zoom is unsupported or storage is blocked): **pass**, reasoned from source.
- **Spec:** D2, D3, D4 (from source), D6, D7 met; D1 met after the fix; D5 no loop met, sticky header and TOTAL row "cannot tell without a browser".

| # | Severity | Finding | Outcome |
|---|---|---|---|
| 1 | major | The factor was applied once and never checked while sideways overflow is hidden: at a factor near 0.6 to 0.7 the table could end up some px wider and the last column's edge be cut off with no scrollbar | Fixed in loop 1 (`061dc31`): bounded verify pass comparing the table with the card inside the same zoom |
| 2 | minor | The verify pass scaled the estimate before rounding, so a small overshoot never moved the factor (hand case 1334 / 2664) | Fixed in loop 2 (`76a73b2`): corrects from the applied width; the test pins the formula |
| 3 | minor | After a failed measurement the switch said Fit; then (after the first fix) a click did nothing visible but stored `full` | Fixed (`061dc31`, `76a73b2`): shows `↔ 100%`, next click retries Fit |
| 4 | minor | Disabled switch looked enabled | Fixed (`061dc31`) |
| 5 | minor | Every hover on a page or campaign link re-runs the measurement | Accepted, `TODO.md` |
| 6 | minor | If init throws after the observers are attached they stay live behind a disabled switch | Accepted, `TODO.md` |
| 7 | minor | The tests prove strings, not arithmetic | Accepted, `TODO.md` (section 5 allows it) |
| 8 | minor | RESULT.md was still "Pending" at review time; plan line 4 wording was wrong | Fixed in this file |
| 9 | minor | No red-run evidence was given to the reviewer | The red lines are in "Done-when evidence"; the note about the first slice is in `TODO.md` |

Loop 2 was not re-checked by the reviewer (the cap is two loops; it is a one-line formula change and a three-line simplification, both proposed by the reviewer). Declined to judge by the reviewer: everything that needs a browser, and whether "never" covers the scrollbar inside the campaigns panel.

## Rulings

- Ruling: CSS `zoom` on the card, not a transform - layout-based, so sticky rows, scroll height and click targets need no compensation and every floating thing is already outside the card - if a target browser draws zoom badly, the table looks rough in Fit mode and the user stays on 100% until a transform version is built.
- Ruling: breakdown page left alone - it already fits (fixed layout, 100% width) and would need special handling (table created inside `template x-if`, window scroller, sticky offset) - none; if Mira wants the same switch there for consistency it is a separate small task.
- Ruling: the "more / less" expanders shrink with the table - they are cell text, not floating elements, and taking them out of the table is a re-style (out of scope) - if Busing expects them at normal size, a follow-up turns them into a popover.
- Ruling: `overflow-x:hidden` and a forced vertical scrollbar on `#scroll` in Fit mode - "never a sideways scrollbar" holds even if the fit is off by a pixel, and the width cannot oscillate - a permanent scrollbar track when the rows fit; an error in the fit would hide content instead of scrolling (hence the verify pass and checklist lines 1 and 2).
- Ruling: factor rounded down to 3 decimals, label rounded to whole percent - rounding down can only leave the table a hair narrower in layout, never wider - up to about 0.1% of the width unused inside the card, not visible.
- Ruling: plain script with observers instead of hooks inside `privateUI()` - no line of the page's Alpine code changes and every Alpine-driven change is caught - extra measurements on changes that do not affect the width (accepted minor 5).
- Ruling: the helper's first commit and both fix commits went through `frontend-developer`, the review through `skeptic-reviewer` on the sonnet tier - CLAUDE.md, medium tier - none.
- Ruling: the full suite ran on the last code commit, not on the final docs commit - the commits after it touch only `TODO.md`, agent memory and handoff files - none.

No conflict between CLAUDE.md and the handoff was found. The handoff says PHP 8.2; CLAUDE.md and the local Herd say 8.4; nothing in this change depends on it.

## Deploy notes for Mira

- Views only: no migration, no config, no asset build, no queue restart. After pulling, clear compiled views if the server caches them (`php artisan view:clear`).
- The first load after deploy opens in Fit for everyone, including Marketing users; tell Busing where the `↔ Fit` button is (right after "🔄 Refresh").
- Run the browser checklist; lines 1, 2, 4 and 5 are the ones that decide.
- Rollback: revert the branch's merge, or as a stopgap remove the one `@include('owner._fit_to_width')` line; the `data-ow-fit` attribute alone does nothing.

## Proposed tasks

1. If checklist line 8 shows stutter: make the measurement cheaper (ignore mutations that cannot change a width, or measure without resetting the zoom). Needs a browser to tune.
2. If "never" covers the inside of an expanded row: fit the campaigns panel's inner table too, or let the panel widen the row.
3. If Busing wants the notes readable in Fit mode: open "more" as a normal-size popover instead of unfolding in the cell.
4. Optional: the same switch on the breakdown page, only for consistency (it already fits).
5. Suggestion for the owner, not done: `ExampleTest` still fails (expects 200 on `/`, gets 302); delete or fix it so the suite is green.
