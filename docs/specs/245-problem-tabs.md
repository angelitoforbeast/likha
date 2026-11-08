# Spec 245: the problems banner splits its list into Red, Orange and White tabs

Type: change. Weight: bounded. Risk tier: low (a view on the Chatbot and Chatblast screens; a mistake hides a problem from the team). Budget: small job, at most 2 attempts per step.

## Why

The problems banner on top of the Chatbot and Chatblast screens (`resources/views/layouts/chatblast.blade.php`, data from `App\Services\Chatbot\ProblemsPanel::panel()`) opens into one list of pages, each page with lines of three levels (🔴 red, 🟠 orange, ⚪ white, `ProblemsPanel::LEVELS`). With many pages the urgent lines get buried among orange and white ones. The owner wants the expanded list split by level, with tabs.

## Stories

S1 (P1). When the banner is expanded, a row of tabs sits above the list: "🔴 Red (N)", "🟠 Orange (N)", "⚪ White (N)", "All (N)", where N is the number of problem lines of that level (All: every line). The Red tab is open by default; when there are no red lines, the first tab that has lines is open.
- Each level tab shows only the lines of that level, still grouped under their page name, pages in the existing order (`panel()` already sorts them); a page with no line of that level does not appear in that tab.
- The All tab shows exactly today's list.
- A tab with 0 lines is shown but disabled (or dimmed and not clickable).
- The "Acknowledged and done" section and every link, acknowledge button and data attribute on a line keep working the same in every tab.

S2 (P1). The collapsed banner line stays as it is ("⚠ N pages with problems (M urgent) ▾"), with the same colours.

S3 (P2). The open tab is remembered in the browser for the next page view (localStorage, wrapped so a blocked storage never breaks the page; default Red).

Edge: no problems → no banner (as today). Only white lines → the White tab opens.

## Decisions already made (do not reopen unless something is actually broken)

| Decision | Reason |
|---|---|
| Tabs per level: Red, Orange, White, plus All. | The owner asked for the urgent, orange and other levels to be separate, as tabs when expanded. |
| Lines stay grouped by page inside a tab. | That is how the team reads the list today. |
| No change to what counts as a problem, the levels, the thresholds or `panel()`'s data. | View-only change. |

## Threat model

Trusted: the panel data (built by the server). No user input besides the tab choice, which only switches what is shown.

## Constraints

- Tests first: feature tests that render a Chatbot/Chatblast page with a faked panel (red, orange and white lines on several pages) and check the tabs, counts, default tab, per-tab content, the All tab, and that the collapsed line is unchanged. Use the existing problems banner tests (`tests/Feature/Chatblast/PageProblemsTest.php` and similar) as the pattern.
- Plain JavaScript or the stack the layout already uses; no new package. Accessible tabs (role="tablist", role="tab", aria-selected, keyboard left/right if the layout already handles keys elsewhere; otherwise Tab and Enter are enough).
- English UI text. Code comments explain the reason itself. Commit messages carry no Co-Authored-By, Claude-Session or "Generated with Claude Code" lines and name no people.
- No `.env`, server or deploy changes.

## Done when

1. Feature tests prove every case of S1, S2 and the edges (S3 by a test of the markup/script hook if a browser test is not available; say so).
2. `tests/Feature/Chatblast` and the full suite add no new failures compared with the base commit (2 known failures: ExampleTest, ItemTableFitScriptTest).
3. No migration.
4. Branch `feat/245-problem-tabs` pushed to `origin`; the result lists its head commit.

## Out of scope

Changing levels, thresholds or messages; the storage banner; other screens.

## Report back

In `docs/specs/245-problem-tabs.result.md`: plan, done-when evidence, files changed, head commit, and a screenshot path if you can render the page locally (optional).
