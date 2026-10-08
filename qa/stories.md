# Stories

One section per slice. Each story has a story line, an independent test and a case table
(Given / When / Then) with the test that proves the case and its last run.

## Owner checks

No JavaScript test exists for the order page, so these are checked by hand on the running page
(the order page under `/encoder/checker_1`). Tick each one when seen.

- [ ] S-03.4 (slice 015): on a row whose AI check fails, click that row's AI Fix button. The alert should read "Row #N failed: AI check failed. Ref: log #<id>", not "HTTP 500", and hold no customer text.
- [ ] S-03.5 (slice 015): click AI Checker on a batch that holds a row that fails. That row should show the Failed mark, the failed counter should go up by one, and the loop should go on to the next row.
- [ ] S-07.5 (slice 016): on a night-filtered page, change the date; the address has no `night_step` and the line is gone.
- [ ] S-08.6 (slice 016): open a night-filtered page (the "for a person" link on the AI checker logs page) on a phone, or at a width of 390 x 844. The "Night run filter" line should wrap and the first table rows should not be hidden under the fixed header.
- [ ] S-09.7 (slice 016): on a phone, tap a night's "N for a person" count on the AI checker logs page. Checker 1 should open in the same tab with that night's rows.
- [ ] S-13.7 (slice 017): on your usual screen, open the suppliers view (`/item?layout=suppliers`) with real data. You should be able to compare supplier prices at a glance, and the table width should be acceptable.
- [ ] S-17.7 (slice 017): with real data in the suppliers view, scroll, hover over rows and tap. The table should be as compact as your owner/private table.
- [ ] S-19.8 (slice 017): use the suppliers view for your usual day. Nothing you use in the Old view should be missing.

## Slice 015 – row runner error message

Slice 015: 19 passed, 0 failed, 0 blocked, 0 skipped, 2 not run

New tests are in `tests/Feature/NightRun/RowRunnerErrorMessageTest.php` unless another class is
named.

### S-01 A failed row never leaks customer text to the browser (P1)

As a staff member running AI Fix or AI Checker, I want a failed row to show a short fixed message
with a reference, so that customer names, phones, addresses and chat text never appear in a
browser response.

Independent test: make the engine throw an exception whose message holds a made-up customer line,
call run-row, and check the response has the fixed message and the reference but not that line.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-01.1 | happy | Given the engine throws an exception with the message "JUANA TESTCUSTOMER 123 TEST ST 09170000000", when run-row is called (Astra engine and classic engine), then status is 500, `ok` is false and `error` is exactly `AI check failed. Ref: log #<id>` with the id of the failed log row | `test_S_01_1_a_thrown_row_returns_the_fixed_message_with_the_log_id` | Passed · 2026-10-08 · auto |
| S-01.2 | negative | Given the same throw, when the whole response body, the `ai_checker_logs` row and every captured log line are searched, then the customer line appears nowhere (assert on the whole response content, not only on `error`) | `test_S_01_2_the_customer_line_appears_nowhere` | Passed · 2026-10-08 · auto |
| S-01.3 | edge | Given a message that is empty, longer than 10,000 characters, or non-ASCII ("Ñandú, Bgy. Sta. Cruz, ₱599"), when it is thrown, then the response is the same fixed message and its length does not depend on the exception | `test_S_01_3_the_message_does_not_depend_on_the_exception` | Passed · 2026-10-08 · auto |
| S-01.4 | edge | Given a nested exception whose previous exception holds the customer line, when run-row is called, then the line appears nowhere in the response or the log lines | `test_S_01_4_a_previous_exception_is_not_shown_or_logged` | Passed · 2026-10-08 · auto |

### S-02 A database failure never exposes SQL or bound values (P1)

As the owner, I want a database error to show no SQL, so that bound customer values never reach a
browser.

Independent test: make the order update fail with a sqlite trigger and check the response holds no
SQL fragment and no bound value.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-02.1 | happy | Given a trigger that aborts `UPDATE macro_output` with a marker text and a row whose chat holds "JUANA TESTCUSTOMER 123 TEST ST", when run-row is called, then 500, and the body contains none of "UPDATE", "macro_output", "SQLSTATE", "bindings", the marker, or the customer text | `test_S_02_1_a_database_failure_shows_no_sql_and_no_bound_value` | Passed · 2026-10-08 · auto |
| S-02.2 | negative | Given the same failure, when the log lines are read, then one `AI_CHECKER_ROW_FAIL` warning exists whose context is exactly `exception` (the QueryException class name), `sqlstate` and `macro_output_id`, and the exception message is logged nowhere | `test_S_02_2_a_database_failure_logs_class_sqlstate_and_row_id_only` | Passed · 2026-10-08 · auto |
| S-02.3 | edge | Given the `ai_checker_logs` insert fails as well (triggers on both tables), when run-row is called, then 500 with `error` exactly `AI check failed. No log reference.`, one `AI_CHECKER_LOG_FAIL` line and one `AI_CHECKER_ROW_FAIL` line | `test_S_02_3_both_writes_failing_gives_the_message_without_a_reference` | Passed · 2026-10-08 · auto |
| S-02.4 | edge | Given an exception that is not a database exception, when it is logged, then the context has `exception` and `macro_output_id` and no `sqlstate` key | `test_S_02_4_a_plain_exception_logs_no_sqlstate` | Passed · 2026-10-08 · auto |
| S-02.5 | edge | Given the logger throws when the `AI_CHECKER_ROW_FAIL` line is written, when a row fails, then the runner still returns status 500, `ok` false, the fixed message with the reference of the failed log row, and the five keys with `result` null | `test_S_02_5_a_failing_logger_does_not_break_the_failure_path` | Passed · 2026-10-08 · auto |

### S-03 What the page relies on is unchanged (P1)

As a staff member, I want the failure to stay readable and the page to keep working, so that the
alert and the batch counters behave as before.

Independent test: a thrown row returns 500 with `ok` false and a non-empty string `error`.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-03.1 | happy | Given an engine exception, when run-row returns, then status 500, `ok === false`, `error` is a non-empty string, and the payload's keys are exactly `ok` and `error` | `test_S_03_1_a_thrown_row_is_500_with_ok_false_and_a_string_error` | Passed · 2026-10-08 · auto |
| S-03.2 | negative | Given a row id that does not exist, when run-row is called, then 404 and exactly `['ok' => false, 'error' => 'Row not found']`, with no log row | `RunRowCharacterizationTest::test_row_not_found_is_a_404_with_no_log_row` (existing, unchanged) | Passed · 2026-10-08 · auto |
| S-03.3 | negative | Given the address file is missing, when run-row is called, then 500 and exactly `['ok' => false, 'error' => 'jnt_address.txt missing or empty']`, with no log row | `RunRowCharacterizationTest::test_missing_address_list_is_a_500_with_no_log_row` (existing, unchanged) | Passed · 2026-10-08 · auto |
| S-03.4 | edge, owner check | Given a failed row on the single-row button, when the staff member clicks AI Fix, then the alert reads "Row #N failed: AI check failed. Ref: log #<id>" and not "HTTP 500" | none: see Owner checks | Not run · manual |
| S-03.5 | edge, owner check | Given the batch loop meets a failed row, when it continues, then the row shows the Failed mark, the failed counter goes up and the loop goes on | none: see Owner checks | Not run · manual |

### S-04 The night job treats a thrown row the same (P1)

As the owner, I want the night run to handle a failed row exactly as before, so that retries,
counts and reasons do not change.

Independent test: the night job on a row that throws ends `failed` with the reason "Error while
running the row".

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-04.1 | happy | Given the runner's engine throws at night, when the job classifies it, then the row ends `failed` with the reason "Error while running the row" (existing behaviour) | `NightAstraRowJobTest::test_a_final_failure_gets_a_fixed_reason_and_nothing_from_openai_or_the_exception`, row `runner exception` (existing, unchanged) | Passed · 2026-10-08 · auto |
| S-04.2 | negative | Given that failure, when the night row, the step and the log lines are searched, then no customer text and no exception message appear | `test_S_04_2_the_night_row_step_and_log_hold_no_customer_text` | Passed · 2026-10-08 · auto |
| S-04.3 | edge | Given a thrown row at attempts 2, when it fails, then there is no retry and `consecutive_failures` goes up by 1 | the same existing test as S-04.1 (unchanged) | Passed · 2026-10-08 · auto |
| S-04.4 | edge | Given a thrown row, when the runner returns, then its array has exactly the keys `status`, `payload`, `result`, `log_id`, `last_error`, with `result` null | `test_S_04_4_a_thrown_row_returns_the_five_keys_with_a_null_result` | Passed · 2026-10-08 · auto |

### S-05 The reference finds the log row (P2)

As the owner, I want the reference to find the failed row's log, so that I can look it up.

Independent test: take the id from the message and find the `ai_checker_logs` row.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-05.1 | happy | Given the log row was written, when the response returns, then the id in the message equals the id of the one row with `outcome = 'failed'` and `final_code = '❌'` | `test_S_05_1_the_reference_is_the_id_of_the_failed_log_row` | Passed · 2026-10-08 · auto |
| S-05.2 | negative | Given the log write failed, when the response returns, then the message holds no id and no invented one: `AI check failed. No log reference.` | `test_S_05_2_no_log_row_means_no_id_in_the_message` | Passed · 2026-10-08 · auto |
| S-05.3 | edge | Given two failures one after another, when each response is read, then the two ids differ and each matches its own log row | `test_S_05_3_two_failures_get_two_different_references` | Passed · 2026-10-08 · auto |

## Slice 016 – Night run rows on Checker 1

Slice 016: 47 passed, 0 failed, 0 blocked, 0 skipped, 2 not run

New tests are in `tests/Feature/NightRun/Checker1NightFilterTest.php` unless another class is
named. The link is `/encoder/checker_1?date=<orders date of the night>&night_step=<Astra step id>`.
"The night's rows" are the rows of `night_astra_rows` for that step with `state = 'done'` and
`proceed` false, whatever their `code`.

### S-06 The rows Astra left for a person, on Checker 1 (P1)

As the owner, I want to click a night's "for a person" count and land on Checker 1 showing exactly
those rows, so that I can see what the encoders encoded, or will encode, on them.

Independent test: seed a step with 2 rows done and not proceed, 1 done and proceed, 1 failed and
1 skipped; open the link; the table lists exactly the 2.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-06.1 | happy | Given a step with rows in every state, when `/encoder/checker_1?date=<orders date>&night_step=<id>` opens, then the table lists exactly the night's rows and no other row of that date | `test_S_06_1_the_link_lists_exactly_the_nights_rows` | Passed · 2026-10-08 · auto |
| S-06.2 | happy | Given an encoder has since set a listed row's STATUS to PROCEED and edited its ADDRESS, when the link opens, then the row is still listed, with its current STATUS and ADDRESS | `test_S_06_2_a_row_edited_since_is_still_listed_with_its_current_values` | Passed · 2026-10-08 · auto |
| S-06.3 | happy | Given the link, when it opens, then the table has the same columns, row buttons and toolbar buttons as the unfiltered page, and no extra column | `test_S_06_3_the_filtered_table_has_the_same_columns_and_buttons` | Passed · 2026-10-08 · auto |
| S-06.4 | edge | Given a step whose rows all exist on the orders date, when the link opens, then the number of rows found equals that step's `for_person` | `test_S_06_4_the_rows_found_equal_the_steps_for_person` | Passed · 2026-10-08 · auto |
| S-06.5 | edge | Given a `done`, not proceed row of the step with an empty or null `code`, when the link opens, then it is listed | `test_S_06_5_a_row_with_an_empty_or_null_code_is_listed` | Passed · 2026-10-08 · auto |
| S-06.6 | edge | Given a night row whose `macro_output` row no longer exists, when the link opens, then the page loads, that row is absent, and the sign says how many are shown of how many | `test_S_06_6_a_deleted_order_is_absent_and_the_line_shows_the_gap` | Passed · 2026-10-08 · auto |
| S-06.7 | edge | Given a listed row whose `ts_date` was moved to another date after the night, when the link opens with the step's orders date, then it is not listed and the sign count shows the gap | `test_S_06_7_a_row_moved_to_another_date_is_not_listed` | Passed · 2026-10-08 · auto |
| S-06.8 | edge | Given more than 100 night rows and PAGE not chosen, when the link opens, then 100 per page; page 2 keeps `night_step`; every row appears once across the pages | `test_S_06_8_more_than_100_rows_are_paged_and_keep_the_filter` | Passed · 2026-10-08 · auto |
| S-06.9 | edge | Given a second Astra step with a `done`, not proceed row that points to an order of the same orders date, when the first step's link opens, then that order is not listed and the line counts only the first step's rows | `test_S_06_9_another_steps_row_on_the_same_date_is_not_listed` | Passed · 2026-10-08 · auto |

### S-07 The night filter stays under the other filters (P2)

As an encoder or the owner, I want Page, Filter, the status chips, the pagination and the date to
work on the night's rows, so that the table is not suddenly the whole day again.

Independent test: open the link, choose a Page; the URL still has `night_step` and the table is
the night's rows of that page.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-07.1 | happy | Given the link, when the page draws, then the six chip counts (TOTAL, PROCEED, CANNOT PROCEED, ODZ, BLANK, INCOMPLETE) count the filtered set and the Page dropdown lists only pages present in it | `test_S_07_1_chips_and_page_list_count_the_filtered_set` | Passed · 2026-10-08 · auto |
| S-07.2 | happy | Given the link, when a Page is chosen in the dropdown, then the request keeps `night_step`, only that page's night rows show, and there is no pagination | `test_S_07_2_choosing_a_page_keeps_the_filter_without_pagination` | Passed · 2026-10-08 · auto |
| S-07.3 | happy | Given the link, when the Filter select changes, a status chip is clicked or a pagination link is followed, then each keeps `night_step` | `test_S_07_3_filter_chips_and_pagination_keep_the_filter` | Passed · 2026-10-08 · auto |
| S-07.4 | edge | Given `status_filter=BLANK` or `INCOMPLETE` on top of the night filter, when the page draws, then the result is the intersection and never more than either alone | `test_S_07_4_a_status_chip_on_top_is_the_intersection` | Passed · 2026-10-08 · auto |
| S-07.5 | edge, owner check | Given a night-filtered page, when the date picker is changed, then the request drops `night_step` (the filter belongs to one night's orders date) and the page is the plain page of the new date | `test_S_07_5_the_date_picker_drops_the_filter` (the drawn markup and the plain page of the new date); the click itself: see Owner checks | Passed · 2026-10-08 · auto |
| S-07.6 | edge | Given a valid `night_step` with no `date`, when the address is opened, then it is redirected to the same address with `date` set to the step's orders date and every other parameter kept, and the page that draws uses and shows that date in the picker, not yesterday | `test_S_07_6_without_a_date_the_steps_orders_date_is_used` | Passed · 2026-10-08 · auto |
| S-07.7 | negative | Given `night_step` with a valid `date` that is not the step's orders date, when the page draws, then it does not error, shows the rows matching both (usually none), and the sign says the date is not the night's orders date | `test_S_07_7_another_date_gives_the_intersection_and_says_so` | Passed · 2026-10-08 · auto |
| S-07.8 | negative | Given a night-filtered page, when the scripts that build the requests of Validate 1, Download, the VALIDATED badges and the AI Checker and Astra Check count and start are inspected, then they are byte for byte what they are today for the same date and Page (they do not carry `night_step` and act on the whole date); the scripts of Validate and ITEM CHECKER, which send the ids of the rows in the table, are byte for byte unchanged too | `test_S_07_8_the_button_scripts_are_unchanged_and_carry_no_night_step` (pins the script text; the scripts are not run) | Passed · 2026-10-08 · auto |

### S-08 Checker 1 says a night filter is on, and clears it in one click (P1)

As a viewer of the table, I want one line saying which night is shown and how many rows, with a
way back, so that I do not mistake it for a short day.

Independent test: open the link; one line names the night and the count; its link returns the page
without `night_step`.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-08.1 | happy | Given the link, when the page draws, then one line shows: "Night run filter", the night date, the orders date, "<N> of <M> shown", "Validate 1, Download and the AI buttons still use the whole date", and a "Show all rows" link | `test_S_08_1_the_line_names_the_night_and_the_count` | Passed · 2026-10-08 · auto |
| S-08.2 | happy | Given the line, when "Show all rows" is followed, then the URL has no `night_step` and keeps `date`, `PAGE` and the Filter value | `test_S_08_2_show_all_rows_drops_only_the_night_filter` | Passed · 2026-10-08 · auto |
| S-08.3 | negative | Given no `night_step`, when the page draws, then the line is absent | `test_S_08_3_no_line_without_the_parameter` | Passed · 2026-10-08 · auto |
| S-08.4 | edge | Given some night rows are missing or off the date, when the line draws, then N counts the rows matching the night filter and the date alone (not narrowed by Page, Filter or a chip) and M is the step's current number of night rows | `test_S_08_4_n_ignores_page_filter_and_chip_and_m_is_the_steps_count` | Passed · 2026-10-08 · auto |
| S-08.5 | edge | Given an Astra step with zero night rows, when its link is opened by hand, then the table is empty, the line says "0 of 0 shown", and the clear link works | `test_S_08_5_a_step_with_no_night_rows_says_0_of_0` | Passed · 2026-10-08 · auto |
| S-08.6 | edge, owner check | Given a phone width of 390 x 844, when the page opens, then the line wraps and does not hide table rows under the fixed header | none: see Owner checks | Not run · manual |
| S-08.7 | negative | Given hostile text in any row field (for example `<script>alert(1)</script>` as a page name), when the line draws, then the line holds only fixed words, integers and dates, and nothing taken from a row | `test_S_08_7_the_line_holds_nothing_from_a_row` | Passed · 2026-10-08 · auto |

### S-09 The Night run block links the count (P1)

As the owner, I want the "for a person" count to be a link, so that one click takes me to those
rows.

Independent test: draw the logs page with a night that has 2 for a person; the count is a link to
`/encoder/checker_1?date=<orders date>&night_step=<step id>`.

The tests of this story are in `tests/Feature/NightRun/NightRunPageTest.php`.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-09.1 | happy | Given a night with rows for a person, when the logs page draws, then "N for a person" in the collapsed line is a link to the route with the orders date and the step id, and the words "N for a person" stay together inside the link | `NightRunPageTest::test_S_09_1_the_collapsed_count_is_a_link` | Passed · 2026-10-08 · auto |
| S-09.2 | happy | Given the expanded block, when it draws, then "N left for a person" is the same link and the by-code breakdown after it stays plain text | `NightRunPageTest::test_S_09_2_the_expanded_count_is_the_same_link` | Passed · 2026-10-08 · auto |
| S-09.3 | edge | Given N is 0, when the block draws, then it is plain text, not a link | `NightRunPageTest::test_S_09_3_a_count_of_zero_is_plain_text` | Passed · 2026-10-08 · auto |
| S-09.4 | edge | Given a night with no Astra step, when the block draws, then there is no link | `NightRunPageTest::test_S_09_4_no_astra_step_no_link` | Passed · 2026-10-08 · auto |
| S-09.5 | edge | Given a signed-in user who is not the CEO role but can open the logs page, when the block draws, then the link is shown to them too (everything CEO-only today stays CEO-only) | `NightRunPageTest::test_S_09_5_a_non_ceo_sees_the_link_too` | Passed · 2026-10-08 · auto |
| S-09.6 | negative | Given the change, when the logs page draws, then the existing test's sequence "... rows", "... PROCEED", "... for a person", "... failed" still reads in that order | `NightRunPageTest::test_the_ceo_sees_the_night_with_every_action` (existing; its one-line comparison now reads the page text with the tags removed) | Passed · 2026-10-08 · auto |
| S-09.7 | edge, owner check | Given a phone, when the count is tapped, then Checker 1 opens in the same tab with that night's rows | none: see Owner checks | Not run · manual |

### S-10 A bad parameter never errors, never widens, never leaks (P1)

As the owner, I want the URL parameter treated as untrusted input, so that no edit of the address
bar can break the page or show other data.

Independent test: send `night_step=abc`; the page answers 200, shows the notice and no rows.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-10.1 | negative | Given `night_step` of "abc", "1abc", "1.5", "-1", "+1" (a real plus sign, sent as `%2B1`), "1e3" or "0x1", when the page opens, then status 200, no rows, one line "Night run filter not valid. No rows shown." with the "Show all rows" link, and no "of ... shown" text | `test_S_10_1_a_value_that_is_not_digits_shows_no_rows_and_the_notice` | Passed · 2026-10-08 · auto |
| S-10.2 | negative | Given digits that name no step ("0", "999999"), when the page opens, then the same as S-10.1 | `test_S_10_2_digits_that_name_no_step` | Passed · 2026-10-08 · auto |
| S-10.3 | negative | Given the id of a step that is not an Astra step (an import step), when the page opens, then the same as S-10.1, and nothing about that step appears | `test_S_10_3_a_step_that_is_not_astra` | Passed · 2026-10-08 · auto |
| S-10.4 | edge | Given `night_step=` (empty), a value of spaces only, or the parameter absent, when the page opens, then it is the page as today: no filter, no line | `test_S_10_4_empty_or_absent_is_the_page_as_today` | Passed · 2026-10-08 · auto |
| S-10.5 | negative | Given `night_step[]=1` or `night_step[a]=1`, when the page opens, then no 500 and the same as S-10.1 (an array must never be cast to 1) | `test_S_10_5_an_array_is_not_valid` | Passed · 2026-10-08 · auto |
| S-10.6 | edge | Given "99999999999999999999" or a 10,000-character digit string, when the page opens, then 200, the same as S-10.1, and no database error | `test_S_10_6_very_long_digits_never_reach_the_database` | Passed · 2026-10-08 · auto |
| S-10.7 | negative | Given "1 OR 1=1", "1;DROP TABLE macro_output" or "1' OR '1'='1", when the page opens, then the same as S-10.1, `macro_output` is untouched, and the value never appears in SQL text (bindings only) | `test_S_10_7_sql_text_is_not_valid_and_never_in_a_statement` | Passed · 2026-10-08 · auto |
| S-10.8 | negative | Given any mix of `night_step`, `date`, `PAGE`, the Filter value and `status_filter`, when the page opens, then the ids shown are a subset of the night's rows and of the unfiltered result for the same other parameters | `test_S_10_8_any_mix_only_narrows` | Passed · 2026-10-08 · auto |
| S-10.9 | negative | Given any value of `night_step`, when the page draws, then it holds nothing from `night_astra_rows` beyond which rows are listed: no code, reason, cost or log link | `test_S_10_9_nothing_of_the_night_rows_is_printed` | Passed · 2026-10-08 · auto |
| S-10.10 | edge | Given "007" and a step with id 7 that is an Astra step, when the page opens, then it is read as step 7 | `test_S_10_10_leading_zeros_read_as_the_step` | Passed · 2026-10-08 · auto |
| S-10.11 | edge | Given an Astra step whose id has 9 digits (for example 123456789), when the link opens with that id, then the page lists that step's night rows and the line says how many are shown | `test_S_10_11_a_nine_digit_step_id_is_accepted` | Passed · 2026-10-08 · auto |

### S-11 Access is what it is today; the filter only narrows (P1)

As the owner, I want everyone who can open Checker 1 to be able to use the link, and nobody to see
a row they could not see before.

Independent test: open the link as a non-CEO signed-in user and as the CEO role; both get the same
rows; a guest is sent to sign-in.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-11.1 | happy | Given a signed-in user who is not the CEO role and can open Checker 1, when the link opens, then 200 and the same rows as for the CEO role | `test_S_11_1_a_non_ceo_gets_the_same_rows` | Passed · 2026-10-08 · auto |
| S-11.2 | negative | Given a guest, when the link opens, then redirected to sign-in with no row content | `test_S_11_2_a_guest_is_sent_to_sign_in` | Passed · 2026-10-08 · auto |
| S-11.3 | negative | Given a non-CEO, when `night_run.rows` is requested, then still 403 (unchanged) | `NightRunRoutesTest::test_each_route_is_for_the_ceo_only` (existing, unchanged) | Passed · 2026-10-08 · auto |

### S-12 Checker 1 without the parameter is unchanged (P1)

As an encoder using Checker 1 every day, I want the page to behave exactly as before when there is
no night parameter.

Independent test: the same fixture, with and without the change, gives the same ids, chip counts,
Page list and pagination.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-12.1 | happy | Given no `night_step`, when the page opens, then the records, the six chip counts, the Page list and the pagination are what they are today for the same fixture | `test_S_12_1_without_the_parameter_rows_chips_pages_and_pagination_are_as_today` | Passed · 2026-10-08 · auto |
| S-12.2 | negative | Given no `night_step`, when the page draws, then no query touches `night_run_steps` or `night_astra_rows` and no line shows | `test_S_12_2_without_the_parameter_no_night_table_is_read` | Passed · 2026-10-08 · auto |
| S-12.3 | edge | Given PAGE chosen and no night filter, when the page opens, then it is still not paginated | `test_S_12_3_a_chosen_page_is_still_not_paginated` | Passed · 2026-10-08 · auto |
| S-12.4 | edge | Given a night-filtered page, when a row is saved or a field updated through the existing routes, then the request and its effect are what they are today for that row id | `test_S_12_4_updating_a_field_of_a_listed_row_works_as_today` | Passed · 2026-10-08 · auto |

## Slice 017 – Suppliers group on the item table

Slice 017: 10 passed, 0 failed, 0 blocked, 0 skipped, 50 not run

New tests are in `tests/Feature/Item/SuppliersGroupTest.php` unless another class is named. "The
suppliers view" is `/item?layout=suppliers` in the effective CEO view (role CEO and `view_as` not
`marketing`).

### S-13 The CEO sees Supplier 1, 2, 3 and PO beside each item (P1)

As the CEO, I want a SUPPLIERS group right after the item, so that I can compare what each
supplier charges on one line.

Independent test: render the suppliers view as the CEO; the header has SUPPLIERS over four
sub-columns and the item-row template has four cells after the Item cell.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-13.1 | happy, server | Given the suppliers view, when it renders, then the header has two rows: Page, Item and every other header span both rows; "SUPPLIERS" spans four columns; under it Supplier 1, Supplier 2, Supplier 3, PO in that order, right after Item and before the configurable columns; the four sub-headers have no sort handler and are not draggable | `SuppliersGroupTest::test_S_13_1_header_has_two_rows_with_suppliers_over_four_plain_subheaders` | Not run · pending build |
| S-13.2 | happy, server | Given the item-row template of the suppliers view, when it renders, then four supplier cells (three quote cells and the PO cell) sit after the Item cell and before the configurable columns. The four columns are one cell spanning four for the placeholder, one spanning four for the red band, a loop over three slots and the PO cell | `SuppliersGroupTest::test_S_13_2_item_row_has_four_supplier_cells_after_the_item_cell` | Not run · pending build |
| S-13.3 | negative, server | Given the suppliers view, when it renders, then its Item cell no longer holds the supplier stack (no PO line, no quote line, no "dati" line, no grey "walang supplier", no inline quote form) and still holds "N running page(s)" and "walang running page" | `SuppliersGroupTest::test_S_13_3_item_cell_no_longer_holds_the_supplier_stack` | Not run · pending build |
| S-13.4 | edge, server | Given the suppliers view, when it renders, then each full-width row (loading, empty, no-worklist result, no-category result, the expanded page block) spans four more columns than in the Old view, the TOTAL row still starts `<td>TOTAL</td>` and its next empty cell covers Item plus the four, and a page row and the repeated per-page header each have one cell spanning the four, so every row has the same number of columns | `SuppliersGroupTest::test_S_13_4_full_width_rows_and_total_span_four_more_columns` | Not run · pending build |
| S-13.5 | happy, server | Given a CEO request with `layout=suppliers`, when it is routed, then the suppliers view renders; `layout=old` renders the Old view and anything else the default layout, exactly as today (only the exact strings select) | `SuppliersGroupTest::test_S_13_5_only_the_exact_string_suppliers_selects_the_suppliers_view` | Not run · pending build |
| S-13.6 | edge, browser | Given a column set where columns were hidden or reordered, when the suppliers view draws, then the group stays right after Item and header and body cells line up | none: browser check | Not run · browser |
| S-13.7 | edge, owner check | Given real data on his usual screen, when the suppliers view draws, then he can compare prices at a glance and accepts the width | none: see Owner checks | Not run · manual |

### S-14 Supplier 1 to 3 are the three cheapest quotes; the rest sit behind "+N" (P1)

As the CEO, I want the quotes ordered cheapest first with no-price last, so that the comparison is
by price and nothing is hidden.

Independent test: seed five quotes priced 160, 142, 148, 155 and none; GET `/item/quotes` lists
142, 148, 155, 160, none.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-14.1 | happy, server | Given quotes priced 160, 142, 148, 155 for one item, when the CEO calls GET `/item/quotes`, then they come back 142, 148, 155, 160 | `SuppliersGroupTest::test_S_14_1_quotes_come_back_cheapest_first` | Not run · pending build |
| S-14.2 | negative, server | Given one quote with no price and three with prices, when the endpoint is called, then the one without a price is last (on the in-memory sqlite too) | `SuppliersGroupTest::test_S_14_2_a_quote_without_a_price_is_last` | Not run · pending build |
| S-14.3 | edge, server | Given two quotes with the same price, when the endpoint is called twice, after an unrelated quote is saved, and in the save and delete responses, then their order is the same every time (lower id first) | `SuppliersGroupTest::test_S_14_3_equal_prices_keep_the_lower_id_first_every_time` | Not run · pending build |
| S-14.4 | edge, server | Given a quote priced 0, when the endpoint is called, then it is ordered after every priced quote, like one without a price, and is never flagged cheapest; its stored price is returned as it is | `SuppliersGroupTest::test_S_14_4_a_zero_price_is_ordered_last_and_never_cheapest` | Not run · pending build |
| S-14.5 | happy, server | Given two or more quotes with a price above 0, when the endpoint is called, then every quote at the lowest price has `cheapest: true` (ties both) and the others false | `SuppliersGroupTest::test_S_14_5_every_quote_at_the_lowest_price_is_cheapest` | Not run · pending build |
| S-14.6 | negative, server | Given exactly one priced quote, or only quotes without a price, when the endpoint is called, then no quote is `cheapest` | `SuppliersGroupTest::test_S_14_6_no_cheapest_with_one_priced_quote_or_none` | Not run · pending build |
| S-14.7 | edge, server | Given a saved price change that moves a quote from first to third, when POST `/item/quotes` returns, then its list is already in the new order | `SuppliersGroupTest::test_S_14_7_the_save_answer_is_already_in_the_new_order` | Not run · pending build |
| S-14.8 | edge, server | Given the script of the suppliers view, when read, then the helpers that take the first three and count the rest are the pinned text (first three; rest = count minus three, never negative) and they read the server's order and the `cheapest` flag instead of sorting or comparing prices themselves | `SuppliersGroupTest::test_S_14_8_script_helpers_take_the_first_three_and_count_the_rest` | Not run · pending build |
| S-14.9 | negative, server | Given the default layout's quote list and the item photo page, which read the same endpoint, when they render and the endpoint answers, then they still list every quote (characterisation: only quotes without a price, priced 0 or tied can change place) | `SuppliersGroupTest::test_S_14_9_the_default_layout_and_the_photo_page_still_list_every_quote` | Passed · 2026-10-08 · auto |
| S-14.10 | happy, browser | Given an item with five quotes, when the suppliers view draws, then Supplier 1 to 3 are the three cheapest in order and Supplier 3 carries "+2"; exactly three quotes show no "+N"; "+N" lists every quote in server order | none: browser check | Not run · browser |
| S-14.11 | edge, browser | Given an item with two PO suppliers, when it draws, then the PO cell shows the first of today's list over its cost and "+1" | none: browser check | Not run · browser |

### S-15 Every state of the supplier cells reads right (P1)

As the CEO, I want each supplier situation to look distinct and honest.

Independent test: on the preview, items in each state below show what the case says.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-15.1 | happy, browser | Given an item with no quote and no PO supplier, when the lists have loaded, then one red cell spans the four sub-columns with "wala pang supplier" once and the "+ supplier quote" button inside; no grey "walang supplier" anywhere | none: browser check | Not run · browser |
| S-15.2 | happy, browser | Given PO suppliers but no quote, then three "+" cells and the PO cell filled; no red band | none: browser check | Not run · browser |
| S-15.3 | happy, browser | Given one quote, then Supplier 1 shows name over price with MOQ, two "+" cells, a dash in PO, and the price is not marked cheapest | none: browser check | Not run · browser |
| S-15.4 | happy, browser | Given three quotes and a PO supplier, then three cells cheapest first, only the cheapest price marked, the PO cell with name and cost | none: browser check | Not run · browser |
| S-15.5 | edge, browser | Given a quote without MOQ, or without a price, or priced 0, then only what exists shows (a dash for no price, the typed 0 for 0), no reserved gap, and it is not marked | none: browser check | Not run · browser |
| S-15.6 | edge, browser | Given a 120-character supplier name, or a price of 99999999 with MOQ 100000000, then the text is cut with an ellipsis inside the cell, the row does not grow and nothing overlaps the next cell; the full values are in the card | none: browser check | Not run · browser |
| S-15.7 | edge, browser | Given an item with no running page and no supplier, then "walang running page" shows in the Item column and the red band shows once | none: browser check | Not run · browser |
| S-15.8 | edge, browser | Given an expanded item, then its page rows show below with one empty cell under the group and keep their own three-line RTS / DEL / INT cell; the TOTAL row stays last and lines up | none: browser check | Not run · browser |
| S-15.9 | negative, browser | Given the quotes or PO-suppliers list has not answered yet, or a fetch failed, then the four cells show a neutral placeholder and neither the red band nor the "+" cells; they appear only after both lists have answered. The placeholder is the visible grey text "hindi na-load" after a failed fetch (title: the full sentence) and "…" while loading | none: browser check | Not run · browser |
| S-15.10 | edge, server | Given the suppliers view's script and template, when read, then the band and the "+" cells are bound to a loaded state that is set only after both fetches have answered successfully (pinned text) | `SuppliersGroupTest::test_S_15_10_band_and_plus_cells_are_bound_to_the_loaded_state` | Not run · pending build |

### S-16 Add, edit and remove a quote from the new cells with the same save logic (P1)

As the CEO, I want add, edit and remove to keep working from the new cells.

Independent test: from an empty "+" cell add a quote; the row shows it without a reload.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-16.1 | negative, server | Given the suppliers view's item row, when it renders, then every new control inside it (cell, name, "+", "+N", edit, remove, link, photo, Change, Copy, form fields and buttons) stops the click from reaching the row's handler that opens the page rows | `SuppliersGroupTest::test_S_16_1_every_new_control_stops_the_click_from_reaching_the_row` | Not run · pending build |
| S-16.2 | negative, server | Given a Marketing user, when POST `/item/quotes` or POST `/item/quotes/delete` is sent, then 403 and nothing is written (the delete case is new; the save case exists in `QuotePhotoTest`) | `SuppliersGroupTest::test_S_16_2_a_marketing_user_cannot_save_or_delete_a_quote` | Passed · 2026-10-08 · auto |
| S-16.3 | edge, server | Given a supplier that already has a quote on the item, when it is saved again, then that one quote is updated and the old values go to the history (existing `QuoteHistoryTest`, named, not rewritten) | `QuoteHistoryTest::test_changes_to_price_moq_or_link_write_the_old_values` (existing, unchanged) | Passed · 2026-10-08 · auto |
| S-16.4 | edge, server | Given a quote already removed, when delete is sent again, then the answer is ok with the current list and no error | `SuppliersGroupTest::test_S_16_4_deleting_a_removed_quote_again_answers_ok_with_the_current_list` | Passed · 2026-10-08 · auto |
| S-16.5 | edge, server | Given an empty price, an empty MOQ, a price above the limit or a link longer than the limit, when saved, then the same validation as today applies and nothing is written on a rejected request (characterisation) | `SuppliersGroupTest::test_S_16_5_validation_is_as_today_and_a_rejected_save_writes_nothing` | Passed · 2026-10-08 · auto |
| S-16.6 | edge, server | Given the suppliers view's script and template, when read, then the quote form opens for one row only: the open state compares the row's own item name as well as the shared quote key (pinned text), and the save and delete calls are the page's existing functions and routes | `SuppliersGroupTest::test_S_16_6_the_form_opens_for_one_row_only` | Not run · pending build |
| S-16.7 | happy, browser | Given an empty "+" cell, when a quote is saved, then it appears in the right cell without a reload and the form closes; edit from the card updates the cell (it may move to another cell); remove empties it; the chips' counts refresh as today | none: browser check | Not run · browser |
| S-16.8 | negative, browser | Given any new control, when clicked, then the page rows do not open or close; given a failed save, then the existing alert shows, the form stays open with the values and the cells do not change | none: browser check | Not run · browser |

### S-17 The row is compact; Change and Copy are on hover; the item column sticks (P1)

As the CEO, I want a row of about 50 px, so that I can see more items per screen.

Independent test: on the preview at 1366 x 768 every row without a long name is about 50 px.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-17.1 | edge, server | Given the render of the suppliers view, when the page's styles are read, then every rule added for it sits in a block that is rendered only for the suppliers view (so no other view carries it), and the existing test that scans the default layout's font sizes still passes unchanged | `SuppliersGroupTest::test_S_17_1_the_suppliers_styles_are_rendered_only_for_the_suppliers_view` | Not run · pending build |
| S-17.2 | happy, server | Given the suppliers view's RTS / DEL / INT cell on an item row, when it renders, then it is one line of three values with the names and counts in each value's tooltip; the Old view's cell and every page row's cell render as today | `SuppliersGroupTest::test_S_17_2_rts_del_int_is_one_line_on_item_rows_only` | Not run · pending build |
| S-17.3 | happy, browser | Given any row without a three-line name, then the row is 49 to 51 px; a very long item name wraps to at most three lines, about 60 px, and the sticky column does not widen | none: browser check | Not run · browser |
| S-17.4 | happy, browser | Given a row, when the pointer is on it or keyboard focus is inside it, then Change and Copy appear and work as today; Esc closes an open card. A card that holds the open add or edit form closes on Cancel, Esc or a successful save, not on a click or tap elsewhere | none: browser check | Not run · browser |
| S-17.5 | edge, browser | Given a touch device or a 390 px wide screen, then Change and Copy are always visible, a tap on a supplier name opens its card and a tap elsewhere closes it; the item column is sticky only at 768 px and wider. A card that holds the open add or edit form closes on Cancel, Esc or a successful save, not on a click or tap elsewhere | none: browser check | Not run · browser |
| S-17.6 | edge, browser | Given sideways scroll at 1366, then the item column stays, opaque on every row kind and under the header corner; a card opened on the last rows or in the PO cell is not cut by the scroll area or hidden by the TOTAL row | none: browser check | Not run · browser |
| S-17.7 | edge, owner check | Given his real data, when he scrolls, hovers and taps, then he agrees the table is as compact as his owner/private table | none: see Owner checks | Not run · manual |

### S-18 The Marketing view gets none of it (P1)

As the owner, I want supplier names, prices and MOQ never to reach the Marketing view.

Independent test: request `layout=suppliers` as Marketing; the Old view renders and none of the
new markers appear.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-18.1 | happy, server | Given a Marketing user, a Marketing-OIC user, and a CEO account with `view_as=marketing`, when each requests `/item?layout=suppliers`, then the response is the Old view exactly as `layout=old` gives it to them (same normalised output) and contains none of: "SUPPLIERS", "Supplier 1", "Supplier 2", "Supplier 3", the new class names, the new helper names, the link to the suppliers view, or a seeded supplier name | `SuppliersGroupTest::test_S_18_1_non_ceo_views_get_the_old_view_with_no_suppliers_markers` | Not run · pending build |
| S-18.2 | negative, server | Given those three requests, when the page's script is read, then it does not call the quotes or PO-suppliers loaders (as today) | `SuppliersGroupTest::test_S_18_2_the_script_does_not_call_the_loaders_for_those_requests` | Not run · pending build |
| S-18.3 | negative, server | Given a Marketing user and a Marketing-OIC user, when they call GET `/item/quotes` and GET `/item/suppliers`, then the lists are empty, and no `cheapest` flag or any quote field is present | `SuppliersGroupTest::test_S_18_3_marketing_roles_get_empty_quote_and_supplier_lists` | Passed · 2026-10-08 · auto |
| S-18.4 | edge, server | Given the Old view and the default layout rendered for Marketing at this commit, when compared with the base commit (token normalised), then they are identical | `SuppliersGroupTest::test_S_18_4_marketing_renders_of_the_old_and_default_layout_are_identical_to_the_base` | Passed · 2026-10-08 · auto |

### S-19 Nothing he uses changes; the other views are untouched (P1)

As the CEO, I want the Old view and the default layout to stay as they are while I try the new one.

Independent test: the Old view's table partial has the hash the existing test pins.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-19.1 | happy, server | Given `resources/views/item/_table_old.blade.php`, when hashed, then the existing test that pins it byte for byte passes unchanged (the file is not edited) | `ItemPageTest::test_old_table_partial_is_byte_identical_to_the_base_commit` (existing, unchanged) | Passed · 2026-10-08 · auto |
| S-19.2 | happy, server | Given the default layout rendered for the CEO at this commit, when compared with the base commit (token normalised), then it is identical | `SuppliersGroupTest::test_S_19_2_default_layout_for_the_ceo_is_identical_to_the_base` | Passed · 2026-10-08 · auto |
| S-19.3 | edge, server | Given the Old view rendered for the CEO, when compared with the base commit, then the only difference is the one toolbar link to the suppliers view | `SuppliersGroupTest::test_S_19_3_old_view_for_the_ceo_differs_only_by_the_toolbar_link` | Not run · pending build |
| S-19.4 | happy, server | Given the suppliers view, when it renders, then everything the Old view's table has is present: the configurable columns loop, HOLD, the expand arrow and page rows, TOTAL, the toolbar, the sourcing chips with their handler, and the worklist's extra lines under the item name | `SuppliersGroupTest::test_S_19_4_everything_the_old_table_has_is_present` | Not run · pending build |
| S-19.5 | happy, server | Given the same quotes, PO suppliers and item data, when the worklist endpoint is called, then the four lists and their counts are what they are today (existing `WorklistTest`, named) | `WorklistTest::test_ceo_gets_items_classified_into_the_four_lists` (existing, unchanged) | Passed · 2026-10-08 · auto |
| S-19.6 | edge, server | Given the suppliers view, when it renders, then it uses no `x-html` and no `innerHTML` (the existing no-`x-html` test covers the new files) | `SuppliersGroupTest::test_S_19_6_the_suppliers_files_use_no_x_html_and_no_inner_html` | Not run · pending build |
| S-19.7 | edge, browser | Given the same data in the Old view and the suppliers view, when each chip is selected, then the same items and counts show; column settings, sorting and header dragging work for every existing column | none: browser check | Not run · browser |
| S-19.8 | edge, owner check | Given his usual day in the suppliers view, then he finds nothing missing compared with the Old view | none: see Owner checks | Not run · manual |

### S-20 Staff and supplier text is only text (P1)

As the owner, I want supplier names, links and notes shown as text, so that no input can run script.

Independent test: a quote named `<img src=x onerror=alert(1)>` with link `javascript:alert(1)`
shows as text, with no alert and no clickable link.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-20.1 | negative, server | Given the suppliers view's templates, when read, then every supplier name, price, MOQ, date, link and photo value is bound as text or as an attribute value through Alpine bindings, never as HTML, and the quote's note is not shown | `SuppliersGroupTest::test_S_20_1_supplier_values_are_bound_as_text_and_the_note_is_not_shown` | Not run · pending build |
| S-20.2 | negative, server | Given the page's link guard, when read, then a quote's link is rendered as a link only when it starts with http:// or https:// (pinned text), opens in a new tab and carries rel noopener | `SuppliersGroupTest::test_S_20_2_a_link_is_rendered_only_through_the_http_guard` | Not run · pending build |
| S-20.3 | negative, browser | Given a supplier named `<img src=x onerror=alert(1)>` and links `javascript:alert(1)`, `data:text/html,x` and ` JaVaScRiPt:x`, then the name shows as typed, no alert runs and no clickable link appears; a name with quotes, `&`, `<`, a backslash, "Ñandú Trading 金龙" or an emoji shows as typed in the cell, the card, and any title or aria-label | none: browser check | Not run · browser |

### S-21 The page does not make more requests (P2)

As the CEO, I want the suppliers view to load as fast as the Old view.

Independent test: the quotes and PO-suppliers routes are each fetched once on load.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-21.1 | happy, server | Given the suppliers view's render, when the script is read, then the quotes loader and the PO-suppliers loader are each called once at start-up as in the Old view, and the new templates and helpers contain no fetch of their own | `SuppliersGroupTest::test_S_21_1_each_loader_is_called_once_and_the_new_templates_fetch_nothing` | Not run · pending build |
| S-21.2 | edge, browser | Given hover, tap and "+N", then no request is sent (the card uses loaded data); a save or delete sends only the existing POST and the existing worklist reload | none: browser check | Not run · browser |
