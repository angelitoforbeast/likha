# Result: spec 015 Row runner error message

> Committed beside the spec: names no people and no decision ids.

**Status:** partial: plan written, waiting for the reviewer's "go". No application code, test or
`phpunit.xml` change has been made.
**Date:** 2026-10-08
**Branch / PR:** `fix/015-row-runner-error-message` (base `dbf7383`), no PR
**Preview or run link:** n/a

## Summary

Nothing is built yet. The plan is in `docs/plans/015-row-runner-error-message.md`: one seam method
and a changed catch block in `app/Services/AiCheckerRowRunner.php`, a test-only application key in
`phpunit.xml`, one new test file with a test per case, one changed assertion in the existing
characterisation test, and `qa/stories.md`. The spec review this risk tier asks for was run before
the plan was sent. Four questions are open below; each has a default that is built on a plain "go".

## Case table

Not run yet. The plan (section 3) names the test for every case and says which are red before the
fix and which pin behaviour that already holds.

## Story changes

none so far (`qa/stories.md` is created in task 2, after the go).

## Done-when checklist

- [ ] Cases S-01.1 to S-05.3 pass, each with a test named after it, except S-03.4 and S-03.5: not started.
- [ ] Red line for every fix slice: not started.
- [ ] `git grep -n "getMessage()" -- app/Services/AiCheckerRowRunner.php` returns no line: not started (today: line 99).
- [ ] `git diff dbf7383 --stat` lists only the allowed files: holds so far (the spec, this result, the plan).
- [ ] `php.bat -l` passes on every changed PHP file: no PHP file changed yet.
- [ ] Full suite before and after the fix: the application key task is not done yet. On the base
      without a key: `Tests: 287 failed, 180 warnings, 57 passed (2114 assertions)`, the failures
      being `MissingAppKeyException`.
- [ ] The risk tier's review: spec review done (see Rulings); the diff review follows the code.
- [ ] `qa/stories.md` has this slice with the Test column filled: not started.
- [ ] The result is filled in: this is the plan-stage version.

## How to run

```
"C:/Users/Forbeast/.config/herd/bin/composer.bat" install --no-interaction
"C:/Users/Forbeast/.config/herd/bin/php.bat" artisan test
```

The suite needs the application key that task 1 adds to `phpunit.xml`; until then most tests stop
at `MissingAppKeyException`.

## Rulings

Ruling: `composer install --no-interaction` was run once before the plan, as the spec allows; `git status` shows no change to `composer.json` or `composer.lock` — needed to read how the tests run — none, `vendor/` is ignored by git.
Ruling: the full suite was run once on the unchanged base to record the starting point (287 failed, 180 warnings, 57 passed, all failures the missing application key) — the done-when asks for a before and an after — none, it changes no file.
Ruling: the spec review was done by the `skeptic-reviewer` agent in spec-review mode, on the spec and the draft plan; verdict "ready, with plan changes", no blocker, no major, eight minors — the workflow asks for it on a high-risk spec before the plan is sent — if the review missed something, the diff review after the code is the second chance.
Ruling: all eight minors of the spec review were taken into the plan (open questions written out; the night test proves which catch ran; the route tests compare `error` exactly per engine; the previous-exception case gets a second proof on the route; exact string for the no-reference message; literal `sqlstate`; honest note on copied route helpers; the mutation that turns each "green today" test red) — they make the tests prove their Then — a little more test code.
Ruling: tests throw named exception classes only — the logged `exception` is compared with a literal, and the name of an anonymous class holds a file path — none.
Ruling: the new tests go into one new file that extends `NightAstraTestCase` — it already captures every log line, so that helper is not copied a third time, and no existing test file is restructured — the file mixes three seams (runner, route, job) in one class.

## Deferred minors

none so far.

## Merge danger

To be written with the work. Expected: two-way door (one service file; reverting the fix commit
restores the old message).

## Conflicts with CLAUDE.local.md

- The spec asks for "one failing test per case first". Six cases and half of a seventh describe behaviour that already
  holds (S-03.1, S-03.2, S-03.3, S-04.1, S-04.3, S-04.4, and the search half of S-04.2); a test
  for them cannot fail first without asserting something false, and the kit's test rule says a
  red run must fail because the behaviour is missing. The plan treats them as characterisation
  tests, which the legacy section of `CLAUDE.local.md` provides for. See open question 2.
- The spec asks for a test named after every case; the kit's test rule says not to add a second
  test for a behaviour an existing test proves. For S-03.2, S-03.3, S-04.1 and S-04.3 the plan
  names the existing tests, as the spec itself allows, so nothing is duplicated.

## Tests

Not written yet. Starting point on the base, without an application key:
`Tests: 287 failed, 180 warnings, 57 passed (2114 assertions)`.

## Open questions

Each has a default. A plain "go" means all four defaults.

1. **The test subclass cannot reach the HTTP route.** `MacroCheckerController::runRow` builds the
   runner with `new AiCheckerRowRunner()` (line 184), so a test subclass with a throwing engine is
   never used by the route, and the controller is not among the files this work may touch.
   **Default:** cases with a chosen plain message (S-01.1, S-01.3, S-01.4, S-02.4, S-04.4, S-05.2,
   S-05.3) are tested by calling the runner directly; "the whole response body" is the payload
   serialised the way the controller does it. On top of that, S-01.2 posts to the real route for
   both engines with a sqlite trigger whose abort text is the customer line, searches the whole
   response content and compares `error` exactly. The database cases (S-02.x, S-03.1, S-05.1) use
   the real route.
   **Alternative:** allow one line in the controller (`app(AiCheckerRowRunner::class)`, as the
   night job already does) so a test can swap the runner on the route. That adds the controller
   to the allowed files and is a small behaviour-neutral change outside the spec's list.

2. **Cases that are green before the fix.** S-03.1, S-04.4, the existing tests named for S-03.2,
   S-03.3, S-04.1 and S-04.3, and the search half of S-04.2 pin behaviour that must not change, so
   they have no red run. **Default:** they are written before the code, reported with their green
   run and with the change that would turn each red; the red lines in the result are for the
   other cases (S-01.1 to S-01.4, S-02.1 to S-02.4, S-04.2's log-line half, S-05.1 to S-05.3).

3. **A failure before the `try`.** `MacroOutput::find()` and `MacroChecker::loadAddressMaps()` run
   before the `try` in `run()`. If the database fails there, the exception goes to the framework's
   error response, not to the fixed message. The only bound value is the row id from the URL, so
   no customer text is involved; with debug off the body is the framework's generic error, with
   debug on it would show SQL text and file paths. The debug setting of production lives in the
   environment file, which was not read. No case covers this path. **Default:** not built; listed
   under "Proposed tasks". **Alternative:** move the two calls inside the `try` (the 404 and the
   address-file 500 stay byte for byte) and add a case for it.

4. **The log call inside the catch is not guarded.** If writing the log itself throws (for
   example the log file cannot be written), the new `Log::warning('AI_CHECKER_ROW_FAIL', …)`
   throws out of `run()`: the browser gets the framework's 500 with no reference, and at night
   the job's own catch logs first too, so the row would stay `running` until the tick closes it
   instead of ending `failed`. The existing `AI_CHECKER_LOG_FAIL` line behaves the same today. It
   is a fault on a trusted path with no leak and no data loss. **Default:** not guarded, as the
   neighbouring line; listed under "Proposed tasks". **Alternative:** wrap the new call in a
   `try` that swallows the failure, with one more case.

## Process suggestions

- The spec's test seam (a subclass of the runner) and its file list (controller not included) do not fit together for the route, because the controller builds the runner with `new` — evidence: `MacroCheckerController.php:184` against `RunNightAstraRow.php:132`. A spec that names a seam could say which callers must reach it.
- "One failing test per case first" met cases that describe unchanged behaviour — evidence: plan section 3, column "Red before the fix?". Marking such cases "characterisation" in the spec would remove the question.

## Proposed tasks

- Catch failures before the `try` in the row runner — a database failure at `find()` skips the fixed message and depends on the debug setting — normal.
- Guard the log calls in the row runner's catch blocks — a log that cannot be written turns a handled failure into an unhandled one and leaves a night row `running` — low.
- Build the runner through the container in the controller — lets route tests swap the engine and matches the night job — low.

## Suggested next steps

Answer open questions 1 to 4 (or "go" for the defaults). Then tasks 1 to 5 of the plan are built
and this file is completed.
