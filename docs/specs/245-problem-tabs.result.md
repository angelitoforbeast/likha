# Result 245: the problems banner splits its list into Red, Orange and White tabs

## Plan

One task, risk tier low (kept from the spec). View-only: `resources/views/layouts/chatblast.blade.php`; `ProblemsPanel::panel()` is not touched.

**Where the code is today (cf1dd382)**

- The banner is one `<div data-alert="problems">`: the toggle button (the collapsed line), then `<ol id="cb-problems-list">` with one `<li data-page data-level>` per page and one `<li data-level data-kind>` per line, then the "Acknowledged and done" `<li data-acked>` and the doubles help, then `#cb-problems-actions`. The script opens and closes the list (remembered in `localStorage`, in try/catch) and handles Acknowledge / Done / Undo with one click listener on the banner.
- The existing tests read the banner from `data-alert="problems"` to the first `</ol>`; two of them (`PageProblemsTest`, `SetupProblemsBannerTest`) compare its whole text.

**Change (smallest)**

1. The top `@php` block counts the lines per level (`red`, `orange`, `white`, and `all`) and picks the open tab: Red, else the first level with lines, else All (only acknowledged lines left).
2. A `role="tablist"` row of four buttons ("🔴 Red (N)", "🟠 Orange (N)", "⚪ White (N)", "All (N)") sits between the toggle and the list, hidden and shown with the list. Each tab has `role="tab"`, `aria-selected`, `aria-controls="cb-problems-list"` and `data-tab`; a level tab with 0 lines is `disabled` and dimmed. The list gets `role="tabpanel"`.
3. One list stays (no copies, so every link, button and data attribute is the same element in every tab). The server renders the open tab already applied: a line of another level and a page with no line of that level get `hidden`. The acknowledged section and the doubles help are never filtered.
4. The script switches tabs by the same rule (a line is shown when the tab is All or its `data-level` is the tab; a page is shown when one of its lines is shown) and remembers the tab in `localStorage` `cb-problems-tab` (try/catch; a stored tab that is unknown or now disabled is ignored, so the server default stays).
5. The collapsed line is not touched.
6. The two text-comparing test helpers drop the tab row before reading the text, so their expected texts stay as they are.

**Tests** (new `tests/Feature/Chatblast/ProblemTabsTest.php`, the setup of `ProblemsPanelTest`: red, orange and white lines on several pages)

| Case | Type | Given / When / Then |
|---|---|---|
| S1.1 | happy | Tabs in order with counts of lines per level and All = every line; Red selected, the others not |
| S1.2 | happy | Server-rendered Red tab: only red lines visible, grouped under their pages in `panel()` order; pages without a red line hidden |
| S1.3 | happy | Orange and White tabs by the script's rule over the markup: only that level, pages in order, pages without it absent |
| S1.4 | happy | All tab: the same pages and lines as today's list (same parse as the existing tests) |
| S1.5 | edge | A tab with 0 lines is `disabled`; no red lines → Orange opens; only white lines → White opens |
| S1.6 | happy | Acknowledged section, links, Acknowledge/Done buttons and their data attributes unchanged and never hidden by a tab |
| S2.1 | happy | Collapsed line text and colours unchanged (red when urgent, slate when not) |
| S3.1 | script hook | `localStorage` key `cb-problems-tab` read and written inside try/catch; disabled tabs skipped. No browser test in this repo, so S3 is proven by the markup and script only |
| E1 | edge | No problems → no banner and no tab row |

## Done-when evidence

## Story changes

## Files changed

## Head commit

## For the reviewer before release
