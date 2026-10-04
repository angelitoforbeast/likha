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

- 005 fix list 1: format-helper changes (ilMoneyKita, ilPct) apply to the named cells only; the page-card path `ilPageVal` case 'proj_prof_1d' (peso()) still uses 2-dp ilMoney, and ilPct on tiny negatives (-0.04) prints "▼ −0.0%". Tests remain string-grep of the Blade.

- 006 T1 tabs: tests are still string-grep of the Blade (ilTab/setTab never executed); header sort keys `cpp`/`orders_last_day` work only via _itemSortValue's default branch (agg field); a sortCol from one tab stays active, invisible, after switching to the other tab; ilColspan/ilWatchViewport/ilTotVisible may be dead after _table_new removal (check in T6); _table_old pinned by sha1 in a test, so any later legit edit must update the hash.

- 006 T2 To order cells: ItemPageTest still string-grep (ilNext/ilDaysLeft/ilCfTint never executed); profit pesos shown under the proj_pct_7d grant only (proj_prof_7d is a separate column id); ilCfTint falls to the 15/0 default when a matched rule colour is rgb()/hsl() (regex hex|name only), against "owner rule wins"; working tree can hold the next task's tests (T3) that fail while reviewing the previous commit.

- 006 T3 sort/TOTAL: tests string-grep only (ilOrderTotal, rank sort never executed); developer claims like "an old test asserts ilDays" must be grepped (it did not; ilDays is dead); first click on rank header sorts desc = grey first, red last; TOTAL pesos silently skip red rows with no cost line (spec-accepted).

- 006 T4 Sales rows + T2 fix: tests string-grep only (ilCfTint, ilCheapQuote, ilSalesColspan never executed); TOTAL row carries tints though 005 TOTAL had "no fills" and spec §5 is silent; a quote with price<=0 now skipped by ilCheapQuote but server chip counts/old views (`q.price!==null`) still treat it as a quote; sales cells have no il-m-label, card mode is T6.

- 006 T5 details panel: tests string-grep only (ilLeadLine/ilOrderReason/ilSalesADay never executed); ilLeadLine shows trend word + "losing money" to Marketing with no lifecycle grant (same as 005), prints "(—)" when lifecycle unknown; .il-only-* CSS and the lt1366 ilColspan decrement are now dead (check T6); removed helpers were not referenced outside _il_expand/tests (grepped OK).

- 006 T6 widths/cards: tests string-grep only (CSS needles, media blocks via mediaBlock helper); widths/min-widths verified by hand (796+300, 822+300, -48 at 72px pct); `il-w-item` is a marker class with no CSS rule; card mode hides thead so sorting is unreachable below 1,100 px (inherited from 005); removed helpers/classes grepped clean in views and tests; dynamic `il-tone-`+tone has no teal producer.

- 007 night run (spec review): import jobs have tries 1, timeout 3600 and no failed() hook, so a killed job leaves its run `running`/`queued` forever; any new "one at a time" guard must say who clears a dead run on every path (Likha has no Force-stop).
- AstraEncoder: HTTP timeout 300 s x 2 attempts per post, up to 8 tool rounds per row; any job timeout or sweep threshold below ~600 s kills the worker before post() returns, so "timeout is retried" paths are unreachable. post() logs 500 chars of the OpenAI error body (a 401 body carries a key fragment).
- Macro import run ends `failed` when any one sheet fails; a rule that needs a `done` run is blocked by one bad sheet.
- Specs name methods that don't exist (007: `MacroChecker::lists()` is really `loadValidationRefs()`); grep every method name, not only files.
- Queue state machines: check what happens to the dispatch marker when a row is re-queued, and whether "step flipped to running" and "rows inserted" share a transaction.
- phpunit.xml uses QUEUE_CONNECTION=sync and CACHE_STORE=array: delayed dispatches run inline, and a lock test with block(n) sleeps n seconds.
- 007 T1 import starters: a "skip the final write when a flag is set" rule strands the run in an active state when the job was healthy (macro Cancel on the last sheet); the safe rule is a final write conditional on the status still being active. Check every "leave it as it is" branch for who ends the run afterwards.
- Import job loop branches (cancel branch, between-sheet re-read, final write) have no test because the Google client is built inside handle(); only the "already closed at start" early return is tested. `MacroImportController::import` is an unrouted, unguarded second macro start (dead code; check it stays unrouted).
- Run row committed, then dispatch outside the transaction: a failed dispatch leaves an active run with no job, and with a guard that run now blocks starts (Likha: 120 min, no Force-stop).
- 007 T2 night imports: macro job touches its run/items only at sheet start and sheet end (nothing during the row loop, max 5,000 rows, indexed lookup), so any no-progress rule is "one whole sheet + two Google calls"; Force-stop uses run `updated_at` 10 min, night rule 15 min on run+items. A `queued` run behind a busy single `default` worker has no progress either and gets closed while healthy (harmless: old job returns at start).
- Job start is still read-then-unconditional `status = running`: a close landing between the read and the write revives the run (ms window). Check any new closer against it.
- try/catch "DB unreachable → defaults" branches (routes/console.php, NightRunSettings::read) are only tested with a dropped table (hasTable false), never with a throwing connection: removing the catch keeps tests green.
- Scheduler `withoutOverlapping()` default is 1440 min = exactly the daily period; a killed `schedule:run` leaves a mutex that expires the same second the next night's run checks it. Ask for a short expiry on daily entries.
- night_date = Manila date at the call; an import time set before midnight (23:30) lands on the previous night's date. Check every later task (Astra condition, banner) for that assumption.
- Red runs that are errors (file not found, command does not exist, class/method undefined) keep showing up as TDD evidence; they are not behaviour failures.
- 007 T3 runner/AstraEncoder: `AI_CHECKER_LOG_FAIL` logs a QueryException message (SQL + bindings = customer chat/evidence) and the runner's 500 payload carries the raw exception message; later tasks must not copy either into night tables or pages. A failed row with `last_error` null (no key, unparsable answer, deleted row, DB error) is not a transport failure: check how the caller classifies it.
- Conditional `UPDATE ... WHERE STATUS blank` returning 0 is reported as "Status set by a person" even when the row was deleted; then the runner's re-read is null and it ends as a 500 "read property on null". No test for a row deleted mid-call.
- Blank-STATUS rule (NULL or TRIM = '') is now written twice (MacroCheckerController::blankRowsQuery, AstraEncoder); a third copy (night selection) is the DRY trigger.
- NightRun tests do not pin LOG_CHANNEL: Log::warning in tests lands in the machine's real laravel.log; retry tests really sleep 1.2 s each (usleep in post()).
- "Moved verbatim" checks: also grep the old file for every `use` the diff removes (here AstraEncoder, MacroChecker, Log were only used by the moved code: OK).
- 007 T4 night Astra: the database queue takes jobs by id, so a delayed re-dispatch lands behind the whole backlog; a breaker that counts only final failures stays at 0 until every row had a first attempt. Checklist proposal: for any retry + consecutive-failure breaker, ask where the retry job lands in the queue.
- Sweeps scoped to steps in state `running` never touch a stopped step: a row left `running` (worker killed) or `queued` (lost delayed job) in a stopped run stays that way until a later Retry re-opens it.
- tick(): tonight's start/condition runs before the sweeps with no try/catch, so one throw (missing table, DB error) skips the sweep of every running run that minute.
- A dead Likha run (`running`, no Force-stop) blocks the Astra condition every night when the Likha night switch is off (nothing else closes it).
- 007 T4 untested branches: the job's own catch, start()'s transaction rollback, routes/console.php catch, late start of a waiting step, blank variants ('' / spaces) at job level, transient on a stopped step. Runner 500 path drops the cost of a billed call.
- Blank-STATUS rule is now 3 SQL copies (blankRowsQuery, AstraEncoder, NightAstraRun::whereStatusBlank) plus one PHP copy (isStatusBlank); all agree today (spaces only), check on any change.
- 007 T4 fix / T5: a breaker that counts transient first attempts trips on a short fast-failing blip (10 quick 5xx/429 in ~20 s with 2 workers) before any 65 s retry can run; the same row can count twice (first attempt + retry). Checklist proposal: for any consecutive-failure breaker, ask how many seconds 10 fast failures take, not only how many rows.
- 007 T5 manual actions: Run now for yesterday between the first import and the Astra time takes tonight's one step (scheduled start then does nothing; rows of the later import wait for a second click); manual runs never wait for imports that start after them. "Waiting for the worker" shows for the first seconds of every start/re-open and during a 65 s retry wait after a long timeout (rule ignores step started_at).
- Night summary values that come from outside and reach all logs-page viewers: `for_person_by_code` keys (AI final_code), sheet names, run messages, step reasons. Any Blade built on them: `{{ }}` / x-text only, checkbox value "1" (the save rule is `boolean`, "on" fails).
- CEO rule is now copied in MacroCheckerController, Checker1SettingsController, NightRunController plus the EnsureCeo middleware; all use the real role, so a CEO in "view as" still gets CEO data.
- Concurrency of reopen/start (double click) is only tested as two sequential requests; sqlite cannot prove the conditional-update race.
- Blade checkboxes with `@checked(old($field, $saved))`: after a validation error an unchecked box has no old value, so it falls back to the saved value and comes back checked (a switch the CEO turned off stays on). Check every checkbox that uses old() with a default.
- Pages with a second form on a view that already prints `$errors->all()` and one shared "saved" flash: the new form's errors show twice and the old banner text (Idle Summary) shows for the new save.
- Blade page tests run without the CSRF middleware and never execute Alpine: a removed `@csrf`, `:href` guards and x-text helpers are unproven. Long outside text (sheet names, exception text) needs `break-words` or it brings back horizontal scroll on a phone.
- Night run "N of M done" counts only steps that have a record: a night where an import never started reads "2 of 2 done"; only the latest night's banner says "no record".
- Time-gated breaker (007 fix loop 2, closed): check the gate with three timings: fast failures (stops at the gate age or at the first final retry), slow timeouts (count x duration / workers), and a success racing the streak-start write. Spec text for a rule changed in a fix loop (§9 "Waiting for the worker") tends to stay on the old wording.
- 008 log cleanup (clean review): MacroChecker has exactly 4 Log:: calls, AiCheckerRowRunner 1; errorIdent now copied in MacroChecker (''-returning) and AstraEncoder (null-returning); AiCheckerRowRunner:99 still returns getMessage() in the 500 payload (out of scope, not a log).

**Why:** these produced findings in the 001 spec review and are likely to recur in follow-up handoffs on /item.
**How to apply:** in any /item, quote, HOLD or supply review, check these before reading the rest of the diff.
