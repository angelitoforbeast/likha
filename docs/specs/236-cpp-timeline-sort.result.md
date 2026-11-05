# Result 236: sort the table in the CPP timeline snapshot popup

Status: done. Branch `feat/236-cpp-timeline-sort`, cut from `31d2b123`. Not pushed, not deployed.

## What shipped

One slice (risk tier low), commit `feat: the CPP timeline snapshot popup sorts by a header click (spec 236)`:

- `public/js/cpp-sort.js`: three new functions, `sortItems`, `nextFor` and `aria`. They use the spec 230 rules that already exist (`key`, `numeric`, `next`, `order`). `attach` and the three CPP tables are unchanged.
- `resources/views/ads_manager/cpp_timeline.blade.php`: the page loads `cpp-sort.js` with the same versioned script tag as /cpp. Each of the nine popup headers has `tabindex="0"`, a click handler, an Enter handler, a Space handler and `:aria-sort`. The view also gets the same ▲/▼ `aria-sort` CSS as /cpp and a pointer cursor. The sort state (`modal.sort`) lives on the modal and is cleared in `openDetail`. `modalVisibleRows()` filters zero-spend rows as before, then sorts.
- Design note: the popup rows are drawn by an Alpine `x-for`, so the **data** is sorted, not the `<tr>`s. Moving the rows in the DOM as `attach` does would fight Alpine's own row order on the next re-render, for example when "Show 0-adspent" is toggled. Each column sorts on its raw value from `/ads_manager/cpp/timeline/detail` (`page_name`, `item_names`, `amount_spent`, …). It goes through the same `key()`, so numeric strings sort as numbers, and null or empty values go last.
- Copy still reads the table in DOM order, so it copies the rows in the order shown. The arrow comes from CSS, so it is not part of the copied text.
- TOTAL is in the table's `<tfoot>`, outside the sorted rows, so it always stays at the bottom. The popup has no child rows, so the parent/child rule has nothing to act on here.

## Case table

Command: `php.bat vendor/bin/phpunit tests/Feature/CPP` → `OK (22 tests, 118 assertions)`.

| Case | Test | Result |
|---|---|---|
| S-236.1.1 | `CppTimelineSortTest::test_S_236_1_1_a_numeric_column_sorts_highest_first_then_reversed_then_back_to_the_original_order` | pass |
| S-236.1.2 | `CppTimelineSortTest::test_S_236_1_2_a_text_column_sorts_a_to_z` | pass |
| S-236.1.3 | `CppTimelineSortTest::test_S_236_1_3_the_total_row_stays_last_and_empty_cells_go_last` | pass |
| S-236.1.4 | `CppTimelineSortTest::test_S_236_1_4_opening_a_new_snapshot_starts_unsorted` | pass |
| S-236.1.5 | `CppSortTest` (11 tests, unchanged) and `CppAdGroupRowsTest` (6 tests) | pass |
| wiring | `CppTimelineSortTest::test_S_236_the_popup_headers_sort_by_click_enter_or_space_and_show_the_arrow` | pass |

Red run (before the code): all five new tests failed with `TypeError: c.sortModal is not a function` / `c.modalAria is not a function` (the wiring test failed on the missing script tag).

How the tests run: the same way as CppSortTest. They render the timeline page, take its own `cppTimeline()` script and run it in Node with `cpp-sort.js` and a stubbed `fetch` that returns a snapshot. So the cases exercise the page's real Alpine component methods, not a copy.

## Full suite

`php.bat vendor/bin/phpunit`: `Tests: 4560, Assertions: 77652, Failures: 2, PHPUnit Deprecations: 95, Skipped: 6.`. The two failures are the known ones, `ExampleTest::test_the_application_returns_a_successful_response` and `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`. There are no new failures.

Note: `php.bat artisan test tests/Feature/CPP` in this worktree marks every CPP test, including the unchanged spec 230 ones, as a warning (`file_get_contents(...)`). Plain `vendor/bin/phpunit` runs them all green. This comes from the environment and the runner, not from this change.

Lint: `php.bat -l` on the new test file and `node --check public/js/cpp-sort.js` both pass. `npm run build` is not needed: `cpp-sort.js` is a plain file in `public/`, not a Vite asset.

## Story changes

None. The five cases were added as written under `## Slice 236 – Sort the CPP timeline snapshot` in `qa/stories.md`. S-236.1.3 states where TOTAL lives (the table foot), and one wiring test covers decision 4 (Enter or Space).

## Review

Risk tier low: the main session ran lint and tests; there was no reviewer pass, per the tier table. Browser check: not run (no browser session in this worker). Opening a snapshot at /ads_manager/cpp/timeline and clicking a header is the manual check.

## Accepted findings

None open.

## Spec conflicts

None.

## Merge danger

Low. The change touches only `cpp-sort.js` (functions added, `attach` untouched), the timeline view's popup header and Alpine methods, a new test file and `qa/stories.md`. There is no migration, route or config change. The script tag's `?v=` hash changes, so browsers fetch the new `cpp-sort.js` on both /cpp and the timeline.

## Suggestions for the owner

- `num(null)` shows `0` in the Orders/Proceed columns while the sort treats null as empty (last). The detail endpoint always returns numbers there today, so nothing visible changes.
