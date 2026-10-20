# Result 092: copy and paste blocks of botfield values on /chatbot/pages, saved with one button

Branch `feat/092-pages-copy-paste`, cut from `main` at `d7dfb2c6` as the brief asked. The kit's flow cuts from `develop`, but `develop` is behind `main` here, so I followed the brief. Nothing was pushed, and no server was touched.

## Commits

| Commit | What |
|---|---|
| `5fbaaaad` | docs: the brief, verbatim, with the scroll fix added as a decision row |
| `a152d9d1` | feat: `POST /chatbot/cells`, the bulk save (one request, one transaction, all or nothing, previous values for Undo), stories S-092 |
| `e1189e68` | feat: `public/js/chatbot-paste.js`, the TSV parser and writer and the paste plan |
| `9ed3e651` | feat: the Paste values modal, the pending paste with Save, Cancel and Undo, grid select, copy and paste, and the table filling the screen on `/chatbot/pages` |
| `de00073a` | fix: sequences, subscribers, replies and inbox fit the screen (same scroll bug) |
| `9f71c481` | fix: review findings: a non-text value is refused, a non-link typed while a paste is pending isn't added, more test cases |
| (this commit) | docs: result, stories' last run, TODO |

## What changed

| Area | Change |
|---|---|
| `POST /chatbot/cells` | New route, throttled 30 per minute, same access as a single cell (`NavAccess` `/chatblast`, 403 otherwise). Body `{changes: [{page_name, key, value}], blanks_empty}`, at most 5,000 changes. Every change is checked first: the page is on the list, the column exists, the value is text, MAX_VALUE letters, MAX_BYTES bytes, and an image column takes only an http(s) link. One bad change gives a 422 that names the cells, and nothing is saved. Then everything is written in one `DB::transaction`, through `PageSettings::set` (or deactivate for a clear), so history rows and the Ready cache work as for one cell. ACTIVE, FLOW and the system keys are skipped and counted. A blank keeps the value unless `blanks_empty`. When the same cell appears twice, the last one wins. The answer is `{saved, unchanged, skipped, pages, undo}`; `undo` holds the previous value of each changed field (`''` = it was empty). |
| `ChatbotController::cell` | Its length, bytes and link checks moved into `valueError()`, shared with the bulk save. Same messages, same behaviour. |
| `public/js/chatbot-paste.js` | A plain script, like `rts-column-filter.js`. `parseTsv` reads Sheets' quoting: quoted cells with tabs, line breaks and doubled quotes; CRLF; a trailing newline; and a cell such as `"Hi" po` that only starts with a quote is read as written. `toTsv` quotes the same way, for Copy. `plan()` maps the columns by header name when at least half of the first line names fields, otherwise by order from the chosen field. One row goes to every page, N rows to N pages, and any other count is refused. Each field gets a status: changed, unchanged, blank, or flagged (not a link). |
| Paste values modal | A "Paste values" button ("Paste…" on a phone) and a new checkbox in each Page cell, with select-all in the header. The ticked rows are the targets, and with none ticked the modal opens its page picker. One textarea; a "Starting at field" dropdown (hidden when a header line is found); "Paste blanks as empty"; "Show unchanged". Notes list ignored header names, "ACTIVE and FLOW are not changed by paste", values dropped past the last field, and links flagged. The preview is a vertical scrolling list grouped by page, one line per field, with "Now: …" faded below when the value differs. Long values fold to 3 lines and open on click. The button reads "Save N fields on M pages"; Cancel closes without changes. |
| Grid (secondary, built) | A click selects a cell (blue) and still opens the row as before. Shift+click extends the selection to a rectangle. Ctrl/Cmd+A in the grid selects every visible botfield cell. Ctrl/Cmd+C copies TSV. Ctrl/Cmd+V pastes from the top-left of the selection, right and down in the order shown; one value fills the whole selection. Pasted cells are pending: amber, with "Not saved yet. Was: …" on hover. A bar under the table reads "N cells changed on M pages · not saved yet · Save · Cancel". A cell edited while changes are pending joins them (FLOW keeps saving on its own). Leaving the page with pending changes asks first. **Not built: drag to select** (see TODO). |
| Undo | After any Save (modal or bar), the bar reads "Saved N fields on M pages." with Undo. Undo sends the previous values back through `/chatbot/cells` and lasts until the next Save. |
| Scroll fix | `/chatbot/pages`: `#cbot` is a `.cb-page` and `#cb-box` a `.cb-scroll` (the layout's own pattern). The fixed `max-height: calc(100vh - 15rem)` is gone, the table fills exactly the rest of the screen, and its horizontal bar sits at the bottom of the screen. The same bug was on sequences, sequence subscribers, replies (`calc(100vh - 19rem)`) and inbox (`calc(100vh - 15rem)`). Those are now `.cb-page` too: the table boxes size to their content up to the remaining height (`shrink`, with the same minimum height as before), and the inbox grid fills the rest. The flow editor canvas keeps its `calc` (see Notes). |

## Tests

Case table (the `phpunit --testdox` run on the final tree):

| Case | Test | Result |
|---|---|---|
| S-092.1 TSV parser: quotes, line breaks, tabs, trailing newline, CRLF, round trip | `ChatbotPasteScriptTest::test_S_092_1…` | ✔ pass |
| S-092.2 header line maps by name (unknown listed, ACTIVE/FLOW noted); otherwise by order from the chosen field, extras counted | `ChatbotPasteScriptTest::test_S_092_2…` | ✔ pass |
| S-092.3 one row → every page, N rows → N pages, other counts refused | `ChatbotPasteScriptTest::test_S_092_3…` | ✔ pass |
| S-092.4 non-links flagged, blanks keep unless ticked, equal values unchanged | `ChatbotPasteScriptTest::test_S_092_4…` | ✔ pass |
| S-092.5 one request, history rows, counts, previous values, last duplicate wins | `ChatbotPasteTest::test_S_092_5…` | ✔ pass |
| S-092.6 all or nothing: too many letters, too many bytes, not a link, unknown column, page not on the list, no page, not text, over 5,000 changes | `ChatbotPasteTest::test_S_092_6…` | ✔ pass |
| S-092.7 blanks keep by default and clear with `blanks_empty`; ACTIVE and FLOW skipped | `ChatbotPasteTest::test_S_092_7…` | ✔ pass |
| S-092.8 no /chatblast access → 403, signed out → redirect | `ChatbotPasteTest::test_S_092_8…` | ✔ pass |
| S-092.9 Undo restores every field (an empty one cleared again) | `ChatbotPasteTest::test_S_092_9…` | ✔ pass |
| S-092.10 Paste values modal | headless Chrome, items 3, 4 and 6 below | ✔ pass |
| S-092.11 grid select, copy, paste, pending, bar, join, leave warning | headless Chrome, item 5 | ✔ pass |
| S-092.12 page doesn't scroll, horizontal bar on screen, sticky kept | headless Chrome, items 1 and 2 | ✔ pass |

"A page not visible to the role" (from Done when) is the "page not on the list" case in S-092.6: this controller shows every listed page to every role that has access.

**Red runs (before the code):**
- `ChatbotPasteTest`: all 5 failed with "Expected response status code [200] but received 404" (no route yet).
- `ChatbotPasteScriptTest`: all 4 failed with `node:fs` "no such file" for `public/js/chatbot-paste.js` (module not created yet).
  - After the code, 2 still failed. One was a test typo (`'tab\there'` in single quotes), fixed in the test. The other was `isImageLink` using `URL`, which node's bare `vm` context lacks; it now uses a regex.
- The review's non-text case failed with 200 instead of 422. The duplicate-cell and 5,000-cap cases passed at once: they pin behaviour that was already there.
- In the browser, "a non-link typed while changes are pending" failed (`pending: 14`) before its fix.

**Suites:**
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: 701 tests, all pass (1 skip, as before). The baseline before the work was 692.
- Full suite (`vendor/bin/phpunit`): 2,056 tests. 1 failure and 1 error, the two known ones (`ExampleTest`, `NightRun\ImportStartTest`), same as before. No new failure.
- `php -l` on the changed PHP files; `node --check` on the page's inline script.

## Browser check

There were no browser tools in this session (the browser-checker agent had none either). So I drove the installed Chrome headless over its DevTools protocol, with a script outside the repo. It uses Node 24's built-in WebSocket; no package was installed. The app ran with `php artisan serve` on a temporary sqlite database outside the repo. Settings were passed as environment variables; no env file was created. The data: 14 pages, a few botfield values, and 40 sequences.

Result: **31/31 checks passed**, no console errors. Screenshots are in `docs/specs/092-pages-copy-paste/`.

| # | Check | Evidence |
|---|---|---|
| 1 | `/chatbot/pages` at 1366×768, 1920×1080 and 390×844: the page doesn't scroll (main and document overflow 0), the table box ends 16 px above the bottom of the window, and the table scrolls sideways inside it. The header row and the Page column stay put while the table scrolls. | `1366-pages.png`, `1920-pages.png`, `390-pages.png`, `1366-pages-scrolled.png` |
| 2 | Sequences, subscribers, replies and inbox at 1366×768 and 390×844: page overflow 0 on all. | `1366-sequences.png`, `390-sequences.png`, `1366-inbox.png`, `390-inbox.png` |
| 3 | Header-row paste on 3 pages: mapped by name, "Not a field, ignored: PAGE.", "ACTIVE and FLOW are not changed by paste.", 3 links flagged, "Save 9 fields on 3 pages". Save shows the values in the grid (the non-links are not saved) and offers Undo; Undo restores the old values. | `1366-paste-modal.png`, `1366-after-save.png`, `1366-after-undo.png` |
| 4 | 3 rows go one per page in grid order. 2 rows on 3 pages are refused, with the reason, and Save is disabled. Cancel changes nothing. | `1366-paste-three-rows.png`, `1366-paste-refused.png` |
| 5 | Grid paste of a 3×4 block: 12 amber cells, "12 cells changed on 3 pages", "Was: 499" on hover. Shift+click with Ctrl+C gives `1\t2\n5\t6`. Ctrl+A selects all 714 visible botfield cells. A typed link joins the pending set (13 on 4 pages); a typed non-link is refused. Leaving is blocked. Cancel discards; Save then Undo works. | `1366-grid-pending.png`, `1366-grid-saved.png` |
| 6 | 390 px: "Paste…" opens the modal with the page picker. The textarea is full width, the preview scrolls, and Save and Cancel are on screen. No sideways page scroll. | `390-paste.png` |
| 7 | Keyboard: the Paste button and the checkboxes take focus, the modal opens with focus in the textarea, and Escape closes it. | — |

Clipboard events were dispatched with `DataTransfer` (headless Chrome has no real clipboard). The OS clipboard path was not exercised.

## Review

`skeptic-reviewer`, standard depth (the brief set the task to medium). No blockers or majors.

The minors on untrusted paths were fixed in one final wave (`9f71c481`):
- a non-text value is refused;
- a non-link typed into an image cell while a paste is pending is no longer added;
- tests were added for a duplicate cell and for the 5,000 cap.

The other minors are accepted with reasons in `TODO.md`:
- drag-select is not built;
- Undo can be refused whole when a legacy value fails today's checks;
- there is no test for a rollback inside the transaction;
- a one-cell paste of a field name is read as a header;
- the flow editor canvas keeps its `calc`.

## Story changes

- New section S-092 with twelve cases in `qa/stories.md` (the spec brought no stories). They were written from the spec's decisions and were not run past the reviewer before coding, because the brief said to start at once.
- S-092.6 gained "not text" and "over 5,000 changes" during the review fixes.
- S-092.11 doesn't promise drag-select, because it wasn't built.

## Notes and conflicts

- **Branch base:** the brief said `main`; the kit says `develop`. I followed the brief because `develop` is behind `main`.
- **Checkbox column:** the checkbox sits inside the Page cell, not in a new column. A new sticky column would have shifted the offsets of the Page, Active and Flow sticky columns.
- **Flow editor:** `resources/views/chatbot/editor.blade.php` still has `#cv { height: calc(100vh - 17rem) }`. It has no scrollbar to lose (the canvas pans and zooms), and spec 090 is changing that page right now.
- **Merge danger:**
  - `resources/views/chatbot/replies.blade.php` is also touched by spec 090. My change there is three lines: the style line, the `#rp` class and the `#rp-box` class.
  - `routes/web.php` gains one line in the `/chatbot` group, next to `/cell`.
  - `ChatbotController` gains `cells()` and `valueError()`.
- **Deploy:** no migration and no new setting. `public/js/chatbot-paste.js` is a static file served from `public/`, with no Vite build step.
