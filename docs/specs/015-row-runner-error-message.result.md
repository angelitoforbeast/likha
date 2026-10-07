# Result: spec 015 Row runner error message

> Committed beside the spec: names no people and no decision ids.

**Status:** done
**Date:** 2026-10-08
**Branch / PR:** `fix/015-row-runner-error-message` (base `dbf7383`), no PR, not pushed
**Preview or run link:** n/a

## Summary

A row that fails in the AI checker no longer returns the exception message. The catch block of
`AiCheckerRowRunner::run()` now returns `AI check failed. Ref: log #<id>` (the id of the failed
`ai_checker_logs` row) or `AI check failed. No log reference.` when that row could not be written,
and writes one `AI_CHECKER_ROW_FAIL` warning with the exception class, the SQLSTATE (database
exceptions only) and the order id. That log call is guarded, so a logger that cannot write does
not break the failure path. Status 500, the payload keys, the runner's five return keys and the
night job's handling are unchanged. The engine choice moved into one overridable method so tests
can make the engine throw a chosen exception; `phpunit.xml` carries a throwaway test key so the
suite runs in any checkout.

## Case table

Command: `php.bat vendor/phpunit/phpunit/phpunit --no-progress --testdox tests/Feature/NightRun/RowRunnerErrorMessageTest.php`
→ `OK (20 tests, 201 assertions)`. New tests are in that file unless another class is named.

| Case | Test | Result |
|---|---|---|
| S-01.1 | `test_S_01_1_a_thrown_row_returns_the_fixed_message_with_the_log_id` (astra, classic) | pass: `✔ … with data set "astra"`, `✔ … with data set "classic"` |
| S-01.2 | `test_S_01_2_the_customer_line_appears_nowhere` (astra, classic; route and runner) | pass: `✔ … "astra"`, `✔ … "classic"` |
| S-01.3 | `test_S_01_3_the_message_does_not_depend_on_the_exception` (empty, 10,001 chars, non-ASCII, TypeError) | pass: four `✔` |
| S-01.4 | `test_S_01_4_a_previous_exception_is_not_shown_or_logged` | pass: `✔` |
| S-02.1 | `test_S_02_1_a_database_failure_shows_no_sql_and_no_bound_value` | pass: `✔` |
| S-02.2 | `test_S_02_2_a_database_failure_logs_class_sqlstate_and_row_id_only` | pass: `✔` |
| S-02.3 | `test_S_02_3_both_writes_failing_gives_the_message_without_a_reference` | pass: `✔` |
| S-02.4 | `test_S_02_4_a_plain_exception_logs_no_sqlstate` | pass: `✔` |
| S-02.5 | `test_S_02_5_a_failing_logger_does_not_break_the_failure_path` | pass: `✔` |
| S-03.1 | `test_S_03_1_a_thrown_row_is_500_with_ok_false_and_a_string_error` | pass: `✔` (characterisation) |
| S-03.2 | `RunRowCharacterizationTest::test_row_not_found_is_a_404_with_no_log_row` (existing, unchanged) | pass: `OK (1 test, 4 assertions)` |
| S-03.3 | `RunRowCharacterizationTest::test_missing_address_list_is_a_500_with_no_log_row` (existing, unchanged) | pass: `OK (1 test, 5 assertions)` |
| S-03.4 | none: owner check in `qa/stories.md` | not run |
| S-03.5 | none: owner check in `qa/stories.md` | not run |
| S-04.1 | `NightAstraRowJobTest::test_a_final_failure_gets_a_fixed_reason_and_nothing_from_openai_or_the_exception`, row `runner exception` (existing, unchanged) | pass: `OK (1 test, 33 assertions)` |
| S-04.2 | `test_S_04_2_the_night_row_step_and_log_hold_no_customer_text` | pass: `✔` |
| S-04.3 | the same existing test as S-04.1 (attempts 1 → 2, nothing pushed, `consecutive_failures` 1) | pass: as S-04.1 |
| S-04.4 | `test_S_04_4_a_thrown_row_returns_the_five_keys_with_a_null_result` | pass: `✔` (characterisation) |
| S-05.1 | `test_S_05_1_the_reference_is_the_id_of_the_failed_log_row` | pass: `✔` |
| S-05.2 | `test_S_05_2_no_log_row_means_no_id_in_the_message` | pass: `✔` |
| S-05.3 | `test_S_05_3_two_failures_get_two_different_references` | pass: `✔` |

Deliberate change to an existing test:
`RunRowCharacterizationTest::test_an_exception_in_the_engine_is_a_500_and_a_failed_log_row`, one
assertion (line 439), from `'Array to string conversion'` to `'AI check failed. Ref: log #'` plus
the id of its log row. The log row it asserts is untouched. `OK (1 test, 11 assertions)`.

### Red runs (before the catch block changed)

Developer's run of the new file against the runner with the seam only:
`Tests: 19, Assertions: 153, Failures: 17`. Line numbers are in `RowRunnerErrorMessageTest.php`
as it was at that run.

| Test / data set | Before the fix | First failure line |
|---|---|---|
| S-01.1 astra, classic | fail | `:198` expected `'AI check failed. Ref: log #1'`, actual `'JUANA TESTCUSTOMER 123 TEST ST 09170000000'` |
| S-01.2 astra, classic | fail | `:208` actual `'SQLSTATE[23000]: Integrity constraint violation: 19 JUANA TESTCUSTOMER … SQL: update "macro_output" set "FULL NAME" = …'` |
| S-01.3 empty | fail | `:225` actual `'{"ok":false,"error":""}'` |
| S-01.3 10,001 chars | fail | `:225` actual `'{"ok":false,"error":"JJJJ…'` |
| S-01.3 non-ASCII | fail | `:225` actual `'{"ok":false,"error":"\u00d1and\u00fa, Bgy. Sta. Cruz, \u20b1599"}'` |
| S-01.4 | fail | `:234` actual `'Hindi natuloy ang row'` |
| S-02.1 | fail | `:246` body contains `… set \"FULL NAME\" = JUANA TESTCUSTOMER 123 TEST ST …` |
| S-02.2 | fail | `:255` `actual size 0 matches expected size 1` (no `AI_CHECKER_ROW_FAIL` line) |
| S-02.3 | fail | `:274` `error` is the `SQLSTATE[23000] … MARKER-pinalya-ng-test …` message |
| S-02.4 | fail | `:286` `actual size 0 matches expected size 1` |
| S-02.5 | fail | `:309` `error` is the customer line. With the log call added but not yet guarded it was red on its own: `RuntimeException: Hindi maisulat ang log` at `AiCheckerRowRunner.php:105`; green once the call was wrapped |
| S-03.1 | pass | characterisation: turns red if the status, a key or the type of `error` changes |
| S-04.2 | fail | `:343` `actual size 0 matches expected size 1`; its search for customer text is green before the fix and turns red if the message is ever logged |
| S-04.4 | pass | characterisation: turns red if a key is added, dropped or reordered, or `result` is set |
| S-05.1 | fail | `:363` `0 is identical to 1` (no reference in the message) |
| S-05.2 | fail | `:375` actual is the customer line |
| S-05.3 | fail | `:389` two arrays not identical |

Repeated by the main session on the final test file (after the review wave), with the runner
file set back to the seam-only commit for the run and restored afterwards:
`Tests: 20, Assertions: 160, Failures: 18`; green: S-03.1 and S-04.4 only. The data set added in
the review wave (S-01.3 "TypeError") is among the 18.

## Story changes

- S-02.5 added (edge): the logger throws when the `AI_CHECKER_ROW_FAIL` line is written, and the
  runner still returns 500, `ok` false, the fixed message with the reference and the five keys.
  Reason: follow-up 015-1 from the reviewer.
- No other story or case text was changed. The table has a Test and a Last run column, and S-03.4
  and S-03.5 are under `## Owner checks`.

## Done-when checklist

- [x] Cases S-01.1 to S-05.3 plus S-02.5 pass, each with a test named after it, except S-03.4 and
      S-03.5 (owner checks). Evidence: the case table above; `OK (20 tests, 201 assertions)` and
      the four single runs of the existing tests.
- [x] Red line for every fix slice, watched before the code: the red-run table above. Test and
      code of the fix are in one commit (`96748ed`), tests written and run first. One exception,
      stated plainly: commit `f1dbb99` (test only) landed after the code. It holds the review's
      findings: one added assertion in S-01.2 and one added data set in S-01.3. Neither needed a
      code change; the data set is red against the pre-fix runner (see above).
- [x] `git grep -n "getMessage()" -- app/Services/AiCheckerRowRunner.php` → no output, exit 1.
- [x] `git diff dbf7383 --stat` lists only allowed files:
      ```
       app/Services/AiCheckerRowRunner.php                |  24 +-
       docs/plans/015-row-runner-error-message.md         | 171 +++++++++
       docs/specs/015-row-runner-error-message.md         | 170 +++++++++
       docs/specs/015-row-runner-error-message.result.md  | 141 ++++++++
       phpunit.xml                                        |   1 +
       qa/stories.md                                      |  92 +++++
       .../Feature/NightRun/RowRunnerErrorMessageTest.php | 397 +++++++++++++++++++++
       .../NightRun/RunRowCharacterizationTest.php        |   2 +-
       8 files changed, 995 insertions(+), 3 deletions(-)
      ```
      (taken before this result's final version; the same eight files after it).
- [x] `php.bat -l` on every changed PHP file: `No syntax errors detected` for
      `app/Services/AiCheckerRowRunner.php`, `tests/Feature/NightRun/RowRunnerErrorMessageTest.php`,
      `tests/Feature/NightRun/RunRowCharacterizationTest.php`.
- [x] Full suite before and after the fix, no new failures. Details under "Tests". **It does not
      match the spec's expected count by one test:** this worktree shows 2 failing before and
      after, not 1. The extra one is `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`,
      which needs `storage/app/credentials.json`, a file git does not track and this worktree
      does not have. It fails the same way before the fix and is outside this work.
- [x] The risk tier's review: see Rulings (spec review before the plan; adversarial diff review
      after the code: pass, no blocker, no major, five minors; three fixed, two accepted).
- [x] `qa/stories.md` has this slice with the Test column filled.
- [x] This result is filled in.

## How to run

From a fresh clone of the branch (no environment file needed for the tests):

```
"C:/Users/Forbeast/.config/herd/bin/composer.bat" install --no-interaction
"C:/Users/Forbeast/.config/herd/bin/php.bat" artisan test
"C:/Users/Forbeast/.config/herd/bin/php.bat" vendor/phpunit/phpunit/phpunit --no-progress --testdox tests/Feature/NightRun/RowRunnerErrorMessageTest.php
```

## Rulings

Ruling: `composer install --no-interaction` was run once before the plan, as the spec allows; `composer.json` and `composer.lock` show no diff — needed to run the tests — none, `vendor/` is ignored by git.
Ruling: the full suite was run once on the unchanged base to record the starting point — the done-when asks for a before and an after — none, it changes no file.
Ruling: the spec review was done by the `skeptic-reviewer` agent in spec-review mode on the spec and the draft plan; verdict "ready, with plan changes", no blocker, no major, eight minors, all taken into the plan — the workflow asks for it on a high-risk spec — the diff review is the second chance.
Ruling: tests throw named exception classes only — the logged `exception` is compared with a literal, and the name of an anonymous class holds a file path — none.
Ruling: the new tests are one file extending `NightAstraTestCase` — it already captures every log line, and no existing test file is restructured — the file mixes three seams (runner, route, job).
Ruling (reviewer's answer 1): chosen-message cases are tested at the runner with the payload serialised as the controller does it; S-01.2 also runs on the real route for both engines with the customer line as the trigger's abort text; database cases use the real route; the controller is not touched — a test subclass cannot reach the route — the route is proven with database exceptions only.
Ruling (reviewer's answer 2): cases that are green before the fix are written before the code and reported as characterisation tests, with the change that would turn each red — they describe behaviour that must not change — none.
Ruling (reviewer's answer 3): a failure before the `try` is not built here; it is under "Proposed tasks" — outside this spec's cases — a database failure at that point still depends on the debug setting.
Ruling (follow-up 015-1): the new `Log::warning('AI_CHECKER_ROW_FAIL', …)` call is wrapped so a failure of the logger is swallowed there and `run()` still returns the 500 payload with the fixed message; only that call is wrapped, the neighbouring `AI_CHECKER_LOG_FAIL` line stays as it is; case S-02.5 added, tested at the runner, red first; done-when extended to S-02.5 — the log call must not break the failure path this fix exists for — a logger failure on that line is silent.
Ruling: the `backend-developer` agent type is not available in this session; tasks 3 and 4 were built by a general subagent (opus) with `.claude/agents/backend-developer.md` as its brief — the session's instruction for this case — none.
Ruling: the diff review was done by the `skeptic-reviewer` agent (opus), adversarial depth, on `1062cc6..96748ed`: pass, no blocker, no major, five minors. Fixed in one wave (commit `f1dbb99`): (1) the route half of S-01.2 now asserts the logged exception is `Illuminate\Database\QueryException` per engine, so the test proves the database failed and not the engine before its write; (2) S-01.3 gained a data set with a PHP `TypeError`, because every `\Throwable` is treated the same; (4) an unused import removed. Accepted: see "Deferred minors" — minors on untrusted paths are fixed before the branch is handed over — none.
Ruling: the three review fixes are test-only edits and were made by the main session, not sent back to a developer subagent, then run red against the pre-fix runner and green against the fix — three small edits with no production code — the wave had no second reviewer pass; the red and green runs are in this file.
Ruling: accepted minors are recorded in this file, not in `TODO.md` — the done-when limits the diff to a fixed list of files that does not include `TODO.md` — see "Conflicts with CLAUDE.local.md".
Ruling: log-line and body searches in the new tests ignore case and use `print_r` for log lines — the framework writes `update` in lower case, and an exception object in a log context would serialise to `{}` under `json_encode` and hide its message — none.
Ruling: the searches for the exception message in log lines look for `SQLSTATE[` and not `SQLSTATE` — the wanted context key `sqlstate` would match a case-insensitive search for the plain word — none.
Ruling: the suite summaries are given from plain `phpunit` as well as `artisan test` — in this worktree `artisan test` reports most passing tests as "warnings" (a `file_get_contents` notice per test that plain `phpunit` does not show; its cause was not chased, no environment file was read) — the two runners agree on which two tests fail.
Ruling: no browser check was run — the page is not changed and S-03.4 and S-03.5 are owner checks — the alert text is unverified until the owner check.

## Deferred minors

- The needle "bindings" in S-02.1 can never match: the framework's database exception inlines the
  values and never prints that word. Kept because the case lists it; it is harmless.
- The trigger SQL and the classic engine's faked answers in the new test file are short copies of
  what `NightAstraRowJobTest` and `RunRowCharacterizationTest` hold privately. Sharing them would
  mean restructuring existing test files, which this work must not do. Listed under "Proposed
  tasks".

## Merge danger

Two-way door. Blast radius: one catch block in `app/Services/AiCheckerRowRunner.php`, reached only
when a row throws; the staff member sees a different alert text and the server log gains one
warning line per failed row. No schema change, no migration, no config, no dependency. The night
job reads none of the changed values. `phpunit.xml` gains a test-only key that the application
never uses outside the tests. Revert: `git revert 96748ed` restores the old message (the seam
commit `bce6123` and the tests can stay or be reverted with it; `f1dbb99` must be reverted too if
`96748ed` is, because its tests assert the new message).

## Conflicts with CLAUDE.local.md

- The spec asks for "one failing test per case first"; cases that describe unchanged behaviour
  cannot fail first, and the kit's test rule says a red run must fail because the behaviour is
  missing. Settled by the reviewer: they are characterisation tests.
- `CLAUDE.local.md` says accepted findings are recorded in `TODO.md`; the spec's done-when limits
  the diff to a list of files without `TODO.md`. The two accepted minors are recorded in this
  file instead. If they belong in `TODO.md`, it is a two-line addition.
- `CLAUDE.local.md` says no test commit after the code it pins and that missing cases from the
  review are added test-first by the developer. The review's two test additions needed no code
  change, so they are a `test:` commit after the fix (`f1dbb99`).

## Tests

Full suite, plain `phpunit` (`php.bat vendor/phpunit/phpunit/phpunit --no-progress`):

| When | Summary |
|---|---|
| Base, no application key (`artisan test`) | `Tests: 287 failed, 180 warnings, 57 passed (2114 assertions)`, the failures being `MissingAppKeyException` |
| After the key, before the fix | `Tests: 524, Assertions: 5396, Errors: 1, Failures: 1, Skipped: 3.` |
| After the fix and the review wave | `Tests: 544, Assertions: 5597, Errors: 1, Failures: 1, Skipped: 3.` |

`artisan test` for the same two points: `Tests: 2 failed, 465 warnings, 57 passed (5396 assertions)`
and `Tests: 2 failed, 485 warnings, 57 passed (5597 assertions)`.

The two red tests are the same before and after:

1. `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` (the old
   failure the spec names).
2. `Tests\Feature\NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`:
   `InvalidArgumentException: file "…storage\app/credentials.json" does not exist`. The file is
   not tracked by git and is absent in this worktree; in a checkout that has it the suite should
   stand at the spec's 1 failed, 3 skipped, and 540 passed (520 + the 20 new tests).

The 20 new tests cover: the fixed message and its reference for both engines, the whole response
body, the log row and every log line searched for the customer line, message independence (empty,
long, non-ASCII, a PHP Error), a previous exception, a database failure on the real route, the
log line's exact context, both writes failing, a failing logger, the night job, the five return
keys, and the reference finding its log row.

## Open questions

None open. The four plan questions were answered by the reviewer (see Rulings).

## Process suggestions

- The spec's test seam (a subclass of the runner) and its file list (controller not included) do not fit together for the route, because the controller builds the runner with `new` — evidence: `MacroCheckerController.php:184` against `RunNightAstraRow.php:132`. A spec that names a seam could say which callers must reach it.
- "One failing test per case first" met cases that describe unchanged behaviour — evidence: the red-run table, rows S-03.1 and S-04.4. Marking such cases "characterisation" in the spec would remove the question.
- The spec's expected suite count assumes a checkout with untracked local files — evidence: `ImportStartTest` needs `storage/app/credentials.json`. Naming such files in the spec would explain the extra failure in a fresh worktree up front.
- The done-when file list and the rule that accepted findings go to `TODO.md` contradict each other — evidence: "Conflicts with CLAUDE.local.md". Adding `TODO.md` to the allowed list would settle it.

## Proposed tasks

- Catch failures before the `try` in the row runner — `MacroOutput::find()` and `MacroChecker::loadAddressMaps()` run before it, so a database failure there skips the fixed message and what the browser sees depends on the debug setting; the only bound value is the row id — normal.
- Guard the `AI_CHECKER_LOG_FAIL` log call in the row runner the same way as the new one — a log that cannot be written still turns a handled failure into an unhandled one on that line — low.
- Build the runner through the container in the controller — lets route tests swap the engine and matches the night job — low.
- Make `ImportStartTest` independent of `storage/app/credentials.json` — it fails in any checkout without that untracked file — low.
- Share the run-row test helpers (route call, classic engine fakes, failing triggers) in the night-run test base — three test files now hold their own copies — low.
- Look at `ASTRA_KEY_DECRYPT` in `AstraEncoder.php:104` — it logs an exception message (a decrypt error, not customer text), unlike the other log lines on this path — low.
- Find the cause of the per-test `file_get_contents` notice under `artisan test` in a checkout without an environment file — it turns passing tests into "warnings" and hides the real summary — low.

## Suggested next steps

Review the branch locally (commits `8462d1c`, `1062cc6`, `bce6123`, `96748ed`, `f1dbb99`, plus the
documents). After merge and deploy, the owner runs the two checks under `## Owner checks` in
`qa/stories.md` on the live page.
