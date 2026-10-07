# Plan 015: Row runner error message

Spec: `docs/specs/015-row-runner-error-message.md`. Branch `fix/015-row-runner-error-message`,
base `dbf7383`. Risk tier: high. No code is written before the reviewer's "go".

## 1. What the code shows (on `dbf7383`)

- The leak is one expression: `app/Services/AiCheckerRowRunner.php:99`,
  `'error' => $e->getMessage()` in the `catch (\Throwable $e)` of `run()`.
  `MacroCheckerController::runRow` returns that payload as JSON (line 197).
- Two callers build the runner differently: the controller with `new AiCheckerRowRunner()`
  (line 184), the night job with `app(AiCheckerRowRunner::class)` (`RunNightAstraRow.php:132`).
  **A test subclass of the runner therefore cannot reach the HTTP route** unless the controller
  changes, and the controller is not in the list of files this work may touch. Section 3 deals
  with this; it is open question 1 in the result file.
- The night job reads only `result`, `log_id` and `last_error` from the runner's array; it never
  reads `payload`. A thrown row gives `result = null` and `last_error = null`, which the job
  classifies as "Error while running the row". The fix does not touch any of the three.
- Both engines write the order outside any `try` (`AstraEncoder.php:318` and `:323`,
  `MacroChecker.php:460`), so a sqlite trigger on `UPDATE macro_output` reaches the runner's catch
  for the Astra engine and for the classic engine.
- The page reads `j.error` in the single-row alert (`index.blade.php:2096`) and only `j.ok` in the
  batch loop (`:1946`). Nothing parses the text of `error`.
- Suite on the base in this worktree, before the application key task
  (`php.bat artisan test`): `Tests: 287 failed, 180 warnings, 57 passed (2114 assertions)`. The
  failures are `MissingAppKeyException` ("No application encryption key has been specified").
  `composer install --no-interaction` ran once; `composer.json` and `composer.lock` show no diff.

## 2. The change

In `app/Services/AiCheckerRowRunner.php` only:

1. **Seam** (no behaviour change): the line that picks the engine moves into one method,

   ```php
   protected function engine(string $engine)
   {
       return $engine === 'astra' ? new AstraEncoder() : new MacroChecker();
   }
   ```

   and `run()` calls `$svc = $this->engine($engine);` at the same place, inside the `try`. Tests
   use a subclass of the runner that returns an engine whose `processRow()` throws a chosen
   exception: a subclass of `AstraEncoder` for the Astra case (so the `instanceof AstraEncoder`
   branches and `last_error` behave as in production) and a subclass of `MacroChecker` for the
   classic case. The exceptions thrown in tests are named classes (`\RuntimeException`,
   `\LogicException`), so the logged `exception` is compared with a literal. No container
   binding, no constructor change.

2. **The catch block**: after the failed log row is written as today,

   ```php
   $fail = ['exception' => get_class($e)];
   if ($e instanceof \Illuminate\Database\QueryException) $fail['sqlstate'] = (string) $e->getCode();
   $fail['macro_output_id'] = $id;
   Log::warning('AI_CHECKER_ROW_FAIL', $fail);

   $error = $logId !== null ? 'AI check failed. Ref: log #' . $logId : 'AI check failed. No log reference.';
   ```

   and the return uses `$error` in place of `$e->getMessage()`. Status, the two payload keys, the
   five keys of the returned array, `result = null`, `log_id` and `last_error` stay as they are.
   The comment above it is in Taglish and gives the reason (the message of a database exception
   carries the SQL and the bound values).

Nothing else in the file changes. `phpunit.xml` gets one line, `<env name="APP_KEY" value="base64:…"/>`,
with a value from `php.bat artisan key:generate --show` (prints a new key, writes nothing).

## 3. Tests

One new file, `tests/Feature/NightRun/RowRunnerErrorMessageTest.php`, extending
`NightAstraTestCase` (it already captures every log line in `$this->logLines`, blocks stray HTTP,
stores a test API key and fakes the queue, so the log capture is not copied a third time). The route tests
need three small things that are private or inline in `RunRowCharacterizationTest` (the `postJson`
call to run-row, signing in a user, the three faked answers of the classic engine); the new file
has its own short versions and the existing file is not edited for that. One method per
case, named `test_S_01_1_…`. The customer line is the literal from the spec. Expected strings are
written out from the spec, never built the way the code builds them.

Three ways to make a row fail, each used where the case asks for it:

| Way | Seam | Used for |
|---|---|---|
| Throwing engine through the runner subclass | runner (`run()` called directly) | a chosen message on a plain exception |
| sqlite trigger on `UPDATE macro_output` (and on `INSERT ai_checker_logs`) | HTTP route (`postJson`), real controller | database failures, and the final JSON |
| the same trigger, row run by `RunNightAstraRow::handle()` | job | S-04 |

Because the subclass cannot reach the route (section 1), the cases that say "run-row is called"
with a chosen plain message are tested at the runner, and the test serialises the payload exactly
as the controller does (`response()->json($out['payload'], $out['status'])->getContent()`) to
search "the whole response body". To prove the same on the real route for both engines, S-01.2
also posts to the route with a trigger whose abort text is the customer line (the text then sits
inside the `QueryException` message and again in its previous `PDOException`), and compares
`error` exactly with the fixed message there too, per engine. This is open question 1 in the
result file.

| Case | Test (seam) | Red before the fix? |
|---|---|---|
| S-01.1 | `test_S_01_1_a_thrown_row_returns_the_fixed_message_with_the_log_id` (runner; table: astra, classic) | red: `error` is the customer line |
| S-01.2 | `test_S_01_2_the_customer_line_appears_nowhere` (runner, whole serialised payload + log row + log lines; and route with the customer line as trigger text, astra and classic, where `error` is also compared exactly with the fixed message) | red: the body holds the line |
| S-01.3 | `test_S_01_3_the_message_does_not_depend_on_the_exception` (runner; table: empty, 10,001 characters, non-ASCII) | red: empty `error`, long `error`, non-ASCII `error` |
| S-01.4 | `test_S_01_4_a_previous_exception_is_not_shown_or_logged` (runner) | red on `error` (outer message is returned today); the previous exception's line is a guard. Second proof: the route half of S-01.2, where the line sits in the `QueryException` and in its previous `PDOException` |
| S-02.1 | `test_S_02_1_a_database_failure_shows_no_sql_and_no_bound_value` (route, trigger; the fake AI answer carries the customer text so it is a bound value of the failing update) | red: body holds `SQLSTATE`, `UPDATE`, `macro_output`, the marker |
| S-02.2 | `test_S_02_2_a_database_failure_logs_class_sqlstate_and_row_id_only` (route, trigger; the expected context is written out: `Illuminate\Database\QueryException`, `'23000'`, the order's id) | red: no `AI_CHECKER_ROW_FAIL` line |
| S-02.3 | `test_S_02_3_both_writes_failing_gives_the_message_without_a_reference` (route, two triggers) | red |
| S-02.4 | `test_S_02_4_a_plain_exception_logs_no_sqlstate` (runner) | red: no line |
| S-03.1 | `test_S_03_1_a_thrown_row_is_500_with_ok_false_and_a_string_error` (route, trigger) | **green today** (pins what the page relies on; turns red if the status, a key or the type of `error` changes) |
| S-03.2 | existing `RunRowCharacterizationTest::test_row_not_found_is_a_404_with_no_log_row`, unchanged | green today |
| S-03.3 | existing `RunRowCharacterizationTest::test_missing_address_list_is_a_500_with_no_log_row`, unchanged | green today |
| S-03.4, S-03.5 | none: `## Owner checks` in `qa/stories.md` | n/a |
| S-04.1 | existing `NightAstraRowJobTest::test_a_final_failure_gets_a_fixed_reason_and_nothing_from_openai_or_the_exception`, row `runner exception`, unchanged (asserts `failed`, "Error while running the row") | green today |
| S-04.2 | `test_S_04_2_the_night_row_step_and_log_hold_no_customer_text` (job, trigger with the customer line as abort text and in the chat; searches night row, step and log lines). It also proves the failure went through the runner's catch and not the job's own: the night row's `log_id` is the failed log row, there is one `AI_CHECKER_ROW_FAIL` line and no `NIGHT_ASTRA_ROW` line | red: no `AI_CHECKER_ROW_FAIL` line. The search itself is green today (the job never reads the payload) and turns red if the message is ever logged |
| S-04.3 | the same existing job test (starts at attempts 1, ends at attempts 2, `Queue::assertNothingPushed`, `consecutive_failures` 1), unchanged. That test cannot tell the runner's catch from the job's own (both give the same row); the S-04.2 test closes that | green today |
| S-04.4 | `test_S_04_4_a_thrown_row_returns_the_five_keys_with_a_null_result` (runner) | **green today** (turns red if a key is added, dropped or reordered, or `result` is set) |
| S-05.1 | `test_S_05_1_the_reference_is_the_id_of_the_failed_log_row` (route, trigger) | red |
| S-05.2 | `test_S_05_2_no_log_row_means_no_id_in_the_message` (runner, plain exception + trigger on the log insert; `log_id` null, `error` compared exactly with `AI check failed. No log reference.`) | red |
| S-05.3 | `test_S_05_3_two_failures_get_two_different_references` (runner) | red |

One existing assertion changes on purpose:
`RunRowCharacterizationTest::test_an_exception_in_the_engine_is_a_500_and_a_failed_log_row`,
line 439, from `'Array to string conversion'` to the fixed message with the id of its log row.
The log row it asserts is untouched.

Cases marked "green today" describe behaviour that must not change; they cannot fail before the
fix without asserting something false. They are written before the code and reported as
characterisation tests, with their green run, not as red lines (open question 2 in the result file).

## 4. Tasks

| # | Task | Cases | Tier | Side | Commit |
|---|---|---|---|---|---|
| 1 | Test-only application key in `phpunit.xml`; full suite run on the otherwise unchanged base, summary recorded | none (done-when: suite runs) | low | backend | `chore:` |
| 2 | Stories: `qa/stories.md` with slice 015 and `## Owner checks` (S-03.4, S-03.5) | all (text only) | low | backend | `docs:` (batched with 1 for the check) |
| 3 | Seam `engine()` in the runner, no behaviour change, existing run-row tests green | none (enables S-01, S-02.4, S-04.4, S-05.2, S-05.3) | high | backend | `refactor:` |
| 4 | The fix: all new tests red first (or green for the characterisation cases), then the catch block; the one assertion in the characterisation test | S-01.1–S-01.4, S-02.1–S-02.4, S-03.1, S-04.2, S-04.4, S-05.1–S-05.3; S-03.2, S-03.3, S-04.1, S-04.3 by existing tests | high | backend | `fix:` (tests and code together) |
| 5 | Review, fix loops (at most two), minors, full suite, done-when evidence, result | all | high | n/a | `fix:` per loop, `docs:` for the result |

Tasks 3 and 4 touch the same file and run one after the other; nothing is `parallel-safe`.

Developer and reviewer: `backend-developer` (opus) for tasks 3 and 4, `skeptic-reviewer` (opus),
adversarial depth, on the diff of tasks 3 and 4 together. If the `backend-developer` agent type is
not available in the session, a general subagent gets `.claude/agents/backend-developer.md` as its
brief, and the result says so. Tasks 1 and 2 are low: the main session runs lint and the suite.

The spec review this tier asks for was run before this plan was sent (`skeptic-reviewer`,
spec-review mode: "ready, with plan changes", no blocker and no major). Its plan changes are in
this plan; its questions for the spec's author are in the result under "Open questions".

## 4a. Paths the spec's cases do not cover

Found while reading. Neither is built without an answer (open questions 3 and 4 in the result
file):

- `MacroOutput::find()` and `MacroChecker::loadAddressMaps()` run before the `try`, so a throwable
  there goes to the framework's error response and not to the catch. The only bound value is the
  row id (an integer from the URL), so no customer text is involved; what the browser sees
  depends on the application's debug setting.
- `Log::warning()` inside the catch is not guarded. If the log cannot be written, the new call
  throws out of `run()`: the browser gets the framework's 500 without a reference, and at night
  the job's own catch logs first as well, so the row would stay `running` until the tick closes
  it. The existing `AI_CHECKER_LOG_FAIL` line has the same property today.

## 5. Verification before "done"

Each done-when item is run by the main session and its command and output go into the result:
the new test file and the two touched test classes; `git grep -n "getMessage()" -- app/Services/AiCheckerRowRunner.php`;
`git diff dbf7383 --stat`; `php.bat -l` on every changed PHP file; the full suite after task 1
(expected from the spec: 1 failed, 3 skipped, 520 passed) and after task 4 (the same one failure,
plus the new tests passing).

No browser check: the page is not changed, and S-03.4 and S-03.5 are owner checks.
