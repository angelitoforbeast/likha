# Result: spec 209 no hang

**Status:** done
**Date:** 2026-10-31
**Branch / PR:** `feat/209-no-hang` (worktree `.claude/worktrees/perf-209`), not pushed, no PR
**Preview or run link:** n/a (local only: `node tests/perf/run.mjs` on the seeded set; raw output in `docs/specs/209-evidence/`)

## Summary

The hang is in the page script of /chatbot/pages and is now gone on a set bigger than Incepxion (160 active pages × 65 value columns, 11,360 cells, 24 Ad-based pages, 4,800+ chats).

- **Cause, measured:** every `render()` rebuilt the whole table, then `markClamped()` wrote and read each of ~5,400 text cells in turn. Each read after a write forced a layout of the whole table. One render cost 66–114 s of a single main-thread task (first load 66 s; a cell save 114 s, because it renders three times; a Save in the Edit window 81 s; Undo 93 s; a paste 85 s; a filter 9–85 s). That is the "Page Unresponsive".
- **Fix** (one file, `resources/views/chatbot/index.blade.php`):
  - Rows are cached per page with a signature of everything the row shows. A render rebuilds only the rows whose signature changed, and builds them lazily, 6 per frame.
  - A reload keeps the row objects (and DOM) of pages whose data did not change. Chats today and Last chat are painted in place.
  - Filters hide and show rows instead of rebuilding them.
  - The fade is measured in one read pass, then one write pass, and only for rows not yet measured at the current table width.
  - Value-cell content gets `contain: paint`, so shifting rows does not repaint every piece of every cell.
- **After:** the longest task for any measured action is 157 ms (first load 138 ms). Checks after load: no task of 50 ms or more.
- **Other screens:** every other Chatbot screen and /chatblast/page-settings was already under target (longest task under 50 ms; page settings load 87–108 ms). Nothing there needed a change.

## Case table

| Case | Test or measurement | Result |
|---|---|---|
| S-209.1 | Timing run "Save 58 fields in the Edit window" (new chats on 80 pages just before), and "grid = server: after Save 58" | pass: 88 ms (before 80,894 ms). Bar "Saved 58 fields on 1 page." Values equal /chatbot/data. Rows drawn again: Perf Page 010, plus Perf Page 003, whose cell was edited just before so its Check is due again. |
| S-209.1/.5 | `ChatbotNoHangTest::test_S_209_1_and_5_a_reload_keeps_unchanged_rows_and_never_keeps_a_row_whose_values_changed`, `…the_row_signature_changes_with_everything_the_row_shows` | pass (`OK (3 tests, 36 assertions)`). Red first: `row-cache is in the page` on the base view; after review, red on the previous commit's view with `TypeError: RowCache.withoutCheck is not a function` and "new chat counts alone do not rebuild the row". |
| S-209.2 | Timing run "first load (after the data arrives)" and "each Check that finishes after load" | pass: 138 ms (before 65,999 ms). Checks: no task ≥ 50 ms across all 160 checks. |
| S-209.3 | Timing run | pass, all under 200 ms:<br>• single cell edit and save 106 ms (before 114,154)<br>• paste 10×10: 91 ms (before 85,379)<br>• its Save 125 ms (before 85,775)<br>• search 90 ms (before 8,968)<br>• clear 157 ms (before 85,497)<br>• Flow filter 74 ms (before 56,113)<br>• Check all 136 ms (before 81,408)<br>• Connections < 50 ms (before < 50)<br>• Check again < 50 ms (before < 50)<br>• failed save 112 ms, keeps the old value and shows "!" (grid = server) |
| S-209.4 | `ChatbotNoHangTest::test_S_209_4_the_fade_measures_every_cell_before_it_writes_any` | pass. Red first: `clamp is in the page`. |
| S-209.4 | Timing run, three "fade check" rows, checked against the text itself in Chrome | pass: 4,831 of 5,462 text cells faded, 0 wrong. The open row shows `block, mask none, clamp none`. After closing, 0 wrong. |
| S-209.5 | Timing run "Undo the 58 fields" and "grid = server: after Undo (S-209.5)" | pass: 153 ms (before 92,665). Old values back. Only Perf Page 010 drawn again. |
| S-209.6 | Timing run, per-screen table below | pass: every screen under target before and after. No change needed. |
| S-209.7 | `node tests/perf/run.mjs` | pass: one command, prints the table (screen, action, longest task, longest frame, long-task total, elapsed, target, pass) |

## Per-screen numbers

Longest main-thread task. "<50" means Chrome reported no long task at all.

- **Before:** `docs/specs/209-evidence/before.txt`, on base `ca2d067f` plus this branch's timing kit.
- **After:** `docs/specs/209-evidence/after.txt`, on `eadc31aa`.
- Both runs used the same seed.

| Screen | Action | Before (ms) | After (ms) |
|---|---|---|---|
| /chatbot/pages | first load (after the data arrives) | 65,999 | 138 |
| /chatbot/pages | each Check after load | <50 | <50 |
| /chatbot/pages | open a row / close it | 157 / 165 | 69 / 97 |
| /chatbot/pages | single cell edit and save | 114,154 | 106 |
| /chatbot/pages | open Edit | 62 | <50 |
| /chatbot/pages | Save 58 fields in Edit | 80,894 | 88 |
| /chatbot/pages | Undo the 58 fields | 92,665 | 153 |
| /chatbot/pages | select a cell | 183 | 151 |
| /chatbot/pages | paste 10×10 / its Save | 85,379 / 85,775 | 91 / 125 |
| /chatbot/pages | search / clear search | 8,968 / 85,497 | 90 / 157 |
| /chatbot/pages | untick a Flow value / Check all | 56,113 / 81,408 | 74 / 136 |
| /chatbot/pages | open Connections | <50 | <50 |
| /chatbot/pages | Check again | <50 | <50 |
| /chatbot/pages | show / hide Ad groups | 94,222 / 76,183 | 111 / 99 |
| /chatbot/pages | failed cell save; three searches during a reveal | (added later) | 112; 83 |
| /chatbot (Flow list) | load | <50 | <50 |
| Flow editor (66 steps) | load / Arrange | <50 / <50 | <50 / <50 |
| /chatbot/sequences | load / open one (10 steps) / add a step / Save | <50 each | <50 each |
| /chatbot/chats | load | <50 | <50 |
| /chatbot/inbox | load / open a conversation | <50 / <50 | <50 / <50 |
| /chatbot/replies | load / filter Sent | <50 / (see note) | <50 / <50 |
| /chatbot/results | load | <50 | <50 |
| /chatbot/health | load | <50 | <50 |
| /chatbot/speed | load | <50 | <50 |
| /chatbot/live | load and 15 s of updates | <50 | <50 |
| /chatblast/page-settings | load | 87 | 108 |
| /chatblast/page-settings | search / clear | <50 / <50 | <50 / <50 |
| /chatblast/page-settings | cell editor open and Escape | (see note) | <50 |

Note on the before run: three actions on screens this branch does not change had script mistakes, fixed afterwards:

- Replies filter: it fired `change` instead of submitting the form.
- Page settings cell: it used a pick column with no text input.
- The editor's "Save draft" and the sequence "add a step and Save": the done condition matched at once, before any work.

Those screens' code is untouched, so the after numbers are also their before numbers.

## Story changes

- Added the S-209 section to `qa/stories.md` (it was not there yet).
- Two cases added to the measurements, beyond the spec's list:
  - A failed cell save keeps its old value and shows "!".
  - Three searches during a reveal leave every row once, with the right values.
- The grid is now compared with `/chatbot/data` after each save. That comparison is the check that decision 4 never shows stale values.

## Done-when checklist

- [x] P1 cases pass with before/after numbers from the committed script on the seeded set (tables above; `docs/specs/209-evidence/`).
- [x] tests/Feature has no new failures vs the base.
  - Before: `Tests: 3817, Assertions: 72259, Failures: 2, Skipped: 4`.
  - After: `Tests: 3820, Assertions: 72295, Failures: 2, Skipped: 4`.
  - Both runs fail the same two tests: `ExampleTest::test_the_application_returns_a_successful_response` and `ItemTableFitScriptTest::test_every_catalog_column_id_has_a_minimum_width_and_a_place_in_the_drop_order`.
- [x] Per-screen table (above).
- [x] Deploy notes (below).
- [x] Skeptic review of decision 4: first round "request changes" (3 majors), all fixed; second round "approve", and its minors were fixed too (Rulings).
- [x] Browser check of every button on the grid: 44 checks in headless Chrome, all pass ("buttons" rows in `after.txt`; screenshots in `docs/specs/209-evidence/`).

## How to run

1. Create `.env.perf209` at the project root. It holds local test values only:
   - `APP_ENV=perf209`, a fresh `APP_KEY`, `APP_URL=http://127.0.0.1:8209`
   - `DB_CONNECTION=sqlite`, `DB_DATABASE=<project>/storage/perf209/db.sqlite`
   - `CACHE_STORE=database`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=null`, `MAIL_MAILER=array`
   - `OPENAI_API_KEY=`, `FORBEAST_SITE_KEY=`, `GATEWAY_URL=`, `INCEPXIONBOT_URL=`, `CHATBOT_REPLIES_ENABLED=false`
   - `HTTPS_PROXY=http://127.0.0.1:9`, `HTTP_PROXY=http://127.0.0.1:9`, `NO_PROXY=127.0.0.1,localhost`
2. Build and seed the database: `php tests/perf/migrate.php && php tests/perf/seed.php` (about 1 minute).
3. Run `node tests/perf/run.mjs`. It starts `php artisan serve --env=perf209 --no-reload` and headless Chrome, and stops them at the end. Nothing outside 127.0.0.1 resolves.

Options:
- `--only=pages,buttons,…` runs only those screens.
- `--json=file` also writes the results as JSON.
- `PERF_TIMEOUT=ms` limits each action (the base code needs minutes).
- `PERF_PROFILE=<action>` or `PERF_TRACE=<action>` prints a CPU profile or a renderer breakdown for one action.

The script sets the PHP path itself: `--php=` or `PHP`, else the Herd PHP 8.4.

## Rulings

- **Ruling:** the layout's Tailwind CDN script is answered, in the timing run only, with a stylesheet built locally from `node_modules/tailwindcss` 4.1.
  - Why: no downloads or outside connections were allowed.
  - Cost if wrong: the CDN runtime's own work (it scans added nodes) is not in the numbers. Live pages may be somewhat slower than measured. The fix also cuts that work, because it adds about 70 cells per changed row instead of 11,000 per render.
- **Ruling:** rows show over several frames, 6 per frame, top first, with `aria-busy` on the table meanwhile.
  - Why: showing one row costs about 10 ms of style and layout. Showing 160 at once is one task over half a second.
  - Cost if wrong: on first load and when a filter is cleared, the rows fill in over about 1–2 s instead of after one long freeze.
- **Ruling:** rows hidden by a filter stay in the table, hidden. A row that has never been shown is built only when it is shown.
  - Why: a filter change shows and hides rows instead of rebuilding the table.
  - Cost if wrong: none seen.
- **Ruling:** a row waiting for its rebuilt version stays visible but `inert` until the new one shows. Handlers resolve the current row object by page.
  - Why: buttons on an old row must not write to a stale object.
  - Cost if wrong: for a few frames, a click on that row does nothing.
- **Ruling:** `#cb-table td.cb-cell > div { contain: paint; overflow-clip-margin: 4px; }`.
  - Why: when rows shift, it halves the browser's paint-property work (PrePaint 67–90 ms → 23–34 ms in a trace).
  - Cost if wrong: anything that overflows a value cell's content box by more than 4 px would be clipped. The pencil's focus ring was checked and shows whole (`open-row-and-focused-pencil.png`). The Check popover is not inside a value cell.
- **Ruling:** Chats today and Last chat are not part of a row's signature. They are written into the kept row object and painted in place.
  - Why: with chats coming in, they change on almost every reload, and would otherwise rebuild almost every row.
  - Cost if wrong: none seen; the grid matched the server after "new chats on half the pages".
- **Ruling:** the start prompt said to build without waiting for a go on the plan. CLAUDE.local.md says to wait for the reviewer's go.
  - Why: the start prompt comes from the reviewer, so it counts as the go.
  - Cost if wrong: the plan was not reviewed before code.

## Deferred minors

- Ad groups shown/hidden still costs 99–111 ms. It is the most expensive grid action left, because inserting sub-rows shifts every row below. It is under target.
- The timing numbers vary by about ±50 ms between runs. The worst seen across about 15 runs of the final code was 218 ms, once, for "show Ad groups". That run was before `contain: paint`; since then every run stayed under 200 ms.
- A timing-dependent test, `ChatbotAiProviderPoolTest::test_S_150_C11…`, failed once in a Chatblast folder run ("3.999766 matches expected 5"). It passed in 3 reruns and in the full suite. It is unrelated to this change (server-side AI deadline).

## Merge danger

- Only `resources/views/chatbot/index.blade.php` changes in the app. Specs 207 and 208, both waiting for release, also edit this file.
- This branch is cut on 208. Release 207, 208 and 209 in order, or merge 209 after 208.
- New files: `tests/perf/*`, `tests/Feature/Chatblast/ChatbotNoHangTest.php`, the evidence folder, and the S-209 stories section.

## Deploy notes

- Front end only. One Blade view; its script and style are inline.
- No built asset to rebuild: `npm run build` is not needed, and `public/js` is unchanged.
- No migration, no config, no queue change.
- Clear the compiled views as usual on deploy: `php artisan view:clear`.
- Staff need a page reload to get the new script.

## Tests

- **New:** `tests/Feature/Chatblast/ChatbotNoHangTest.php` (3 tests, 36 assertions), which runs the page's `row-cache` and `clamp` blocks in Node.
- **Chatblast folder:** `Tests: 1592, Assertions: 52102, … Skipped: 1`, OK, on `211e8838`. On `4cb6bcfc`, one known-flaky timing test failed once (see Deferred minors).
- **Full tests/Feature on `eadc31aa`:** `Tests: 3820, Assertions: 72295, Failures: 2, PHPUnit Deprecations: 92, Skipped: 4`. The 2 failures are the same two as the base.
- **Browser:** `node tests/perf/run.mjs` on `eadc31aa`, 96 rows, all "yes" (`docs/specs/209-evidence/after.txt`).

## Open questions

- None blocking.
- The numbers come from headless Chrome on this machine, with a locally built stylesheet in place of the Tailwind CDN. Whether staff see the same on their PCs (with the CDN and slower CPUs) is worth one look on Incepxion after release.

## Process suggestions

- Keep `node tests/perf/run.mjs` in the release checklist for the chatbot area. It also compares the grid with the server after saves, so it catches stale redraws, not only slowness.
- The CDN Tailwind build in `layouts.chatblast` rescans the page on every DOM change. A built stylesheet (Vite is already set up) would remove that cost for all staff screens. See Proposed tasks.

## Proposed tasks

- Replace `<script src="https://cdn.tailwindcss.com">` in `resources/views/layouts/chatblast.blade.php` with a built stylesheet. This is a front-end build change, larger than this spec. The CDN runtime watches DOM mutations and generates CSS on the main thread on every page of the chatbot area.

209: done — /chatbot/pages longest task from 66–114 s down to under 160 ms on 160 pages × 65 columns (rows cached and redrawn only when changed, fade measured in one pass); other Chatbot screens already under target; tests/Feature no new failures
