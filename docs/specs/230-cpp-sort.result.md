# Result 230: sort the CPP tables by clicking a column header

## Status

Done, not pushed, not deployed. No migration, no server change. Branch `feat/230-cpp-sort` from `b26149a5`.
Low tier, so no reviewer pass: lint and tests were run by the main session (see Checks). Browser check skipped:
this session has no browser tools, and the page needs a signed-in user with ad data (see Open questions).

Merge danger: a two-way door. Blast radius: the CPP page only (`/ads_manager/cpp`), in the browser. Nothing is
sorted until a header is clicked, so the page opens exactly as today. Revert: revert the commit; nothing is stored.

## Plan

One low-risk frontend slice, no server change.

1. `public/js/cpp-sort.js`, a plain script like `public/js/chatbot-live-rules.js` that sets one name, `CppSort`:
   - pure rules: the sort key of a cell (`data-sort` when the cell has one, else its text with ₱, commas and %
     taken off; "—" or empty is no value), the click cycle per column (number: ▼ → ▲ → original; text: A→Z →
     Z→A → original), and the row order (rows with no value last in both directions, ad group rows
     (`cpp-ag-row`) move with the page row above them in their own order, rows marked `data-sort-fixed` (TOTAL)
     stay at the bottom);
   - `attach(table, states, name)`: wires the headers (pointer cursor, `tabindex="0"`, click, Enter and Space),
     sets `aria-sort` on the sorted header only, and applies the stored sort to the freshly drawn rows by
     moving the `<tr>` nodes, so both Copy buttons (which read the table's rows in DOM order) copy what is shown.
2. `cpp.blade.php`: include the script; mark both TOTAL rows `data-sort-fixed`; give the date cells
   `data-sort="<ISO date>"`; give the by-date table an id; call `CppSort.attach` after each table is drawn, with
   one `states` object for the visit, so a re-render keeps the sort. The ▲/▼ comes from CSS on `aria-sort`
   (`::after`), so it never ends up in the copied header text.
3. Tests: `tests/Feature/CPP/CppSortTest.php`, one test per case, running the script in Node with the repo's
   `tests/js/dom-stub.cjs` (the way `AdGroupPagesTest` runs the ad group panel), plus one feature test that the
   page loads the script and wires all three tables.
4. Stories into `qa/stories.md`, slice 230; the full suite once at the end against the baseline run on `b26149a5`.

## Summary

Built as planned, in one commit (`feat(cpp): click a column header to sort the three CPP tables (spec 230)`):

- `public/js/cpp-sort.js` (new, ~105 lines, ASCII only like the other plain scripts): `CppSort.key`, `numeric`,
  `next`, `order` and `attach`.
  - Key: a cell's `data-sort` if set, else its text. ISO dates become `yyyymmdd` numbers. ₱ and % are dropped;
    commas only when they are thousands separators, so `₱1,200.00` is 1200 but `₱499, ₱599` (a list of prices)
    stays text. Empty or "—" is no value.
  - A column is a number column when every page row's value in it is a number; the click cycle is
    desc → asc → original for numbers (and dates), asc → desc → original for text. Text compares case-blind with
    numeric collation. Ties and empty cells keep the drawn order.
  - Order: a page row and the `cpp-ag-row` rows under it are one block; blocks are sorted, empties last in both
    directions; `data-sort-fixed` rows (TOTAL) always go last.
  - `attach` runs after every draw: the drawn order is the "original" order, and the sort kept in the page's one
    `cppSorts` object (per table: `page`, `date`, `single`) is applied right away, so a date, page or item filter
    change that redraws the table keeps the sort. Nothing is saved.
- `resources/views/ads_manager/cpp.blade.php` (+16, −5): the script tag (with the `?v=` hash, as the chatbot
  editor does), the two CSS lines for ▼/▲ on `th[aria-sort]`, `const cppSorts = {};`, `data-sort-fixed` on both
  TOTAL rows, `data-sort="${date}"` on both date cells, `id="cppDateTable"` on the by-date table, and three
  `CppSort.attach(...)` calls right after the tables are drawn. No query, controller or other markup changed; the
  look is unchanged apart from the pointer cursor and the arrow on the sorted header.
- `tests/js/dom-stub.cjs` (+1): `removeAttribute`, which `attach` uses to clear `aria-sort`.
- `tests/Feature/CPP/CppSortTest.php` (new): 11 tests.
- `qa/stories.md`: slice 230 with the case tables.

## Checks (command and output)

PHP is `"/c/Users/Forbeast/.config/herd/bin/php.bat"`, shortened to `php` here.

Red run (before `public/js/cpp-sort.js` existed), `php artisan test tests/Feature/CPP/CppSortTest.php`:

```
  ⨯ s 230 1 1 cpp sorts highest first then lowest first then back to the original order
  ... (all 11 ⨯)
  Tests:    11 failed (12 assertions)
Error: Cannot find module '...\public\js/cpp-sort.js'
```

(The first attempt at the red run stopped on a name clash: the helper `page()` collides with
`ChatblastTestCase::page()`; renamed `cppPage()`. The second green attempt failed because `require` loads a
`.js` file as an ES module in this repo (`"type": "module"`); the test now runs the script with
`vm.runInThisContext`, as a browser would.)

Lint: `php -l tests/Feature/CPP/CppSortTest.php` → `No syntax errors detected`; `node --check public/js/cpp-sort.js` → ok.

## Rulings

- Ruling: the three tables are `#summaryOfAdsTable` (by page), the all-pages "Performance by Date" table (now
  `#cppDateTable`) and `#singlePageTable` (one page selected: by date with Item Names, CODs, PROCEED) — they are
  the tables at the spec's three line numbers — cost if wrong: one table without sorting, a small follow-up.
- Ruling: the sort reads the cell (its text, or `data-sort` for dates) instead of the numbers in `rawData` —
  every number cell is drawn from the value with `toFixed(2)` and a ₱ or %, so the text gives back the exact
  value, and this keeps the change to markup attributes instead of rewriting the three row templates — cost if
  wrong: a future cell format the parser does not know (say "1.2k") would sort as text; caught by the key tests.
- Ruling: dates count as a number column (newest first on the first click), as the spec's S-230.1.3 asks, over
  the "text A→Z first" reading of a "Nov 5, 2026" label — cost if wrong: one more click.
- Ruling: a column is a number column only when every page row's value in it is a number. Item Price on the
  by-page table holds lists ("₱499, ₱599") on some rows, so there it is text (A→Z first, numeric collation, which
  reads "₱1,200" by its first digit group, so it can come before "₱900") — cost if wrong: on a table with mixed
  rows, Item Price sorts like text, not by amount; when every row has one price it sorts as a number.
- Ruling: the ▼/▲ is drawn by CSS from `aria-sort` (`th[aria-sort]::after`), not added as text, so the copied
  header row stays "CPP", exactly as today; the copy functions are unchanged — cost if wrong: none for the sheet;
  a screen reader announces the sort through `aria-sort`, not the arrow.
- Ruling: `aria-sort` is removed from the other headers rather than set to "none" (one sorted header at a time,
  as S-230.3.1 says "on that header only") — cost if wrong: none; both are valid ARIA.
- Ruling: rows marked `data-sort-fixed` are kept at the bottom; only the two TOTAL rows carry it — cost if wrong:
  none today; a new summary row would need the mark.
- Ruling: "Copy with ad groups" and "Copy Table" both copy the shown order because the `<tr>` nodes themselves
  move; Copy Table still leaves the ad group rows out, as before — cost if wrong: none.
- Ruling: tested the way the repo tests other page scripts (PHPUnit running the script in Node on
  `tests/js/dom-stub.cjs`, like `AdGroupPagesTest`), not with a separate `node --test` file, so the cases run
  in the project's test command — cost if wrong: none; the tests skip with "Node is not installed" where there
  is no Node, like the existing ones.
- Ruling: one line added to the shared DOM stub (`removeAttribute`); it is additive and the stub's other user
  (`AdGroupPagesTest`) does not call it — cost if wrong: none.

## Story changes

None. The spec's cases are in `qa/stories.md` as written, with the Given / When / Then filled in from the
decisions (for example "upper and lower case together" for .1.2, "by the date, not the text" for .1.3).

## Open questions

None blocking.

## Proposed tasks
