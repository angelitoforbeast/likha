# Spec 015: Row runner error message

**Project:** Likha, the business operations app (orders, ads reports, J&T shipments, night checker run).
**Stack:** Laravel 12, PHP 8.4 (Laravel Herd locally), Blade + Alpine.js; tests are PHPUnit on in-memory sqlite.
**Shape:** change · **Weight:** bounded · **Risk tier:** high (customer text can reach a browser response)

> Committed with the work as `docs/specs/015-row-runner-error-message.md`. Names no people and no
> decision ids: "the owner" decided, "the reviewer" checks and merges.

---

## Why

When the AI checker fails on one order row, `App\Services\AiCheckerRowRunner::run()` returns the
raw exception message in its 500 payload (`'error' => $e->getMessage()` in the `catch (\Throwable $e)`
block), and `MacroCheckerController::runRow` sends that payload to the browser as JSON. A database
exception's message carries the SQL and its bound values, which can hold a customer's name, phone,
address or chat text; the page shows the string to the staff member in an alert. After this change
the response carries a short fixed message with a reference the owner can look up, and the server
log gets the exception class only. Everything else about a failed row stays as it is.

## Stories

Add this slice to `qa/stories.md` (create the file; heading `## Slice 015 – row runner error
message`) on this branch, in the usual format (story line, independent test, case table with a
Test and a Last run column).

**S-01 A failed row never leaks customer text to the browser (P1)**
As a staff member running AI Fix or AI Checker, I want a failed row to show a short fixed message
with a reference, so that customer names, phones, addresses and chat text never appear in a
browser response.
Independent test: make the engine throw an exception whose message holds a made-up customer line,
call run-row, and check the response has the fixed message and the reference but not that line.

| Case | Type | Given / When / Then |
|---|---|---|
| S-01.1 | happy | Given the engine throws an exception with the message "JUANA TESTCUSTOMER 123 TEST ST 09170000000", when run-row is called (Astra engine and classic engine), then status is 500, `ok` is false and `error` is exactly `AI check failed. Ref: log #<id>` with the id of the failed log row |
| S-01.2 | negative | Given the same throw, when the whole response body, the `ai_checker_logs` row and every captured log line are searched, then the customer line appears nowhere (assert on the whole response content, not only on `error`) |
| S-01.3 | edge | Given a message that is empty, longer than 10,000 characters, or non-ASCII ("Ñandú, Bgy. Sta. Cruz, ₱599"), when it is thrown, then the response is the same fixed message and its length does not depend on the exception |
| S-01.4 | edge | Given a nested exception whose previous exception holds the customer line, when run-row is called, then the line appears nowhere in the response or the log lines |

**S-02 A database failure never exposes SQL or bound values (P1)**
As the owner, I want a database error to show no SQL, so that bound customer values never reach a
browser.
Independent test: make the order update fail with a sqlite trigger and check the response holds no
SQL fragment and no bound value.

| Case | Type | Given / When / Then |
|---|---|---|
| S-02.1 | happy | Given a trigger that aborts `UPDATE macro_output` with a marker text and a row whose chat holds "JUANA TESTCUSTOMER 123 TEST ST", when run-row is called, then 500, and the body contains none of "UPDATE", "macro_output", "SQLSTATE", "bindings", the marker, or the customer text |
| S-02.2 | negative | Given the same failure, when the log lines are read, then one `AI_CHECKER_ROW_FAIL` warning exists whose context is exactly `exception` (the QueryException class name), `sqlstate` and `macro_output_id`, and the exception message is logged nowhere |
| S-02.3 | edge | Given the `ai_checker_logs` insert fails as well (triggers on both tables), when run-row is called, then 500 with `error` exactly `AI check failed. No log reference.`, one `AI_CHECKER_LOG_FAIL` line and one `AI_CHECKER_ROW_FAIL` line |
| S-02.4 | edge | Given an exception that is not a database exception, when it is logged, then the context has `exception` and `macro_output_id` and no `sqlstate` key |

**S-03 What the page relies on is unchanged (P1)**
As a staff member, I want the failure to stay readable and the page to keep working, so that the
alert and the batch counters behave as before.
Independent test: a thrown row returns 500 with `ok` false and a non-empty string `error`.

| Case | Type | Given / When / Then |
|---|---|---|
| S-03.1 | happy | Given an engine exception, when run-row returns, then status 500, `ok === false`, `error` is a non-empty string, and the payload's keys are exactly `ok` and `error` |
| S-03.2 | negative | Given a row id that does not exist, when run-row is called, then 404 and exactly `['ok' => false, 'error' => 'Row not found']`, with no log row (the existing test stays unchanged and green) |
| S-03.3 | negative | Given the address file is missing, when run-row is called, then 500 and exactly `['ok' => false, 'error' => 'jnt_address.txt missing or empty']`, with no log row (the existing test stays unchanged and green) |
| S-03.4 | edge, owner check | Given a failed row on the single-row button, when the staff member clicks AI Fix, then the alert reads "Row #N failed: AI check failed. Ref: log #<id>" and not "HTTP 500". No JavaScript test exists: list it under `## Owner checks` in `qa/stories.md`, not as an automated test |
| S-03.5 | edge, owner check | Given the batch loop meets a failed row, when it continues, then the row shows the Failed mark, the failed counter goes up and the loop goes on. Owner check, as above |

**S-04 The night job treats a thrown row the same (P1)**
As the owner, I want the night run to handle a failed row exactly as before, so that retries,
counts and reasons do not change.
Independent test: the night job on a row that throws ends `failed` with the reason "Error while
running the row".

| Case | Type | Given / When / Then |
|---|---|---|
| S-04.1 | happy | Given the runner's engine throws at night, when the job classifies it, then the row ends `failed` with the reason "Error while running the row" (existing behaviour) |
| S-04.2 | negative | Given that failure, when the night row, the step and the log lines are searched, then no customer text and no exception message appear |
| S-04.3 | edge | Given a thrown row at attempts 2, when it fails, then there is no retry and `consecutive_failures` goes up by 1 |
| S-04.4 | edge | Given a thrown row, when the runner returns, then its array has exactly the keys `status`, `payload`, `result`, `log_id`, `last_error`, with `result` null |

**S-05 The reference finds the log row (P2)**
As the owner, I want the reference to find the failed row's log, so that I can look it up.
Independent test: take the id from the message and find the `ai_checker_logs` row.

| Case | Type | Given / When / Then |
|---|---|---|
| S-05.1 | happy | Given the log row was written, when the response returns, then the id in the message equals the id of the one row with `outcome = 'failed'` and `final_code = '❌'` |
| S-05.2 | negative | Given the log write failed, when the response returns, then the message holds no id and no invented one: `AI check failed. No log reference.` |
| S-05.3 | edge | Given two failures one after another, when each response is read, then the two ids differ and each matches its own log row |

**Tests:** one failing test per case first, named with its case ID (`S-01.1 …` in the method name or
description), at the seam the case describes: the runner for payload and log lines, the HTTP route
for the final JSON, the job for S-04. Expected values come from the case, never recomputed the way
the code does it. Existing tests that already cover a case (the two exact-payload tests for S-03.2
and S-03.3, the job's runner-exception case for S-04.1 to S-04.3) may be named as that case's test
if they assert the Then; say which. If the code shows a gap no case covers, ask the reviewer in the
result before building it.

## Constraints

**Decisions already made** (don't reopen them unless something is actually broken):

| Topic | Decision |
|---|---|
| The message | Exactly `AI check failed. Ref: log #<id>` when the failed log row was written, and exactly `AI check failed. No log reference.` when it was not. English, like the two neighbouring error strings. The id is inside the string; no new payload keys. |
| The class name | Never sent to the browser. Server log only. |
| The server log | A new `Log::warning('AI_CHECKER_ROW_FAIL', …)` in the catch block with `exception` (class name), `sqlstate` (only for a database query exception, as the existing `AI_CHECKER_LOG_FAIL` line does) and `macro_output_id`. Never the message, the previous exception or the trace. |
| Kinds of failure | Every `\Throwable` is treated the same way. |
| Status and keys | Status stays 500; `ok` stays false; `error` stays a non-empty string; the runner's return array keeps its five keys; `last_error` is passed through as now. |
| Other error paths | The 404 "Row not found" and the 500 "jnt_address.txt missing or empty" stay byte-for-byte as they are. |
| The existing characterization test | `RunRowCharacterizationTest::test_an_exception_in_the_engine_is_a_500_and_a_failed_log_row` asserts the old message ("Array to string conversion"). That one assertion changes on purpose to the fixed message; the log row it asserts stays as it is. List it in the result as a deliberate change. |
| Making the engine throw in tests | Database failures: a sqlite trigger, as the existing night tests do. A chosen message on a plain exception: the smallest seam in the runner, one overridable method that builds the engine, used by a test subclass; no container binding, no behaviour change. Describe it in the plan. |
| The page | `resources/views/macro_output/index.blade.php` is not changed. The batch view keeps showing only the Failed mark. |
| Running the suite here | This worktree has no installed dependencies and no environment file. Allowed, before the plan: `composer install --no-interaction` once from the unchanged lock file (no update, no require; `composer.json` and `composer.lock` must show no diff; no npm install). As the first task of the work: add a test-only application key to `phpunit.xml` (an `<env name="APP_KEY" …/>` with a throwaway value generated for tests, for example with `php.bat artisan key:generate --show`; never the real key, and the environment file is never read or created) so the whole suite runs in any checkout. |

**Threat model.** Untrusted (treat as data, never show or log): exception messages, previous
exceptions and traces from the engines and the database (they can carry customer text, SQL and
bound values); every field of an order row; anything the AI provider returns. Trusted: the files
in this repository, config, the log id the application itself generates. Risk tier high: a leak
here shows a customer's personal data to whoever has the page open. A major needs a one-line
realistic scenario. Fix loops stop after two; a remaining security major goes to the reviewer.

**Rules**
- Don't start other Claude Code sessions; use subagents inside this session.
- Talk only to the reviewer, through the result file. Plan first, then build.
- No `Co-Authored-By`, `Claude-Session` or "Generated with Claude Code" lines in commit messages.
- Code comments explain the reason itself, in Taglish like the file; no names, no decision labels,
  no spec citations.
- No commands that start with an environment variable.
- Downloads: only the one `composer install` above.
- Change only what this spec needs: no refactors, renames or other fixes; suggestions go into the
  result under "Proposed tasks".
- Design every message so a staff member with no technical knowledge can act on it: it says the
  check failed and gives a number to pass on, nothing else.

## Budget

- Attempts: at most 2 tries at the same step; then stop and report what you tried and what you need.
- Size: small job (one service file, `phpunit.xml`, tests, `qa/stories.md`). If it is turning out
  much bigger, stop and report before going on.

## Done when

- [ ] Cases S-01.1 to S-05.3 pass, each with a test named after it, except S-03.4 and S-03.5, which
      are listed under `## Owner checks` in `qa/stories.md`.
- [ ] The result has a red line (test name, first failure line) for every fix slice, watched before
      the code; no test commit lands after the code it pins.
- [ ] `git grep -n "getMessage()" -- app/Services/AiCheckerRowRunner.php` returns no line.
- [ ] `git diff dbf7383 --stat` lists only: `app/Services/AiCheckerRowRunner.php`, `phpunit.xml`,
      files under `tests/`, `qa/stories.md`, and this spec, its result and its plan.
- [ ] `php.bat -l` passes on every changed PHP file.
- [ ] The full suite runs in this worktree after the application key task. Before the fix and after
      it: no new failures. On the base in a checkout with an environment file the suite stands at
      1 failed (the old `ExampleTest`), 3 skipped, 520 passed; that one failure is not this spec's
      to fix. Both summaries in the result.
- [ ] The risk tier's review was done (who reviewed, findings, what was fixed) and is in the result.
- [ ] `qa/stories.md` has this slice with the Test column filled.
- [ ] The result (`docs/specs/015-row-runner-error-message.result.md`) is filled in.

## Out of scope

The page and its JavaScript; the batch view; the engines (`MacroChecker`, `AstraEncoder`) and their
log lines; the night job's code; any other error message; deploying; pushing.

## Report back

Fill in `docs/specs/015-row-runner-error-message.result.md` from its template and commit it with
the work, including every ruling you made (`Ruling: <decision> — <why> — <cost if wrong>`), then end
the run with the one line "result updated: done". No PR: the reviewer reviews the branch. Under
Merge danger say whether this is a one-way or two-way door, the blast radius and how to revert.
