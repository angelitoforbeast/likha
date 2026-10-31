# Spec 209: the chatbot pages never hang the browser

## Why
On 2026-10-31 staff on Incepxion (same code as Likha) got Chrome's "Page Unresponsive" on /chatbot/pages right after saving values in a page's Edit modal (the bar read "Saved 58 fields on 1 page."). Earlier the same day they reported that the page is slow to show, that Connections takes seconds to open, and that Fix chat block / Save and test lag 5–10 s. Server times were measured and are fast (Connections 14 ms, Check 30–60 ms; specs 207 and 208 fix the slow server parts); the hang is in the browser.
What reading resources/views/chatbot/index.blade.php shows (as of 206; confirm on this base):
- `render()` rebuilds the whole table (every row × every value column; Incepxion has columns up to Seq 13 / Seq Img 13) and is called after almost every action: each Save (`load()` → `render()`), each cell save mark (also again 2.5 s later), each filter click, each paste, each switch, each Ad groups toggle.
- `markClamped()` runs after every render over every `.cb-txt` cell and, per cell, writes (`scrollTop = 0`, `classList.toggle('cb-clamped')`) then reads (`scrollHeight`, `clientHeight`). Each read after a write forces a layout of the whole large table: layout thrashing, O(cells) full layouts — the likely freeze.
- `folded()` adds one requestAnimationFrame per value that reads `scrollHeight`.
- `paintCheck()` scans every `td[data-check]` for each finished Check; `load()` queues a Check for every page without a cached one.
The owner's ask: fix the hang, and check the whole chatbot area (the pages grid, its modals, and the other Chatbot screens in the same nav: Flow, Sequences, Page settings, Chats, Inbox, Replies, Results, Health, Speed, Live) for the same kind of problem, and fix all of them.

## Decisions (do not reopen)
| # | Decision | Why |
|---|---|---|
| 1 | Measure first: a seeded local set the size of Incepxion or bigger (at least 150 active pages, 60 value columns with realistic 1–5 line texts and image links, Ad-based pages with ad groups) and a headless Chrome timing script that reports the longest main-thread task for: first load, Save in the Edit modal, a cell edit, a paste of a 10×10 block, a filter change, opening Connections, Check again. Record the before numbers on this base. | Fix what is measured, and keep a script to prove it. |
| 2 | Target: no main-thread task over 200 ms for any of those actions on that set (first load: under 500 ms after the data arrives), measured with the same script after the change. | "Page Unresponsive" must not happen; the page must feel instant. |
| 3 | Layout reads are batched: measure all cells first, then write all classes (or replace the measurement with pure CSS, e.g. line-clamp with a fade that needs no JS measurement). No read-after-write in any loop. | Removes the thrashing. |
| 4 | After a save, a cell edit, a check or a switch, only the affected row(s) are redrawn, not the whole table; a full render only on load, filters, columns or a reload. A Save reloads data without rebuilding unchanged rows (keyed by page). Off-screen rows may be rendered lazily (e.g. content-visibility or rows built as they scroll into view) if the numbers need it. | Cost per action should not grow with the number of pages × columns. |
| 5 | Same visible behaviour: every column, button, warning (including 207 and 208's), filter, paste, undo, Edit, Preview, Connections, Ad groups sub-rows and the 3-line fade work as now. The existing feature tests keep passing. | A speed fix, not a redesign. |
| 6 | The other Chatbot screens are audited with the same script (load plus their main action, with seeded data of the same scale). Any screen over the target gets the same kind of fix in this spec; the result lists each screen with before/after numbers. A fix needing a server or schema change larger than a query fix goes to "Proposed tasks" instead. | The owner asked for the whole chatbot area checked. |
| 7 | /chatblast/page-settings is included in the audit (staff use it beside /chatbot/pages). | Same staff, same complaint. |

## Stories / cases (S-209)
P1:
- .1 With the seeded set, saving 58 fields of one page in the Edit modal: the modal closes, the row shows the new values, the bar reads "Saved 58 fields on 1 page." with Undo, and the longest task is under 200 ms (before number recorded).
- .2 First load of /chatbot/pages: the longest task after the data arrives is under 500 ms; Checks that finish afterwards each cost under 50 ms.
- .3 A single cell edit, a 10×10 paste and its Save, a filter change, Connections open, Check again: each under 200 ms.
- .4 The 3-line fade still shows on long texts in closed rows and not on short ones; opening a row still shows the full text.
- .5 Undo after a modal Save restores the old values and redraws only that row.
- .6 Each other Chatbot screen and /chatblast/page-settings: load and main action measured; any over target fixed to under target.
P2: .7 The timing script runs with one command and prints a table (action, longest task, total) so it can be rerun before each release.

## Threat model and risk
Trusted: signed-in staff. No new inputs, routes or data. Risk tier: medium (the most used staff page; a broken render blocks bot setup). Run a skeptic reviewer on decision 4 (partial redraw must never show stale values after Save, Undo or a failed save) and a browser check of every button on the grid.

## Done when
P1 pass with evidence (before/after numbers from the committed script on the seeded set); tests/Feature no new failures vs the base; the result has the per-screen table; deploy notes (front-end only expected; any built asset to rebuild); the final line.

## Out of scope
Server speed already covered by 207 and 208, Botcake API calls, layout or design changes, new features, deploying.

## Budget
Large job. At most 2 attempts at the same failing step, then stop and write what you tried and what you need into the result.
