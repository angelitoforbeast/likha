# Brief 090: the Google Sheet step shows the spreadsheet, its tabs and its column headers

## Why

In the flow editor (`/chatbot`, the Google Sheet step, e.g. n43 in "Main Flow (from CBB)"), the owner types a spreadsheet link, a tab name by hand, and one template per line (A, B, C …) without seeing the sheet. The step has no link today, so every run fails it with "No spreadsheet link or ID".

The owner, 2026-10-20: "pag naglagay ng link, magsshow yung gsheet name at sheet name, pipili ng sheet name, tapos makikita din yung column header", and "pwede ba makita yung actual row?".

Rows are written by `App\Services\Chatbot\ChatbotSheet` with the service account in `storage/app/credentials.json` (client email `gsheet-to-database@banded-arch-432107-c9.iam.gserviceaccount.com`). The sheet must be shared with it as Editor. Each row is logged in `chatbot_replies` (kind `sheet`, content `{sheet, tab, row}`) and shown on `/chatbot/replies`.

## Decisions (do not reopen)

| Decision | Why |
|---|---|
| **Paste a link and see the sheet.** When a link or ID is entered, the editor calls a new endpoint (behind the same access as the editor; rate-limited). It reads `spreadsheets.get` with fields `properties.title` and `sheets.properties(title, sheetId, index)`. It shows the **spreadsheet name** and the **tabs as a dropdown**. The tab field becomes that dropdown. A saved tab that no longer exists is shown as "missing" in red. | The owner. |
| **Not shared, or not found.** When Google refuses (403 or 404), show a clear line: "Share this sheet with gsheet-to-database@… as Editor", with a copy button for the email, and a "Check again" button. Never show Google's raw error or any credential. | The owner's staff will hit this first. |
| **Headers.** After a tab is picked, read its row 1 (`values.get` `'<tab>'!1:1`). Turn the "Columns" box into a table: one row per sheet column, showing the letter (A, B, C …), the **header text** from row 1, and the template input for that column. The variable chips insert into the focused input, as today. Columns past the last header can still be added ("+ Column"). Stored step config stays compatible: still the ordered `columns` list (empty strings for skipped columns), so the engine, existing flows and drafts work unchanged. | The owner: see the headers. |
| **Cache.** Spreadsheet metadata and headers are cached for 5 minutes per spreadsheet and tab. "Refresh" bypasses the cache. | Google API quota. |
| **Preview row.** A "Preview row" button fills the templates with a real customer's values, chosen from the page's recent chats (a picker defaulting to the latest customer of any page using this flow). It is shown under the headers as the exact cells that would be written, formula guard included (`ChatbotVars::sheetCell`). It writes nothing. | The owner: see the actual row before publishing. |
| **Open row.** When a row is written, keep the `updatedRange` that the append returns (e.g. `'All Orders'!A123:J123`) in the reply's content. On `/chatbot/replies`, and in the Inbox's executed actions where sheet rows show, add an **"Open row"** link to `https://docs.google.com/spreadsheets/d/<id>/edit#gid=<sheetId>&range=A123`. Rows written before this spec show no link. | The owner: see the actual row afterwards. |
| **Security.** The endpoint accepts only a spreadsheet ID (parsed from the link) and a tab name. It never fetches arbitrary URLs. It returns only the title, tab names and header strings, each capped at 200 characters and with at most 100 columns. | No SSRF; minimal data. |

## Done when

- **Tests (Google faked):**
  - a link gives the title and tabs;
  - picking a tab gives its headers;
  - 403 and 404 give the share message with the email;
  - the cache and Refresh work;
  - the stored `columns` stay compatible (an old flow opens and saves unchanged);
  - the preview fills the cells with the formula guard and writes nothing;
  - `updatedRange` is saved and the "Open row" link is built correctly;
  - the endpoint refuses a non-Google link and other users without access.
- **Browser check:** the sheet step modal at 1366 and 390 px, showing the name, the tab dropdown, the header table, the preview and the share message (with fakes). No page-level horizontal scroll.
- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` passes, and the full suite adds no new failures.
- Report the commits.

## Out of scope

- Reading data rows other than row 1.
- Writing headers.
- Changing how rows are appended.
