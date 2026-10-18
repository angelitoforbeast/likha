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
- The Page settings help paragraph is five lines and stays in the fixed part, which leaves about 310 px of grid at 1280 × 720. Folding it under a "How to use" toggle would give the grid more room.
