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

**Followed as planned**, with one addition: `PageOwnersScreenTest` had three greedy `((?!…)[\s\S])*` order checks. With the longer layout script, one of them ran out of PCRE JIT stack (`preg_match` returned false, "JIT stack limit exhausted", although the card sits 274 characters after its group). The three are now lazy and non-capturing, `(?:(?!…)[\s\S])*?`, which means the same thing.

## Done-when evidence

**How the tests were run.** `vendor/` in this worktree is a junction to the main checkout's `vendor/`. Composer resolves `App\`, `Database\` and `Tests\` from the vendor folder's real path, and `Application::inferBasePath()` does the same, so a plain `php vendor\bin\phpunit` here ran the **main checkout's** app, tests base classes and views, not this branch. Every run below therefore used a run-only bootstrap kept in the scratchpad (not committed), which requires `vendor/autoload.php`, sets `APP_BASE_PATH` to this worktree and prepends a PSR-4 loader for `App\`, `Database\Factories\`, `Database\Seeders\` and `Tests\` from this worktree:

`cmd //c "php vendor\bin\phpunit --colors=never --bootstrap <scratchpad>\wt245-bootstrap.php [path]"`

1. **Feature tests prove S1, S2 and the edges; S3 by the script hook.** `tests/Feature/Chatblast/ProblemTabsTest.php`: `OK (9 tests, 97 assertions)`. Written first and committed red (`95aa434f`, 7 of 9 failing), then the layout (`69b41771`). There is no browser test in this repo, so S3 is proven by the markup and script hook. It was also tried once by hand: a page rendered by the test with a faked panel, served from the scratchpad, in a browser. Collapsed: list and tab row hidden. Opened: Red tab showed `Page Uno:red, Page Dos:red`; Orange `Page Uno:orange, Page Tres:orange`; White `Page Tres:white, Page Kwatro:white`; All every line; the acknowledged section was shown in every tab. Picked White, reloaded: White still open, `localStorage['cb-problems-tab'] = 'white'`.
2. **No new failures.**
   - Baseline: the docs-only commit `80b13177`, through the shim. `Tests: 4737, Assertions: 78348, Failures: 2, Skipped: 6`: `ExampleTest` and `ItemTableFitScriptTest` (the 2 known).
   - After: `f6413ac8`, through the shim. `Tests: 4746, Assertions: 78445, Failures: 2, Skipped: 6`: the same 2 known (`ExampleTest`, `ItemTableFitScriptTest`). The 9 new tests account for the +9.
   - `tests/Feature/Chatblast` after the fix: no failure under `tests/Feature/Chatblast` in the full run above. The folder run before the regex fix had `Tests: 2107, Failures: 2`: `PageOwnersScreenTest` S_170_10 (the JIT stack, see Plan) and a draft of `ProblemTabsTest` that was edited during the run. After the fix, `PageOwnersScreenTest` gave `OK (52 tests, 262 assertions)`.
   - For the record, the first baseline without the shim (the main checkout's code) gave `Tests: 4737, Failures: 4`: the 2 known plus 2 `SharedPagesBasePinTest` cases. Those belong to that checkout, not this branch.
3. **No migration.** `git diff --stat cf1dd382..HEAD -- database` is empty.
4. **Pushed.** Branch `feat/245-problem-tabs` on `origin`; head commit below.

## Story changes

New slice 245 in `qa/stories.md`: S-245.1 (6 cases), S-245.2 (1), S-245.3 (1), all passed on 2026-11-08. No existing story changed.

## Files changed

- `resources/views/layouts/chatblast.blade.php`: tab counts and the open tab in the top `@php` block; the `role="tablist"` row; `role="tabpanel"` and the server-applied `hidden` on lines and pages of other levels; the tab script with `localStorage` `cb-problems-tab`; the tab row opens and closes with the list.
- `tests/Feature/Chatblast/ProblemTabsTest.php`: new, 9 tests.
- `tests/Feature/Chatblast/PageProblemsTest.php`, `tests/Feature/Chatblast/SetupProblemsBannerTest.php`: the text helpers drop the tab row (`<div id="cb-problems-tabs">`), so the expected list texts are unchanged.
- `tests/Feature/Chatblast/PageOwnersScreenTest.php`: three order regexes made lazy (see Plan).
- `qa/stories.md`: slice 245.
- `docs/specs/245-problem-tabs.md`, `docs/specs/245-problem-tabs.result.md`.

## Head commit

`ae5c9ffc` (code and evidence; this line is added by the commit after it, "docs: head commit in the spec 245 result").

## For the reviewer before release

- **Test runs in a worktree with a junctioned `vendor/`** test the other checkout unless the bootstrap shim is used (see Done-when 2). That affects every worktree set up this way, not only this branch.
- One list, filtered by `hidden`, not four copies. Every Acknowledge/Done/Undo button and link is the same element in every tab, and the one ack form still moves under the clicked line. If the form is open and the user switches to a tab that hides that line, the form is hidden with the line and is back when the tab is picked again.
- All is never disabled, even at 0. It is the open tab when only acknowledged lines are left, so the "Acknowledged and done" section is still reachable.
- Keyboard: the layout handles no keys elsewhere, so the tabs are plain buttons (Tab and Enter/Space), as the spec allows. There is no left/right arrow handling.
- `/chatbot/inbox` has its own `role="tab"` buttons (`.ib-tab`). The banner script selects only `#cb-problems-tabs [role="tab"]`, and the inbox script selects only `.ib-tab`, so they do not clash.
- Screenshot: a phone-width view of the White tab was taken in the browser pane. It is not committed.
