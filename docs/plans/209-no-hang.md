# Plan 209: the chatbot pages never hang the browser

## Approach

Measure first, fix what is measured, keep the measuring script.

1. **Timing kit** (`tests/perf/`, committed): `migrate.php` builds a throwaway sqlite database under
   `storage/perf209/` (every migration file on its own; the MySQL-only ones are skipped), `seed.php` fills it with
   160 active pages × every default value column (1–5 line texts, image links on the local server), 1 in 7 pages
   Ad-based with 3 ad groups, 20 published flows, 4,800 chats, and a local CEO test user. `run.mjs` starts
   `php artisan serve --env=perf209` and headless Chrome (no outside host resolves), signs in, runs every action and
   prints one table: action, longest main-thread task, longest animation frame, long-task total, elapsed. The
   layout's Tailwind CDN script is answered with a stylesheet built locally from `node_modules/tailwindcss` (no
   download); the CDN runtime's own cost is therefore not in the numbers (noted in the result).
2. **Before numbers** on this base for every action of decision 1, then the other Chatbot screens and
   /chatblast/page-settings (load plus main action).
3. **Grid fixes** (`resources/views/chatbot/index.blade.php`), driven by the numbers:
   - Layout reads batched: the 3-line fade measures every visible text cell first, then writes the classes; no
     read after a write. `folded()` (History, Changes) measures all its boxes in one frame, then adds the buttons.
   - Rows are kept, not rebuilt: each page's row nodes are cached with a signature of everything the row shows
     (its data, marks, pending paste values, error, open, picked, ad-group state, check). A render rebuilds only the
     rows whose signature changed and puts them in place; rows hidden by a filter stay in the table, hidden, so a
     filter change shows and hides rows instead of rebuilding them.
   - A reload after Save, Undo or Connections keeps the row objects of pages whose data did not change (so the
     closures of their buttons point at the current object) and the cached rows with them.
   - Any other hot spot the numbers show (Check paints, Edit window) gets the same treatment.
4. **Other screens**: any screen over target gets the same kind of fix; server or schema work larger than a query
   fix goes to "Proposed tasks".

## Tasks

| # | Task | Side | Risk | Cases | Parallel-safe |
|---|---|---|---|---|---|
| 1 | Timing kit and seeded set, before numbers | test tooling | low | S-209.7 (and the numbers for all) | yes |
| 2 | Batched layout reads (fade, folded) | frontend | medium | S-209.4, part of .2, .3 | no (same file as 3) |
| 3 | Row cache and partial redraws (save, cell edit, check, switch, paste, filters, reload) | frontend | medium | S-209.1, .2, .3, .5 | no |
| 4 | Audit and fixes of the other Chatbot screens and /chatblast/page-settings | frontend | medium | S-209.6 | after 3 |
| 5 | Skeptic review of decision 4, browser check of every grid button, result | — | — | all | — |

## Tests

- Browser parts: `node tests/perf/run.mjs` before and after; the numbers go into the result.
- Feature tests (PHP, run in node like the existing marker tests): the row signature and the reload merge are pure
  functions inside marked blocks of the view, tested in node: a changed value, mark, pending value, error, check or
  open state changes the signature; an unchanged one does not; the reload keeps unchanged row objects and replaces
  changed ones (stale values after Save, Undo or a failed save are the risk).
- tests/Feature: baseline before code is 3817 tests, 2 known failures (ExampleTest, ItemTableFitScriptTest); no new
  failures after.
