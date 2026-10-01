# Handoff 002: Worklist chips filter the existing item table

**From:** Mira · **To:** Claude Code · **Approver:** Busing (Angelito Forbes, CEO)
**Project:** Likha AI Tech · **Weight:** bounded · **Shape:** change · **Risk tier:** medium (CEO-only view, no new inputs)

## 1. Context

Handoff 001 is live on likhaaitech.com. Busing opened `/item?start_date=2026-09-01&end_date=2026-10-01&list=hanapan` and said: "paano yung mga walang running ads? hindi makita, bat nawala yung mga columns na need ko makita?"

When a list chip is selected, the page swaps the full item table for a simple ITEM / HOLD / SUPPLIERS / STATUS / ACTIONS table. The table he works from disappears: PAGE, PROMO, PRICE, NP/O(1M), ADSPENT, ORDERS (1D), PROF.PROFIT (1D), ITEM VAL., CPP, SET RTS%, RTS/DEL/INT, TCPR, BREAKEVEN CPP, PROF.PROFIT, PROF.% (1M/7D/3D/1D), HOLD and ACTION. The "walang running page" and other warnings in the item cell disappear with it.

His original request was "filter or sort", and 001 got that wrong. The live check also found the lists take about 10-20 s to appear ("Loading…").

## 2. Goal

Selecting a list chip filters the existing Lahat table in place: same columns, same row rendering, same warnings, same actions, same sort controls. Only the rows whose item belongs to the selected list are shown. Each row's item cell also shows that list's extra information. The lists appear noticeably faster.

## 3. Decisions already made

| Topic | Decision |
|---|---|
| Table | One table. The chips are a filter on it. Remove the separate simple worklist table. |
| Membership | A row is shown when its item's base key (`ItemSupplierQuote::keyFor`, `N x` stripped) is in the selected list from `/item/worklist`. The "1 x" and "2 x" rows of one product both show. Each keeps its own HOLD, and the item cell shows "kabuuan: N" (base total HOLD units) when the base has more than one variant. |
| Hold-only items | Items with HOLD but no running page ("walang running page") must show in the filtered table exactly as they do in Lahat, with their warnings. |
| Extra info in the item cell, only while a list is selected | **Hanapan:** nothing extra (the existing "wala pang supplier" warning is enough). **May quote:** the quotes with latest price and "dati ₱X (date)" (already on the quote line). **I-order na:** the shortfall. **Naka-order:** the open PO (supplier, date, ordered, dumating, hinihintay, araw na, lead time). Reuse what 001 built; just render it in the existing cell. |
| Sort | The default sort for a selected list is HOLD descending. The table's existing sort controls still work. |
| Counts, `?list=`, CEO-only | Unchanged from 001. |
| Out | Team access, AI search, Supply Finance PO status changes (separate question to Busing), snapshot HOLD. |

## 4. Requirements

1. Filter the existing Alpine table by list membership. Keep every existing column and cell. Lahat stays exactly as it is.
2. Show the extra info per the table above inside the existing item cell, escaped, `x-text` only.
3. **Speed.** Find why `/item/data` + `/item/worklist` take 10-20 s on production.
   - Candidates: all PO lines loaded into PHP, the worklist recomputing HOLD separately from `/item/data`, unindexed joins, the page waiting for both requests in sequence.
   - Fix what is small and safe, for example fetching the worklist in parallel with the data, and selecting only needed columns.
   - Report the rest with exact SQL so Mira can EXPLAIN on production. No schema change without asking.
4. Tests:
   - Keep 001's tests green.
   - Update `ItemPageTest` for the single-table markup. If the filtering logic can be a small JS function, test it the way the repo can (there is no JS test runner), or state that it isn't tested.

## 5. Done when

- [ ] `php.bat artisan test` green apart from the known ExampleTest failure; `php.bat -l` clean; `npm run build` OK.
- [ ] The diff shows each of these:
  - one table, with chips filtering rows by base-key membership
  - all original columns and warnings present when a list is selected
  - per-list extra info in the item cell
  - "kabuuan" for multi-variant bases
  - the speed changes, and the remaining speed findings with SQL in RESULT.md
- [ ] skeptic-reviewer ran (Spec / Correctness / Declined to judge) and RESULT.md is filled in. All commits on `fix/worklist-filters-table`, clean tree.

## 6. Budget

2 attempts per step; size small-medium. Stop and report if the existing table's data can't be filtered by base key without changing `/item/data`'s contract.
