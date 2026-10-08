# Result: spec 016 Night run rows on Checker 1

> Committed beside the spec: names no people and no decision ids.

**Status:** done
**Date:** 2026-10-08
**Branch / PR:** `feat/016-night-rows-checker1`, based on `525ef44`, head = the commit that carries this file; no PR, no push
**Preview or run link:** n/a (the worktree has no environment file, so the app was not started; three owner checks are listed in `qa/stories.md`)

## Summary

The "N for a person" count of a night on the AI checker logs page is now a link to
`/encoder/checker_1?date=<orders date>&night_step=<Astra step id>`. On Checker 1 a new small class,
`App\Support\NightStepFilter`, reads `night_step` as untrusted input and adds one more `AND` to the
page's existing base query (a sub-select on `night_astra_rows`: the step's rows that are `done` and
not PROCEED), so the rows, the six chips, the Page list and the pagination narrow together and can
never widen. One line in the fixed header says which night is shown and how many rows, with a
"Show all rows" link; a hidden field keeps the filter under Page and Filter, and changing the date
drops it. A value that is not a valid Astra step shows no rows and the notice, never the whole day
and never an error. Without `night_step` the page runs the same statements as before.

## Case table

All run with `php.bat vendor/phpunit/phpunit/phpunit --testdox <file>` on the final tree:
`Checker1NightFilterTest` → `OK (38 tests, 905 assertions)`, `NightRunPageTest` →
`OK (12 tests, 146 assertions)`. Tests are in `Checker1NightFilterTest` unless a class is named.

| Case | Test | Result |
|---|---|---|
| S-06.1 | `test_S_06_1_the_link_lists_exactly_the_nights_rows` | pass ✔ |
| S-06.2 | `test_S_06_2_a_row_edited_since_is_still_listed_with_its_current_values` | pass ✔ |
| S-06.3 | `test_S_06_3_the_filtered_table_has_the_same_columns_and_buttons` | pass ✔ |
| S-06.4 | `test_S_06_4_the_rows_found_equal_the_steps_for_person` | pass ✔ |
| S-06.5 | `test_S_06_5_a_row_with_an_empty_or_null_code_is_listed` | pass ✔ |
| S-06.6 | `test_S_06_6_a_deleted_order_is_absent_and_the_line_shows_the_gap` | pass ✔ |
| S-06.7 | `test_S_06_7_a_row_moved_to_another_date_is_not_listed` | pass ✔ |
| S-06.8 | `test_S_06_8_more_than_100_rows_are_paged_and_keep_the_filter` | pass ✔ |
| S-06.9 | `test_S_06_9_another_steps_row_on_the_same_date_is_not_listed` | pass ✔ (added by fix list 1) |
| S-07.1 | `test_S_07_1_chips_and_page_list_count_the_filtered_set` | pass ✔ |
| S-07.2 | `test_S_07_2_choosing_a_page_keeps_the_filter_without_pagination` | pass ✔ |
| S-07.3 | `test_S_07_3_filter_chips_and_pagination_keep_the_filter` | pass ✔ |
| S-07.4 | `test_S_07_4_a_status_chip_on_top_is_the_intersection` | pass ✔ |
| S-07.5 | `test_S_07_5_the_date_picker_drops_the_filter` (drawn markup and the plain page of the new date) + owner check | pass ✔; the click itself not run (manual) |
| S-07.6 | `test_S_07_6_without_a_date_the_steps_orders_date_is_used` | pass ✔ |
| S-07.7 | `test_S_07_7_another_date_gives_the_intersection_and_says_so` | pass ✔ |
| S-07.8 | `test_S_07_8_the_button_scripts_are_unchanged_and_carry_no_night_step` | pass ✔ (characterisation; script text only) |
| S-08.1 | `test_S_08_1_the_line_names_the_night_and_the_count` | pass ✔ |
| S-08.2 | `test_S_08_2_show_all_rows_drops_only_the_night_filter` | pass ✔ |
| S-08.3 | `test_S_08_3_no_line_without_the_parameter` | pass ✔ |
| S-08.4 | `test_S_08_4_n_ignores_page_filter_and_chip_and_m_is_the_steps_count` | pass ✔ |
| S-08.5 | `test_S_08_5_a_step_with_no_night_rows_says_0_of_0` | pass ✔ |
| S-08.6 | none: owner check in `qa/stories.md` | not run (manual) |
| S-08.7 | `test_S_08_7_the_line_holds_nothing_from_a_row` | pass ✔ |
| S-09.1 | `NightRunPageTest::test_S_09_1_the_collapsed_count_is_a_link` | pass ✔ |
| S-09.2 | `NightRunPageTest::test_S_09_2_the_expanded_count_is_the_same_link` | pass ✔ |
| S-09.3 | `NightRunPageTest::test_S_09_3_a_count_of_zero_is_plain_text` | pass ✔ |
| S-09.4 | `NightRunPageTest::test_S_09_4_no_astra_step_no_link` | pass ✔ |
| S-09.5 | `NightRunPageTest::test_S_09_5_a_non_ceo_sees_the_link_too` | pass ✔ |
| S-09.6 | `NightRunPageTest::test_the_ceo_sees_the_night_with_every_action` (existing) | pass ✔ (characterisation) |
| S-09.7 | none: owner check in `qa/stories.md` | not run (manual) |
| S-10.1 | `test_S_10_1_a_value_that_is_not_digits_shows_no_rows_and_the_notice` | pass ✔ |
| S-10.2 | `test_S_10_2_digits_that_name_no_step` | pass ✔ |
| S-10.3 | `test_S_10_3_a_step_that_is_not_astra` | pass ✔ |
| S-10.4 | `test_S_10_4_empty_or_absent_is_the_page_as_today` | pass ✔ (characterisation) |
| S-10.5 | `test_S_10_5_an_array_is_not_valid` | pass ✔ |
| S-10.6 | `test_S_10_6_very_long_digits_never_reach_the_database` | pass ✔ |
| S-10.7 | `test_S_10_7_sql_text_is_not_valid_and_never_in_a_statement` | pass ✔ |
| S-10.8 | `test_S_10_8_any_mix_only_narrows` | pass ✔ |
| S-10.9 | `test_S_10_9_nothing_of_the_night_rows_is_printed` | pass ✔ |
| S-10.10 | `test_S_10_10_leading_zeros_read_as_the_step` | pass ✔ |
| S-10.11 | `test_S_10_11_a_nine_digit_step_id_is_accepted` | pass ✔ (added by fix list 1) |
| S-11.1 | `test_S_11_1_a_non_ceo_gets_the_same_rows` | pass ✔ |
| S-11.2 | `test_S_11_2_a_guest_is_sent_to_sign_in` | pass ✔ (characterisation) |
| S-11.3 | `NightRunRoutesTest::test_each_route_is_for_the_ceo_only` (existing, unchanged) | pass ✔ `OK (1 test, 28 assertions)` (characterisation) |
| S-12.1 | `test_S_12_1_without_the_parameter_rows_chips_pages_and_pagination_are_as_today` | pass ✔ (characterisation) |
| S-12.2 | `test_S_12_2_without_the_parameter_no_night_table_is_read` | pass ✔ (characterisation) |
| S-12.3 | `test_S_12_3_a_chosen_page_is_still_not_paginated` | pass ✔ (characterisation) |
| S-12.4 | `test_S_12_4_updating_a_field_of_a_listed_row_works_as_today` | pass ✔ (characterisation) |

**Red lines, watched before the code of each slice:**

| Slice (commit) | Test | First failure line |
|---|---|---|
| T2 filter (`a4f774c`) | `test_S_06_1_the_link_lists_exactly_the_nights_rows` | `Failed asserting that two arrays are identical.` (19 of the 20 new tests failed; run summary `Tests: 27, Assertions: 323, Failures: 19.`) |
| T2 fix loop (`9bc254d`) | `test_S_07_6_without_a_date_the_steps_orders_date_is_used` | `Expected response status code [201, 301, 302, 303, 307, 308] but received 500.` |
| T3 line and form (`aaf7436`) | `test_S_08_1_the_line_names_the_night_and_the_count` | `Failed asserting that null is identical to 'Night run filter · night of Mon, Oct 5 (orders of Oct 4) · 2 of 2 shown · Validate 1, Download and the AI buttons still use the whole date · Show all rows'.` (run summary `Tests: 38, Assertions: 578, Failures: 18.`) |
| T4 link (`d5e9858`) | `NightRunPageTest::test_S_09_1_the_collapsed_count_is_a_link` | `Failed asserting that null is identical to Array &0 [` (run summary `Tests: 12, Assertions: 137, Failures: 3.`) |
| Minors wave (`f64be03`) | `test_S_08_2_show_all_rows_drops_only_the_night_filter` | `Failed asserting that null is identical to 'b'.` |

**Characterisation cases** (commit `adf26a5`, before any product code): on the unchanged code
`Checker1NightFilterTest` gave `OK (7 tests, 163 assertions)`. What would turn each red:

| Case | Turns red when |
|---|---|
| S-07.8 | any `<script>` block of the page differs between the filtered and the plain page for the same date and Page, or a script mentions `night_step` |
| S-09.6 | the words or the order of the collapsed line change ("… rows · … PROCEED · … for a person · … failed") |
| S-10.4 | an empty, spaces-only or absent `night_step` filters the page or shows the line |
| S-11.2 | the route leaves the signed-in group, or a guest gets row content |
| S-11.3 | the CEO check of `night_run.rows` is removed (403 for a non-CEO no longer returned) |
| S-12.1 | the rows, a chip count, the Page list or the 100-per-page paging of the plain page change |
| S-12.2 | the page reads `night_run_steps` or `night_astra_rows` when there is no `night_step`, or shows the line |
| S-12.3 | a chosen Page becomes paginated |
| S-12.4 | a listed row's markup differs under the filter, or `update-field` stops changing the row as it does today |

Four more cases could not be red before their code, because they say that something is absent:
S-08.3 (no line without the parameter), S-10.9 (nothing of the night rows is printed), S-09.3 (a
count of 0 is not a link) and S-09.4 (no Astra step, no link). They were written with their slice
and were green from the first run; they turn red if the line, a night-row field or a link appears
where it must not.

## Story changes

The slice was added to `qa/stories.md` from the spec, with these changes:

- S-10.1: the input " 1" was removed; "+1" is stated as a real plus sign, sent as `%2B1` — amendment 1 (the framework trims every input before a controller reads it).
- S-10.4: "a value of spaces only" added beside empty and absent — amendment 1.
- S-07.6: the Then now says the request is redirected to the same address with `date` set to the step's orders date and every other parameter kept, and that the page then shows that date — amendment 2.
- S-08.1: the words "Validate 1, Download and the AI buttons still use the whole date" in place of "Buttons above still use the whole date" — amendment 3.
- S-07.8: names Validate 1, Download, the VALIDATED badges and the AI Checker and Astra Check count and start as the whole-date requests; Validate and ITEM CHECKER (which send the ids of the rows in the table) are pinned as unchanged script text — amendment 3.
- S-07.5: typed "edge, owner check" and listed under Owner checks as well — amendment 7.
- S-08.6 and S-09.7: the sentence "No automated test: list it under Owner checks" became the Test column entry "none: see Owner checks", in the file's existing form.
- S-06.9 added (edge): a second Astra step with a `done`, not proceed row that points to an order of the same orders date; the first step's link does not list that order, and the line counts only the first step's rows — fix list 1: no fixture had two steps with night rows on one date, so a leak from another step would not have been noticed.
- S-10.11 added (edge): an Astra step whose id has 9 digits (123456789) is accepted, its night rows are listed and the line says how many — fix list 1: the only valid ids tested were 1 and 7, so the upper bound of the digit rule was not pinned.

## Fix list 1

Asked: two gaps in the tests, found by mutating the product code; tests only, no product file
changed. (1) No test accepted a long valid step id: with the digit cap lowered, every test stayed
green. (2) The `step_id` condition of the sub-select was caught only through the M of "0 of 0
shown"; no fixture had another step's night row on the same orders date.

Done: two cases added to `qa/stories.md` (S-06.9, S-10.11), each with a test named after it in
`Checker1NightFilterTest`. Each was shown red against the mutation it is for, then the product
file was restored with `git checkout -- app/Support/NightStepFilter.php` and the tests shown green.

| Case | Mutation of `NightStepFilter` (not committed) | Test | First failure line |
|---|---|---|---|
| S-10.11 | the digit cap `{1,9}` lowered to `{1,8}` in `fromRequest` | `test_S_10_11_a_nine_digit_step_id_is_accepted` | `Failed asserting that two arrays are identical.` (run: `Tests: 40, Assertions: 925, Failures: 1.`) |
| S-06.9 | `where('step_id', $this->stepId)` removed from `nightRows()` | `test_S_06_9_another_steps_row_on_the_same_date_is_not_listed` | `Failed asserting that two arrays are identical.` (run: `Tests: 40, Assertions: 919, Failures: 3.`; the other two red tests under this mutation were S-08.5 and S-10.11, through the M of the line) |

- Green on the real code, before the mutations and again after the restore: `OK (40 tests, 928 assertions)`.
- The product file is untouched: the checksum of `git diff 525ef44 -- app/Support/NightStepFilter.php` was the same before the mutations and after the restore (`751533f1e0b026af85e6c72f0ec4320f41e81df1`), and `git status` showed only the test file as changed.
- Whole suite, plain PHPUnit, after the change: `Tests: 589, Assertions: 6575, Errors: 1, Failures: 1, Skipped: 3.` — the same two known red tests (`ExampleTest`, `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`), no new failure. 589 = 587 + the two new tests.
- Commits: one `test:` commit (the two tests and the two story rows) and one `docs:` commit (this result). The slice now reads 47 passed, 2 not run (the two phone checks).

A note on S-06.9's fixture: two Astra steps cannot share a night (one step per night and kind), so
the second step is another night whose row points to an order that sits on the first night's
orders date; that is the way such a row can exist (an order moved to that date, or a manual run).

## Done-when checklist

- [x] Cases S-06.1 to S-12.4 pass, each with a test named after it, except S-08.6 and S-09.7 — see the case table; `OK (38 tests, 905 assertions)`, `OK (12 tests, 146 assertions)`, `OK (1 test, 28 assertions)`.
- [x] A red line for every feat slice, watched before the code; characterisation cases with their green run and what would turn them red — see the two tables above. Order of commits: `adf26a5` (characterisation tests) before any product code; each feat or fix commit holds its tests and its code together. Two things to know: the test of S-10.10 passed in the first red run for a wrong reason (its date held only one order); a second order was added and the test was then shown red with the filter line switched off (`Failed asserting that two arrays are identical.`) before commit. And the last commit (`f64be03`) adds two assertions asked for by the review to code already in place (hostile parameters in the "Show all rows" address, the spacing around the link in S-09.1); they were green on arrival.
- [x] `git diff 525ef44 --stat` lists only the allowed files:
  ```
  app/Http/Controllers/MacroOutputController.php
  app/Support/NightStepFilter.php                    (the one new class)
  docs/plans/016-night-rows-checker1.md
  docs/specs/016-night-rows-checker1.md
  docs/specs/016-night-rows-checker1.result.md
  qa/stories.md
  resources/views/encoder/_night_run.blade.php
  resources/views/macro_output/index.blade.php
  tests/Feature/NightRun/Checker1NightFilterTest.php
  tests/Feature/NightRun/NightRunPageTest.php
  tests/Feature/NightRun/NightRunTestCase.php
  TODO.md                                            (accepted minors)
  ```
- [x] `MacroOutputController.php`: the diff's hunks are `@@ -13,6 +13,7 @@` (one import) and three hunks headed `public function index(Request $request)`. `git diff 525ef44 -- app/Http/Controllers/MacroCheckerController.php routes/` prints 0 lines.
- [x] `php.bat -l` on every changed PHP file: `No syntax errors detected` for `MacroOutputController.php`, `NightStepFilter.php`, `Checker1NightFilterTest.php`, `NightRunPageTest.php`, `NightRunTestCase.php`.
- [x] Full suite before and after, plain PHPUnit: before `Tests: 544, Assertions: 5598, Errors: 1, Failures: 1, Skipped: 3.`; after `Tests: 587, Assertions: 6552, Errors: 1, Failures: 1, Skipped: 3.` (43 new tests). The same two red tests both times, the two the spec names: `ExampleTest::test_the_application_returns_a_successful_response` and `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`. No new failure. (`artisan test`: before `2 failed, 485 warnings, 57 passed (5598 assertions)`, after `2 failed, 528 warnings, 57 passed (6552 assertions)`.) `composer.json` and `composer.lock` show no diff.
- [x] The risk tier's review was done — see "Review" under Tests.
- [x] `qa/stories.md` has the slice with the Test column filled and the three owner checks (S-07.5, S-08.6, S-09.7) under `## Owner checks`.
- [x] This result is filled in.

## How to run

From a fresh clone of the branch (no environment file is needed; `phpunit.xml` carries the test key):

```
"C:/Users/Forbeast/.config/herd/bin/php.bat" "C:/Users/Forbeast/.config/herd/bin/composer.phar" install --no-interaction
"C:/Users/Forbeast/.config/herd/bin/php.bat" vendor/phpunit/phpunit/phpunit tests/Feature/NightRun/Checker1NightFilterTest.php
"C:/Users/Forbeast/.config/herd/bin/php.bat" vendor/phpunit/phpunit/phpunit tests/Feature/NightRun/NightRunPageTest.php
"C:/Users/Forbeast/.config/herd/bin/php.bat" vendor/phpunit/phpunit/phpunit
```

In the running app: open the AI checker logs page, click a night's "N for a person".

## Rulings

Amendment 016-1 was saved in the main checkout's handoff folder and applied in full: (1) `night_step` is read after the framework's trim, " 1" left S-10.1, a spaces-only value is "empty"; (2) a valid `night_step` with no `date` redirects with the step's orders date and the other parameters kept, a "not valid" one does not redirect; (3) the new words of the line and of S-07.8; (4) the fixed `0` in the hidden field of a "not valid" filter; (5) the one assertion of the existing logs-page test now reads the text with the tags removed, nothing else in that test changed; (6) the main session did the developer work and a separate reviewing agent reviewed it; (7) the third owner check; (8) Validate 1's redirect left as it is and named under Proposed tasks.

Ruling: the test `macro_output` table got thirteen columns, not the ten of the plan (`validate_1`, `validate_2`, `item_checker` as well) — `update-field` writes those three and S-12.4 calls it — none: nullable test-only columns.
Ruling: the date picker's `onchange` always removes the hidden field first, also on a page with no night filter (where no such field exists and nothing happens) — a conditional inside the attribute needed a Blade workaround that was harder to read than the no-op — the plain page's date input differs by that one no-op call; if byte-for-byte markup of the plain page matters, make it conditional.
Ruling: the redirect of amendment 2 writes the step id as a clean integer (`night_step=007` becomes `night_step=7`) and is built with `http_build_query`, not through `route()` — the first review showed that `route()` throws on a parameter with a numeric name and an array value — none.
Ruling: "Show all rows" removes `night_step` and the pagination's `page`, keeps every other parameter (`PAGE`, the Filter value, `status_filter`, anything else) and carries the date shown — page 3 of a short list may not exist in the whole day — a chosen chip survives the clear.
Ruling: "this date is not the night's orders date" sits right after "<N> of <M> shown", in red — the spec gives the words, not the place — one phrase moves.
Ruling: the notice compares the date as parsed, not as typed (`2026-10-4` is the same day) — the spec review asked for it — none.
Ruling: with a "not valid" filter and no `date`, the page uses the default date (yesterday) as today — amendment 2 — none.
Ruling: the link's style is blue and underlined (`text-blue-600 underline`) on both pages, `whitespace-nowrap` on the count — the logs page's other links are blue; the underline makes a count read as a link — a class change if another look is wanted.
Ruling: `role="status"` was first put on the line and then removed — the review called it not asked for — none.
Ruling: `NightStepFilter::nightRowCount()` has no guard for a "not valid" filter — the first review called the guard unreachable, the second noted its removal; the only caller is the valid branch — recorded in `TODO.md`.
Ruling: the re-check of the T2 fix was given to the same reviewing agent together with the T3 review, as a separate, scoped part — one agent run less; the verdicts are separate — none.
Ruling: three T3 tests first stopped with a PHP type error instead of a failed assertion (a null line passed to a string assertion); they were changed to fail properly and the red run was repeated before the view was written — an erroring test proves nothing — none.
Ruling: the tasks ran one after another in this worktree — small job, neighbouring files — none.
Ruling: no browser check — no environment file, so the app cannot be started here — the phone width, the tap and the date-picker click are owner checks.

Deviations from the rules on commands, reported so that none is hidden: (a) during the plan run, one command was typed as `cd <worktree> && composer install …`; it failed ("composer: command not found") and nothing was installed by it; the install was then done once with `php.bat composer.phar install --no-interaction --working-dir=<worktree>`. (b) During T3 one stray `php.bat artisan tinker --execute="echo 1;"` was run in a command that was meant only to look at a compiled view; it read and wrote nothing. Neither changed a file.

## Deferred minors

Accepted, with reasons, in `TODO.md` under "016: night run rows on Checker 1":

- `nightRowCount()` has no guard for a "not valid" filter (the only caller is the valid branch).
- The rules "the night's rows" and "an Astra step" are written twice (the other copies are in files this work may not touch); proposed as a task.
- The date formats `D, M j` / `M j` are written in two views (second use).
- The "night tables missing" branch has no test (trusted path; it fails closed).
- The sub-select and the boolean `proceed` are untested on MySQL and pgsql (sqlite only here; query builder only).
- S-07.5 and S-07.8 read markup and script text and run no JavaScript (no runner in the project).
- S-09.4 has no variant for an empty by-code list.

Fixed in the last wave (untrusted paths): "Show all rows" kept a parameter with a numeric name under the wrong name (`array_merge` renumbered it); a test with hostile parameter names and values on that address; the comment that claimed nothing of the address bar is in the line; the reason for dropping `page`; the spacing between the link and its neighbours in the logs line; `date=` and `date=%20` on the redirect.

## Merge danger

A two-way door. No migration, no data written, no new dependency, no route, role or middleware
change, no build step (the pages load Tailwind from its CDN script, so the new classes need no
`npm run build`). Blast radius if wrong: the Checker 1 page for every signed-in staff member (the
`index` method runs on every load; without `night_step` it adds one null check and no query) and
the Night run block on the AI checker logs page. Revert: revert the squash commit; nothing is left
behind. Two things could not be checked here and are worth one look after deploy: the speed of the
sub-select on production MySQL (a night can hold up to 20,000 rows; the outer query is limited to
one date and `night_astra_rows` has the index `(step_id, state)`), and the three owner checks in
`qa/stories.md`.

## Conflicts with CLAUDE.md

- The kit names `backend-developer` and `frontend-developer` as the developers; this session is not offered them. The main session did the developer work under the same rules, and the reviewing agent stayed separate (accepted in amendment 6).
- The kit's browser check could not run (no environment file, the app cannot be started in this worktree).
- The kit's own hook tests (`node --test 'test/hooks/*.test.mjs'`) were not run: outside the allowed commands, and no kit file was touched.

## Tests

- New: `tests/Feature/NightRun/Checker1NightFilterTest.php` (38 tests, every case of S-06, S-07, S-08, S-10, S-11.1, S-11.2 and S-12 at the route `/encoder/checker_1`), five tests and one helper in `tests/Feature/NightRun/NightRunPageTest.php` (S-09), thirteen nullable columns on the test `macro_output` table in `NightRunTestCase.php`.
- Latest result, plain PHPUnit, whole suite: `Tests: 587, Assertions: 6552, Errors: 1, Failures: 1, Skipped: 3.` — the two red tests are the two known ones named in the spec; everything else passes.
- Limits: S-07.5 and S-07.8 pin the drawn markup and the text of the scripts; the scripts are not run (no JavaScript test runner). The database is sqlite; MySQL and pgsql are not exercised.

**Review (risk tier high).** The reviewer was the kit's `skeptic-reviewer` agent, separate from the
main session, which wrote the code (amendment 6).

| What | Depth | Verdict | Findings and what was done |
|---|---|---|---|
| Spec and plan, before code | spec review | ready | no blocker, no major; five changes taken into the plan |
| T2, `adf26a5..a4f774c` | adversarial | fix needed | 1 major: the redirect passed the query to `route()`, and a parameter with a numeric name and an array value (`night_step=<valid>&5[]=a`, no `date`) made it answer 500. Fixed test-first in `9bc254d`. Minors: view data without a test (pinned in T3 by S-08.4, S-07.7, S-06.6, S-06.7), `date=` and `date=%20` on the redirect (added), the unreachable guard (removed), the duplicated rules and the untested "tables missing" branch (accepted) |
| T2 fix, `a4f774c..9bc254d` | re-check, scoped | pass | the major is closed; 1 minor (the removed guard), accepted |
| T3, `9bc254d..aaf7436` | adversarial | pass | no blocker, no major; it could not inject text into the new markup, lose a parameter or break the page without `night_step`. 6 minors: 4 fixed in `f64be03`, 2 accepted (see Deferred minors) |
| T4, `aaf7436..d5e9858` | standard | pass | no blocker, no major; 3 minors: the spacing around the link is now asserted (`f64be03`), 2 accepted |

Fix loops used: 1 of 2 (T2). T1 and T5 (tests, stories, docs) were checked by the main session
with lint and the tests.

## Open questions

None.

## Process suggestions

- This shell has no `composer` command; Herd ships `composer.phar` — the first install try failed with "composer: command not found". Name it in the Stack section as `php.bat <herd>/bin/composer.phar install --no-interaction --working-dir=<worktree>`.
- `artisan test` in a worktree without an environment file reports most tests as "warnings" (528 of 587 here) — the Stack section's test command gives an unreadable summary; plain `vendor/phpunit/phpunit/phpunit` gives the real numbers.
- `php.bat` is a batch file: a `|` inside a `--filter` argument is taken by the shell and the run prints nothing — one filter per run, or run the file.
- A redirect or link built from the request's own query should always be tried with a parameter that has a numeric name and one with an array value — that is how the one major of this work was found.
- `handoff/README.md` in the main checkout still describes the older protocol and has no rows for 014 and 015.
- The session that runs a spec is told to use the kit's developer agents but is not offered them.

## Proposed tasks

- An invalid `date` on Checker 1 answers 500 — measured signed in during the plan: `date=abc` and `date=2026-13-45` give `InvalidFormatException`, `date[]=1` gives `TypeError`; any staff member can produce it from the address bar — normal.
- The AI Checker and Astra Check buttons read `date` and `PAGE` from the address bar while the other buttons read the picker — they disagree whenever the address has no `date`; this work closes it only for a valid `night_step` (by the redirect) — normal.
- Validate 1's server-built redirect keeps only `date`, `PAGE`, `status_filter` and the To Fix filter — a night filter (or any other parameter) is gone after a successful Validate 1, without a word — low.
- One shared definition of "the night's rows" and of "an Astra step" for `NightRunSummary`, `NightRunController` and `NightStepFilter` — each rule is now written twice — low.
- An array as `PAGE` (`PAGE[]=x`) most likely errors when the view prints it — read from the code by the spec review, not run — low.
- Find the file behind the `artisan test` warnings in a worktree without an environment file — it hides the pass count — low.

## Suggested next steps

Review the branch (`525ef44..HEAD`). After the merge and the deploy, do the three owner checks in
`qa/stories.md` (S-07.5, S-08.6, S-09.7) on the live page and look once at how fast a
night-filtered Checker 1 page loads for a large night.
