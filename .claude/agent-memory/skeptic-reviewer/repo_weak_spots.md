---
name: repo-weak-spots
description: Likha repo weak spots and recurring review findings to re-check first (item/quotes, HOLD, supply POs, specs)
metadata:
  type: project
---

Weak spots found in reviews (first seen in handoff 001 spec review, 2026-10-01). Check these first.

- ItemController::quoteSave writes `field => isset(...) ? ... : null`, so a field a caller doesn't send gets wiped (note already is). Two pages call it with JSON (`item/index`, `item/photo`): any new quote column must keep its value when absent.
- Quote/supplier endpoints are shared by /item and /item/photo; /item/data feeds /item/photo too. A change to one shows up on both pages.
- HOLD SQL uses MySQL `STR_TO_DATE`, which sqlite tests can't run. Check that a spec doesn't claim test coverage of that path, and that `unitsByBaseItem` (snapshot) stays untested on sqlite.
- supply_orders status is ordered|delivered|counted, and a cancel is a hard delete. "Open = ordered" means delivered or counted stock isn't counted, so check any "need to order" logic against stock on hand.
- Specs can point at a section that isn't there (e.g. "question 2"). Grep every cross-reference in a spec.
- Base-item key: three PHP copies (keyFor, HoldService::itemKey, SupplyFinanceController::itemKey, which doesn't strip `N x`) plus JS supKey. A join across them needs one function on both sides.

- ItemTestCase harness lists real migration paths, including ones owned by later tasks (quote_history). Check each task's commit still runs its tests alone; a first WorklistTest run once failed in Migrator, then passed.
- On this box `php.bat artisan test --filter="A|B"` breaks (cmd treats `|` as a pipe); run filters one at a time.
- Quote history (T5): quoteSave's absent-field wipe still applies, so a save that omits moq/link counts as a change and writes a history row; history tests cover only the happy table, not table-missing, updated_by/quoted_at on delete, or A-B-A price.
- File uploads on /item: the failure-cleanup path (delete new file when the tx fails) and isolation from other files usually go untested; UploadedFile::fake() reports mime from the filename, so it can't prove content sniffing.
- Bash may be denied to the reviewer; Read/Grep plus `artisan test --filter=X` still work.

**Why:** these produced findings in the 001 spec review and are likely to recur in follow-up handoffs on /item.
**How to apply:** in any /item, quote, HOLD or supply review, check these before reading the rest of the diff.
