# Brief 092: copy and paste blocks of botfield values on /chatbot/pages, saved with one button

## Why

On `/chatbot/pages` (`resources/views/chatbot/index.blade.php`, `ChatbotController` cell endpoint, `App\Services\ChatbotFields`), every botfield cell is set one at a time, and values save as you type. There are 60 pages and about 50 columns (Prompts, Main Flow, Fill-Up Form, Seq Img N, Seq N …).

The owner, 2026-10-20: "copy paste lang. isang copy, isang paste, malalagyan na lahat ng value. pero may button na save".

## Owner's refinement (2026-10-20, it takes precedence over the grid paste below)

"May magoopen, tapos ipapaste dun, pero per field kita, scrollable vertically." The **main way** to paste is a **Paste modal**:

| Decision | Detail |
|---|---|
| Open | A "Paste values" button above the grid opens a modal. The target pages are the rows ticked in a new checkbox column; with none ticked, the modal lets you pick the pages. |
| Paste box | One textarea where the owner pastes a block copied from Google Sheets (TSV, quoted multi-line cells supported). |
| Field mapping | If the first pasted line matches field names (a header row, e.g. copied with the LDT sheet headers), columns map **by name**. Otherwise they map **by order** starting from a chosen field (a dropdown, default the first botfield). Unknown header names are listed and ignored. |
| One row → many pages | If the paste has **one data row**, it applies to **every** target page. If it has as many rows as target pages, row 1 goes to page 1 and so on, in grid order. Any other count is refused, with the reason. |
| Preview, per field, vertical | After pasting, the modal shows the result as a **vertical, scrollable list**: grouped by page, one line per field ("Field name: new value", with the current value faded beneath when it differs). Long values fold to 3 lines and expand on click. Unchanged fields are hidden behind a "show unchanged" toggle. |
| Save / Cancel | "Save N fields on M pages" applies everything in one request and one transaction (same rules as below: limits, image-link check, ACTIVE and FLOW never changed, blanks keep the value unless "Paste blanks as empty" is ticked). Cancel closes without changes. After Save: Undo, as below. |

The in-grid selection, copy and Ctrl+V below stay **secondary**: build them only if time allows after the modal, and say in the result which were built.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **Select cells like a spreadsheet.** Click a cell to select it. Shift+click or drag selects a rectangle. Ctrl/Cmd+A inside the grid selects all visible botfield cells. Selection works across the sticky columns and the scroll. | Familiar. |
| **Copy:** Ctrl/Cmd+C copies the selected rectangle as TSV, the format Google Sheets and Excel paste. Cells with line breaks or tabs are quoted the way Sheets does, so a round trip keeps them. | Copy from the grid. |
| **Paste:** Ctrl/Cmd+V with a cell selected pastes a TSV block from Google Sheets, Excel or the grid. It fills to the right (columns) and down (rows, in the order shown, so it honours the current search and filter) from that cell. Quoted multi-line cells parse correctly. A single copied value pasted onto a selected rectangle fills the whole rectangle. | One copy, one paste. |
| **Pending, then Save.** Pasted cells are **not** saved at once. They show as pending, highlighted, with the old value on hover. A sticky bar reads "N cells changed on M pages · Save · Cancel". Save sends all changes in one request, applied in one transaction through the same `PageSettings` path as a single cell (history kept, same validation, same limits: `ChatbotFields` MAX_VALUE and MAX_BYTES). Cancel discards. Leaving the page with pending changes asks for confirmation. Typing in a single cell keeps today's behaviour, with one exception: a cell edited while there are pending pasted changes joins the pending set. | The owner: "may button na save". |
| **What can be pasted.** Botfield columns only. ACTIVE (On/Off) and FLOW are skipped in a paste, with a note "ACTIVE and FLOW are not changed by paste". Image columns (PICTURE, Seq Img N) accept links; a non-link is flagged and not saved. Values beyond the last column or row are dropped, with a note of how many. An empty pasted cell clears the value only if "Paste blanks as empty" is ticked (default off, so blanks keep the current value). | Safety. |
| **Undo last save.** After a Save, an "Undo" button (for that session, until the next Save) restores every cell of that save to its previous value through the same path. | Safety. |
| **Permissions** are the same as editing a cell today. | — |
| **Mobile (390 px):** paste via a "Paste…" button that opens a textarea (phones have no grid paste). Same pending, Save and Cancel. | Usable on phone. |
| **`/chatbot/pages`: the horizontal scrollbar is always visible.** The table box `#cb-box` uses `max-height: calc(100vh - 15rem)`. The header area (app bar, title, help line, banner, filters) is taller than 15rem, so the box runs past the bottom of the screen: `<main>` scrolls and the table's horizontal scrollbar is only reachable at the very bottom (owner's screenshot, 2026-10-20: "bakit wala yung horizontal slider pag hindi pa nakakarech sa baba, mali yun"). Use the layout's own pattern (`.cb-page` / `.cb-scroll`: flex, `min-h-0`, `flex-1 basis-0`), so the table fills exactly the remaining height. The page itself doesn't scroll. The table scrolls inside, with its horizontal bar at the bottom of the screen and the sticky header and first columns kept. Check every chatbot and chatblast page that uses a fixed `calc(100vh - …)` for the same bug, and fix them the same way. Browser check at 1366×768, 1920×1080 and 390 px: the horizontal bar is visible without scrolling the page. | The owner. |

## Done when

- **Tests:**
  - the TSV parser (quotes, line breaks, tabs, a trailing newline, CRLF);
  - the bulk-save endpoint (all-or-nothing, history rows, limits, image-link check, blank handling, skips ACTIVE and FLOW, permissions, a page not visible to the role);
  - Undo restores the values.
- **Browser check:**
  - select, copy and paste a 3×4 block from TSV;
  - the pending highlight and bar;
  - Save, then Undo;
  - Cancel;
  - the phone Paste… textarea;
  - no page-level horizontal scroll, with the scroll fix (in this spec) working.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes, and the full suite adds no new failures.
- Report the commits.

## Out of scope

- `/chatblast/page-settings`. The same feature there can follow if the owner wants it.
- Bulk On/Off and FLOW.
- CSV file import.
