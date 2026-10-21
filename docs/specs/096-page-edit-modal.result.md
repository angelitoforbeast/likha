# Result 096: an Edit button on each page row opens that page's fields, with paste, and Save

Branch `feat/096-page-edit-modal`, cut from `release/090b` as the brief asked. Nothing was pushed, and no server was touched.

## Commits

| Commit | What |
|---|---|
| `fe99cbc9` | docs: the brief, verbatim |
| `56aafdd8` | feat: an Edit button per row, the page modal with paste and Save, `firstRowOnly` in the paste plan; the global Paste values modal, its page picker and the row checkboxes removed |
| `6f042c2c` | fix (review): a stored value with a trailing space is not counted as a change when the modal opens |
| (this commit) | docs: result, stories S-096, TODO, screenshots |

## What changed

| Area | Change |
|---|---|
| Page cell | Under the page name and ID sits an **Edit** button (pencil plus "Edit"; on a phone the pencil only). Its aria-label is "Edit <page>". |
| Page modal (`#pe`) | **Title:** the page name, with the page ID muted.<br>**Paste box** at the top: "Paste a row copied from Google Sheets (with or without the header line)", with "Starting at field" (hidden when a header line is found) and "Paste blanks as empty".<br>**Fields:** every botfield in grid column order, one per line, in a list that scrolls vertically. Text fields are auto-growing textareas that keep line breaks. Image fields (PICTURE, Seq Img 1–20) are link inputs with a small preview. ACTIVE and FLOW are not listed.<br>**Footer:** "Save N changes" and Cancel, always visible. On a phone the modal is full screen. |
| Paste | Uses 092's `ChatbotPaste.plan()` with one target, the page.<br>**Mapping:** a header line maps by name; otherwise the values map by order from the chosen field.<br>**Rows:** only the first data row is used; the rest are counted in a note.<br>**Filled fields** turn amber with "Was: …" under them.<br>**Blank cells** keep the current value unless the box is ticked.<br>**Non-links** in an image field turn red with "Not a link…" and are not saved.<br>**Re-paste:** a new paste, or another "Starting at field", replaces the previous paste and does not stack on it.<br>**Notes** list ignored names, ACTIVE/FLOW, dropped values and extra rows. |
| Hand edits | Any field typed in turns amber and counts as a change. |
| Save | Sends only the changed fields of this page, in one request to `POST /chatbot/cells` (092's bulk save: all or nothing, history, limits, link check). The modal closes, the grid reloads, and the bar under the table reads "Saved N fields on 1 page." with **Undo** (092's undo). |
| Close | Cancel, ✕, Escape or a click outside asks "Discard the changes to <page>?" when anything changed. Without changes, the modal closes at once. Leaving the page with an open, changed modal asks first too. |
| `public/js/chatbot-paste.js` | `plan()` takes `firstRowOnly`: after the header line, it keeps the first data row only and reports `extraRows`. Without the option, behaviour is unchanged. |
| Removed | The "Paste values" / "Paste…" button, the `#paste` modal with its page picker, preview and "Show unchanged", the row checkboxes and select-all, and their CSS and JS. The grid's select, copy and Ctrl/Cmd+V paste, the pending bar, "Paste blanks as empty" in the filter bar, and Undo are as before. |
| Server | No change. The modal's data is the existing `GET /chatbot/data` (columns in grid order, each row's values), so no new endpoint was needed. |

## Tests

Case table (the `phpunit --testdox` run on the final tree):

| Case | Test | Result |
|---|---|---|
| S-096.1 data for one page: columns in grid order, current values with line breaks; 403 without access, 401 signed out | `ChatbotPageEditTest::test_S_096_1…` | ✔ pass |
| S-096.2 saving one page through bulk-save changes only that page | `ChatbotPageEditTest::test_S_096_2…` | ✔ pass |
| S-096.3 Edit button and page modal are on the page; Paste values button, page picker and checkboxes are gone | `ChatbotPageEditTest::test_S_096_3…` | ✔ pass |
| S-096.4 one page: first data row only, by name or by order | `ChatbotPasteScriptTest::test_S_096_4…` | ✔ pass |
| S-096.5 modal layout | headless Chrome, check 2 | ✔ pass |
| S-096.6 paste, amber, flagged, blanks, re-paste, hand edit | headless Chrome, checks 3, 3b, 4, 7c | ✔ pass |
| S-096.7 Save, grid updated, Undo, confirm on close | headless Chrome, checks 5, 6, 7 | ✔ pass |
| S-096.8 phone layout | headless Chrome, checks 9, 10 | ✔ pass |

**Red runs:**
- `test_S_096_3` failed with "Failed asserting that '…' contains "id="pe"" (no modal yet).
- `test_S_096_4` failed with "Failed asserting that 'The paste has 2 rows but 1 page is selected…' is null" (no `firstRowOnly` yet).
- `test_S_096_1` and `test_S_096_2` passed at once. They pin the existing endpoints the modal reuses; no server code was needed.
- The review fix: browser check 7c failed (`pe-changed` on a stored "250 ") with the fix stashed, and passed with it.

**Suites:**
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake`: 740 tests, all pass (1 skip, as before).
- The 092 tests (`ChatbotPasteTest`, `ChatbotPasteScriptTest`) pass unchanged. None of them covered the removed modal; its case S-092.10 was browser-only and is marked retired in `qa/stories.md`.
- `node --check` passes on the page's inline script.

## Browser check

The browser-checker had no browser tools here, so I used the same approach as 092. A script outside the repo drove the installed Chrome headless over the DevTools protocol (Node 24 built-ins; no package installed). The app ran with `php artisan serve` on a temporary sqlite database outside the repo, with settings passed as environment variables; no env file was created. The data: 14 pages and a few values, including a 4-line Main Flow.

Result: **13/13 checks passed**, no console errors. Screenshots are in `docs/specs/096-page-edit-modal/`.

| # | Check | Evidence |
|---|---|---|
| 1 | All 14 rows have an Edit button ("✎ Edit", aria-label "Edit Alpha Shop"). There is no Paste values button, no `#paste` modal and no checkbox in the table. | `1366-pages.png` |
| 2 | Edit opens "Alpha Shop 1001". All 51 botfields are one per line in grid order, the list scrolls, the 4-line Main Flow keeps its lines and the textarea grows to fit. There are 21 image previews, no ACTIVE or FLOW, focus is in the paste box, and Save is disabled. | `1366-modal.png` |
| 3 | A header paste (PRICE, Main Flow, PICTURE, ACTIVE, Colour, Quantity + 2 data rows): price 599 and Main Flow are amber with "Was: …", the non-link PICTURE is red and not counted, and the blank Quantity is kept. The notes list Colour as ignored, mention ACTIVE/FLOW and say "Only the first row was used". The button reads "Save 2 changes". | `1366-modal-pasted.png` |
| 3b | Without a header, from "Quantity": the value goes to Quantity, the previous paste's PRICE goes back to 499, and the button reads "Save 1 change". | — |
| 4 | A hand edit in Prompt 1 turns amber with "Was: (empty)"; "Save 3 changes". | — |
| 5 | Save: the modal closes and the Alpha Shop row shows 599, "Hello po! / Welcome." and the typed prompt. The non-link was not saved, Bravo Store is unchanged, and the bar reads "Saved 3 fields on 1 page." with Undo. | `1366-after-save.png` |
| 6 | Undo restores all three fields. | `1366-after-undo.png` |
| 7 | Cancel after an edit asks "Discard the changes to Bravo Store?" and saves nothing. Escape without changes closes with no question. | — |
| 7c | A stored "250 " (trailing space) is not shown as changed; Save is disabled and Escape doesn't ask. | — |
| 8 | Grid select + paste of a 2×2 block gives 4 pending cells and the bar, as in 092. | — |
| 9–10 | At 390×844, the Edit button is the pencil only (aria-label kept) and the page doesn't scroll sideways. The modal is 390×844 at 0,0, the fields scroll (512 px), and Save and Cancel end at 832 px whether scrolled or not. | `390-pages.png`, `390-modal.png` |

Clipboard paste into the paste box was simulated by setting the text and firing `input` (headless Chrome has no real clipboard). The OS clipboard path was not exercised.

## Review

`skeptic-reviewer`, standard depth. No blockers or majors.

- **Fixed (`6f042c2c`):** a stored value with a trailing space showed as changed when the modal opened. Fields are now compared with the trimmed old value.
- **Accepted, with reasons in `TODO.md`:**
  - editing the paste box again re-applies the paste over a field fixed by hand after the paste;
  - the modal works on the row as it was when it opened;
  - the image preview loads typed links (like the grid);
  - the modal's behaviour is covered by the browser check, not an automated test (the project has no JS test runner for page scripts).

## Story changes

- New section S-096, eight cases, in `qa/stories.md`. The brief brought no stories, and it said to start at once, so they were written from its decisions and not run past the reviewer first.
- S-092.10 (the Paste values modal) is marked retired, because spec 096 removes that modal.

## Notes and conflicts

- **Branch base:** the brief said `release/090b`; the kit says `develop`. I followed the brief.
- **"Toast" for Undo:** Undo is in 092's existing bar under the table ("Saved N fields on 1 page. · Undo"), which the brief calls 092's undo. No separate toast was added.
- **Spec review:** the kit asks for a plan and a "go" before code. The brief said to start now, so the design is in this result instead.
- **Merge danger:** `resources/views/chatbot/index.blade.php` (only spec 092's paste parts changed), `public/js/chatbot-paste.js` (one option added). Spec 094 (flow editor) and 095 (BotcakeOutbox) should not touch these files.
- **Deploy:** no migration and no new setting. `public/js/chatbot-paste.js` is a static file with a hash in its URL, so browsers fetch the new copy.
