# Result 060: Chatblast pages keep the header fixed and scroll only the data

## What shipped

One slice, medium risk (UI screens), frontend. Display only: no route, endpoint, data or server change.

- `resources/views/layouts/chatblast.blade.php`
  - The body is a full-height column (`flex h-screen flex-col overflow-hidden`). The top bar does not shrink.
  - `main` fills the rest (`flex-1 min-h-0 flex-col`). The page header (title, description, actions) does not shrink.
  - New component classes:
    - `.cb-page` is the page's content column. Its children keep their height.
    - `.cb-page > .cb-scroll` takes the remaining height and is the only scroll container (`overflow: auto`, at least 16rem tall).
    - `.cb-scroll thead th` sticks to the top of that container: `sticky top-0`, white background, one thin slate-200 line drawn as an inset box-shadow (a `th` border does not move with a sticky cell in a collapsed table), no bottom border.
    - `.cb-scroll.is-scrolled thead th` adds a very light shadow (`0 2px 4px -2px`, 8% slate) once the rows have scrolled.
  - A small script toggles `is-scrolled` on scroll. The old comment ("no sticky, plain document") is replaced.
- `resources/views/chatblast/index.blade.php` (To send and History): the root `#cb` is a `cb-page`, and the table card is the `cb-scroll` (it was `overflow-x-auto`). The filters, chips, per-sequence and per-page lines, action buttons and Test PSID stay above it. The pager stays below it. The row modal is unchanged.
- `resources/views/chatblast/messages.blade.php`: the root `#cm` is a `cb-page`. Notice, filters and placeholder chips stay fixed. "+ New message", "Flow and template" and the message cards are wrapped in one `cb-scroll`.
- `resources/views/chatblast/sequence.blade.php`: the root `#cs` is a `cb-page`. Notice and the overlap warning stay fixed. "+ New sequence" and the sequence cards are wrapped in one `cb-scroll`.
- `resources/views/page_settings/index.blade.php`, limited to the wrapper and the thead styling for the merge with feat/057:
  - the content root `<div>` gets `class="cb-page"`;
  - the `#grid` card's `overflow-x-auto` becomes `cb-scroll`;
  - the two `#grid-table thead` style lines now say white background and no bottom border (the line comes from the layout), with the comment updated.
- Unchanged from amendment 6: the modals, no Log out, and the button, chip and table styles.

### How the scroll container works

The window never scrolls. The body is exactly the viewport height and hides overflow. Inside it are the top bar (fixed height), then `main`. Inside `main` are the page header, then the page's `.cb-page` column. Every child of that column keeps its natural height except the one `.cb-scroll`. That one gets `flex: 1 1 0` (the column and `main` have `min-height: 0`, so they never grow past the viewport), so it takes exactly the leftover height and scrolls its own content with `overflow: auto`. On table pages the table's `th` cells are `position: sticky; top: 0` inside that box, so the column header row stays at its top while the rows move under it. If a table is wider than the box, the sideways scroll also stays inside the box.

Fallback for very short windows: the scroll box keeps at least 16rem. If the fixed parts plus 16rem do not fit, `main` (`overflow-y: auto`) scrolls instead of anything being cut off. The three sub-pages that share the layout (Customers, PSID coverage, Orders) have no `.cb-page`, so they now scroll inside `main` under the fixed top bar.

## Case table

| Case | Test | Result |
|---|---|---|
| S-060.1 | `ChatblastAppTest::test_S_060_1_the_data_area_is_the_only_scroll_container_under_a_fixed_header` (to send, history, messages, sequences, page settings) | Pass (5 data sets) |
| S-060.2 | Browser check below | Pass |
| S-060.3 | Browser check below | Pass |

Red run, before the change: all 5 data sets of S-060.1 failed at the first assertion, "Failed asserting that '<!DOCTYPE html>…' matches PCRE pattern "#<body class="[^"]*\bh-screen\b[^"]*\boverflow-hidden\b#"". It was re-run against the base commit's views with the final `vendor/` in place and failed the same way.

Tests (`php artisan test tests/Feature/Chatblast tests/Feature/PageSettings`): 302 tests, 2219 assertions, 0 failures. Each test shows a "Metadata found in doc-comment" warning: the project already uses `@dataProvider` doc-comments, which PHPUnit 11 marks as deprecated. These are not assertion failures.

Lint: `php -l` reports no syntax errors on the five changed views and the test file.

## Browser check (headless Chrome over CDP, scratch app)

- **Setup:**
  - A scratch app on `127.0.0.1:8060` served this worktree against a scratch sqlite file outside the repo, built with the tests' schema helpers.
  - Made-up data: 6 pages, 120 outbox rows in mixed states, 20 messages and 2 sequences.
  - Every outbound HTTP call was faked to a 404, so no Botcake or J&T call could happen.
  - A scratch CEO session was minted, then deleted afterwards.
- **Method:** on each page at 1366 × 768 and 1280 × 720, the check:
  - measured the top bar, the `h1`, the filter row, the scroll box and the first `th`;
  - set the scroll box to 600 px down and measured again;
  - took a screenshot before and after.
- **Evidence:** screenshots and `evidence.txt` are in the main checkout's `handoff/060-chatblast-sticky-header/screens/`. There are 22 PNGs: `<page>-<width>-top.png` and `<page>-<width>-scrolled.png` for each page, plus `to-send-<width>-modal.png`.

| Page | Width | Header and controls stay | Column header at the box top | Page h-scroll | Data scrolls | Shadow after scroll |
|---|---|---|---|---|---|---|
| To send | 1366 / 1280 | yes / yes | yes / yes | none / none | yes / yes | yes / yes |
| History | 1366 / 1280 | yes / yes | yes / yes | none / none | yes / yes | yes / yes |
| Messages | 1366 / 1280 | yes / yes | (cards) | none / none | yes / yes | (none, no table) |
| Sequences | 1366 / 1280 | yes / yes | (cards) | none / none | yes / yes | (none, no table) |
| Page settings | 1366 / 1280 | yes / yes | yes / yes | none / none | yes / yes | yes / yes |

- "Stay" means:
  - the top bar, `h1`, filter row and scroll box have the same top before and after scrolling;
  - the first `th` sits 1 px under the box top (the card border);
  - the document never scrolls (`scrollHeight == clientHeight`);
  - `main` never needed its fallback scroll at these sizes.
- **Modal:** on To send, after scrolling, clicking a row opened the details modal (`position: fixed`). Escape closed it. This was checked at both widths.
- **Sub-pages:** Customers, PSID coverage and Orders render at 1280 px with no page h-scroll.
- **No JS errors.**

## Story changes

Added section S-060 to `qa/stories.md`. The spec brought no stories. The three cases restate its decisions: one automated layout case, and two browser cases for scrolling, the modal, and the card pages.

## Conflicts and notes

- **Inner sideways scroll on History at 1280 px.** The History table is 1268 px at its narrowest with the sample data: the Status column holds the "Sent (unconfirmed)" badge, and the Sent column holds timestamps. At 1280 px the box has 1215 px inside its scrollbar, so the table scrolls 53 px sideways inside the table area only, as the spec allows. The page itself does not scroll sideways. This is not new: before, the page scrollbar took the same 15 px. At 1366 px it fits. The spec's styling decisions kept the table styles, so I didn't narrow any columns.
- **Colour of the line.** The spec says gray-200. The existing Chatblast styles use slate-200 (#e2e8f0) for every border, including the top bar's. The fixed parts use slate-200 so the lines match.
- **Page header background.** The page header and its filters sit on the page's existing light background with no band. The top bar and the column header row are white with one thin line, as the spec asks.
- **Test setup.** The worktree had no `vendor/`. A link to the main checkout's `vendor/` would load the main checkout's `app/` and views, so `vendor/` was copied in (the lock file is identical, and `vendor/` is git-ignored). All tests and the browser check above ran with the copy.

## Self-review

- Every Chatblast page has exactly one `cb-scroll`, and the test checks this. On the card pages no table sits inside the scroll box, so the sticky `thead` rule touches only the three data tables.
- The page settings diff is 5 lines: wrapper, grid class, two style lines and their comment. The grid's own JS-built header still sets `bg-gray-100` classes, but the page's existing `#grid-table thead th` rule overrides them, now to white.
- `scrollIntoView` in page settings works inside the new scroll box.

## Suggestions for the owner (not done)

- If History must also fit at 1280 px without the inner sideways scroll, the Status badge could wrap, or the Sent column could show the time only. That is a table-style change, which this spec kept as it was.
- The Page settings help paragraph is five lines and stays in the fixed part (unchanged by amendment 1, which lists Page settings' fixed part as it was), which leaves about 310 px of grid at 1280 × 720. Folding it under a "How to use" toggle would give the grid more room.

## Amendment 1: a short fixed block, Test PSID in a modal, a Ready chip

One more slice, medium risk, frontend (commit `b2a09a87`). Display only, apart from the new chip's filter, which runs in the browser on data the grid already has.

### What shipped

- **To send and History** (`resources/views/chatblast/index.blade.php`). The fixed part is now: top bar, title with its description, one filter row and one chip row. Only the table rows scroll.
  - **Filter row:** From and To (History only), Page, Sequence, Search, Show. The action buttons sit at the right end of the same row: Send all ready, Send selected and Refresh now on To send; Retry failed on History. The old separate action row is gone.
  - **Chip row** (`data-cb-chip-row`), left to right:
    - the status chips;
    - on To send, "Ready per sequence" and "Ready per page" as two small one-line texts that cut off with "…" and show the full text as a tooltip;
    - at the right, in small text, the "Sending: running · in queue · sent in the last hour" status, the selected page's Test PSID owner, and Pause or Resume;
    - a small "Test PSID" button at the right end.
  - **Test PSID:** the disclosure is gone. The button opens a centred modal with the same picker (page, name search, current PSID, results; the same element ids, so the existing script is unchanged). The modal closes with ✕, Escape or a click outside, and the search box gets focus when it opens.
  - **Under the table:** the action message (`#notice`, e.g. "12 queued." or the fetch progress) and To send's "Updated … · next refresh …" now sit on the pager row. They needed a place outside the two fixed rows, and the pager row was already there, so this adds no height.
- **Layout** (`resources/views/layouts/chatblast.blade.php`): tighter page header on every Chatblast page. Main padding is `py-4` instead of `py-6`, the header block's bottom margin is `mb-3` instead of `mb-5`, and the description sits `mt-0.5` under the title instead of `mt-1`.
- **Page settings** (`resources/views/page_settings/index.blade.php`), one line: `['ready', 'Ready', r => borderOf(r) === 'border-l-green-500']`, right after `Pages`.
  - Ready is defined as "the row gets the green border", so the chip and the border can never disagree.
  - Green means: has orders, token status ok, page ID, token, shipping flow, message field and template all present, template approved, and last sync not failed.
  - It uses the existing chip logic: click to filter, click again to clear, and it combines with the name search.
- **Messages and Sequences:** no change was needed. The header and filter row were already fixed and the cards already scroll.

### How the fixed block measures (headless Chrome, scratch app)

| Page | Width | Filter row | Chip row | Gaps title→description / filters→chips / chips→table | Table starts at |
|---|---|---|---|---|---|
| To send | 1366 and 1280 | 55 px, one line | 32 px (the two small Ready lines) | 2 / 12 / 12 px | 250 px (was 414 px) |
| History | 1366 and 1280 | 56 px, one line | 22 px | 2 / 12 / 12 px | 241 px |

- At 1366 × 768 that leaves 10 table rows fully visible on both pages, where before the fixed part took 414 px.
- After scrolling the rows, the chip row and the column header stay put.
- There is no page h-scroll at either width.

### Case table (amendment 1)

| Case | Test | Result |
|---|---|---|
| S-060.4 | `ChatblastAppTest::test_S_060_4_the_chip_row_holds_the_status_and_a_test_psid_button_that_opens_a_modal` (to send, history) | Pass |
| S-060.5 | Browser check | Pass: on both pages at both widths, the modal opened with the search box focused and closed by ✕, by Escape and by a click outside. A search inside the modal called the endpoint and showed its answer. |
| S-060.6 | `PageSettingsGridTest::test_S_060_6_a_ready_chip_right_after_pages_counts_the_pages_with_the_green_border` and the browser check | Pass: the scratch data had 6 pages (3 complete and ok, 1 with an invalid token, 1 without a template, 1 without orders). The chip read "Ready 3", and the 3 pages it showed were exactly the 3 with the green border. With "Page 2" in the name search, only Sample Page 2 showed. A second click brought back all 6. |

Red run, before the change: S-060.4 failed in both data sets at "matches PCRE pattern #data-cb-chip-row.*id="chips".*id="state".*id="test-psid-open"#s". S-060.6 failed because the page had no `['ready', 'Ready', …]` chip line.

Tests after the amendment (`php artisan test tests/Feature/Chatblast tests/Feature/PageSettings`): 305 tests, 2235 assertions, 0 failures. The warnings are the same doc-comment deprecation as before. `php -l` reports no syntax errors on the changed files.

The original scroll check was run again after the amendment: every page at both widths still keeps the top bar, header and controls in place, scrolls only the data, has no page h-scroll, and the row modal opens and closes. No JS errors.

Screenshots: main checkout `handoff/060-chatblast-sticky-header/screens/amendment-1/`.
- `to-send-<width>.png`, `history-<width>.png` and their `-scrolled` versions.
- `to-send-test-psid-modal.png`, `history-test-psid-modal.png` and `to-send-test-psid-search.png`.
- `page-settings-ready.png` and `page-settings-ready-search.png`.
- `messages-1366.png` and `sequences-1366.png`.
- `evidence-a1.txt`.
- `scroll-check/`, the rerun of the original check.

### Story changes (amendment 1)

Added section S-060A with S-060.4 (the fixed block on To send and History), S-060.5 (the Test PSID modal) and S-060.6 (the Ready chip).

### Notes (amendment 1)

- **Where the action buttons and the action message went.** The amendment names only the filter row and the chip row as the fixed block, and asks for no extra rows.
  - The action buttons (Send all ready, Send selected, Refresh now, Retry failed) moved to the right end of the filter row.
  - The action message and "Updated … · next refresh …" moved to the pager row under the table, which was already fixed.
  - The action message is therefore shown below the table rather than beside the buttons. If the owner wants it next to the buttons, it can move into the filter row. Long messages, like the fetch progress, would then wrap that row.
- **Ready per sequence / per page.** These stay visible but take one line each and cut off with "…", with the full text in a tooltip. With many pages, the per-page line used to wrap over several lines of the fixed block.
- **Test PSID owner of the filtered page** stays visible in the chip row's right side. With a page selected, a long page name can push the chip row to a second line at 1280 px.
- **Merge with feat/057:** this amendment adds one more line to `resources/views/page_settings/index.blade.php`, the Ready chip, inside the `CHIPS` list.

## Amendment 2: History lists the latest processed first

One slice, medium risk, backend (commit `fix: history lists the latest processed first (spec 060)`).

### What shipped

- `app/Http/Controllers/ChatblastController.php`: `list()` now sorts through a new private `ordered()`.
  - **To send:** unchanged, `id` descending.
  - **History > Sent** (sent and "Sent (unconfirmed)"): `sent_at` descending, then `id` descending. Rows without `sent_at` go last. MySQL and SQLite already sort NULL last in a descending order; pgsql gets `sent_at DESC NULLS LAST`, following the project's mysql/pgsql branching for raw SQL.
  - **History > Failed, Skipped, No PSID, No config:** `updated_at` descending (when the row was processed), then `id` descending.
  - The counts, the date range filter and Retry failed are unchanged.

### Indexes (no migration added)

- **Sent:** the existing single-column `sent_at` index matches the order. On InnoDB a secondary index also holds the primary key, so a backward scan of `sent_at` gives `sent_at DESC, id DESC` without a sort.
  - There is no `(status, sent_at)` index. The status and date filter is checked on the rows the scan reads.
  - I did not run EXPLAIN against MySQL, so whether the optimizer picks this index on production data is not verified.
- **Other chips:** no index fits `updated_at DESC` across pages. The only index with `updated_at` is `(page_name, status, updated_at)`, which helps only when a page is filtered.
  - Without a page filter these chips sort their filtered rows in memory. That is the day's (or the From–To range's) failed, skipped, no PSID or no config rows, normally a small set.
  - As the amendment asks, no index was added.

### Case table (amendment 2)

| Case | Test | Result |
|---|---|---|
| S-060.7 | `ChatblastToSendTest::test_S_060_7_history_lists_the_latest_processed_first` (sent, failed) | Pass |

- **Sent data set:** a row built 3 days ago and sent at 09:30 is listed before a row built 1 day ago and sent at 08:00. Of two rows sent at 09:30, the higher id comes first. An unconfirmed row without `sent_at` comes last.
- **Failed data set:** rows are ordered by `updated_at` descending, then `id` descending.
- **Red run:** both data sets failed with "Failed asserting that two arrays are identical." (the list was in `id` order).
- **Chatblast tests** (`php artisan test tests/Feature/Chatblast`): 189 tests, 1252 assertions, 0 failures. The warnings are the same doc-comment deprecation as before. `php -l` reports no syntax errors on the controller.

### Story changes (amendment 2)

Added section S-060B with S-060.7.
