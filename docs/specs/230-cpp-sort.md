# Spec 230: sort the CPP tables by clicking a column header

**Project:** Likha (Laravel; the same code runs incepxion.pro). Page: Ads Manager → CPP (`/ads_manager/cpp`), view resources/views/ads_manager/cpp.blade.php.
**Shape:** change · **Weight:** bounded · **Risk tier:** low (read-only page, client-side only)

## Why
The CPP page has three tables, built in JavaScript in cpp.blade.php:
- by page: `#summaryOfAdsTable`, around line 488;
- by date: around line 622;
- by date with items: around line 707.

Their column headers are plain text. The owner wants to click a header to sort the rows, for example to see the highest CPP or the biggest spend first.

## Decisions already made (settled with the owner; don't reopen them unless something is actually broken)
| # | Topic | Decision |
|---|---|---|
| 1 | Which tables | All three CPP tables, every column. |
| 2 | Clicks | First click sorts descending for number columns (highest first) and A→Z for text. A second click reverses it, and a third goes back to the original order. The header shows ▲ or ▼ on the sorted column only. |
| 3 | Values | Numbers sort as numbers, using the value behind the cell, not the formatted text: ₱1,200 is above ₱900, 12% above 9%. Empty or "—" cells always go last, in either direction. Dates sort by date. |
| 4 | Rows that stay put | The TOTAL row stays at the bottom. In the by-page table, the ad group rows under an ad-based page move with their page and keep their own order under it. Ad group rows are never sorted on their own. |
| 5 | Re-render | When the page re-renders a table (date change, page filter, item filter), the chosen sort is applied again. It is kept per table for the visit only; nothing is saved. |
| 6 | Copy | "Copy Table" and "Copy with ad groups" copy the rows in the order shown. |
| 7 | Look | The header is clickable (cursor pointer, keyboard reachable with Enter or Space, `aria-sort` set). The design is unchanged otherwise. |

## Stories
Add these to qa/stories.md under `## Slice 230 – Sort the CPP tables`, in that file's format, with one test per case named with its case ID. The page is JavaScript, so test it the way the existing CPP tests do (tests/Feature/CPP). If those tests only read rendered HTML, put the sort logic in a small function and test it with a Node test (`node --test`), then assert in a PHP feature test that the page includes it and wires the headers.

S-230.1 Click a header to sort (P1)
- .1 happy: by-page table, click "CPP" → rows highest CPP first, ▼ shown; click again → lowest first, ▲; click a third time → original order, no arrow.
- .2 happy: "Page Name" → A→Z, then Z→A.
- .3 happy: by-date tables, "Date" → newest first, then oldest first.
- .4 edge: ₱ and comma amounts and % values sort as numbers, not text.
- .5 edge: empty or "—" cells are last in both directions.

S-230.2 Rows that must not move wrongly (P1)
- .1 happy: the TOTAL row stays last after any sort.
- .2 happy: ad group rows stay under their page when pages are sorted.
- .3 edge: a re-render (date or filter change) keeps the chosen sort.
- .4 edge: Copy Table copies in the shown order.

S-230.3 Keyboard and screen reader (P2)
- .1 happy: Tab to a header and press Enter or Space → it sorts; `aria-sort` is ascending or descending on that header only.

## Constraints
**Threat model.** Low tier: no new input; the page already holds the data. Don't change any server query.

**Rules**
- Don't start other Claude Code sessions.
- Run the CPP tests and the whole suite once at the end. Name any failure that was already red on b26149a5 as pre-existing.

## Budget
- Attempts: at most 2 tries at the same step, then stop and report.
- Size: small.

## Done when
- [ ] Cases S-230.1 to S-230.3 pass, each with a test named after it.
- [ ] The full suite adds no new failures compared with b26149a5.
- [ ] qa/stories.md has slice 230.
- [ ] The result is filled in.

## Out of scope
Other Ads Manager pages, cpp_timeline, server-side sorting, saving the sort, and deploying (the reviewer releases it).

## Report back
Fill in docs/specs/230-cpp-sort.result.md, including every ruling (`Ruling: <decision> — <why> — <cost if wrong>`). Then end with the "230:" line.
