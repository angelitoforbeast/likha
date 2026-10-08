# Spec 016: Night run rows on Checker 1

**Project:** Likha, the business operations app (orders, ads reports, J&T shipments, night checker run).
**Stack:** Laravel 12, PHP 8.4 (Laravel Herd locally), Blade + Alpine.js; tests are PHPUnit on in-memory sqlite.
**Shape:** change · **Weight:** bounded · **Risk tier:** high (a URL parameter on a page every staff member uses)

> Committed with the work as `docs/specs/016-night-rows-checker1.md`. Names no people and no
> decision ids: "the owner" decided, "the reviewer" checks and merges.

---

## Why

Every night the Astra step checks yesterday's orders that have no status. The Night run block on
the AI checker logs page (`/encoder/checker_1/ai-checker/logs`) shows, per night, for example
"279 rows · 131 PROCEED · 148 for a person · 0 failed". The owner wants to click that "for a
person" count and land on the existing Checker 1 table (`/encoder/checker_1`) showing exactly
those rows, so that he can see what the encoders actually encoded, or will encode, on the rows
Astra left to them. Today Checker 1 can only be filtered by date, page, checker code and status,
so those rows cannot be picked out. This change adds one optional URL parameter that narrows the
existing table, one line that says the filter is on, and the link.

## Stories

Add this slice to `qa/stories.md` (heading `## Slice 016 – Night run rows on Checker 1`) on this
branch, in the file's existing format (story line, independent test, case table with a Test and a
Last run column). The file's last slice is 015 with S-01 to S-05.

The link: `/encoder/checker_1?date=<orders date of the night>&night_step=<Astra step id>`.
"The night's rows" below always means: rows of `night_astra_rows` for that step with
`state = 'done'` and `proceed` false, whatever their `code` (null and empty included). This is
exactly what the block counts as `for_person` (`app/Services/NightRunSummary.php`, about lines
184 and 209). Failed, skipped, not_run, queued and running rows are not part of it.

**S-06 The rows Astra left for a person, on Checker 1 (P1)**
As the owner, I want to click a night's "for a person" count and land on Checker 1 showing exactly
those rows, so that I can see what the encoders encoded, or will encode, on them.
Independent test: seed a step with 2 rows done and not proceed, 1 done and proceed, 1 failed and
1 skipped; open the link; the table lists exactly the 2.

| Case | Type | Given / When / Then |
|---|---|---|
| S-06.1 | happy | Given a step with rows in every state, when `/encoder/checker_1?date=<orders date>&night_step=<id>` opens, then the table lists exactly the night's rows and no other row of that date |
| S-06.2 | happy | Given an encoder has since set a listed row's STATUS to PROCEED and edited its ADDRESS, when the link opens, then the row is still listed, with its current STATUS and ADDRESS |
| S-06.3 | happy | Given the link, when it opens, then the table has the same columns, row buttons and toolbar buttons as the unfiltered page, and no extra column |
| S-06.4 | edge | Given a step whose rows all exist on the orders date, when the link opens, then the number of rows found equals that step's `for_person` |
| S-06.5 | edge | Given a `done`, not proceed row of the step with an empty or null `code`, when the link opens, then it is listed |
| S-06.6 | edge | Given a night row whose `macro_output` row no longer exists, when the link opens, then the page loads, that row is absent, and the sign says how many are shown of how many |
| S-06.7 | edge | Given a listed row whose `ts_date` was moved to another date after the night, when the link opens with the step's orders date, then it is not listed and the sign count shows the gap |
| S-06.8 | edge | Given more than 100 night rows and PAGE not chosen, when the link opens, then 100 per page; page 2 keeps `night_step`; every row appears once across the pages |

**S-07 The night filter stays under the other filters (P2)**
As an encoder or the owner, I want Page, Filter, the status chips, the pagination and the date to
work on the night's rows, so that the table is not suddenly the whole day again.
Independent test: open the link, choose a Page; the URL still has `night_step` and the table is
the night's rows of that page.

| Case | Type | Given / When / Then |
|---|---|---|
| S-07.1 | happy | Given the link, when the page draws, then the six chip counts (TOTAL, PROCEED, CANNOT PROCEED, ODZ, BLANK, INCOMPLETE) count the filtered set and the Page dropdown lists only pages present in it |
| S-07.2 | happy | Given the link, when a Page is chosen in the dropdown, then the request keeps `night_step`, only that page's night rows show, and there is no pagination |
| S-07.3 | happy | Given the link, when the Filter select changes, a status chip is clicked or a pagination link is followed, then each keeps `night_step` |
| S-07.4 | edge | Given `status_filter=BLANK` or `INCOMPLETE` on top of the night filter, when the page draws, then the result is the intersection and never more than either alone |
| S-07.5 | edge | Given a night-filtered page, when the date picker is changed, then the request drops `night_step` (the filter belongs to one night's orders date) and the page is the plain page of the new date |
| S-07.6 | edge | Given `night_step` with no `date`, when the page draws, then the date used and shown in the picker is the step's orders date, not yesterday |
| S-07.7 | negative | Given `night_step` with a valid `date` that is not the step's orders date, when the page draws, then it does not error, shows the rows matching both (usually none), and the sign says the date is not the night's orders date |
| S-07.8 | negative | Given a night-filtered page, when the requests of Validate 1, Validate, Download, the VALIDATED badges and the AI Checker count and start are inspected, then they are byte for byte what they are today for the same date and Page (they do not carry `night_step` and act on the whole date) |

**S-08 Checker 1 says a night filter is on, and clears it in one click (P1)**
As a viewer of the table, I want one line saying which night is shown and how many rows, with a
way back, so that I do not mistake it for a short day.
Independent test: open the link; one line names the night and the count; its link returns the page
without `night_step`.

| Case | Type | Given / When / Then |
|---|---|---|
| S-08.1 | happy | Given the link, when the page draws, then one line shows: "Night run filter", the night date, the orders date, "<N> of <M> shown", "Buttons above still use the whole date", and a "Show all rows" link |
| S-08.2 | happy | Given the line, when "Show all rows" is followed, then the URL has no `night_step` and keeps `date`, `PAGE` and the Filter value |
| S-08.3 | negative | Given no `night_step`, when the page draws, then the line is absent |
| S-08.4 | edge | Given some night rows are missing or off the date, when the line draws, then N counts the rows matching the night filter and the date alone (not narrowed by Page, Filter or a chip) and M is the step's current number of night rows |
| S-08.5 | edge | Given an Astra step with zero night rows, when its link is opened by hand, then the table is empty, the line says "0 of 0 shown", and the clear link works |
| S-08.6 | edge, owner check | Given a phone width of 390 x 844, when the page opens, then the line wraps and does not hide table rows under the fixed header. No automated test: list it under `## Owner checks` in `qa/stories.md` |
| S-08.7 | negative | Given hostile text in any row field (for example `<script>alert(1)</script>` as a page name), when the line draws, then the line holds only fixed words, integers and dates, and nothing taken from a row |

**S-09 The Night run block links the count (P1)**
As the owner, I want the "for a person" count to be a link, so that one click takes me to those
rows.
Independent test: draw the logs page with a night that has 2 for a person; the count is a link to
`/encoder/checker_1?date=<orders date>&night_step=<step id>`.

| Case | Type | Given / When / Then |
|---|---|---|
| S-09.1 | happy | Given a night with rows for a person, when the logs page draws, then "N for a person" in the collapsed line is a link to the route with the orders date and the step id, and the words "N for a person" stay together inside the link |
| S-09.2 | happy | Given the expanded block, when it draws, then "N left for a person" is the same link and the by-code breakdown after it stays plain text |
| S-09.3 | edge | Given N is 0, when the block draws, then it is plain text, not a link |
| S-09.4 | edge | Given a night with no Astra step, when the block draws, then there is no link |
| S-09.5 | edge | Given a signed-in user who is not the CEO role but can open the logs page, when the block draws, then the link is shown to them too (everything CEO-only today stays CEO-only) |
| S-09.6 | negative | Given the change, when the logs page draws, then the existing test's sequence "... rows", "... PROCEED", "... for a person", "... failed" still reads in that order (`NightRunPageTest`) |
| S-09.7 | edge, owner check | Given a phone, when the count is tapped, then Checker 1 opens in the same tab with that night's rows. Owner check, as above |

**S-10 A bad parameter never errors, never widens, never leaks (P1)**
As the owner, I want the URL parameter treated as untrusted input, so that no edit of the address
bar can break the page or show other data.
Independent test: send `night_step=abc`; the page answers 200, shows the notice and no rows.

| Case | Type | Given / When / Then |
|---|---|---|
| S-10.1 | negative | Given `night_step` of "abc", "1abc", "1.5", "-1", "+1", "1e3", " 1" or "0x1", when the page opens, then status 200, no rows, one line "Night run filter not valid. No rows shown." with the "Show all rows" link, and no "of ... shown" text |
| S-10.2 | negative | Given digits that name no step ("0", "999999"), when the page opens, then the same as S-10.1 |
| S-10.3 | negative | Given the id of a step that is not an Astra step (an import step), when the page opens, then the same as S-10.1, and nothing about that step appears |
| S-10.4 | edge | Given `night_step=` (empty) or the parameter absent, when the page opens, then it is the page as today: no filter, no line |
| S-10.5 | negative | Given `night_step[]=1` or `night_step[a]=1`, when the page opens, then no 500 and the same as S-10.1 (an array must never be cast to 1) |
| S-10.6 | edge | Given "99999999999999999999" or a 10,000-character digit string, when the page opens, then 200, the same as S-10.1, and no database error |
| S-10.7 | negative | Given "1 OR 1=1", "1;DROP TABLE macro_output" or "1' OR '1'='1", when the page opens, then the same as S-10.1, `macro_output` is untouched, and the value never appears in SQL text (bindings only) |
| S-10.8 | negative | Given any mix of `night_step`, `date`, `PAGE`, the Filter value and `status_filter`, when the page opens, then the ids shown are a subset of the night's rows and of the unfiltered result for the same other parameters |
| S-10.9 | negative | Given any value of `night_step`, when the page draws, then it holds nothing from `night_astra_rows` beyond which rows are listed: no code, reason, cost or log link |
| S-10.10 | edge | Given "007" and a step with id 7 that is an Astra step, when the page opens, then it is read as step 7 |

**S-11 Access is what it is today; the filter only narrows (P1)**
As the owner, I want everyone who can open Checker 1 to be able to use the link, and nobody to see
a row they could not see before.
Independent test: open the link as a non-CEO signed-in user and as the CEO role; both get the same
rows; a guest is sent to sign-in.

| Case | Type | Given / When / Then |
|---|---|---|
| S-11.1 | happy | Given a signed-in user who is not the CEO role and can open Checker 1, when the link opens, then 200 and the same rows as for the CEO role |
| S-11.2 | negative | Given a guest, when the link opens, then redirected to sign-in with no row content |
| S-11.3 | negative | Given a non-CEO, when `night_run.rows` is requested, then still 403 (unchanged) |

**S-12 Checker 1 without the parameter is unchanged (P1)**
As an encoder using Checker 1 every day, I want the page to behave exactly as before when there is
no night parameter.
Independent test: the same fixture, with and without the change, gives the same ids, chip counts,
Page list and pagination.

| Case | Type | Given / When / Then |
|---|---|---|
| S-12.1 | happy | Given no `night_step`, when the page opens, then the records, the six chip counts, the Page list and the pagination are what they are today for the same fixture |
| S-12.2 | negative | Given no `night_step`, when the page draws, then no query touches `night_run_steps` or `night_astra_rows` and no line shows |
| S-12.3 | edge | Given PAGE chosen and no night filter, when the page opens, then it is still not paginated |
| S-12.4 | edge | Given a night-filtered page, when a row is saved or a field updated through the existing routes, then the request and its effect are what they are today for that row id |

**Tests:** one failing test per case first, named with its case ID, at the seam the case describes:
the HTTP route for what the page returns (`macro_output.index`, `macro_checker.logs`), a unit test
only where a case is about the resolver alone. Cases that pin behaviour that must not change
(S-07.8, S-09.6, S-11.3, S-12.1 to S-12.4) are characterisation tests: report them with their
green run and say which change would turn them red, instead of a red line. Expected values come
from the case, never recomputed the way the code does it. If the code shows a gap no case covers,
put a numbered question in the result before building it.

## Constraints

**Decisions already made** (settled with the owner; don't reopen them unless something is actually
broken):

| Topic | Decision |
|---|---|
| Where | The same route, `/encoder/checker_1` (`macro_output.index`), with extra URL parameters. The existing table is filtered in place: every existing column, warning, button and row action stays exactly as it is. No new table, no new page, no new column. |
| Which rows | All of the night's rows as defined above, with their current values and current status, not only the ones still blank. |
| Parameters | `night_step` (the Astra step's id) and the existing `date` (orders date). No other new parameter. |
| Who | Everyone who can open Checker 1 today. The route keeps its guard (the group `web`, `auth`, `allowed_ip` in `routes/web.php`); no role check is added or removed. The link in the block is shown to everyone who sees the block. `night_run.rows`, the cost and "Show rows" stay CEO-only. |
| Narrow only | The night filter is one more condition inside the existing base query of `MacroOutputController::index`, so that records, chip counts, the Page list and pagination all narrow together. It can never return a row the same request without `night_step` would not return. |
| How it filters | A subquery on `macro_output.id` against `night_astra_rows` with the step id as a bound integer. The ids are never loaded into PHP and never put in the URL (a night can hold up to 20,000 rows). |
| Reading the value | Accept only a string of ASCII digits of at most 9 characters, then read it as an integer; the step must exist and be of the Astra kind (the same rule as `NightRunController::astraStep()`). Anything else is "not valid". An array, a sign, spaces, decimals and exponents are not valid. |
| Not valid | Fail closed: status 200, no rows, the notice line, the clear link. Never the whole day, never a 404 or 500. An empty or absent parameter is simply no filter. |
| Date | With no `date`, the step's orders date is used. With a different valid `date`, the result is the intersection and the line says the date is not the night's orders date. Changing the date picker drops `night_step`. An invalid `date` value behaves as it does today; that is not this spec's to change (name it under Proposed tasks if you confirm it errors). |
| The filters form | The GET form on the page (date, PAGE, checker) drops unknown parameters today. It gets a hidden `night_step` so that Page and Filter keep the filter; the date picker's submit removes it. |
| Buttons on the whole date | Validate 1, Validate, Download, the VALIDATED badges and the AI Checker count and start keep working on the whole date and Page exactly as today, also on a night-filtered page. They are not changed; the line says "Buttons above still use the whole date". |
| The line | One line inside the page's fixed header, in English like the logs page, in the page's existing small-text style. Valid filter: "Night run filter · night of <D, M j> (orders of <M j>) · <N> of <M> shown · Buttons above still use the whole date · Show all rows". Not valid: "Night run filter not valid. No rows shown. · Show all rows". When the date is not the night's orders date, add "this date is not the night's orders date". Only fixed words, integers and dates; nothing from a row. |
| The link | In `resources/views/encoder/_night_run.blade.php`: the "N for a person" count in the collapsed line and "N left for a person" in the expanded block. Same tab. A count of 0 is plain text. The words "N for a person" stay together inside the anchor. |
| Tests need a fuller fixture | There is no test of `macro_output.index` today; the test `macro_output` table in `tests/Feature/NightRun/NightRunTestCase.php` lacks columns the controller selects, and the page needs the layout's table. Extending the test fixture is part of this work. If rendering the full page in a test turns out to need much more than that, stop and report in the plan before building. |
| Running the suite here | This worktree has no installed dependencies and no environment file. Allowed, before the plan: `composer install --no-interaction` once from the unchanged lock file (no update, no require; `composer.json` and `composer.lock` must show no diff; no npm install). `phpunit.xml` already carries a test-only application key. |

**Threat model.** Untrusted (validate, never trust, treat as data): the `night_step` and `date`
URL parameters and every other query parameter; every field of an order row (customer and sheet
text). Trusted: the files in this repository, config, ids the application itself generates. Risk
tier high: the page is open to every signed-in staff member and the parameter reaches a query on
the orders table. A major needs a one-line realistic scenario. Fix loops stop after two; remaining
findings are accepted with a reason in `TODO.md` unless they are a security or data-loss major,
which goes to the reviewer.

**Rules**
- Don't start other Claude Code sessions; use subagents inside this session, in the foreground.
- Talk only to the reviewer, through the result file. Plan first, then build.
- No `Co-Authored-By`, `Claude-Session` or "Generated with Claude Code" lines in commit messages.
- Code comments explain the reason itself, in Taglish like the files around them; no names, no
  decision labels, no spec citations.
- No commands that start with an environment variable. Never open, read or create an environment
  file.
- Downloads: only the one `composer install` above.
- Change only what this spec needs: no refactors, renames or other fixes in the controller or the
  views; suggestions go into the result under "Proposed tasks".
- When the owner asks for a filter on a page he already uses, the existing table is filtered in
  place and keeps every column, warning and action. These must stay visible and working on a
  night-filtered page: the status chips with counts, the Page dropdown, the Filter select, the date
  picker, the toolbar buttons, every table column and every row button.
- Write every message so that a staff member with no technical knowledge understands it.

## Budget

- Attempts: at most 2 tries at the same step; then stop and report what you tried and what you need.
- Size: medium job (one controller method, two Blade views, possibly one small class that resolves
  the parameter, the test fixture and tests, `qa/stories.md`). If it is turning out much bigger,
  stop and report before going on.

## Done when

- [ ] Cases S-06.1 to S-12.4 pass, each with a test named after it, except S-08.6 and S-09.7, which
      are listed under `## Owner checks` in `qa/stories.md`.
- [ ] The result has a red line (test name, first failure line) for every feat slice, watched before
      the code; characterisation cases are reported with their green run and the change that would
      turn them red; no test commit lands after the code it pins.
- [ ] `git diff 525ef44 --stat` lists only: `app/Http/Controllers/MacroOutputController.php`,
      `resources/views/macro_output/index.blade.php`,
      `resources/views/encoder/_night_run.blade.php`, at most one new class under `app/` that
      resolves the parameter, files under `tests/`, `qa/stories.md`, `TODO.md` if findings were
      accepted, and this spec, its result and its plan.
- [ ] In `MacroOutputController.php` the diff touches only the `index` method (and an import line
      if needed); `git diff 525ef44 -- app/Http/Controllers/MacroCheckerController.php routes/`
      is empty.
- [ ] `php.bat -l` passes on every changed PHP file.
- [ ] The full suite runs in this worktree before the change and after it: no new failures. On the
      base in the main checkout the suite stands at 1 failed (the old `ExampleTest`), 3 skipped,
      540 passed; a fresh worktree shows one more red test,
      `ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`,
      which needs a file git does not track. Neither is this spec's to fix. Both summaries in the
      result.
- [ ] The risk tier's review was done (who reviewed, findings, what was fixed) and is in the result.
- [ ] `qa/stories.md` has this slice with the Test column filled, and the two owner checks listed.
- [ ] The result (`docs/specs/016-night-rows-checker1.result.md`) is filled in.

## Out of scope

Making Validate, Download, the badges or the AI Checker follow the night filter; a column or badge
for what Astra left on a row; links for the PROCEED or failed counts; the engines (`MacroChecker`,
`AstraEncoder`) and the night job; `NightRunController`; the handling of an invalid `date`; any
change to routes, roles or middleware; deploying; pushing.

## Report back

Fill in `docs/specs/016-night-rows-checker1.result.md` from its template and commit it with the
work, including every ruling you made (`Ruling: <decision> — <why> — <cost if wrong>`; an
unrecorded deviation is a secret decision) and the deferred minors, then end the run with the one
line "result updated: done". No PR and no push: the reviewer reviews the branch. Under Merge danger
say whether this is a one-way or two-way door, the blast radius and how to revert.
