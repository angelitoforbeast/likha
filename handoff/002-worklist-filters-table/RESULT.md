# Result 002: Worklist chips filter the existing item table

Status: **done, waiting for Mira's review of branch `fix/worklist-filters-table`** (cut from `develop` at `58cd73c`). There's no PR because `gh` isn't installed, so this file stands in for the PR body. Nothing was pushed or merged.

## Summary

- **One table.** The separate worklist table from 001 is gone. Selecting a chip filters the existing Lahat table in place, with the same columns, rows, warnings, actions and sort controls.
  - A row shows when its base key (`supKey`, the same rule as `ItemSupplierQuote::keyFor`) is in the selected list from `/item/worklist` and the row has HOLD > 0.
  - Hold-only items ("walang running page") show exactly as they do in Lahat.
  - "1 x" and "2 x" rows of one product both show, each with its own HOLD.
- **Item cell (CEO only, only while a list is selected):**
  - "kabuuan: N" when the base has more than one variant
  - I-order na: "Kulang N — i-order na"
  - Naka-order: the open PO (supplier, date, "+K pa", ordered · dumating · hinihintay, days waiting / lead time, in red when over)
  - Hanapan and May quote add nothing; the existing warning and quote line (with "dati") already cover them.
- **Sort.** With no column sort chosen, rows sort by HOLD descending. A column sort Busing already chose is kept when he switches chips.
- **Speed.** `/item/data` and `/item/worklist` now start alongside the heavy `item-summary` request instead of after it, so the counts and HOLD-only rows no longer wait for it. At startup, photos, suppliers and quotes load in parallel.
- **Unchanged:** Lahat, the Marketing view, counts, `?list=`, the CEO-only gate, and every server endpoint (no PHP or schema change).

## Done-when evidence

| Done-when | Evidence |
|---|---|
| Suite green apart from ExampleTest; `php -l`; build | `artisan test` → `Tests: 1 failed, 3 skipped, 96 passed (1108 assertions)`. The only failure is the known `Tests\Feature\ExampleTest > the application returns a successful response` (302). `php -l tests/Feature/Item/ItemPageTest.php` → no syntax errors (the only changed PHP file; the Blade view isn't plain PHP). `npm run build` → `✓ built in 7.67s`. |
| One table, chips filter rows by base-key membership | `247e73c`: separate card removed, main card back to plain `<div class="card">`, `itemGroups()` calls `worklistKeep(u.name, u.hold)` (`054041c` adds the HOLD > 0 check), `worklist.byKey` lookup built in `loadWorklist()`. |
| All original columns and warnings present when a list is selected | Same `<table>`, same `displayRows()` template. The filter only removes item groups, so the columns, "walang running page" / "wala pang supplier" warnings, supplier/quote lines and actions are the Lahat markup. |
| Per-list extra info in the item cell; "kabuuan" | `900709c`: `worklistItem(name)` block in the item aggregate row's first `<td>`, wrapped in `@if($effectiveIsCEO)`, text bindings only. |
| Speed changes; remaining findings with SQL | `ff56f45`: `loadHold()` and `loadWorklist()` are called before the `item-summary` fetch in `load()`, and `init()` uses `Promise.all`. Remaining findings and the SQL are under "Speed findings" below. |
| skeptic-reviewer; RESULT filled; branch; clean tree | Review below. Commits `fa57af0..` on `fix/worklist-filters-table`; `git status` clean after the last commit. |

`ItemPageTest` (2 tests, 19 assertions) asserts in the CEO render:
- the chips, `worklistKeep(u.name, u.hold)` and the empty-list row are present
- the old worklist card and the code that hid the main card are gone
- the kabuuan and Kulang markers are present
- `loadHold()`/`loadWorklist()` appear before the `item-summary` fetch, and `init()` uses `Promise.all`

In the Marketing render it asserts that none of the chips, the empty-list row or the item-cell block appear.

**Red runs** (developer report, sonnet `frontend-developer`):
- T1: `ItemPageTest → assertStringContainsString('worklistKeep(u.name)')` failed.
- T2: the same test failed on the new `'kabuuan: '` assertion. The exact first line wasn't captured.
- T3: `test_hold_and_worklist_load_in_parallel_with_the_item_summary → Failed asserting that 174689 is less than 173735.`
- The review fix (`054041c`) changed the test assertion together with the code, so it had no separate red run.
- There's no JS runner, so the filter logic itself isn't executed by any test (see TODO.md).

## Speed findings

Production evidence from Mira (read-only, 2026-10-01):
- The worklist HOLD query returns **72 items in 0.44 s**.
- `macro_output.waybill` and `from_jnts.waybill_number` are both **utf8mb4 / utf8mb4_unicode_ci**, so the join can use `idx_from_jnts_waybill`.
- `supply_order_items` has **44 PO lines**.

**Cause:** the HOLD and PO parts aren't slow. Before 002, `load()` called `loadWorklist()` (and `loadHold()`) only after `await`ing the heavy `owner.private.item-summary` request, so the chips stayed on "…" for the whole item-summary time. After that, `init()` loaded photos, then suppliers, then quotes, one at a time.

**Fixed (small, safe):** HOLD and worklist start alongside item-summary, and the three startup loads run in parallel.

**Remaining:**
- The first rows that have page data still wait for `item-summary` itself (`OwnerPrivateController`, the owner/private aggregation). It's outside this handoff; see the suggestion below.
- SQL used for the evidence above, so it can be re-run with EXPLAIN:

```sql
EXPLAIN SELECT mo.`ITEM_NAME` AS item_name, COUNT(*) AS hold_count
FROM macro_output AS mo
LEFT JOIN from_jnts AS fj ON fj.waybill_number = mo.waybill
WHERE fj.waybill_number IS NULL
  AND NULLIF(TRIM(mo.waybill), '') IS NOT NULL
  AND (mo.`STATUS` IS NULL OR LOWER(REPLACE(REPLACE(TRIM(mo.`STATUS`),' ',''),'_','')) NOT IN ('cannotproceed','odz'))
  AND mo.ts_date BETWEEN '2026-09-01' AND '2026-10-01'
GROUP BY mo.`ITEM_NAME`;
```

## Files changed

- `resources/views/item/index.blade.php`:
  - removed the worklist table card and `worklistRows()`
  - main card un-hidden
  - empty-list row (CEO only)
  - item-cell worklist block (CEO only)
  - `worklist.byKey`
  - new `worklistItem()` and `worklistKeep()`
  - filter in `itemGroups()`
  - `load()` starts HOLD and worklist first
  - `loadHold()` stale-response guard
  - `init()` uses `Promise.all`
- `tests/Feature/Item/ItemPageTest.php`: single-table and parallel-load assertions.
- `TODO.md`: handoff 002 accepted findings.
- `handoff/002-worklist-filters-table/*`, `handoff/README.md`.

## Migrations

None. No PHP, route or schema changes.

## Rulings

- Ruling: rows with HOLD 0 are hidden while a list is selected (Mira-approved); `054041c` enforces it per row, not just per base — otherwise a "1 x" page row with HOLD 0 shows next to its "2 x" sibling that has the HOLD — cost if wrong: Busing doesn't see a zero-HOLD variant's page metrics while filtering (it stays in Lahat).
- Ruling: an existing column sort is kept across chips; HOLD desc only when no column sort is set (Mira-approved) — cost if wrong: one line to reset `sortCol` on chip click.
- Ruling: Copy all / Expand all act on the filtered rows (Mira-approved) — "Copy all" on Hanapan copies that list.
- Ruling: base-key matching uses the page's existing JS `supKey` — same rule as `keyFor`, already used for supplier and quote lookups — cost if wrong: none found; 001's key-equality test pins the PHP side.
- Ruling: built T1–T3 through the kit's `frontend-developer` (sonnet) as one run with one commit per task (all three edit the same Blade file, so they couldn't run in parallel). I made the review fixes myself because they were small (four lines) — cost if wrong: none to the code; the reviewer's findings are listed below.
- Ruling: browser check skipped — running the app reads the local `.env` database (handoff constraint) — cost if wrong: Alpine runtime issues surface in Busing's next look instead of here.

## Review findings

`skeptic-reviewer`, standard depth (sonnet), on `fa57af0..ff56f45`. Verdict: **go with changes; no blocker or major.**

### Spec

- **minor** `worklistKeep` matched by base key only, so a zero-HOLD "1 x" row showed next to its "2 x" sibling, against the approved zero-HOLD ruling. **Fixed** in `054041c` (`worklistKeep(name, hold)`: HOLD > 0 required while a list is selected).
- Otherwise the reviewer found no Spec deviations. It checked: one table, Lahat and the Marketing view unchanged, per-list cell info, kabuuan, sort kept, Copy/Expand on filtered rows.

### Correctness

- **minor** The empty row could say "Walang item…" while `/item/data` was still loading. **Fixed**: "Loading…" until HOLD has loaded too.
- **minor** The empty row and the spinner row both showed "Loading…" during the first load. **Fixed**: the empty row is suppressed while that spinner shows.
- **minor** `loadHold` had no stale-response guard, which matters more now that it runs in parallel. **Fixed** with a request counter, like `loadWorklist`.
- **minor** "No data for selected date." next to "Walang item…" when the summary comes back empty → **accepted** (TODO: cosmetic).
- **missing tests** No JS logic tests (filter, empty-row conditions); the parallel-load test checks source order only → **accepted** (TODO: no JS runner in the repo).
- Checked clean:
  - no XSS (all `x-text`)
  - single-root templates and unique keys
  - null access safe
  - `load()` firing HOLD first is safe (nothing reads `rows` before the summary returns)
  - `Promise.all` loaders are independent

### Declined to judge

- **Alpine runtime behaviour in a browser:** no browser or JS runner. Not done here (see Rulings); Busing's review on production is the check.
- **Production performance numbers:** taken from Mira's evidence above.
- **Blank item names ("—") in the worklist vs `/item/data`:** a pre-existing edge case; not changed.

## How Mira can preview it locally without touching a real database

Same as 001: **not the page**. Running the app uses the local `.env` database, and the older migrations don't run on sqlite. Safe check: `"/c/Users/Forbeast/.config/herd/bin/php.bat" artisan test --filter=ItemPageTest`. The server behaviour behind the chips is covered by 001's `--filter=WorklistTest`.

## Deploy notes for Mira

- No migrations, no new routes, no config.
- `php artisan view:clear` for the Blade change.
- `npm run build` only if production builds assets on deploy; the Vite bundle is unchanged, since the change is inline Blade/Alpine.

## Suggestions for Busing

- **The remaining wait is `item-summary`** (the owner/private aggregation behind the main table's page columns). Profiling it is a separate job; it's shared with `/owner/private`.
- 001's suggestions still stand: the snapshot HOLD rule, one shared key helper, stock on hand vs HOLD, and `quoteSave` clearing fields the page doesn't send.
