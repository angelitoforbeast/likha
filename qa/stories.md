# Stories

One section per slice. Each story has a story line, an independent test and a case table
(Given / When / Then) with the test that proves the case and its last run.

## Slice 015 – row runner error message

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
| S-01.1 | happy | Given the engine throws an exception with the message "JUANA TESTCUSTOMER 123 TEST ST 09170000000", when run-row is called (Astra engine and classic engine), then status is 500, `ok` is false and `error` is exactly `AI check failed. Ref: log #<id>` with the id of the failed log row | `test_S_01_1_a_thrown_row_returns_the_fixed_message_with_the_log_id` | pass, 2026-10-08 |
| S-01.2 | negative | Given the same throw, when the whole response body, the `ai_checker_logs` row and every captured log line are searched, then the customer line appears nowhere (assert on the whole response content, not only on `error`) | `test_S_01_2_the_customer_line_appears_nowhere` | pass, 2026-10-08 |
| S-01.3 | edge | Given a message that is empty, longer than 10,000 characters, or non-ASCII ("Ñandú, Bgy. Sta. Cruz, ₱599"), when it is thrown, then the response is the same fixed message and its length does not depend on the exception | `test_S_01_3_the_message_does_not_depend_on_the_exception` | pass, 2026-10-08 |
| S-01.4 | edge | Given a nested exception whose previous exception holds the customer line, when run-row is called, then the line appears nowhere in the response or the log lines | `test_S_01_4_a_previous_exception_is_not_shown_or_logged` | pass, 2026-10-08 |

### S-02 A database failure never exposes SQL or bound values (P1)

As the owner, I want a database error to show no SQL, so that bound customer values never reach a
browser.

Independent test: make the order update fail with a sqlite trigger and check the response holds no
SQL fragment and no bound value.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-02.1 | happy | Given a trigger that aborts `UPDATE macro_output` with a marker text and a row whose chat holds "JUANA TESTCUSTOMER 123 TEST ST", when run-row is called, then 500, and the body contains none of "UPDATE", "macro_output", "SQLSTATE", "bindings", the marker, or the customer text | `test_S_02_1_a_database_failure_shows_no_sql_and_no_bound_value` | pass, 2026-10-08 |
| S-02.2 | negative | Given the same failure, when the log lines are read, then one `AI_CHECKER_ROW_FAIL` warning exists whose context is exactly `exception` (the QueryException class name), `sqlstate` and `macro_output_id`, and the exception message is logged nowhere | `test_S_02_2_a_database_failure_logs_class_sqlstate_and_row_id_only` | pass, 2026-10-08 |
| S-02.3 | edge | Given the `ai_checker_logs` insert fails as well (triggers on both tables), when run-row is called, then 500 with `error` exactly `AI check failed. No log reference.`, one `AI_CHECKER_LOG_FAIL` line and one `AI_CHECKER_ROW_FAIL` line | `test_S_02_3_both_writes_failing_gives_the_message_without_a_reference` | pass, 2026-10-08 |
| S-02.4 | edge | Given an exception that is not a database exception, when it is logged, then the context has `exception` and `macro_output_id` and no `sqlstate` key | `test_S_02_4_a_plain_exception_logs_no_sqlstate` | pass, 2026-10-08 |
| S-02.5 | edge | Given the logger throws when the `AI_CHECKER_ROW_FAIL` line is written, when a row fails, then the runner still returns status 500, `ok` false, the fixed message with the reference of the failed log row, and the five keys with `result` null | `test_S_02_5_a_failing_logger_does_not_break_the_failure_path` | pass, 2026-10-08 |

### S-03 What the page relies on is unchanged (P1)

As a staff member, I want the failure to stay readable and the page to keep working, so that the
alert and the batch counters behave as before.

Independent test: a thrown row returns 500 with `ok` false and a non-empty string `error`.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-03.1 | happy | Given an engine exception, when run-row returns, then status 500, `ok === false`, `error` is a non-empty string, and the payload's keys are exactly `ok` and `error` | `test_S_03_1_a_thrown_row_is_500_with_ok_false_and_a_string_error` | pass, 2026-10-08 |
| S-03.2 | negative | Given a row id that does not exist, when run-row is called, then 404 and exactly `['ok' => false, 'error' => 'Row not found']`, with no log row | `RunRowCharacterizationTest::test_row_not_found_is_a_404_with_no_log_row` (existing, unchanged) | pass, 2026-10-08 |
| S-03.3 | negative | Given the address file is missing, when run-row is called, then 500 and exactly `['ok' => false, 'error' => 'jnt_address.txt missing or empty']`, with no log row | `RunRowCharacterizationTest::test_missing_address_list_is_a_500_with_no_log_row` (existing, unchanged) | pass, 2026-10-08 |
| S-03.4 | edge, owner check | Given a failed row on the single-row button, when the staff member clicks AI Fix, then the alert reads "Row #N failed: AI check failed. Ref: log #<id>" and not "HTTP 500" | none: see Owner checks | not run |
| S-03.5 | edge, owner check | Given the batch loop meets a failed row, when it continues, then the row shows the Failed mark, the failed counter goes up and the loop goes on | none: see Owner checks | not run |

### S-04 The night job treats a thrown row the same (P1)

As the owner, I want the night run to handle a failed row exactly as before, so that retries,
counts and reasons do not change.

Independent test: the night job on a row that throws ends `failed` with the reason "Error while
running the row".

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-04.1 | happy | Given the runner's engine throws at night, when the job classifies it, then the row ends `failed` with the reason "Error while running the row" (existing behaviour) | `NightAstraRowJobTest::test_a_final_failure_gets_a_fixed_reason_and_nothing_from_openai_or_the_exception`, row `runner exception` (existing, unchanged) | pass, 2026-10-08 |
| S-04.2 | negative | Given that failure, when the night row, the step and the log lines are searched, then no customer text and no exception message appear | `test_S_04_2_the_night_row_step_and_log_hold_no_customer_text` | pass, 2026-10-08 |
| S-04.3 | edge | Given a thrown row at attempts 2, when it fails, then there is no retry and `consecutive_failures` goes up by 1 | the same existing test as S-04.1 (unchanged) | pass, 2026-10-08 |
| S-04.4 | edge | Given a thrown row, when the runner returns, then its array has exactly the keys `status`, `payload`, `result`, `log_id`, `last_error`, with `result` null | `test_S_04_4_a_thrown_row_returns_the_five_keys_with_a_null_result` | pass, 2026-10-08 |

### S-05 The reference finds the log row (P2)

As the owner, I want the reference to find the failed row's log, so that I can look it up.

Independent test: take the id from the message and find the `ai_checker_logs` row.

| Case | Type | Given / When / Then | Test | Last run |
|---|---|---|---|---|
| S-05.1 | happy | Given the log row was written, when the response returns, then the id in the message equals the id of the one row with `outcome = 'failed'` and `final_code = '❌'` | `test_S_05_1_the_reference_is_the_id_of_the_failed_log_row` | pass, 2026-10-08 |
| S-05.2 | negative | Given the log write failed, when the response returns, then the message holds no id and no invented one: `AI check failed. No log reference.` | `test_S_05_2_no_log_row_means_no_id_in_the_message` | pass, 2026-10-08 |
| S-05.3 | edge | Given two failures one after another, when each response is read, then the two ids differ and each matches its own log row | `test_S_05_3_two_failures_get_two_different_references` | pass, 2026-10-08 |

## Owner checks

No JavaScript test exists for the order page, so these are checked by hand on the running page.

| Case | Steps | Expected |
|---|---|---|
| S-03.4 | On the order page, make one row fail (for example a row the AI check cannot write) and click AI Fix on that row | The alert reads "Row #N failed: AI check failed. Ref: log #<id>", not "HTTP 500", and holds no customer text |
| S-03.5 | Start AI Checker on a batch that holds a row that fails | That row shows the Failed mark, the failed counter goes up by one and the loop goes on to the next row |
