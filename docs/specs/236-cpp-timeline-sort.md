# Spec 236: sort the table in the CPP timeline snapshot popup

**Project:** Likha (the same code runs incepxion.pro). Page: /ads_manager/cpp/timeline (resources/views/ads_manager/cpp_timeline.blade.php, Alpine.js). **Shape:** change · **Weight:** bounded · **Risk tier:** low

## Why
Clicking a cell on the timeline opens a popup titled like "Nov 5, 2026 · 7PM Snapshot", around line 303 of the view. Its table can't be sorted. Spec 230 added header-click sorting to the three CPP tables with public/js/cpp-sort.js. The owner wants the same in this popup.

## Decisions already made
| # | Decision |
|---|---|
| 1 | Every column of the popup's table sorts by a header click, with the same rules as spec 230: the first click sorts numbers highest first and text A→Z; the second click reverses; the third goes back to the original order; ▲ or ▼ comes from `aria-sort`; ₱, comma and % values sort as numbers; empty or "—" cells go last; a TOTAL row stays at the bottom; any child rows stay with their parent. |
| 2 | Reuse public/js/cpp-sort.js (extend it if needed; keep the three CPP tables working). |
| 3 | Opening another snapshot starts unsorted. The sort is kept only while the popup is open. |
| 4 | Headers are keyboard reachable (Enter or Space), as in spec 230. |

## Stories
Add these under `## Slice 236 – Sort the CPP timeline snapshot` in qa/stories.md, with one test per case, run the way tests/Feature/CPP/CppSortTest.php runs the script:
- S-236.1.1 happy: the popup's numeric column → highest first, then reversed, then the original order.
- S-236.1.2 happy: a text column → A→Z.
- S-236.1.3 edge: the TOTAL row stays last; empty cells go last.
- S-236.1.4 edge: opening a new snapshot → unsorted.
- S-236.1.5 happy: the spec 230 CppSortTest still passes.

## Done when
- [ ] The cases pass, and the CPP tests pass.
- [ ] The full suite adds no new failures (known: ExampleTest, ItemTableFitScriptTest; BigBroadcastTest and AstraAddressRulesTest S-26.18 are flaky).
- [ ] The result is filled in.
