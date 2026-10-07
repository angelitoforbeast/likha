# Result: spec 014 Plain comments and notes

> Committed beside the spec: names no people and no decision ids.

**Status:** partial — plan written, waiting for the reviewer's "go" and the answers below. No file other than the spec, this result and the plan has changed.
**Date:** 2026-10-08
**Branch / PR:** `chore/014-plain-comments` (base `7ecc05b`); no PR, the reviewer reviews the branch
**Preview or run link:** n/a (no behaviour change)

## Summary

Plan stage only. The spec and this result were committed first (`4046656`), then the plan
(`docs/plans/014-plain-comments.md`). The done-when search was run on the base: it returns 185 lines
in 48 files, about twice the size the spec expects, and 15 of those files are not in the spec's
list. One hit is a fixture value on an executable line. The test suite cannot run in this worktree
yet because it has no `vendor/` folder. Four questions for the reviewer are under "Open questions".

## Case table

To be filled when the work is done. Checks run so far:

| Check | Command | Result |
|---|---|---|
| Search on the base | the done-when search from the start prompt, with `-c` | 185 lines in 48 files (per group in the plan, section 1) |
| Baseline test run | `"C:/Users/Forbeast/.config/herd/bin/php.bat" artisan test` | did not start: `require(.../vendor/autoload.php): Failed to open stream: No such file or directory` |

## Story changes

none

## Done-when checklist

- [ ] The search given in the start prompt returns no line. — not started (185 lines today)
- [ ] `git diff 7ecc05b --stat` lists only files from the list above plus this spec, its result and its plan. — cannot hold together with the first item; see question 2
- [ ] Every hunk of `git diff 7ecc05b` in a PHP, Blade or JavaScript file changes only comment lines, docblock lines, or a test's name or description string. — not started; method in the plan, section 4; see question 3
- [ ] `php.bat -l` passes on every changed PHP file. — not started
- [ ] The full test suite was run on 7ecc05b before any change and on the finished branch. — blocked; see question 4
- [ ] `git diff 7ecc05b --stat -- resources/views/macro_output/` is empty. — true today (nothing there is touched), to be re-run at the end
- [ ] The result is filled in. — plan stage only

## How to run

From the worktree root, in Git Bash:

- Search: the command in the start prompt.
- Lint: `"C:/Users/Forbeast/.config/herd/bin/php.bat" -l <file>`
- Tests: `"C:/Users/Forbeast/.config/herd/bin/php.bat" artisan test` (needs `vendor/`, see question 4)

## Rulings

Ruling: the baseline test run was not done in the main checkout — it sits on `7ecc05b` with `vendor/` installed, but a test run there writes cache files outside this worktree, which this session may not do — the baseline waits for the answer to question 4.
Ruling: in code comments, a removed paperwork pointer is not replaced by a pointer to `docs/specs/NNN`; in Markdown it is — the project's rule that code comments never cite a spec wins over the spec's "point to the committed document" — if wrong, about 30 comment lines get a path added in a second pass.
Ruling: spec citations already in code comments that the search does not match (for example `spec §9`) stay untouched — not needed for this task, minimal touches — if wrong, a follow-on task removes them (listed under "Proposed tasks").
Ruling: follow-up rounds keep their number in Markdown as "follow-up NNN-K" — cross-references inside `TODO.md` and the older documents must still resolve — if wrong, one search-and-replace.

## Deferred minors

none yet

## Merge danger

Two-way door: comments, docblocks and Markdown only (plus two test fixture strings if question 3 is
answered that way). Blast radius if wrong: a misleading comment or note; no user-visible effect.
Revert: `git revert` of the squash commit on `develop`, or delete the branch before merge.

## Conflicts with CLAUDE.local.md

- The spec says a pointer to a removed file should point to the committed document
  (`docs/specs/NNN-*.md`). CLAUDE.local.md says a code comment never cites a spec. CLAUDE.local.md
  wins for code comments; the spec's rule is applied in Markdown only (ruling above).
- CLAUDE.local.md asks for a failing test before production code. No executable line changes, so
  no test-first step applies; the existing suite is the check.
- CLAUDE.local.md's `/ship` pushes and opens a PR; the start prompt says no push and no PR. The
  start prompt is followed; this result stands in for the PR body.

## Tests

Not run: `artisan test` cannot start without `vendor/` in this worktree (output in the case table).

## Open questions

1. **Size.** The search returns 185 lines in 48 files; the budget says about 90 lines in about
   40 files and to stop if it is much bigger. The work is the same kind throughout (63 of the lines
   are `TODO.md`, 62 are the older documents). **Default on "go": do all 185.**
2. **Files not in the spec's list.** The search hits 15 files the list does not name (plan,
   section 1): four older plans, two services, nine test files. Done-when 1 (search empty) and
   done-when 2 (only listed files change) cannot both hold. **Default: the search is the final
   word, as the spec says; all 15 are reworded and done-when 2 is read as "the listed files plus
   these 15", each named in the result.**
3. **A fixture value.** `tests/Feature/OwnerPrivate/ClaudeActionCommandTest.php` lines 133–134
   insert rows whose `source` is `ceo:` plus the owner's first name. It is an executable line and a
   fixture value, both of which the spec says not to edit, and the search matches it. No assertion
   uses the literal. Options: (a) change both to `ceo:CEO User`, the value the sibling test
   `ClaudeActionSaveRouteTest` already uses, and list the two lines as the only non-comment hunk;
   (b) leave them and accept two remaining search lines. **Default: (a).**
4. **Running the tests.** This worktree has no `vendor/` (and no `node_modules/`; no build is
   needed, no asset changes). Options: (a) `composer install` in the worktree from the unchanged
   lock file, which fetches packages unless they are all in composer's local cache, against
   "Downloads: none"; (b) a directory junction from the worktree's `vendor` to the main checkout's,
   which I would first prove loads this worktree's classes and not the main checkout's, and drop if
   it does not; (c) the reviewer runs both suites. **Default: none; this one needs an
   answer, because (a) goes against "Downloads: none". I recommend (a).** With `vendor/` in place,
   the baseline is run in this worktree before the first edit: the tree then differs from `7ecc05b`
   only by the three Markdown files of this spec. Without an answer done-when 5 cannot be met.

## Process suggestions

- The spec's file list and budget were taken from a narrower search than the done-when search — evidence: 48 files and 185 lines against "about 40 files, about 90 lines"; 15 files missing from the list.
- A new worktree has no installed dependencies, and the spec allows no downloads — evidence: `artisan test` fails at `vendor/autoload.php`. A line in the start prompt on how the worktree gets `vendor/` would remove question 4.

## Proposed tasks

- Remove the remaining spec and decision-id citations from code comments (for example `spec 007 §6.3`, `A2`, `D3` in lines the search does not match) — the project's rule says code comments cite neither, and this spec's search does not cover them — low.

## Suggested next steps

Answer questions 1–4 (or say "go" for the defaults), then resume this session. Work order: T1–T4
from the plan, then verification and the final result.
