# Handoff 013: the Daily Summary table on owner/private becomes truly compact

Weight: bounded. Shape: change (presentation only). Risk tier: low to medium (a page the owner and the marketing team use every day; no data or money logic).
Base: commit 617fb34 (develop = main, live).

## Goal
The owner, looking at the live page on a 1707 px wide screen with "Fit 76%": "parang hindi din compact to e. ang laki nung space, tapos di makita yung content na text". Handoff 011 made the table fit the width by shrinking everything with CSS zoom, so the text got smaller while the wasted space stayed and the three text columns (Action, Claude Action, CEO Action) stay cut to one line. Make the table compact by using the space well: more rows on a screen, number columns only as wide as their content, and the text columns wide enough and wrapped so their content can be read without clicking. Still no horizontal scrollbar.

## What the page looks like now (from the owner's screenshot, 2026-10-05)
- About 12 rows fit on a screen about 1000 px high. Rows are tall because of the three-line block of RTS, delivered and in-transit figures (the RTS block) and generous cell padding.
- Number columns (PROMO, ITEM, PRICE, NP per O, ADSPENT, ORDERS, PROF.PROFIT, ITEM VAL., CPP, BREAKEVEN CPP, the four coloured PROF.% cells, TCPR, HOLD) are much wider than their content.
- Action, Claude Action and CEO Action show about 20 characters and "+ more".
- The table is rendered with Alpine in resources/views/owner/private.blade.php (about 3500 lines; column list near line 2128 with `minw`; text cells near lines 1151, 1191, 1256 with fixed `max-width`), inside `<div class="card" data-ow-fit>`; the fit logic lives in resources/views/owner/_fit_to_width.blade.php (button `#owFitSwitch`, localStorage key `owFitMode`).

## Requirements
R1. Compact layout, on by default, in the same switch that 011 added (one switch, not two). In compact layout:
  a. Cell padding and row height are reduced so clearly more rows fit on a screen than today (target: a row whose tallest content is the three-line RTS block is no taller than that block plus a small padding).
  b. Number columns take only the width their content and header need. Long headers may wrap to two lines or use the short labels already in use. The four coloured PROF.% cells and TCPR are narrow.
  c. The width won this way goes to the text columns: Action, Claude Action, Claude Reason, CEO Action, CEO Reason (whichever are shown). Their text wraps and shows up to three lines (the row is already three lines high), then is cut with the existing "+ more" and title behaviour. The author-and-time line under a text stays small and on one line.
  d. PROMO and ITEM may wrap to two lines instead of forcing width.
R2. No horizontal scrollbar in compact layout at any width from 1280 px up. Order of means: first the tighter layout of R1; only if the table is still wider than the card does the existing zoom apply to what is left. The button keeps showing the percentage in use (100% when no zoom is needed). The other state of the switch shows the table exactly as before 011 (full size, horizontal scroll), so anyone can go back.
R3. Optional merge, only if it can be done as presentation in compact layout without changing column ids, sorting, inline editing, the column settings page or any stored setting: PRICE shown as a second line under PROMO, and ITEM VAL. together with ITEM VAL. (CEO) in one cell of two lines. If any of those conditions cannot be kept, skip the merge and say so in RESULT.md; do not force it.
R4. An "Actions" view for the CEO only (the same condition that shows the Claude and CEO columns today): one button that shows only PAGE, ITEM, CPP, the four PROF.% columns, HOLD, Action, Claude Action, Claude Reason, CEO Action, CEO Reason, with the text columns sharing all the remaining width; pressing it again brings back the columns that were shown before. It is a view state kept in the browser only (localStorage); it never writes to the column settings. Editing with the pencil keeps working in this view.
R5. Everything else behaves as today: sorting, expanding a row, inline edits, the pencil editors and their floating box, the breakdown view, the item page card, Excluded, Logs, Snapshots, the Marketing and CEO views, column show and hide from the column settings page, phone widths.

## Decisions already made (do not reopen)
| Decision | Who | Why |
|---|---|---|
| Do it, default on | Busing, 2026-10-05: "oo, ipagawa mo na" after Mira listed: tighten number columns, give the width to the three text columns with up to three lines, merge near-duplicate columns, lower rows, add an Actions view | His words on the current page are in Goal |
| One switch, old layout still reachable | Mira | A layout change for the whole team needs a way back without a deploy |
| Merge is optional and presentation-only | Mira | Column ids feed sorting, editing and the column settings page; breaking those costs more than the space won |
| Actions view is CEO only and not saved to settings | Mira | The Claude and CEO columns are CEO only (handoffs 009, 010); a view toggle must not change what the team sees |
| No data or computation change | Mira | He asked for the look only |

## Constraints
- Never edit resources/views/item/_table_old.blade.php (it is sha1-pinned by a test).
- No change to controllers' queries, to any calculation, to routes, to migrations, to stored column settings or their keys. No new package, no new CDN.
- Keep the existing Alpine patterns and inline-style conventions of the file; prefer a small set of CSS rules scoped under the compact state (for example a class or attribute on the `data-ow-fit` card) over editing every cell. Where a cell has a hard-coded `max-width` for text, override it in compact layout and leave the original in place, so the old layout stays identical.
- The team's Action texts, customer data, keys and passwords never go into the handoff folder, tests or commit messages.
- Budget: medium job. At most 2 attempts at the same failing step, then stop and report what you tried and what you need.

## Threat model
Trusted: the code base and this handoff. Untrusted: nothing new enters; the text columns already print stored text escaped through Alpine `x-text`. Keep it that way: no `x-html`, no innerHTML for those texts.

## Allowed commands
git status, git switch -c feat/013-compact-table 617fb34, git add, git commit, git diff, git log, git show, git grep; the Herd php.bat with: artisan test (any filter), -l on a file, artisan make:test, artisan route:list. You have no browser: say plainly in RESULT.md what was not seen in one.

## Done when
1. `git diff --stat 617fb34..HEAD` shows only resources/views/owner/private.blade.php, resources/views/owner/_fit_to_width.blade.php (and at most one new partial under resources/views/owner), tests, and the handoff folder. `git diff 617fb34..HEAD -- resources/views/item/_table_old.blade.php` is empty.
2. A feature test (or an extension of the existing tests for this page) proves, for a CEO user: the page renders 200; the compact switch and the Actions button are in the HTML; the Actions button is absent for a non-CEO role; the CSS rules for the compact state are present. Show the run.
3. `php artisan test` on the branch head adds no new failures against the base (base: 507 passed, 3 skipped, 1 failed, the old ExampleTest). Show the summary line.
4. In RESULT.md, a table "column -> width rule in compact layout" for every column id in the column list, and the arithmetic showing the expected total width at a 1707 px viewport with the CEO's columns, so Mira can compare with what the browser measures.
5. In RESULT.md, the exact JavaScript one-liner Mira can paste in the browser console to measure: card scrollWidth and clientWidth, the zoom in use, the rendered height of the first five rows, and the rendered width of each text column.
6. The old layout state of the switch produces the same HTML attributes and inline styles as on 617fb34 for the table cells (state how you checked).
7. No commit message carries an attribution line.

## Out of scope
The breakdown view's own layout, the item page, the column settings page, any new column, any change to what the texts say, mobile redesign.

## Report back
RESULT.md with: Plan; Summary (three lines); What changed (files, why); Evidence per done-when item; Rulings you made (each with the reason and its cost); Deploy steps for Mira (caches to clear); What a browser check must look at (step by step, with the one-liner from item 5); Proposed tasks; Suggestions for Mira. End your final message with the branch head.
