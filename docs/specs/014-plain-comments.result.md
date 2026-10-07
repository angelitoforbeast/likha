# Result: spec 014 Plain comments and notes

> Committed beside the spec: names no people and no decision ids.

**Status:** done, with one weakness the reviewer should know first: in this worktree 287 of the suite's tests fail before and after for the same reason (no application key, because the worktree has no environment file), so the two test runs prove "no new failure" but exercise only part of the suite. The token comparison under "Tests" is the stronger proof that nothing executable changed.
**Date:** 2026-10-08
**Branch / PR:** `chore/014-plain-comments` (base `7ecc05b`, head: the commit that carries this file); no push, no PR
**Preview or run link:** n/a (no behaviour change)

## Summary

Comments, docblocks and Markdown notes no longer name people or point at task paperwork, and code
comments no longer cite decision labels or spec sections: 62 files reworded (45 PHP, 4 Blade,
13 Markdown), about 200 lines. The only executable change is two fixture strings in one test, as
the reviewer decided. Checked by both searches (empty), a token comparison of every changed PHP
file against the base (identical apart from those two strings), `php -l` on all 45 PHP files, a
line-by-line read of the Blade and Markdown diffs, and the full suite before and after (same
counts, same result per test).

## Case table

| Check | Command | Result |
|---|---|---|
| Search 1 (names and paperwork words) | the search from the start prompt | no line, exit 1 |
| Search 2 (decision labels, spec citations) | `git grep -n -P "\((A\|B\|D)\d+\)\|spec \d{3}\|spec §" -- app routes resources/views tests` | no line, exit 1 |
| Files changed | `git diff 7ecc05b --name-only` | 65 files: 62 reworded + this spec, result, plan (lists under "Done-when") |
| Comment-only, PHP | token comparison, see "Tests" | 44 of 45 files identical; 1 file differs in exactly 2 string tokens (the approved fixture change) |
| Comment-only, Blade | `git diff 7ecc05b -U0 -- '*.blade.php'` read by eye | 9 changed lines, all inside `{{-- --}}`, `/* */` or `//` comments |
| Lint | `php.bat -l` on each of the 45 PHP files | 45 × "No syntax errors detected" |
| Suite, base | `php.bat artisan test` before the first edit | `Tests: 287 failed, 180 warnings, 57 passed (2114 assertions)` |
| Suite, finished | same command on the finished branch | `Tests: 287 failed, 180 warnings, 57 passed (2114 assertions)` |
| Address data | `git diff 7ecc05b --stat -- resources/views/macro_output/` | empty |
| Lock files | `git diff 7ecc05b --stat -- composer.json composer.lock` | empty |

## Story changes

none

## Done-when checklist

- [x] **The search given in the start prompt returns no line.** Run on the finished branch: no output, exit status 1.
- [x] **`git diff 7ecc05b --stat` lists only allowed files** (read as decided: the spec's list, plus the 15 the first search found, plus the files the second search added). 65 files:
  - this spec, its result, its plan (3);
  - the spec's list (34): `TODO.md`; `docs/specs/001`, `003`–`007`; `docs/plans/001`, `007`; `app/Console/Commands/NightAstraTick.php`, `NightImport.php`; `app/Http/Controllers/Auth/LoginController.php`, `Encoder/Checker1SettingsController.php`, `ItemController.php`, `MacroCheckerController.php`, `NightRunController.php`, `OwnerUsersController.php`; `app/Listeners/RefuseRememberedLoginUnlessCeo.php`; `app/Providers/AppServiceProvider.php`; `app/Services/HoldService.php`, `ItemStockService.php`; `routes/web.php`, `routes/console.php`; `resources/views/encoder/_night_run.blade.php`, `ai_checker_logs.blade.php`, `checker_1/settings.blade.php`, `resources/views/item/index.blade.php`; `tests/Feature/Item/LifecycleStockTest.php`, `StockEndpointTest.php`, `ItemPageTest.php`, `ItemLayoutTest.php`; `tests/Feature/Auth/LoginCharacterizationTest.php`, `CeoRememberedLoginTest.php`; `tests/Unit/SourcingClassifierTest.php`;
  - the 15 from the first search: `docs/plans/003-stock-doi-category.md`, `004-lifecycle-restock.md`, `005-item-layout.md`, `006-item-simple-view.md`; `app/Services/Imports/MacroImportStarter.php`, `app/Services/NightRunSummary.php`; `tests/Feature/Auth/AuthTestCase.php`, `EndOpenSessionsTest.php`, `OwnerPasswordChangeTest.php`; `tests/Feature/NightRun/AstraEncoderAdditionsTest.php`, `NightAstraStartTest.php`, `NightImportCommandTest.php`, `NightRunSummaryTest.php`; `tests/Feature/OwnerPrivate/ClaudeActionColumnSettingsTest.php`, `ClaudeActionCommandTest.php`;
  - 13 more that only the second search (follow-up 014-1) hits: `app/Jobs/RunNightAstraRow.php`, `app/Services/AiCheckerRowRunner.php`, `app/Services/NightAstraRun.php`; `tests/Feature/NightRun/AiCheckerRowRunnerTest.php`, `ImportStartTest.php`, `NightAstraRowJobTest.php`, `NightAstraSelectionTest.php`, `NightAstraTestCase.php`, `NightAstraTickTest.php`, `NightRunPageTest.php`, `NightRunRoutesTest.php`, `NightSettingsTest.php`, `RunRowCharacterizationTest.php`.
- [x] **Every hunk in PHP and Blade changes only comments or docblocks**, with one listed exception. How checked: (1) for each of the 45 changed PHP files, the base version and the new version were tokenised with PHP's `token_get_all`, comment and docblock tokens removed, and the two streams compared: 44 identical; (2) the 4 Blade files' 9 changed lines were read one by one; (3) `git diff 7ecc05b -U0 -- app routes resources tests`, filtered to lines that do not start with a comment marker, leaves three kinds of line: one Blade line inside a `{{-- --}}` block, two code lines whose code is unchanged and only the trailing `//` comment differs (`LoginController.php:50`, `LifecycleStockTest.php:83`), and the exception.
  **The only non-comment hunk:** `tests/Feature/OwnerPrivate/ClaudeActionCommandTest.php` lines 133 and 134, the `source` fixture value `ceo:` + the owner's first name became `ceo:CEO User`. Reason: the first search matched the name, the value is asserted nowhere, and the sibling `ClaudeActionSaveRouteTest` already uses `ceo:CEO User`; decided by the reviewer.
- [x] **`php.bat -l` passes on every changed PHP file.** Loop over the 45 files from `git diff 7ecc05b --name-only -- '*.php' ':!*.blade.php'`: 45 lines `No syntax errors detected in <file>`, no other output.
- [x] **Full suite before any change and on the finished branch: same counts, no new failures.** Before (tree = `7ecc05b` plus the three Markdown files of this spec): `Tests: 287 failed, 180 warnings, 57 passed (2114 assertions)`. After: `Tests: 287 failed, 180 warnings, 57 passed (2114 assertions)`. The sorted per-test result lines of both runs (timings cut off) are identical under `diff`. See the weakness under "Tests".
- [x] **`git diff 7ecc05b --stat -- resources/views/macro_output/` is empty.** No output.
- [x] **Second search (follow-up 014-1) returns no comment line.** No output at all, so there is also no non-comment hit to list.
- [x] **The result is filled in.** This file.

## How to run

From the worktree root, in Git Bash:

- Search 1: the command in the start prompt. Search 2: the command in the case table.
- Lint: `"C:/Users/Forbeast/.config/herd/bin/php.bat" -l <file>`
- Tests: `"C:/Users/Forbeast/.config/herd/bin/php.bat" artisan test`
- Comment-only check for one file: `git show 7ecc05b:<file>` and the working file through `token_get_all`, dropping `T_COMMENT` and `T_DOC_COMMENT`, then compare.

## Rulings

Decisions from the reviewer (answers to the plan's questions):

Ruling: all search hits are reworded, about twice the spec's size estimate — reviewer's decision, same kind of work — n/a.
Ruling: the search is the final word; the 15 files outside the spec's list are reworded and named above — reviewer's decision — n/a.
Ruling: the two fixture `source` values become `ceo:CEO User` and are listed as the only non-comment hunk — reviewer's decision — if wrong, revert two strings.
Ruling: `composer install` was run once in this worktree from the unchanged lock file; no update, no require, no npm install — reviewer's decision — `composer.json` and `composer.lock` show no diff.

Follow-up 014-1 from the reviewer:

Ruling: in PHP and Blade comments, decision labels of past tasks and citations of a spec number or section are dropped too, under the plan's rules 1–3; Markdown keeps its own references; a second search is added to done-when; the job is medium — reviewer's decision — recorded here and followed.

My own:

Ruling: T3 and T4 were done by the main session, not by a developer subagent as the plan said — the `backend-developer` agent type is not installed in this session ("Agent type not found") — cost if wrong: none for the content; every changed line was checked by the token comparison.
Ruling: decision labels without brackets were also removed where they sat in files already being changed (`A1:`, `A2:`, `A3:`, `B1:`, `D3:` at the start of 7 test docblock or comment lines; "para sa B2 sa ibaba" in `LoginController.php:50`) — the follow-up says to drop such labels and names the bracketed form only as an example; leaving them would leave labels whose bracketed twins were just removed — if wrong, 8 comment lines carry a little less shorthand.
Ruling: one such label in a file neither search hits was left alone (`tests/Feature/OwnerPrivate/ClaudeActionViewTest.php:140`, a comment starting `D3:`) — keeping the file list to what the searches require — listed under "Proposed tasks".
Ruling: no pointer to `docs/specs/NNN` was added to any code comment; in Markdown, pointers to removed paperwork files were dropped and none replaced by a path — the project rule against spec citations in code; in Markdown no committed document holds the removed result notes or follow-up texts — if wrong, a reader of `TODO.md` has "the task's result notes" with nowhere to look, which was already true at the base commit.
Ruling: where a comment's only reason was a pointer, the reason was written from the code beside it: "expected values = worked examples computed and written by hand" (`LifecycleStockTest.php:9`, `StockEndpointTest.php:11`), "then a new remember token on every change" (`OwnerPasswordChangeTest.php:11`), "rules of the sourcing worklist" (`SourcingClassifierTest.php:11`), "password change:" (`EndOpenSessionsTest.php:120`), "(approved by the reviewer)" (`ItemController.php:225`), all in Taglish — spec decision "state why" — if wrong, a comment is slightly less exact; no code effect.
Ruling: in Markdown, follow-up rounds read "follow-up NNN-K" and `TODO.md` sub-headings read `### 012-1: … (date)` — numbers kept so cross-references resolve — one search-and-replace if another word is wanted.
Ruling: in Markdown, decision labels such as `(A1)` and `B3` were kept — the follow-up says Markdown keeps its own references — n/a.
Ruling: the baseline suite was run with the spec, result template and plan already committed, not on a bare `7ecc05b` — the start prompt orders that commit first, and three Markdown files cannot affect a test — none.
Ruling: the suite was not made to run fully by creating an environment file or passing a key — environment files are off limits and commands may not start with a variable — cost: the weakness described under "Tests".

## Deferred minors

none

## Merge danger

Two-way door: comments, docblocks and Markdown only, plus two fixture strings in one test. Nothing
a user can see or do changes; no migration, no config, no asset build needed. Blast radius if
wrong: a comment or note that reads less exactly than before. Revert: `git revert` of the squash
commit on `develop` (or drop the branch before merge). Merge conflicts are possible with any open
branch that edits the same comment lines or `TODO.md`; resolve by taking the other branch's code
and re-running the two searches.

## Conflicts with CLAUDE.local.md

- The spec says to point a removed pointer at the committed document; CLAUDE.local.md says code comments never cite a spec. CLAUDE.local.md won for code comments (and follow-up 014-1 then removed the existing citations too).
- CLAUDE.local.md names `backend-developer` for server tasks; that agent is not available in this session, so the main session did the edits (ruling above).
- CLAUDE.local.md's test-first rule: not applicable, no executable behaviour changed.
- CLAUDE.local.md's `/ship` pushes and opens a PR; the start prompt says no push and no PR, which was followed.
- CLAUDE.local.md says the full suite must also keep `test/hooks/` green with `node --test`; not run: no kit file was touched and the command is not among those the start prompt gives.

## Tests

- `"C:/Users/Forbeast/.config/herd/bin/php.bat" artisan test`, before the first edit and on the finished branch: both `Tests: 287 failed, 180 warnings, 57 passed (2114 assertions)`; per-test result lines identical.
- **Weakness.** 286 of the 287 failures are `MissingAppKeyException`: `phpunit.xml` sets no application key and this worktree has no environment file, so every test that encrypts a cookie or session stops there, in both runs. The 180 warnings are the same cause (the framework warns that the environment file is missing). This is the state of the worktree, not of the change. The test that holds the two changed fixture strings (`ClaudeActionCommandTest`) is among those that run and pass (with that warning) in both runs.
- Token comparison (the proof that does not depend on the suite): 45 changed PHP files, comment tokens removed, old against new: 44 `SAME`, 1 `DIFF` with exactly two tokens, the fixture strings.
- `php.bat -l`: 45 files, no syntax errors.
- Not run: `npm run build` (no asset source changed in a way that matters: the Blade changes are comments), the browser check (no visible change; no browser tools needed), `node --test` for the kit hooks.

## Open questions

1. Should the reviewer run the full suite once in the main checkout (which has an environment file) on this branch before merging? I recommend yes: it is the one check this worktree could not give in full.

## Process suggestions

- A fresh worktree has neither `vendor/` nor an environment file, so `artisan test` there cannot run the suite in full — evidence: first run failed at `vendor/autoload.php`; after the install, 286 tests fail on the missing application key. A test key in `phpunit.xml` (`<env name="APP_KEY" …/>`) would make the suite independent of the environment file.
- The spec's file list and size came from a narrower search than its done-when search — evidence: 48 files and 185 lines against "about 40 files, about 90 lines".
- The plan template assumes agent types (`backend-developer`) that this session does not have — evidence: "Agent type 'backend-developer' not found".

## Proposed tasks

- Add a test application key to `phpunit.xml` so the suite runs in any checkout — 286 tests cannot run without an environment file — normal.
- Remove the last unbracketed decision label in a code comment (`tests/Feature/OwnerPrivate/ClaudeActionViewTest.php:140`) and sweep for others the two searches cannot see — same rule as this spec, outside its searches — low.
- Decide whether `TODO.md` should keep decision labels such as `(A1)` and `B3` now that the texts they index are not in the repository — a reader cannot resolve them — low.

## Suggested next steps

Review the branch locally (6 commits on top of `7ecc05b`: spec, plan, application comments, test
comments, `TODO.md`, older documents, then this result). Run the suite once where an environment
file exists. Squash-merge into `develop` if satisfied; no deploy step is needed for this change.
