# Plan 014: Plain comments and notes

Spec: `docs/specs/014-plain-comments.md`. Branch `chore/014-plain-comments`, base `7ecc05b`.
Risk tier: low (comments and documents only). No code is written before the reviewer's "go".

## 1. What the search found (on `7ecc05b`)

The done-when search returns **185 lines in 48 files**. The spec expected about 90 lines in the
files it lists. The difference:

| Group | Files | Lines |
|---|---|---|
| `TODO.md` | 1 | 63 |
| `docs/specs/001`, `003`–`007` | 6 | 43 |
| `docs/plans/001`, `007` (listed) | 2 | 7 |
| `docs/plans/003`, `004`, `005`, `006` (**not listed**) | 4 | 12 |
| `app/`, `routes/`, `resources/views/` (listed) | 18 | 26 |
| `app/Services/Imports/MacroImportStarter.php`, `app/Services/NightRunSummary.php` (**not listed**) | 2 | 2 |
| `tests/` (listed) | 7 | 11 |
| `tests/Feature/Auth/AuthTestCase.php`, `EndOpenSessionsTest.php`, `OwnerPasswordChangeTest.php`; `tests/Feature/NightRun/AstraEncoderAdditionsTest.php`, `NightAstraStartTest.php`, `NightImportCommandTest.php`, `NightRunSummaryTest.php`; `tests/Feature/OwnerPrivate/ClaudeActionColumnSettingsTest.php`, `ClaudeActionCommandTest.php` (**not listed**) | 9 | 12 |

Every hit in PHP and Blade is a comment or docblock line, with one exception:
`tests/Feature/OwnerPrivate/ClaudeActionCommandTest.php` lines 133 and 134 are two `insert()` calls
whose `source` value is the string `ceo:` followed by the owner's first name. That is a fixture
value on an executable line. No assertion compares against that literal (it appears nowhere else).

No test method name and no test description string matches the search, so no test is renamed.

These three points (size, the 15 unlisted files, the fixture value) are questions 1–3 in the
result file. The defaults below are what gets built on a plain "go".

## 2. Rewording rules

Design details the spec leaves open. Each is applied the same way everywhere.

**Code comments and docblocks (PHP, Blade)**

1. A pointer to task paperwork (the paperwork word plus a number, or a follow-up number such as
   `012-1`, with or without a decision id such as `(A1)`, `(D3)`, `(B2)`) is removed. The sentence
   that carried it keeps every fact. Example, `LoginController.php:23`: the comment keeps "CEO lang
   ang may remembered login na 30 araw" and loses the bracket.
2. No new citation of a spec is added to a code comment, also where a committed document exists:
   the project's rule that a code comment never cites a spec wins over the spec's "point to the
   committed document" for code. Citations already there that the search does not match
   (`spec §6.6`, `spec 007 §9`) stay as they are: changing them is not needed for this task.
3. Where the removed pointer was the only content of the comment (a section label such as
   "Night run (… 007) — CEO lang"), the label stays without the bracket; where the reason was
   only in the paperwork, the reason is written out from the committed document for that number
   (`docs/specs/NNN-*.md`) or from the code right below it.
4. A person's name becomes "ang may-ari" / "ang reviewer" (English files: "the owner" /
   "the reviewer"). Example, `HoldService.php:54`: "Inaprubahan ng reviewer."
5. Language stays the file's own (Taglish in `app/`, `routes/`, `resources/`, `tests/`).

**Markdown (`TODO.md`, `docs/specs/00N`, `docs/plans/00N`)**

6. `TODO.md` section headings: `## 001: sourcing worklist (2026-10-01)` as the spec gives it.
   Sub-headings for follow-up rounds keep their number and date:
   `### 012-1: second cookie, password change, explicit registration (2026-10-05)`.
7. Inside the text, the paperwork word becomes "the task", "this task" or "task 008" as the sentence
   needs; a follow-up round becomes "follow-up 007-1" (number kept, so cross-references inside
   `TODO.md` and the documents still resolve).
8. A header line that points at a brief file no longer in the repository
   (`docs/specs/00N` line 3) keeps its other facts (branch, base, tier, approval date) and says
   "Source: the task brief for this work (not kept in the repository)."
9. A pointer to a result file no longer in the repository becomes "the task's result notes" with
   no path; for a number that has a committed document, the pointer goes to `docs/specs/NNN-*.md`
   or `docs/plans/NNN-*.md` only when that document holds the same content, otherwise the pointer
   is dropped and the fact kept.
10. Names: "the owner" / "the reviewer". Section titles keep their numbers
    (`## 15a. The reviewer's answers (follow-up 007-1, 2026-10-04)`).
11. Table headers, code spans, SQL, numbers and section numbers in the older documents do not
    change, except a code span that is itself a path to a removed paperwork file (rule 8 or 9).

## 3. Tasks

All tasks are low tier and touch no file of another task. They run one after another in this
worktree (no extra worktrees: one small review unit each, no gain from running at once).

| # | Task | Side | Tier | Files | Check |
|---|---|---|---|---|---|
| T1 | Reword `TODO.md` | docs | low | `TODO.md` | search on the file returns nothing; `git diff --stat` shows the one file; headings keep number, title, date |
| T2 | Reword the older documents | docs | low | `docs/specs/001`, `003`–`007`; `docs/plans/001`, `003`–`007` | search on `docs/` returns nothing; section numbers unchanged (`git diff` of `^#` lines shows only reworded titles) |
| T3 | Reword comments in application code | backend | low, parallel-safe | the 20 files under `app/`, `routes/`, `resources/views/` | search returns nothing there; `php.bat -l` on each changed PHP file; every changed line is a comment line (section 4) |
| T4 | Reword comments in tests | backend | low, parallel-safe | the 16 files under `tests/` | same checks as T3; fixture value per the answer to question 3 |
| T5 | Verify and fill the result | both | low | `docs/specs/014-plain-comments.result.md` | every done-when item with its command and output |

One commit per task: `docs:` for T1, T2, T5; `chore:` for T3, T4.

No stories and no cases in this spec, so no case IDs. No new test: nothing executable changes, and
the existing suite is the check. No test-first step applies (comments and documents are outside it).

Who edits: `backend-developer` (sonnet) for T3 and T4, run in the foreground, with section 2 as
its brief; the main session does T1, T2 and T5 (Markdown only) and checks every task itself
(lint, search, comment-only check). No reviewer subagent, per the low tier.

## 4. How "comment lines only" is checked (done-when 3)

For every PHP and Blade file in `git diff 7ecc05b --name-only`:

1. `git diff 7ecc05b -U0 -- <file>` and list every added and removed line.
2. Each such line must start (after whitespace) with `//`, `*`, `/**`, `/*`, `#`, `{{--`, or sit
   inside a `{{-- … --}}` or `<!-- … -->` block; a line with code and a trailing `//` comment must
   be identical before and after once the trailing comment is cut off.
3. Any line that fails both tests is listed in the result by file and line. The expected list is
   empty, or exactly the two fixture lines if question 3 is answered that way.
4. As a second, independent check for PHP: `php.bat -r` with `token_get_all` on the old and new
   version of each file, comments and docblock tokens removed, token streams compared; they must be
   equal. (`php -r` on repository files only; no download.)

## 5. Test runs (done-when 5)

Not possible yet: this worktree has no `vendor/` folder, so
`php.bat artisan test` stops at `vendor/autoload.php` before any test runs. See question 4. Once
that is settled: baseline on a clean checkout of `7ecc05b`, then the finished branch, both
summaries in the result.

## 6. Not done

No change to any executable line (except what question 3 may allow), to the address data files, to
seeders, migrations, config, lock files, `storage/` or `public/`. No push, no PR, no deploy.
Things noticed in the code on the way go to the result under "Proposed tasks".
