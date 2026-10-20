# Result 090: the Google Sheet step shows the spreadsheet, its tabs and its column headers

Branch `feat/090-sheet-step-picker`, cut from `main` and rebased onto the newest `main` (`31d3c723`, spec 089). Nothing was pushed, and nothing on a server was touched.

## Commits

| Commit | What |
|---|---|
| `f53f5633` | docs: the brief, verbatim |
| `d1fe619b` | feat: the sheet endpoint, the preview, the editor's sheet step, the Open row link, tests, stories, screenshots |
| `23d36afe` | fix: review findings (one spreadsheet-ID rule shared with the run, the gid looked up again for a new tab, the tab match trims like the run, one request per tab pick, a note past 50 columns, more test cases) |
| (this commit) | docs: result and TODO |

## What shipped

**Endpoint `GET /chatbot/sheet?sheet=&tab=&refresh=`** (`ChatbotSheetController::info`, editor access via NavAccess `/chatblast`, `throttle:30,1`):
- Accepts only a spreadsheet ID: a bare ID, or a `docs.google.com` link (with or without `https://`), parsed by the same `FlowGraph::spreadsheetId` the run uses. Any other host gets 422 and Google is never called. Only the ID goes to the Google client, never a URL.
- Returns `{id, title, tabs: [{title, gid}], tab: {title, gid, missing, headers} | null}`. Tabs come in sheet order. The title, tab names and headers are capped at 200 characters each, with at most 100 headers. A saved tab that no longer exists comes back as `missing: true` and is not read.
- When Google answers 403 or 404, the response is a 422 with `share: true`, the email, and "Share this sheet with gsheet-to-database@… as Editor, then press Check again." Any other error gives a 502 "Google did not answer. Try again in a minute." Google's raw text never reaches the browser.
- `ChatbotSheet::info()` and `headers()` cache for 5 minutes, per spreadsheet and per tab (`sha1` of the tab name). Refresh (`refresh=1`) skips the cache. A refusal is never cached, so Check again works as soon as the sheet is shared.

**Preview** (`GET /chatbot/flows/{id}/sheet-customers`, `POST /chatbot/flows/{id}/sheet-preview`):
- **The customer list** has the latest customers of the pages that use the flow: one per customer, newest first, 20 at most, read from the last 500 chats via the `page_id, received_at` index.
- **The cells:** the preview builds the run's variables for the chosen chat, the same way a live run does (botfields, customer fields, the chat's text and name). It then applies `ChatbotVars::sheetCell` to the templates sent from the form, so unsaved edits are previewed too, formula guard included.
- **No writes:** it adds no reply, customer value or event, and never calls append. A chat from a page that doesn't use the flow gets a 404.

**Open row:**
- **What is stored:** `ChatbotSheet::append()` returns the `updatedRange` from Google. `BotcakeOutbox` keeps it as `range` in the reply's content, together with the tab's `gid` from the cached tab list. If the tab is not in that list (for example a new tab), the list is read once more.
- **When the list can't be read:** the row is still `sent`, with a range and no gid, so it shows no link.
- **The link:** `ChatbotSheet::rowLink()` builds `https://docs.google.com/spreadsheets/d/<id>/edit#gid=<gid>&range=A123`, and only from a well-formed ID, an int gid and a cell like `A123`.
- **Where it shows:** `/chatbot/replies/{id}` returns it as `link`, and the reply's detail shows "Open row". The Inbox thread adds `link` to sheet rows, and the system line shows "Open row" under it. Rows written before this change have no range, so they show no link.

**Editor (`resources/views/chatbot/editor.blade.php`, `sheetForm`):**
- **Loading:** pasting or typing a link reads the sheet after 600 ms. The spreadsheet name appears under the field, next to a Refresh button.
- **The tab** is a dropdown of the sheet's tabs. A saved tab that is gone shows as "Name (missing)" in red, with a line telling the user to pick another tab. Before the sheet is read, or when it can't be read, the tab stays a text field as before.
- **Columns** are a table, one row per sheet column: the letter, the header from row 1, and the template input. The variable chips still go into the last focused input. "+ Column" adds rows past the last header. A tab with more than 50 headers shows a note that a step writes at most 50 columns (A to AX), which is the existing `FlowGraph::MAX_COLUMNS`.
- **Preview:** a customer picker and "Preview row" add a Preview column with the exact cells.
- **Share message:** shows the full email, a Copy email button (with a fallback for plain http) and Check again.
- **Stored config is unchanged:** it is still `{sheet, tab, columns: [...]}`, with empty strings for skipped columns. An input fills only its own index, so a step opened and closed without edits saves exactly what it had.

## Case table

`"/c/Users/Forbeast/.config/herd/bin/php.bat" vendor/bin/phpunit tests/Feature/Chatblast/ChatbotSheetPickerTest.php`, Google faked:

| Case | Test | Result |
|---|---|---|
| S-090.1 | `test_S_090_1_a_link_gives_the_title_and_the_tabs_in_sheet_order` | pass |
| S-090.2 | `test_S_090_2_picking_a_tab_gives_its_headers_and_a_gone_tab_is_missing` | pass |
| S-090.3 | `test_S_090_3_google_refusing_gives_the_share_message_with_the_email_and_no_raw_error` (403, 404, 500) | pass |
| S-090.4 | `test_S_090_4_title_tabs_and_headers_are_cached_5_minutes_and_refresh_bypasses_it` | pass |
| S-090.5 | `test_S_090_5_an_old_sheet_step_opens_and_saves_unchanged` | pass |
| S-090.6 | `test_S_090_6_the_preview_fills_the_cells_of_a_recent_customer_with_the_formula_guard_and_writes_nothing` | pass |
| S-090.7 | `test_S_090_7_the_updated_range_is_kept_and_the_open_row_link_is_built` | pass |
| S-090.8 | `test_S_090_8_the_endpoints_refuse_a_non_google_link_and_users_without_access` | pass |

Output: `OK, but there were issues! Tests: 10, Assertions: 89, PHPUnit Deprecations: 1` (the deprecation is the `@dataProvider` doc comment, the style the other tests in this folder use).

**Red runs:**
- **The first run** stopped at compile: the new fake's `append(): ?string` was incompatible with `append(): void`. After removing that return type, the second run failed on behaviour: S-090.1–4 and S-090.8 got 404 (no route), S-090.6 got 404, and S-090.7 failed with "two arrays are identical" (no range kept). S-090.5 passed from the start, as a characterisation of the unchanged config.
- **The review wave:** S-090.1 failed with "Expected [200] but received 422" (a link without `https://`), and S-090.7 failed on the missing gid of a new tab. The added 502 data set and the guest checks passed at once; they pin code that already worked.

## Suites

- `vendor/bin/phpunit tests/Feature/Chatblast tests/Feature/PageSettings tests/Feature/Botcake` on the final tree: `OK, but there were issues! Tests: 721, Assertions: 4909, PHPUnit Deprecations: 47, Skipped: 1`.
- Full suite on the final tree: `ERRORS! Tests: 2076, Assertions: 21459, Errors: 1, Failures: 1, PHPUnit Deprecations: 47, Skipped: 4`. The 2 reds also fail without this change and come from this fresh worktree: `ExampleTest` (`/` redirects to the login, 302) and `NightRun\ImportStartTest::test_macro_job_final_write_applies_only_while_the_run_is_still_active` (`storage/app/credentials.json` does not exist here). No new failures.
- `php -l` is clean on every changed PHP file. No asset build is needed: the editor's script is inline Blade, and the layout uses the Tailwind CDN.

## Browser check

The checks ran in headless Chrome 155, driven over the DevTools protocol by a throwaway Node script. The app was served by `php -S`, with a router outside the repo that binds a fake `ChatbotSheet` and logs in a CEO, on a throwaway sqlite file with a flow like "Main Flow (from CBB)": n43 with a link, n44 not shared, n45 with a gone tab. No env file was created or read.

Screenshots are in `docs/specs/090-sheet-step-picker/`, at 1366 and 390 px:

| Screen | 1366 | 390 |
|---|---|---|
| Name, tab dropdown, header table | `1366-1-name-tabs-headers.png` | `390-1-name-tabs-headers.png` |
| Preview row (formula guard visible: `'=HYPERLINK("x")`, `'09171234567`) | `1366-2-preview.png` | `390-2-preview.png` |
| Share message with the full email, Copy email, Check again | `1366-3-share-message.png` | `390-3-share-message.png` |
| Saved tab missing, in red | `1366-4-missing-tab.png` | `390-4-missing-tab.png` |

In every state at both widths, `scrollWidth > clientWidth` was false, so there is no page-level horizontal scroll. There were no console errors.

The first pass found two layout problems at 390 px, both fixed before the screenshots: the customer picker was squeezed to one letter, and the share email was cut off in a narrow box. Switching tabs (All Orders → Returns) reloads the headers. "Open row" on Replies and the Inbox is covered by S-090.7 and was not screenshotted.

## Review

`skeptic-reviewer` reviewed the work at adversarial depth, raised from medium because of the new routes. It found no blockers and no majors.

**Minors fixed in the final wave (`23d36afe`):**
- the duplicate ID parser, now one rule with the run;
- the client's tab match didn't trim;
- `bind()` fired two requests per tab pick;
- a stale gid for a new tab;
- headers 51–100 were never shown, now explained by a note;
- the missing tests for a generic Google error, info failing after append, and a guest.

**Accepted with reasons in `TODO.md`:**
- Viewer-only sharing reads fine but every write fails;
- a 403 for "API not enabled" shows the share text;
- no test of the real Google request;
- no test of the rate limit or the preview input edge cases;
- botfields in the preview, the same exposure as the Test tab;
- Open row in the detail only.

## Story changes

- **S-090.1** now also accepts a link without `https://`. Reason: the run already accepts it, and the review found the two rules disagreed.
- **S-090.3** now also covers any other Google error (502, no raw text). Reason: it is part of "never show Google's raw error".
- **S-090.7** now covers a tab that isn't in the cached list yet, and a list that can't be read after the write. Reason: the "Open row" gid comes from the cache.
- **S-090.8** now covers a guest (401). Reason: to pin the `auth` group on the new routes.

## Conflicts and notes

- **Branch base:** `CLAUDE.local.md` says to cut work branches from `develop`. The brief said `main` (the newest), so the branch is on `main`. Before merging, check which base the PR should target.
- **`append()` lost its `: void` return type** and returns `?string`. A return type would have broken the `: void` fakes in three existing test files. This way they stay untouched and simply return null, which means no link.
- **Not done:** reading rows other than row 1, writing headers, and any change to how rows are appended (the brief's out of scope).
