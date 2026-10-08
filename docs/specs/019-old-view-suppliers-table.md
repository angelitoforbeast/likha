# Spec 019: The Old view shows the suppliers table

**Project:** Likha, the business operations app (orders, ads reports, items and sourcing, J&T shipments).
**Stack:** Laravel 12, PHP 8.4 (Laravel Herd locally), Blade + Alpine.js, Tailwind from the CDN; tests are PHPUnit on in-memory sqlite; there is no JavaScript test runner.
**Shape:** change · **Weight:** bounded (one gate, two toolbar links, tests) · **Risk tier:** high (the same gate decides whether supplier names and prices are rendered)

> Committed with the work as `docs/specs/019-old-view-suppliers-table.md`. Names no people and no
> decision ids: "the owner" decided, "the reviewer" checks and merges.

---

## Why

Spec 017 built the item table with the SUPPLIERS group as its own view, `layout=suppliers`,
beside the unchanged Old view, so that it could be tried on real data first. It has now been
looked at in a real browser and works. The owner's request was always about the Old view itself,
the view he opens every day (`/item?...&layout=old`), and he has decided that it should now show
the new table. This spec makes the Old view show the suppliers table for the CEO view, keeps the
original table one click away for a while, and leaves every other viewer and view exactly as they
are.

## Stories

This spec changes cases of slice 017 in `qa/stories.md` on purpose (S-13.5, S-18.1, S-19.3) and
adds a short slice at the end of the file (heading `## Slice 019 – The Old view shows the suppliers
table`; continue the numbering after the last story in the file at the time you write it, and use
those ids in place of the letters below). Every case is `server` unless marked.

"The CEO view" is the effective CEO view (role CEO and `view_as` not `marketing`). "The suppliers
table" is the table of spec 017 (`_table_suppliers`). "The original table" is `_table_old`.

**A. The Old view is the suppliers table for the CEO view (P1)**
As the owner, I want my usual Old view address to show the table with suppliers, so that I do not
have to switch views.
Independent test: a CEO request for `/item?layout=old` renders the suppliers table.

| Case | Given / When / Then |
|---|---|
| A.1 | Given the CEO view, when `/item?layout=old` is requested, then the suppliers table renders (the SUPPLIERS header group, the suppliers-only styles and script members), exactly as `layout=suppliers` rendered it before this spec apart from the toolbar links |
| A.2 | Given the CEO view, when `/item?layout=suppliers` is requested, then the same view renders (old links keep working), and the page's script keeps `layout=old` in the address after the first load (one name for the view) |
| A.3 | Given the CEO view, when the default layout is requested (no `layout`, or any other value such as `OLD`, `x`, an array), then it renders byte for byte as at the base commit (the existing pinned hash) |
| A.4 | Given the CEO view of the Old view, when the toolbar renders, then the link "Suppliers view" is gone and one link "Original table" is there (title "Open the table as it was before the suppliers columns"), which leads to `layout=original` with the current query kept on a plain click, in the style of the links beside it |
| A.5 | owner check: his usual Old view link opens the table with suppliers; nothing he uses is missing |

**B. The original table stays one click away (P2)**
As the owner, I want the table as it was to stay reachable for a while, so that I can compare and
fall back.
Independent test: a CEO request for `/item?layout=original` renders the original table.

| Case | Given / When / Then |
|---|---|
| B.1 | Given the CEO view, when `/item?layout=original` is requested (exact string), then the original table renders with everything the Old view had before spec 017 (the stacked supplier lines in the Item cell included), the address keeps `layout=original` after the first load, and the toolbar has one link "Table with suppliers" (title "Back to the table with suppliers and prices side by side") leading to `layout=old` with the query kept |
| B.2 | Given the CEO view of `layout=original`, when its render is compared with the base commit's Old view for the CEO (the hash pinned by spec 017, token normalised), then the only differences are the toolbar link and the layout word the script keeps in the address; say exactly which strings differ and pin them |
| B.3 | Given the original table's file `_table_old.blade.php`, when hashed, then the existing byte pin still passes (the file is not edited) |

**C. Everyone else sees what they see today (P1)**
As the owner, I want supplier names and prices never to reach the Marketing view, whatever the
address.
Independent test: a Marketing request for `layout=old`, `layout=suppliers` and `layout=original`
each renders the original table as Marketing sees it today.

| Case | Given / When / Then |
|---|---|
| C.1 | Given a Marketing user, a Marketing-OIC user, and a CEO account with `view_as=marketing`, when each requests `layout=old`, `layout=suppliers` and `layout=original`, then all three responses are identical to each other and to that viewer's Old view at the base commit (the hashes pinned by spec 017), and contain none of the suppliers markers (the header words, the `spl-` names, the helper names, either toolbar link, a seeded supplier name) |
| C.2 | Given those viewers, when the page's script is read, then it does not call the quotes or PO-suppliers loaders (as today) |
| C.3 | Given the default layout for those viewers, when rendered, then it is byte for byte the base (pinned hashes) |
| C.4 | Given a request where `layout` is `original ` with a trailing space, `Original`, `ORIGINAL` or an array, when routed, then only what the framework's trimming makes equal to the exact string selects the original table, as for the other layout words; an array selects the default layout without an error |

**Tests:** one failing test per case first, named with its case ID, at the HTTP route for what a
viewer gets and at the rendered view for markup. Cases that pin unchanged behaviour (A.3, B.3,
C.1 for the comparison with the base, C.2, C.3) are characterisation tests: report their green
run and what would turn them red. The cases of slice 017 that this spec changes (S-13.5: which
layout word selects which view; S-18.1: the three addresses for non-CEO viewers; S-19.3: the Old
view for the CEO is now the suppliers table, and the "differs by one link only" comparison moves
to `layout=original`) are updated in `qa/stories.md` and in their tests, each change listed under
"Story changes" with the old and the new Then. Expected values come from the case or the pinned
hashes, never recomputed the way the code does it.

## Constraints

**Decisions already made** (settled with the owner; don't reopen them unless something is actually
broken):

| Topic | Decision |
|---|---|
| The Old view | For the CEO view, `layout=old` renders the suppliers table. `layout=suppliers` renders the same and the address is kept as `layout=old`. |
| The original table | Stays in the code, unedited, and is reachable by the CEO view at `layout=original` (exact string), with one toolbar link each way. It is still what every non-CEO viewer gets. |
| Everyone else | A viewer who is not the CEO view gets the original table for `layout=old`, `layout=suppliers` and `layout=original` alike, byte for byte what the Old view gives them today; none of the suppliers-only blocks, names or links is rendered for them. The default layout does not change for anyone. |
| The gate | Still one server-side boolean computed from the exact layout string and the effective CEO view; every suppliers-only block keeps testing that one boolean. Say in the plan exactly how the three layout words map to the view variables for the CEO view and for other viewers, as a small table. |
| Links | Built like the two links of spec 017 (the current query kept at click time, no new helper name rendered outside the CEO view). The "Suppliers view" and "Old view" links of spec 017 are replaced by the two of this spec. |
| Nothing else | No change to the suppliers table, its styles or script members, the quotes endpoint, the default layout, routes, roles or middleware. |

**Threat model.** Untrusted: every request parameter including `layout` and `view_as`; supplier
names, links and notes (already handled by spec 017's templates, which are not changed). Trusted:
files in this repository, config. Risk tier high: the gate decides whether supplier names and
buying prices are rendered; a mistake shows them to staff who must not see them. A major needs a
one-line realistic scenario. Fix loops stop after two; a remaining security major goes to the
reviewer.

**Rules**
- Don't start other Claude Code sessions; use subagents inside this session, in the foreground.
- Talk only to the reviewer, through the result file.
- No `Co-Authored-By`, `Claude-Session` or "Generated with Claude Code" lines in commit messages.
- Code comments explain the reason itself, in Taglish like the files around them; no names, no
  decision labels, no spec citations.
- No commands that start with an environment variable. Never open, read or create an environment
  file. Downloads: only the one composer install.
- Change only what this spec needs; suggestions go into the result under "Proposed tasks".
- Existing tests that describe the original table by rendering the view directly stay valid for
  `layout=original`; say in the plan which existing tests you touch and why. The byte pin of
  `_table_old.blade.php` and the pinned hashes of the default layout and of the Marketing renders
  are never edited.

## Budget

- Attempts: at most 2 tries at the same step; then stop and report what you tried and what you need.
- Size: small job (one controller method's three lines, a few lines of one Blade file, tests,
  `qa/stories.md`). If it is turning out bigger, stop and report before going on.

## Done when

- [ ] Cases A.1 to C.4 pass under their final ids, each with a test named after it, except the
      owner check, which is listed under `## Owner checks` in `qa/stories.md`.
- [ ] The changed cases of slice 017 are updated in `qa/stories.md` and their tests, each listed
      under "Story changes" with the old and the new Then.
- [ ] `git diff 5370bdc --stat` lists only: `app/Http/Controllers/ItemController.php`,
      `resources/views/item/index.blade.php`, files under `tests/`, `qa/stories.md`, `TODO.md` if
      findings were accepted, and this spec, its result and its plan. `_table_old.blade.php`,
      `_table_suppliers.blade.php`, the suppliers style and script partials, `routes/`,
      `composer.json`, `composer.lock` and every migration show no diff.
- [ ] The pinned hashes of the default layout (all viewers) and of the Old view for the three
      non-CEO viewers are unchanged in the test file and still pass.
- [ ] `php.bat -l` passes on every changed PHP file.
- [ ] The full suite (plain PHPUnit) before and after: no new failures. On the base in a fresh
      worktree expect Tests 624 with 1 error and 1 failure (`ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active`, which needs an untracked file, and the old `ExampleTest`), 3 skipped. Both summaries in the result.
- [ ] The gate change was reviewed by a separate reviewing agent at adversarial depth (try to get
      the suppliers table, its script members, styles or either link rendered for a non-CEO viewer
      with any layout word or odd input); who reviewed, findings, what was fixed, in the result.
- [ ] The result lists the addresses the reviewer should open after release and what each must
      show, for the CEO view and for the Marketing view.
- [ ] The result (`docs/specs/019-old-view-suppliers-table.result.md`) is filled in.

## Out of scope

Removing the original table or its tests; any change to the suppliers table, its styles or its
card; the default layout; the Marketing view; the quotes endpoint; deploying; pushing.

## Report back

Fill in `docs/specs/019-old-view-suppliers-table.result.md` from its template and commit it with
the work, including every ruling you made (`Ruling: <decision> — <why> — <cost if wrong>`), then
end the run with the one line "result updated: done". No PR and no push: the reviewer reviews the
branch. Under Merge danger say whether this is a one-way or two-way door, the blast radius and how
to revert.
