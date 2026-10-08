# Result: spec 016 Night run rows on Checker 1

> Committed beside the spec: names no people and no decision ids.

**Status:** partial (plan only; no product code and no test written yet, waiting for the "go")
**Date:** 2026-10-08
**Branch / PR:** `feat/016-night-rows-checker1`, based on `525ef44`; no PR, no push
**Preview or run link:** n/a (nothing built yet)

## Summary

This run produced the plan only: `docs/plans/016-night-rows-checker1.md`. The plan adds one small
class that reads `night_step` and holds the rule "the night's rows", one extra condition on the
existing base query of the Checker 1 page (so the rows, the six chips, the Page list and the
pagination narrow together), one line in the page's fixed header with a "Show all rows" link, and
the link on the "for a person" count in the Night run block. The page was drawn once in a
throwaway test to check that the test fixture needs only ten more columns and the `tasks` table.
The high-tier spec review was run before the plan was sent; it found no blocker and no major.
Eight questions below need an answer with the "go".

## Case table

Not started: no test exists yet. The plan (section 4) puts every case S-06.1 to S-12.4 in a task;
S-08.6 and S-09.7 are owner checks.

## Story changes

None made yet. Two are proposed and wait for the answers: question 1 (remove the input " 1" from
S-10.1) and question 7 (a third owner check for S-07.5).

## Done-when checklist

Nothing is ticked: this run is the plan.

- [ ] Cases S-06.1 to S-12.4 pass, each with a test named after it, except S-08.6 and S-09.7 — not started
- [ ] A red line for every feat slice; characterisation cases with their green run — not started
- [ ] `git diff 525ef44 --stat` lists only the allowed files — today: the spec, this result and the plan
- [ ] `MacroOutputController.php`: only `index` touched; `MacroCheckerController.php` and `routes/` untouched — not started
- [ ] `php.bat -l` on every changed PHP file — not started
- [ ] Full suite before and after — **before** is done, see Tests
- [ ] The risk tier's review — the spec review is done (see Rulings); the diff reviews come with the build
- [ ] `qa/stories.md` has the slice and the two owner checks — not started
- [ ] The result is filled in — plan stage only

## How to run

From the worktree, after `composer install --no-interaction` (no environment file is needed;
`phpunit.xml` carries the test key):

```
"C:/Users/Forbeast/.config/herd/bin/php.bat" vendor/phpunit/phpunit/phpunit
"C:/Users/Forbeast/.config/herd/bin/php.bat" artisan test
```

## Rulings

Ruling: Composer was run as `php.bat composer.phar install --no-interaction --working-dir=<worktree>` — there is no `composer` command in this shell, only Herd's `composer.phar` — none: same lock file, `composer.json` and `composer.lock` show no diff.
Ruling: a first try at that install was typed as `cd <worktree> && composer install …`, against the rule on commands; it did nothing ("composer: command not found") and was not repeated in that form — reported so that it is not a hidden deviation — none: nothing was installed or changed by it.
Ruling: one throwaway test was written, run and deleted before the plan (it drew the page, logged the queries and tried three bad dates) — the spec asks the plan to say whether the page can be drawn in a test, and only drawing it answers that — none: nothing of it is committed; the real tests are written fresh, test first.
Ruling: the spec review (high tier) was run by `skeptic-reviewer` on the spec and the plan together, before the plan was sent — the kit asks for it on high-risk specs — verdict `ready`, no blocker, no major; its five changes are in the plan.
Ruling: the baseline is reported with two commands (plain PHPUnit and `artisan test`) — here `artisan test` shows 485 tests as "warnings", which hides the pass count — if only one is wanted, plain PHPUnit is the readable one.
Ruling: the row for 016 was added to `handoff/README.md` in the main checkout as it stands (its protocol text still describes the older `HANDOFF.md` way and has no rows for 014 and 015) — only the row was asked for — none.

Planned rulings, to be confirmed by the "go" (not yet acted on):

Ruling: "Show all rows" removes `night_step` and the pagination's `page`, keeps `PAGE`, the Filter value and `status_filter`, and carries the date shown — page 3 of a filtered list may not exist in the full one, and a link without `date` would jump to yesterday — a chip choice survives the clear; say so if it should not.
Ruling: "this date is not the night's orders date" is placed right after "<N> of <M> shown" — the spec gives the words, not the place — one phrase moves.
Ruling: the hidden input and the line never print the address-bar text; the chips and pagination links go on repeating the whole query string, escaped, as today — changing how they are built is outside this spec — none.
Ruling: the tasks run one after another, not in parallel worktrees — two of the three product files are touched by neighbouring tasks and the job is small — a little slower.

## Deferred minors

None yet. The spec review's minors were all taken into the plan (the parsed date for the notice,
the query builder only for `proceed`, the wording of S-07.8 and S-12.4, string-only mixes for
S-10.8, the second copy of the date format named as a risk).

## Merge danger

n/a at plan stage: nothing ships. For the build as planned: a two-way door (no migration, no data
written, one optional URL parameter); the blast radius is the Checker 1 page for every signed-in
staff member and the Night run block; revert is one revert of the squash commit. One thing cannot
be checked here and will be named again at the end: the speed of the sub-select on production
MySQL (tests run on sqlite).

## Conflicts with CLAUDE.md

- The kit names `backend-developer` and `frontend-developer` as the developers. Their files exist
  in `.claude/agents/`, but this session is offered only `skeptic-reviewer`, `story-writer` and
  `browser-checker` of the kit's agents. See question 6.
- The kit's browser check cannot run: the worktree has no environment file and may not get one, so
  the app cannot be started. The plan skips it and relies on the two owner checks.

## Tests

Baseline on `525ef44` in this worktree, before any change:

- `php.bat vendor/phpunit/phpunit/phpunit` → `Tests: 544, Assertions: 5598, Errors: 1, Failures: 1, Skipped: 3.`
- `php.bat artisan test` → `Tests:    2 failed, 485 warnings, 57 passed (5598 assertions)`
- The two red tests are the two the spec names: `Tests\Feature\ExampleTest > the application
  returns a successful response` and `Tests\Feature\NightRun\ImportStartTest > macro job final
  write applies only while the run is still active`. 544 tests = 2 red + 3 skipped + 539 passed,
  which is the spec's main-checkout figure (1 + 3 + 540) with the one extra red test.
- The 485 "warnings" of `artisan test` are every test that boots the application: a PHP warning
  from `file_get_contents(...)` on a path the printer cuts off, most likely the missing environment
  file (not verified; plain PHPUnit reports no warning).

## Open questions

1. **A value with a space around it cannot be told from a clean one.** The app trims every input
   before a controller sees it, so `night_step=%201` arrives as "1" (measured), and a plus sign
   typed in the address bar is a space, so `+1` arrives as "1" too. A real plus sign (`%2B1`)
   arrives as "+1" and is rejected, so "+1" stays in S-10.1, tested as `%2B1`. Options: (a) accept
   the trimmed value, the way every other input of the app is read, and take " 1" out of S-10.1
   (a value of spaces only is then "empty": the page as today, like S-10.4); (b) read the value
   from the raw query string so that " 1" is "not valid", about twelve more lines of parsing on
   the untrusted path and their tests. **Recommended: (a)**; a trimmed "1" is simply step 1, and
   (b) adds code where the tier is high for no gain in safety. Without an answer I build (a).
2. **`night_step` with no `date`: the picker and the address bar would disagree.** The AI Checker
   and Astra Check buttons read the date from the address bar, not from the picker. On
   `/encoder/checker_1?night_step=7` the picker would show the night's orders date while those two
   buttons count and start on yesterday: a paid run that writes rows of the wrong day. The link in
   the block always carries `date`, so this needs a hand-edited address. Options: (a) answer such
   a request with a redirect to the same address with `date` added, every other parameter kept
   (S-07.6 then holds after the redirect; a few lines in `index`); (b) leave it and accept the
   mismatch. **Recommended: (a)**; the spec review asks for it as a requirement. Without an answer
   I build (a).
3. **"Buttons above still use the whole date" is not true for two buttons.** Validate and ITEM
   CHECKER send the ids of the rows that are in the table, today too, so on a night-filtered page
   they act on the night's rows shown, not on the whole date. Validate 1, Download, the badges and
   the AI buttons do use the whole date. S-07.8 lists Validate among the whole-date buttons.
   Options: (a) keep the decided words; (b) say "Validate 1, Download and the AI buttons still use
   the whole date". **Recommended: (b)**, because a staff member will read the line literally.
   Without an answer I keep the decided words, (a), and S-07.8 pins the script text only.
4. **A "not valid" filter and the Page or Filter controls.** Planned: the hidden input then holds
   the fixed value `0`, so choosing a Page or a Filter keeps the page on "Night run filter not
   valid. No rows shown." until "Show all rows" is clicked, and the address-bar text is not
   printed into the form. The other way (no hidden input) would show the whole day after a Page
   choice. Is the fixed `0` acceptable?
5. **One existing assertion must change.** `NightRunPageTest::test_the_ceo_sees_the_night_with_every_action`
   compares the collapsed line with one exact string ("9 rows · 1 PROCEED · 1 for a person · 1
   failed · …"); with "1 for a person" inside a link that string is no longer in the HTML. S-09.6
   asks only for the in-order check, which stays green. Planned: compare that one string against
   the page text with the tags removed, same words, same order. May I edit that assertion?
6. **Who develops.** This session is not offered the kit's developer agents (see Conflicts).
   Planned: the main session does the developer work itself under the same rules (one failing
   test first, one commit per task), and `skeptic-reviewer`, a separate agent, reviews T2 and T3
   at adversarial depth and T4 at standard depth. Is that acceptable?
7. **S-07.5 (the date picker drops `night_step`) happens in the browser.** There is no JavaScript
   test runner in the project, so the automated test can only check the drawn markup (the date
   input removes the hidden field before it submits) and that the new date without `night_step`
   is the plain page. May I add a third owner check: "change the date on a night-filtered page;
   the address has no `night_step` and the line is gone"? The same limit holds for S-07.8: its
   test pins the text of the scripts, it does not run them.
8. **Validate 1 leaves the night filter without saying so.** After a successful Validate 1 the
   page goes to an address the server builds with `date`, `PAGE`, `status_filter` and the To Fix
   filter only. On a night-filtered page the viewer then sees the whole date's To Fix rows and no
   line. The spec forbids changing that request. Planned: accept it as it is and name it under
   Proposed tasks. Or should the line warn about it?

Also for the reviewer's eye, no answer needed unless you disagree: S-10.4 and S-11.2 are true
today already, so they will be reported as characterisation cases (green run and what would turn
them red) beside the ones the spec lists.

## Process suggestions

- The start prompt allows `composer install --no-interaction`, but this shell has no `composer` command; Herd ships `composer.phar` — the first try failed with "composer: command not found". Name the command as `php.bat <herd>/bin/composer.phar install --no-interaction --working-dir=<worktree>`.
- `artisan test` in a worktree without an environment file reports 485 of 544 tests as "warnings" — the Stack section's test command gives an unreadable summary here; plain `vendor/phpunit/phpunit/phpunit` gives the real numbers.
- `handoff/README.md` in the main checkout still describes the `HANDOFF.md` protocol and stops at 013 — rows 014 and 015 are missing and the protocol text is the old one.
- The session that runs a spec is told to use `backend-developer` and `frontend-developer`, but is not offered them — see question 6.

## Proposed tasks

- An invalid `date` on Checker 1 answers 500 — measured signed in: `date=abc` and `date=2026-13-45` give `InvalidFormatException`, `date[]=1` gives `TypeError`; any staff member can produce it by editing the address bar — normal.
- An array as `PAGE` (`PAGE[]=x`) most likely errors as well, when the view prints it — read from the code by the spec review, not run — low.
- The AI Checker and Astra Check buttons read `date` and `PAGE` from the address bar while the other buttons read the picker — they can disagree whenever the two differ — normal.
- Validate 1's redirect address keeps only four parameters — any other filter on the page is lost after it (question 8) — low.
- One shared definition of "the night's rows" for `NightRunSummary` and the Checker 1 filter — after this work the rule is written twice — low.
- `artisan test` without an environment file: find the file behind the 485 warnings — it hides the pass count in every fresh worktree — low.

## Suggested next steps

Answer questions 1 to 8 and send "go". The build then follows the plan's five tasks, T1 (stories,
fixture, characterisation tests) first.
