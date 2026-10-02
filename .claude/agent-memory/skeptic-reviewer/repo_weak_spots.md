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

- 003 stock endpoint (ItemStockService): tests pin worked examples only; Mira's rulings (discount line, NULL submission_time, counted_at<START, delivered status, custom lead/safety, order_qty with floored stock) tend to have no test. Check each ruling for a test that fails if the branch is deleted.
- Float DOI: doi = n/(u/d) is not exact, so floor(doi-lead) and doi<lead can be off at boundaries while order_qty is round()ed first; DOI shown rounded to 0.1 but colour uses unrounded.
- Developer drafting service before tests (red run = only 404): confirm by mutation thinking, not by the red run.
- 003 T3 write endpoints: "update every matching row" tests insert only ONE row (can't catch first-id-only); unique-name races and MySQL ci/accent-insensitive unique vs PHP mb_strtolower give uncaught 500s on insert (CEO-only, minor); `$request->has('x')` is true for ""/null after TrimStrings/ConvertEmptyStrings, so "empty field = absent" logic 422s.
- ItemBaseKey::key and ItemSupplierQuote::keyFor are now copy-pasted regexes pinned only by a unit test; parse()'s base regex (`.+`) differs from key()'s on names like "2 x".
- 003 catch-QueryException-on-unique fallbacks (categorySave, supplySettingsSave): tests do a sequential double submit, so the catch branch itself is never executed; on MySQL REPEATABLE READ the fallback re-SELECT may not see the racing commit (gives a retryable 422, not data loss).
- 003 suggest-categories: substring keywords ("oil" in toilet, "led" in filled, "cap" in capacity) are spec-mandated; --apply loops inserts with no transaction/guard for item_key > 190 chars (macro ITEM_NAME is 255).
- 003 /item page (T5): ItemPageTest is string-grep on rendered HTML only, so JS helpers (doiText, orderByText, null safety, stale guard) are never executed; check any new Alpine logic for a null that `num()` turns into "0" (num(null)="0") and for the 4-place column registration.
- 003 /item/stock map keys come only from hold/demand/received/incoming/left, and `values` only from raw names with orders in [start,end]: items outside those sets show "—" for category/stock/ITEM VAL on the page even if they have a category or cogs row.
- 003 T6 inline edits: JS payload builders use Number(x) so a cleared number input sends 0 (Number('')=0); worklist.list comes from ?list= even for non-CEO, so "list === 'lahat'" guards on new empty-rows silently skip marketing; select keeps the unsaved choice after a failed POST; Enter handlers skip the `saving` guard.
- 003 /item/stock values: after fix loop 1 keys = order names + raw cogs/cogs_ceo names; a page item whose name is only an item_type_mappings alias of the cogs name (no orders in range) still has no values key. CEO-gate test never uses a cogs_ceo-only name for a non-CEO viewer.
- 004 ItemLifecycle: classify's hasOldOrders param is unused (both copies); test table always passes true. Without Bash, "verbatim move" can't be verified from git: ask the main session for the diff.

- 004 T2: owner_private_cols_default (Save-as-default snapshot) is not rewritten by column-config migrations, so Reset-to-default can bring a hidden column back; any new supply_settings group shows up automatically in /jnt/supply "Other Settings" table (filter is a group blacklist).

- 004 T3 ItemStockService: unknown lifecycle_override string, doi_note kept when stock_ready=false, near-zero note on non-active lifecycles, and the 12 h first-date cache hit path (cached value wins over window_first) have no test; Scaling with 0 units in last 7 days (natural scaling is possible) gets v=0 → no doi, no note, no colour. Per-request Schema::hasColumn x2 + hasTable add uncached metadata queries beyond the "+2" claim. Cache key embeds request Host (any authed user can mint entries; minor).

- 004 T4 /item page: set choice, baseProfitPct7 memo (bp7Cache keyed on this.rows identity; rows only replaced at loadItems, so OK), sort and DOI texts are verified only by string-grep of the Blade; spec lines the dev quietly softens ("filter resets to Lahat" became "ignored while hidden") need checking word by word. Tooltip lifecycle rules are hard-coded (30/90/14 days) while the thresholds are settings.

- 005 T2: Alpine :href/:attr built from window.location (viewSwitchUrl) is not reactive to history.replaceState done by load()/setWorklist, so links go stale; ItemPageTest renders the view directly, so controller query mapping (?layout=old) has no test. Reviewer Bash may be denied: verbatim-move check falls back to diff hunk counts.

- 005 T3 new /item row: ItemPageTest is string-grep only, so ilAabot/ilPill/ilDays/ilUrgency are never executed; header sort keys must exist in `_itemSortValue` (action_at falls to default and returns null = no-op sort); server rounds units_per_day to 2 dp so JS reason text ceil(upd*days) can differ from order_qty; ilDays(9.96) prints "10.0".

- 005 T4 expanded block: ItemPageTest still string-grep only (ilPageVal never executed); shared campaigns include keeps 9-11px/#94a3b8 text under .il-camp !important 11px, below the handoff's ">=12 px body / AA" rule; overflow-x:visible with overflow-y:auto computes to auto (scroll can reappear only if content overflows; fixed layout prevents it); Number(null)=0 pattern again in the "Benta/araw" duplicate (units_per_day null prints 0.0); il_action_at sort ignores the non-empty-comment rule that ilLatestAction uses.

- 005 T5 widths/TOTAL: col-width budget in spec §12 matches CSS (sums 1,152/1,040/820 verified); remaining overflow risk is `white-space:nowrap` (.il-nb) text in fixed narrow cells (STOCK 104px "Hindi pa nabibilang ·" overlaps next cell, no scrollbar) and 11px bold th text in 72px col breaking mid-word; ilTotVisible()/itemGroups() re-run (sort + aggOf per item) ~15x per Alpine pass. Breakpoint/stack/card behaviour has only string-grep tests.

**Why:** these produced findings in the 001 spec review and are likely to recur in follow-up handoffs on /item.
**How to apply:** in any /item, quote, HOLD or supply review, check these before reading the rest of the diff.
