# Result 230: sort the CPP tables by clicking a column header

## Status

In progress. Branch `feat/230-cpp-sort` from `b26149a5`.

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

## Checks (command and output)

## Rulings

## Story changes

## Open questions

## Proposed tasks
