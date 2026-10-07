# Spec 014: Plain comments and notes

**Project:** Likha, the business operations app (orders, ads reports, J&T shipments, night checker run).
**Stack:** Laravel 12, PHP 8.4 (Laravel Herd locally), Blade + Alpine.js, Vite; tests are PHPUnit on in-memory sqlite.
**Shape:** change · **Weight:** bounded · **Risk tier:** low

> Committed with the work as `docs/specs/014-plain-comments.md`. Names no people and no decision ids:
> "the owner" decided, "the reviewer" checks and merges.

---

## Why

Comments, notes and the older design documents in this repository name people and point at task
paperwork that was kept in the repository until the commit this branch starts from, and is no
longer here. A developer reading the code cannot follow those pointers, and a comment should carry
its own reason. This spec rewords those lines so each says the reason itself. Nothing a user can
see or do changes.

## Stories

None: no behaviour changes. The checks are the existing test suite and the searches under
"Done when".

## Constraints

**Decisions already made** (settled with the owner; don't reopen them unless something is actually
broken):

| Topic | Decision |
|---|---|
| What may change | Only comments (PHP, Blade, JavaScript), docblocks, Markdown text, and test method names or test description strings. No executable line changes. |
| A comment that only pointed at paperwork | Rewrite it to state why the code is the way it is, in the file's own language (Taglish where the file uses Taglish). If the next lines already say why, drop only the pointer. Keep every fact the comment carried. |
| A person's name | The business owner becomes "the owner" ("ang may-ari"); whoever approved, answered or reviewed becomes "the reviewer" ("ang reviewer"). |
| A pointer to a file that is no longer in the repository | Point to the committed document that holds the same content when there is one (`docs/specs/NNN-*.md`, `docs/plans/NNN-*.md`); otherwise drop the pointer and keep the fact. |
| `TODO.md` headings | Keep the three-digit number, the title and the date, for example `## 001: sourcing worklist (2026-10-01)`. |
| Older documents in `docs/specs/` and `docs/plans/` | Reworded in place the same way. Their content, numbers and section numbers stay. |
| Test names | A test method renamed for this reason keeps its meaning; the suite's counts stay the same. |
| Address data | `resources/views/macro_output/jnt_address.txt` and the backup address file beside it are production address data. A search word appears there inside a town's name. Never edit either file. |
| Other data | No edits to seeders, migrations, config, lock files, fixture values, or anything under `storage/` or `public/`. |

**Threat model.** No input handling changes. Untrusted: none touched. Trusted: the files in this
repository. Risk tier low: comments and documents only. A finding about the code itself is not
fixed here; it goes into the result under "Proposed tasks".

**Files the search found** (start here; the search in the start prompt is the final word):
`TODO.md`; `docs/specs/001-sourcing-worklist.md`, `003-stock-doi-category.md`,
`004-lifecycle-restock.md`, `005-item-layout.md`, `006-item-simple-view.md`, `007-night-run.md`;
`docs/plans/001-sourcing-worklist.md`, `docs/plans/007-night-run.md`;
`app/Console/Commands/NightAstraTick.php`, `NightImport.php`;
`app/Http/Controllers/Auth/LoginController.php`, `Encoder/Checker1SettingsController.php`,
`ItemController.php`, `MacroCheckerController.php`, `NightRunController.php`,
`OwnerUsersController.php`; `app/Listeners/RefuseRememberedLoginUnlessCeo.php`;
`app/Providers/AppServiceProvider.php`; `app/Services/HoldService.php`, `ItemStockService.php`;
`routes/web.php`, `routes/console.php`; `resources/views/encoder/_night_run.blade.php`,
`encoder/ai_checker_logs.blade.php`, `encoder/checker_1/settings.blade.php`,
`item/index.blade.php`; `tests/Feature/Item/LifecycleStockTest.php`, `StockEndpointTest.php`,
`ItemPageTest.php`, `ItemLayoutTest.php`; `tests/Feature/Auth/LoginCharacterizationTest.php`,
`CeoRememberedLoginTest.php`; `tests/Unit/SourcingClassifierTest.php`.

**Rules**
- Don't start other Claude Code sessions; use subagents inside this session.
- Talk only to the reviewer, through the result file. Plan first, then work.
- No `Co-Authored-By`, `Claude-Session` or "Generated with Claude Code" lines in commit messages.
- Code comments explain the reason itself. Comments, commit messages and branch names never name
  people or decision ids; the spec number may appear in branch names and commits.
- No commands that start with an environment variable.
- Downloads: none.
- Change only what this spec needs: no refactors, renames of code, file moves or other fixes.

## Budget

- Attempts: at most 2 tries at the same step; then stop and report what you tried and what you need.
- Size: small to medium (about 40 files, about 90 lines). If it turns out much bigger, stop and report before going on.

## Done when

- [ ] The search given in the start prompt returns no line.
- [ ] `git diff 7ecc05b --stat` lists only files from the list above plus this spec, its result and its plan.
- [ ] Every hunk of `git diff 7ecc05b` in a PHP, Blade or JavaScript file changes only comment lines,
      docblock lines, or a test's name or description string; the result says how this was checked
      and lists any hunk that is not one of these (there should be none).
- [ ] `php.bat -l` passes on every changed PHP file (output in the result).
- [ ] The full test suite was run on 7ecc05b before any change and on the finished branch: same
      counts, no new failures (both summaries in the result).
- [ ] `git diff 7ecc05b --stat -- resources/views/macro_output/` is empty.
- [ ] The result (`docs/specs/014-plain-comments.result.md`) is filled in.

## Out of scope

Any code or behaviour change; acting on what a comment or TODO item describes; the address data
files; rewriting git history; pushing; deploying.

## Report back

Fill in `docs/specs/014-plain-comments.result.md` from its template and commit it with the work,
including every ruling you made (`Ruling: <decision> — <why> — <cost if wrong>`), then end the run
with the one line "result updated: done". No PR: the reviewer reviews the branch. In the result,
say under Merge danger that this is a two-way door (comments and documents only) and how to revert.
