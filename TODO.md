# TODO

Accepted review findings, each with the reason it isn't fixed now (CLAUDE.md workflow step 5).

## 001: sourcing worklist (2026-10-01)

- **`quoteSave` sets fields the caller doesn't send to null** (note, and moq/link when left out). This was already true before the task. Since T5 such a clear also writes a history row. Reason: changing which fields a save may touch is outside the task, and both pages send every field they show. Suggestion for the owner: only update fields present in the request.
- **A malformed `date_range` on `/item/data` means no date filter** (`parseRange` returns nulls), and `/item/photo` builds the range string without an empty guard. Reason: already true before the task, and the input comes from the page's own date pickers.
- **`/item/worklist` loads every PO line and filters by key in PHP.** Reason: trusted data, and the supply finance table is small today. Revisit if the PO history grows large.
- **Untested here, by design or because sqlite can't run it:**
  - `lockForUpdate()` under concurrent saves on MySQL (sqlite ignores it)
  - the pgsql column-quoting branch of `liveHoldQuery`
  - the original `STR_TO_DATE` snapshot query (`HoldService::unitsByBaseItem`, unchanged)

  Reason: the test DB is sqlite in memory; these need MySQL or pgsql.
- **Missing edge tests the reviewers listed** (all on trusted or CEO-only paths, or already covered by the code's structure):
  - partial receipt on an open PO (the UI can't produce it: counting sets status `counted`)
  - the worklist's `Schema::hasTable` fallback paths
  - `lead_time_days` vs `days_since` colouring (front end only)
  - an empty HOLD result
  - history skipped when the table is missing
  - delete's `updated_by`/`quoted_at`
  - price A→B→A and price cleared to null for `prev_price`
  - transaction-failure cleanup of a new photo (the worst case is an orphan file at an unguessable URL, no data lost)

  Reason: keep the suite small (`.claude/rules/tests.md`); none can lose data.
- **A quote photo is silently dropped if the `photo_path` migration hasn't run.** Reason: deploy state is trusted; the deploy notes say to run the migration.
- **The worklist's quote rows render from `/item/quotes` (`itemQuotes`), not from the worklist's own `suppliers[]`.** If that fetch fails, a `may_quote` row shows no quotes. Reason: it keeps edit/delete and "dati" on one data source; both fetches fail together in practice.
- **No JS test harness for the page** (`?list=` across reloads, `safeLink`, FormData). Reason: the project has none; adding one is a new dependency. Checked by `npm run build`, by `ItemPageTest` (CEO-only markup) and by the server-side tests.
- **Test temp files:** `QuotePhotoTest` writes small files with `tempnam()` and doesn't delete them. Reason: OS temp dir, a few bytes each.
- **DRY suggestion:** `url(Storage::disk('public')->url(...))` now appears in four places in `ItemController`; the base-item key rule in four places (`ItemSupplierQuote::keyFor`, `HoldService::itemKey`, `SupplyFinanceController::itemKey` (doesn't strip `N x`), JS `supKey`). Reason: the legacy rule says no refactors; suggestion for the owner.

## 002: worklist chips filter the item table (2026-10-01)

- **No JS test for the filter logic** (`worklistKeep`, `worklistItem`, the "1 x"/"2 x" base-key case, hold-only rows, sort kept across chips, empty-row conditions). `ItemPageTest` checks only the rendered markup and the order in which the page starts its requests. Reason: the repo has no JS test runner, and adding one is a new dependency. The logic is a few lines on top of 001's tested server rows.
- **If `/item/data` fails while a list is selected, the empty row stays on "Loading…"** (`loadHold` swallows its error, as before 002). Reason: the same silent-failure behaviour Lahat already has; a refresh retries.
- **"No data for selected date." can show next to "Walang item sa listahang ito."** when the summary comes back empty while a list is selected. Reason: cosmetic and rare; the two rows say consistent things.
- **The remaining wait on `/item` is the owner/private `item-summary` request itself.** Production evidence: the worklist HOLD query takes 0.44 s for 72 items, the join columns share a collation, and there are 44 PO lines. Reason: the heavy aggregation in `OwnerPrivateController` is outside task 002. Suggestion: profile `item-summary` separately.

## 003: stock, DOI, order qty, category (2026-10-02)

- **No JS test for the new page logic** (`doiText`, `orderByText`, `doiColour`, `categoryKeep`, the inline editors and their payloads, select reset after a failed save, the `saving` guard, the `order_qty` "—" guard, the `loadStock` stale guard). `ItemPageTest` checks only the rendered markup. Reason: no JS runner in the repo; adding one is a new dependency. The numbers themselves are computed and tested on the server (`StockEndpointTest`, worked examples A–D and every ruling).
- **The `QueryException` fallbacks in `categorySave` / `supplySettingsSave` are never executed by a test.** On MySQL REPEATABLE READ the re-select may not see a racing commit, so a double submit can end in a retryable 422. Reason: CEO-only path, sqlite can't reproduce the race, and no data is lost.
- **A page item whose name is only an `item_type_mappings` alias of the cogs name and has no orders in range has no item-level ITEM VAL.** (shows "—"). Reason: rare, and the scan couldn't confirm production has any alias mappings. The page row still shows its value.
- **Keyword rules match substrings** ("oil" in "toilet", "led" in "filled", "cap" in "capacity"). Reason: the rules are the task's trusted data, and the command is a dry run by default so the reviewer and the owner see every proposal before `--apply`.
- **`items:suggest-categories --apply` inserts without a transaction.** A failure partway leaves part of the set applied, and a re-run completes it, because existing assignments are skipped. Reason: the reviewer runs it by hand; it's idempotent.
- **After a date-range change, the stock columns keep the previous numbers until `/item/stock` returns** (no per-cell loading marker after the first load), and a failed fetch shows "—" with the error only in the tooltip. Reason: the request is fast and the same as how other columns refresh; it's cosmetic.
- **Item sorting puts `null` values first in ascending order** (the existing `_itemSortValue` comparator). Reason: pre-existing behaviour shared by every column; changing it touches all columns.
- **Missing edge tests the reviewers listed** (trusted or CEO-only paths): the seed migrations re-run or with existing rows, the FK restrict on category delete, the unauthenticated redirect, the suggest command's missing-table exit, a second `--apply`, null `ITEM_NAME`/`ts_date` rows, invalid dates or `view_as` on `/item/stock`, alias canonicalisation in `values`, and a non-CEO viewer with a `cogs_ceo`-only name. Reason: keep the suite small (`.claude/rules/tests.md`); none can lose data, and the code paths mirror ones already tested.
- **`StockMigrationsTest` compares the START date with "today in Manila"** and could fail if a run straddles Manila midnight. Reason: the migrations run in `setUp` before time can be frozen; the chance is tiny.
- **`ItemBaseKey::key()` repeats `ItemSupplierQuote::keyFor`'s regex**, pinned by a unit test. Reason: the legacy rule says no refactors of the existing copies; suggestion for the owner: one shared helper for all five.

## 004: restock target by lifecycle (2026-10-02)

- **`/jnt/supply` still reads its lifecycle defaults from its own request-param literals (14/30/90/1.5/0.5), not from `ItemLifecycle`'s constants.** Two copies of the same numbers; a test pins the constants. Reason: `/jnt/supply` must not change in this task; suggestion for the owner: point `index()` at the constants.
- **`ItemLifecycle::classify` keeps the unused `$hasOldOrders` parameter**, and the table only passes `true`. Reason: moved verbatim; removing it changes `/jnt/supply`'s method.
- **The column-config migration doesn't touch the `owner_private_cols_default` snapshot.** If the CEO ever used "Save as default" while CATEGORY was visible, "Reset to default" brings CATEGORY back. Reason: CEO-owned data, and the CEO can hide it again in one click.
- **The six `item_palugit` rows also show in `/jnt/supply/config` "Other settings"** (the editor lists every non-class group). That's where the CEO edits them, by design (spec §4).
- **The first-date cache key includes the request Host** (as `item-summary`'s keys do, per the reviewer's answer). A logged-in user who sends odd Host headers can create extra 12-hour cache entries. Reason: login required, same pattern as the existing item-summary cache, the reviewer's decision.
- **Missing edge tests the reviewers listed** (trusted data): an unknown `lifecycle_override` string (falls to the Active palugit and "— Unknown" badge), `stock_ready=false` keeping `doi_note`, a cached first date winning over the window date, the host-specific cache key, the `palugit_override` column-absent branch of `supplySettingsSave`, a malformed saved column config, and the multi-row override update. Reason: keep the suite small (`.claude/rules/tests.md`); none can lose data, and the code paths mirror tested ones.

## 005: /item layout (2026-10-02)

- **No runtime test of the new layout's Alpine logic** (`ilAabot`, `ilPill`, `ilDays`, `ilDate`, `ilMoney`, `ilPct`, `ilPieceCost`, `ilOrderReason`, `ilUrgency`, `ilColOn`/`ilIdOn`, `ilPageVal`, `ilTotVisible`, the `il_action_at` sort, the breakpoints and cards). `ItemPageTest` only proves the code and texts are in the markup. Reason: no JS runner in the repo, and `node` isn't in the worker's allowed commands; a runner is a new dependency. The numbers themselves come from the server and are tested there. Suggestion: allow `node --test` for a small pure helper file, or rely on the reviewer's browser check.
- **The campaigns panel inside /item's expanded block uses 11 px text**, and the shared markup's light greys (`#94a3b8`, `#bcc0c4`) are below AA. Reason: the panel must fit without horizontal scroll (the reviewer's answer 6), and its colours are in the shared include that `/owner/private` uses, which must not change.
- **On a phone the campaigns table wraps mid-word** (fixed layout, ~12 columns in ~340 px). Reason: the price of "no horizontal scroll" without changing the shared include; the reviewer's browser check decides if it's usable.
- **Below 1,100 px the header row is hidden, so header sorts aren't reachable in card view.** Reason: per spec (cards); the default urgency sort still applies.
- **ctrl/cmd-click on "Lumang view" / "Bagong view" opens in the same tab** (the link builds its URL on click). Reason: cosmetic; a plain click is the common case.
- **`.il-table th { overflow-wrap:normal }`** could let a long header word poke past its column at 1,100–1,365 px. Reason: unverified without a browser; part of the reviewer's checklist.
- **When `order_qty` is 0, the reason line's cover figure uses the 2-decimal `units_per_day`** and can differ by 1 from the server's. Reason: nothing is ordered in that case.
- **`ilPageVal` runs a few times per page-card cell** (x-show still evaluates x-text). Reason: only for open items; fine at current counts.
- **`ilPct` on a tiny negative (e.g. −0.04) prints "▼ −0.0%"** (sign is taken before rounding). Reason: cosmetic; fix list 1 asked only for the minus with ▼.
- **The page cards' Prof.Profit(1D) value keeps two decimals**; fix list 1's whole-peso rule is applied only to the KITA NGAYON cells (main row, TOTAL, narrow duplicate), as written. Reason: "nothing else changes"; the reviewer can extend it.
- **The colspan test matches `ilVw.lt1440` / `ilVw.lt1366` by text order only**; deleting the `ilColOn` guard would still pass. Reason: no JS runner; string-grep seam.
- **Missing edge tests the reviewers listed:** `?layout[]=old` and an empty `?layout=` (both give the new layout by the strict `=== 'old'`), a non-CEO `GET /item`, an item with no demand (`velocity_days` 1), TOTAL cells in the Marketing render, `ilPageVal` for `jnt_rdt` with partial members / `rts_pct` 0 / null price range / manual vs cogs item value. Reason: keep the suite small; trusted or display-only paths.

## 006: /item simple view in English (2026-10-02)

- **No runtime test of the 006 helpers** (`ilNext`, `ilReason`, `ilSupplier`, `ilCheapQuote`, `ilQtyCost`/`ilQtyCostAmount`, `ilDaysLeft`, `baseProfit7`, `ilCfTint`, `ilOrderTotal`, `ilSalesColspan`, `ilLeadLine`, `ilOrderReason`, `ilSalesADay`, the rank sort, the first-click-ascending `il_next` sort, the tab switch). `ItemPageTest` proves the code and texts are in the markup, not that they pick the right state. Reason: no JS runner, and adding one is a new dependency (same as 005). Suggestion: allow `node --test` on a small pure helper file.
- **"To order now" ₱ sums only red rows that have a cost line**; "Find a supplier" rows with no item value add to items and pcs but not to ₱. Reason: spec §6 allows it; no price, no honest number.
- **A ₱0 quote counts as "no price"** for Next step and the cost line, but still counts as a quote for the server's "Has a quote" chip and shows "₱0.00" in the details. Reason: display mismatch only; the chip logic is unchanged by design.
- **The Lead time line in the details shows the trend word and "losing money" to every role**, even one without the `lifecycle` grant. Reason: same as 005 (`ilLeadLine` was ungated there); the reviewer decides whether lifecycle is hidden from any role.
- **The Trend entry in the details shows the trend word plus its tip text**, which is also its tooltip. Reason: the visible tip is the "lifecycle detail" the task asks for in the details.
- **Below 1,100 px the header row is hidden, so header sorts aren't reachable in card view.** Reason: inherited from 005; the default urgency sort still applies.
- **The Sales total row tints its four % values** with the same owner rule per window. Reason: one colour meaning per column; the 005 total had no fills because 005 had none anywhere.
- **`il-w-item` is a marker class with no CSS rule** (a test pins the flexible Item column by it). Reason: harmless.
- **At 72 px (1,100–1,365 px) a value like "▼ −100.0%" may wrap.** Reason: width approved in spec §8; the reviewer's browser check decides.
- **`ilCfTint` evaluates the owner's rules with no reference row**, so a rule that compares against another column or a formula is skipped. Reason: the To order value is a combined base-item figure, not a page row.

## 007: night run (2026-10-04)

Accepted review findings. None is a blocker or a major; the majors were fixed.

- **A `queued` macro run waiting 15+ minutes behind a long job on the one `default` worker is closed as stale while healthy.** Reason: harmless (its job returns at start, the new run imports the same rows); the earlier run only reads "Failed / Stale". Trusted path.
- **A night import time set before midnight (e.g. 23:30) is recorded under that day's date, not the next morning's.** Reason: CEO input, trusted; the defaults are 01:00 and 02:00.
- **If the step update fails after a real import start, the step stays "Failed to start: interrupted" though the import runs.** Reason: needs a database failure between two writes; the import page still shows the run.
- **Job dispatch happens after the commit in both import starters**, so a failed dispatch leaves an active run with no job. Reason: database-failure path; macro has Force-stop and the no-progress rule, Likha the 2-hour rule.
- **A stale-closed Likha run's job finishes the sheet it is on** (and marks that sheet done on the closed run) before stopping. Reason: the job is only changed between sheets; same limit as the macro Force-stop.
- **A macro job closed mid-run skips the primary-item recompute and the cache bump** for rows it already imported. Reason: same as today's Cancel branch; the next import recomputes.
- **`app/Http/Controllers/MacroImportController.php` holds a second, unrouted macro start with no guard.** Reason: dead code, not touched by the task. Suggestion for the owner: delete the file.
- **`AI_CHECKER_LOG_FAIL` logs a query exception message** (it can contain order text), and the classic engine's `MACRO_CHECKER_*_HTTP` lines still log the response body. Reason: moved as it was / classic engine is out of scope; follow-up 007-1 item 14 covered Astra's `post` only. Suggestion: class and status only, as Astra now does.
- **The runner's 500 payload carries the raw exception message**, as the browser gets today. Reason: the browser path must not change; the night job never copies it (fixed reasons only, tested).
- **A row deleted during a night call reads "Status set by a person".** Reason: rows aren't deleted in normal use; nothing is written either way.
- **The blank-STATUS rule exists in four places** (`blankRowsQuery`, `AstraEncoder`'s conditional write, `NightAstraRun::whereStatusBlank` and `isStatusBlank`). Reason: the first two are existing or opt-in code the task said not to refactor; they agree (reviewer checked NBSP, tab, '0').
- **A billed call whose runner ends in the exception path adds no cost to the night's estimate.** Reason: the result is null there; the cost is labelled "estimated".
- **A dead Likha run blocks Astra ("Did not run: an import was still running (Likha)") until something starts a Likha import**, which closes runs older than 2 hours. Reason: visible in the banner; with the Likha night switch on it heals itself at 01:00.
- **The tick is scheduled only while the Astra switch is on or a step is waiting/running**, so rows left in a *stopped* run are swept only then. Reason: Retry failed re-opens the step and the tick then runs; nothing is spent meanwhile.
- **After a short blip leaves the breaker's counter at 10 or more, one unrelated final failure before any success stops the run.** Reason: rare; Retry failed recovers it.
- **"10 rows failed in a row" can be fewer than 10 distinct rows** (a row counts on its first attempt and again on its final failure). Reason: it is a count of consecutive failures; stricter is the safe side for money.
- **Run now for yesterday's orders before tonight's Astra time takes that date's one run.** Reason: the reviewer's decision (follow-up 007-1, question 4); the confirm text says so.
- **Banner: a false "has no record" on the day a switch is first turned on after its time** (or a time is moved earlier than now). Reason: the page doesn't know when a switch was turned on; it clears the next night.
- **"N of M done" counts only imports that have a record**, and counts "Done with failed sheets" as done. Reason: the badge shows the worst state and the banner names a missing step.
- **Duration of a re-opened run spans from its first start.** Reason: one record per date.
- **`cost_complete` is derived from the log's model names against the config prices**, not from the engine's `cost_known` flag. Reason: they agree; the flag isn't stored per row.
- **`isCeo()` ignores the CEO's "view as" preview** and now exists in three controllers. Reason: existing pattern, copied as the spec says; a preview is still the CEO.
- **Untested, by design or because sqlite can't run it:** real concurrency of the cache lock, of the claim and of the double-click re-open on MySQL; `INSERT IGNORE` and the `TRIM(STATUS)` clause on MySQL/pgsql; the jobs' between-sheet branches behind the Google client; the catch branches in `routes/console.php` and the job's own catch; the Alpine helpers of the Night run section; CSRF on the three forms (tests run without the middleware). Reason: the test DB is sqlite in memory, the Google fetch is out of test scope (per the task brief), and the project has no JS test harness.
- **Red runs that were "class or method not found"** for several first slices. Reason: `.claude/rules/tests.md` allows it for a module about to be created; the later slices have behaviour failures.
- **`node --test 'test/hooks/*.test.mjs'` was not run.** Reason: not in the task's allowed commands; no kit file was touched.

## 008: checker log cleanup (2026-10-04)

Closes the 007 item above about `AI_CHECKER_LOG_FAIL` and the `MACRO_CHECKER_*_HTTP` lines. Accepted minors from the review:

- **The runner's 500 payload still carries the raw exception message** (`AiCheckerRowRunner.php:99`). Reason: it is a return value, not a log; task 008 forbids any behaviour change. Same item as in 007; proposed as its own task.
- **`errorIdent` now exists twice** (`MacroChecker`, returning `''`; `AstraEncoder`, returning null). Reason: the task forbids touching `AstraEncoder` and allows one small private helper; second copy, not the third. Suggestion: one shared helper when either is next touched.
- **No non-JSON test on the search line.** Reason: it reads `type`/`code` through the same helper the OpenAI line's "not json" case proves; `.claude/rules/tests.md` says no second test for a proven behaviour.
- **A hostile provider could put up to 64 characters of `[A-Za-z0-9_.-]` into `error.type` / `error.code`**, and that would be logged. Reason: same rule as `ASTRA_ENCODER_HTTP` (the reviewer's decision on the shape); OpenAI's values are fixed identifiers, and the 401 key fragment sits in `error.message`, which is never read.
- **`CheckerLogCleanupTest` takes about 6 s.** Reason: the two 800 ms retry sleeps are real and may not change (no behaviour change).
- **`node --test 'test/hooks/*.test.mjs'` was not run.** Reason: the task says it is not needed; no hook was touched.

## 009: Claude Action columns (2026-10-04)

The review found no blocker and no major. Two minors on untrusted paths were fixed (unknown page key note in the command; cache-hit test). Accepted minors:

- ~~The column settings page still offers MOIC / Marketing checkboxes for the two columns; ticking them does nothing.~~ Closed by follow-up 009-1 (round 1): the checkboxes are disabled with a "CEO only" hint and the server drops a posted tick.
- **Two `owner-private:claude-action` runs at the same moment for the same new page-day: the second fails on the unique key with a raw query error.** Reason: trusted operator path, nothing is lost or corrupted; the first write stands.
- **A CEO previewing with `view_as=marketing` still receives the `claude_*` keys in the JSON** (the columns are hidden on the page). Reason: D5 gates on the real role and the viewer is the CEO; same pattern as the 007 `isCeo()` item above. No test pins it.
- **Untested:** the rollback when the audit insert fails, the `forDate` / `forPage` path before the migration has run (`Schema::hasTable` guard, read by eye), the Alpine behaviour of the new cells (more/less, clamp, sort by the two columns) and the layout at phone width. Reason: no JS test harness in the project and no browser tools in the session; the view tests check the rendered markup only.
- **Line breaks in the Claude text show as spaces**, as in the Action note (`x-text` in a normal-wrapping cell). Reason: D8 says "the way the Action note does it".
- **The red run for the main table and breakdown markup is missing** (the developer wrote that markup before its tests; `/item` has a real red run). Reason: found after the fact; the tests were checked against the finished markup and the non-CEO test was later proven red by the marker-comment leak.
- **`node --test 'test/hooks/*.test.mjs'` was not run.** Reason: not in the task's allowed commands; no kit file was touched.

Round 1 (follow-up 009-1), accepted minors from the second review (no blocker, no major):

- **An old grant of the two columns to a team role stays in the stored JSON through save-as-default and reset** (those two copy the stored rows as they are). Reason: it has no effect, `loadConfig` always hides the ids for non-CEO roles and the settings page never lists them (both tested); the next Save from the page rewrites the row without them.
- **The settings page tests assert what the server sends** (the injected `COL_CEO_ONLY` list, the one checkbox template with its `:disabled` binding, the guards in `sectionState`), not two rendered disabled inputs per section; a wrong-way guard in the JS would not be caught. Reason: the rows are built in the browser by Alpine and the project has no JS test harness; the server rules behind them are tested through the real endpoints.
- **No test that a save posting only `order` (drag-to-reorder) keeps the stored `hidden` and `visible_by_role`**, and none that a default snapshot taken with the two columns hidden for the CEO comes back hidden. Reason: both paths are unchanged code shared with every other column; the hide/show and snapshot/reset tests cover the two ids on the same paths.
- **`resources/views/owner/column_settings.blade.php` (the older combined settings view) has its own `sectionState` without the lock.** Reason: no route renders it; the server rules hold whatever a page posts. Suggestion for the owner: delete the dead view.

## 010: Claude Action and Reason editable by the CEO (2026-10-04)

The review found no blocker and no major; the three named checks pass. One minor on the untrusted path was fixed (a test for one blank field and for whitespace-only texts through the route). Accepted minors:

- **The "unchanged" check in `saveClaudeAction` reads the row outside the service's transaction.** If the artisan command changes the same page-day between that read and the write, the CEO's save can answer `unchanged` and write nothing. Reason: two trusted writers, nothing is lost or corrupted, and the response carries the row as it is now, so the cell shows it.
- **The page posts `ts_date: this.endDate`.** While a reload after a date change is still in flight, the rows show the old end date's note and a save would go to the new date. Reason: the same exposure as the team's Action modal (`row.action_date || this.endDate`), CEO only, and the modal shows the date it will save to under the page name.
- **The 500 ms auto-close after a save closes whatever Claude modal is open then**, also one opened for another row inside that half second. Reason: copied from the Action modal on purpose (D3: same behaviour); nothing is lost, the modal can be reopened.
- **Two CEO saves at the same moment for the same new page-day: the second fails on the unique key with a server error.** Reason: one CEO, same accepted finding as the command in task 009; the first write stands.
- **`bumpCacheVersion()` has one-second resolution**: a summary cached in the same second as the save (and after an earlier bump in that second) can stay stale until the next bump or Refresh. Reason: existing behaviour shared with the team's Action save; the cache code is out of scope. The cache test seeds an old version for this reason.
- **The refusal test names three non-CEO roles (Marketing, Marketing - OIC, Data Encoder), not every role string in the app**, and on its own it would also pass if the route did not exist. Reason: the gate is `checkCEOAccess()` (anything that is not the normalized `CEO` gets 404), one representative other role is enough, and the CEO tests plus the route test prove the route exists.
- **`startClaudeDrag`, `claudeModal` and the modal markup copy the Action modal instead of sharing code with it.** Reason: the task forbids touching the Action note's own code; the copy lives once, in two partials used by both pages.
- **Untested: everything that needs a browser.** The ✎ chip, focus on the clicked field, textarea auto-height, drag, the in-place cell update after a save, Escape / Tab in the modal, phone width, and the D5 look (`td.claude-col` white background, TOTAL row). Reason: no JS test harness in the project and no browser tools in the session; the view tests check the rendered markup only.
- **A guest gets the auth middleware's answer (401 for a JSON request, redirect to login otherwise), not 404.** Reason: D1 says "the same refusal the page's other CEO-only actions give"; the test compares with `GET /owner/private/daily` as a guest.
- **The red run was one run of the whole new test file per task, not one per slice**, and the absence assertions (non-CEO page source, breakdown without edit) were green before the change because they assert that something is missing. Reason: found in the developers' reports; the presence tests were red for the missing route and the missing markup.
- **`node --test 'test/hooks/*.test.mjs'` was not run.** Reason: not in the task's allowed commands; no kit file was touched.

Follow-up 010-1 (CEO Action and CEO Reason), accepted minors from the second review (no blocker, no major; the four named checks pass). All are on trusted paths:

- **Before the two new migrations have run, a save through `owner/private/ceo-action` fails with a server error** (the service's `find()` meets a missing table). Reason: deploy order, in the deploy notes: migrate first. The pages and both data endpoints work without the tables (`Schema::hasTable` guard in `forDate` / `forPage`); nothing is lost.
- **No test drops a table to prove that guard**, for either note pair. Reason: same accepted gap as in task 009 (read by eye); the guard is two unchanged lines shared by both services.
- **A4 is held by the type-hint and one test, not by a hard barrier**: the Claude command asks for `PageDayClaudeActionService` and gets it; binding `PageDayCeoActionService` over it in a service provider would hand the command the CEO tables. Reason: only someone editing the repo can do that; the CEO service's docblock says no artisan command may use it, `git grep "page_day_ceo" -- app/Console` is empty, and the command test proves the CEO tables stay untouched.
- **The view tests only search the page source**: the `ceo_*` cases in `ilPageVal`, the sort by the two columns and the editor script are never executed. Reason: no JS test harness and no browser tools, as for the Claude columns.
- **The shared save helper's parameter is still called `$claude` and typed as the Claude service** although it also receives the CEO service (its subclass). Reason: cosmetic; renaming would only add diff lines in `OwnerPrivateController.php`.
- **The red run of the backend task has first-failure lines only for the settings tests**; for the migration, route, payload and command tests the developer reported the cause (missing table, missing route, missing key) without the line. Reason: found in the developer's report after the fact.

## 011: fit-to-width on the owner private table (2026-10-05)

The review found no blocker and one major (the factor was applied once and never checked while sideways overflow is hidden); it was fixed in loop 1 (bounded verify pass) and its arithmetic corrected in loop 2. Both named checks pass. Accepted minors, all on trusted paths:

- **Every change inside the table re-runs the measurement, also a hover on a page or campaign link** (those links write an inline style on hover, and the MutationObserver watches `style` and `class`). Each run sets the zoom to 1 and back and forces a layout of the whole table; possible stutter on a large table. Reason: not a loop (one run per frame, same result), and narrowing the observer would miss real width changes (the more/less toggles and the column bindings also arrive as `style` changes). Needs a browser to measure; see the proposed task in the task's result notes.
- **If `init()` throws after the observers are attached, the switch is disabled but the observers stay live**, so a later measure could apply Fit behind a disabled switch. Reason: only `MutationObserver.observe` or the last `schedule()` call could throw there, and neither realistically does.
- **The verify pass can leave an overflow of less than one layout pixel**, because `offsetWidth` is a whole number. Reason: below what the page can measure; it sits inside the container's 16px padding and nothing is cut off.
- **The tests search the page source only.** `owFitFactor`, the verify pass and every observer are never executed; a wrong division with the right guards would stay green. Reason: no JS test harness in the project and none may be added (the task brief, section 5).
- **Untested: everything that needs a browser.** The fit itself, the sticky header and TOTAL row under zoom, first-paint flicker, scroll position across a recompute, the pencils' click targets at small factors, Firefox and Safari. Reason: no browser tools and no dev server in the session's allowed commands; the checklist for the reviewer is in the result notes.
- **In Fit mode the vertical scrollbar of the table area is always shown**, also when the rows fit the height. Reason: on purpose; a scrollbar that comes and goes changes the available width and is the classic resize loop.
- **The campaigns panel keeps its own sideways scrollbar** (`.expand-wrap{overflow-x:auto;max-width:100%}`) when its inner table is wider than the row. Reason: existing behaviour inside an expanded row, shrunk with the table; removing it means changing the panel, which is out of scope. Question for the reviewer in the result notes.
- **The red run of the first slice was one run of the whole new test file**, and the out-of-scope assertions on the rendered breakdown and item pages were green before the change because they assert that something is missing. Reason: the presence tests and the include scan were red for the missing markup.
- **`node --test 'test/hooks/*.test.mjs'` was not run.** Reason: not in the task's allowed commands; no kit file was touched.

## 012: the CEO account stays logged in for 30 days (2026-10-05)

The review (adversarial depth) found no blocker and one major; the three named checks pass. Three minors on the untrusted path were fixed (`70db3df`: the role re-check fails closed, a tampered real cookie is pinned; `8baeb93`: the role is read only after the password is accepted, so a failed login is again the same with or without an account), and a re-check scoped to those fixes found no blocker and no major.

**Closed by follow-up 012-1 (A1), see the section below; kept for the record:**

- ~~The 30 days are enforced only by the browser.~~ The remember cookie's value carries no issue time, the server compares only id and token, and the stored token is reused by every later login. Someone who copies the cookie value from the CEO's browser can replay it on day 31 or day 300, until the CEO presses Logout (the only thing that cycles the token). The task's threat model says "up to 30 days". Reason it is open: D2 was built as written (cookie lifetime through the guard's remember duration); every real fix changes a decision or the constraints (cycle the token at each CEO login = his other browsers are signed out; a stored expiry = a migration; a second signed cookie with the issue time = a new design). Options and a recommendation were in the task's result notes.

Accepted minors:

- ~~If another `Login` listener throws on a cookie sign-in before the role re-check has run, the session is saved signed in.~~ Closed by follow-up 012-1 (A3): the re-check is registered in `AppServiceProvider::register()` and runs first (tested). Original text: (the framework writes the login id into the session before it fires the event). Today that is `CopyEverydayTasksOnLogin`; listener order comes from file discovery and is not guaranteed. Reason: the cookie holder cannot make that listener throw (it needs broken task data or a database error), the same listener behaves the same way on a password login today, and the fix (the re-check somewhere a throw from any listener is covered) is outside "one small class". The new listener's own failure is covered and tested.
- **A refused account can get its everyday tasks copied once** before it is signed out, and the CEO's are now copied on each cookie sign-in as well. Reason: the copy skips tasks that already exist for the day; it is the account's own data, no access.
- ~~The listener has no registration line; it is found by the framework's listener discovery.~~ Closed by follow-up 012-1 (A3): explicit registration, not discoverable. Original text: (as `CopyEverydayTasksOnLogin` is). A cached events file on the server would hide it until rebuilt: CEO remember would work and D3 would be silently off. Reason: deploy state is trusted; the deploy notes have the step and the check.
- **One CEO password login fires the framework's `Login` event twice** (`Auth::attempt`, then `Auth::login(..., true)` to issue the cookie), so every `Login` listener runs twice and the session id changes three times. Reason: it is what lets the role be read only after the password is accepted, with the base `Auth::attempt($credentials)` untouched for everyone; today the only other listener, `CopyEverydayTasksOnLogin`, skips tasks that already exist for the day. A future listener that is not idempotent would double for the CEO.
- **When the role read fails during a cookie sign-in (a database error), the fail-closed logout also clears the cookie and cycles the token**, so the CEO is signed out of every browser, not only that request. Reason: the safe side; he logs in again.
- **If the profile read throws right after the password is accepted, the answer is a server error while the session is already signed in** (no cookie), for any role; the base did not read the profile at login. Reason: no access is gained that the password did not give; a reload works.
- **Every successful login now reads the employee profile once** (one small query), also for other roles. Reason: needed to know the role; nothing else on their path changed.
- **`email[]=<address>` with the right password logs in** (the framework turns an array into `whereIn`), as before; for a CEO it now also gets the cookie. Reason: existing behaviour, the password must still match that account; the failing array case is pinned.
- **A stale CEO cookie stays in a browser when another account logs in there by password**; when that account's session lapses without Logout, the next page load is the CEO again. Reason: needs the CEO's own device (trusted); proposed task in the result notes.
- **A demoted account keeps the session it already has while it stays active**, like any role today; only the cookie sign-in is re-checked (D3). Reason: every page checks the role per request.
- **The "session regenerated" assertion would also pass with the controller's `regenerate()` line removed** (the guard's own login already changes the id). Reason: the line is unchanged in this diff; the test pins "the id changes on login".
- **`isCeo()` is one more copy of the CEO rule** (same reading as `OwnerPrivateController::getNormalizedRole()`: trimmed, case-insensitive), while `EnsureCeo` is a strict `=== 'CEO'`. An account stored as `ceo` gets the 30-day login and the owner pages but 403 on Boardroom, the same split as before. Reason: no refactor of existing copies (legacy rule); login and re-check share the one new copy.
- **No test for a non-CEO password login in a browser holding a CEO cookie, or for a CEO's second login reusing the stored token.** Reason: both pin behaviour that the open major and the proposed tasks would change.
- **The red run of the cookie sign-in and logout tests was the shared "no cookie at CEO login" failure**, not a failure of their own; the forged-cookie and tampered-cookie tests are pins that pass on the base. Reason: found in the developer's report; the refusal and fail-closed tests have their own red runs.
- **The tests use `/debug/ip` as the protected page**, a route that returns request IP headers to any signed-in user. Suggestion for the owner: remove it.
- **`node --test 'test/hooks/*.test.mjs'` was not run.** Reason: not in the task's allowed commands; no kit file was touched.

### 012-1: second cookie, password change, explicit registration (2026-10-05)

The review (adversarial depth) found no blocker and no defect against A1 to A4; the five named checks pass. Two minors were fixed in one wave (`e93cbcf`: the second cookie's value is built in one place next to its parser; `3f20091`: rows for the future tolerance, the exact 30-day boundary, an array-shaped cookie and a second cookie from a later login).

**Ruled by follow-up 012-2 (options i and ii built as B1 and B2; option iii not built, B3), see the section below; kept for the record:**

- ~~A session that was started from the cookies is never checked again.~~ The second cookie, Logout's token cycle and the password change's token cycle all stop new sign-ins from the cookie; they do not end a session that already exists. Someone who copied both cookies from the CEO's device, signed in once within the 30 days and then sends any request at least every 120 minutes (the session lifetime is an idle limit) stays the CEO past day 30, past the CEO's own Logout (which ends only his own session) and past a password change. A stolen session cookie behaves the same on the base, but A1 ("a copied cookie that works for longer than that is not what he approved") and A2 ("it has to cut that device off") say more than the build does. Reason it is open: every fix reaches outside the follow-up (deleting the user's rows in the `sessions` table on a password change and on Logout; or a hard 30-day age for a remembered session, checked per request). Options were in the task's result notes.

Accepted minors:

- **A refusal cycles the account's token and so ends the remembered login on every CEO browser**, also when the cause is only a missing, damaged or expired second cookie in one browser. Whoever holds the remember cookie alone can cause that once. Reason: A1 says "refused exactly as a non-CEO one is (logged out, both cookies cleared, token cycled)"; he logs in again. In the deploy notes.
- **The second cookie is bound to the user id and the login time, not to the remember token or the browser.** A second cookie from a later password login is accepted together with an earlier remember cookie (pinned by a test), so for someone holding both, the 30 days count from the newest second cookie they hold. Reason: A1 defines the cookie's content; getting a newer second cookie needs the password or another theft.
- **A time up to 300 seconds in the future is accepted** (clock difference between servers); beyond that the cookie is refused. Reason: the value is encrypted by the server, so a future time can only come from a server clock; `vps` and `vps2` clocks are assumed to agree within 5 minutes.
- **A password change gives a token to a user who never had one.** Reason: harmless; no cookie is issued to a non-CEO and the cookie sign-in checks the role.
- **A CEO who changes his own password keeps his current session** and logs in again on every browser when the sessions lapse. Reason: A2 asks for the token cycle; see the open major for sessions.
- **The registration is in `AppServiceProvider::register()`, not `boot()`, with fully qualified class names inline.** Reason: listeners added in `register()` run before discovered or cached ones (tested), which closes the 012 minor above; inline names leave the rest of that legacy file untouched.
- **A stale events cache built from the first run's code would name a `handle` method that no longer exists** and every login would answer a server error until the cache is cleared. Reason: the first run was never deployed; loud and closed, not silent; the deploy notes say to clear it.
- **The red run of the A1 slice was taken in two steps** (the controller's cookie first, so the refusal tests failed on behaviour and not in the login helper), and the two public constants were added before the red run so the tests failed on behaviour, not on an undefined constant. The four rows of `3f20091` are pins with no red run; their sensitivity is reasoned from the frozen clock. Reason: found in the developer's reports.
- **`node --test 'test/hooks/*.test.mjs'` was not run.** Reason: not in the allowed commands; no kit file was touched.

### 012-2: a password change and a CEO Logout end open sessions (2026-10-05)

The review (adversarial depth) found no blocker and no major; named checks 6 and 7 pass and the earlier five are not weakened. Fixed in one wave (`cd65bc3`: Logout reads the role before it signs out, `endSessionsOf` takes an `int`; `5bf439d`: rows for a Marketing caller of the password route and for a Logout by a demoted CEO and by an account without a profile, all under the database driver).

Accepted minors:

- **A sign-in that is in flight at the moment of the cut survives it.** Someone who holds a still-valid CEO cookie pair (or the current password) and sends sign-in requests in a loop while the CEO presses Logout or the password is changed can have one request that passed its check before the cut and writes its new session row after the delete; that session then lives on. Reason: it needs a prepared loop by someone who already holds working credentials (a lost device or a stolen session alone is cut cleanly), a second Logout or password change ends the survivor (its credentials are dead by then), and the real fix is a per-request validity check, the same design class as B3, which the reviewer ruled out of this task. Described for the reviewer in the result notes with options; the deploy checklist tells the owner the order to use for a suspected theft. Traced from the framework source, not run (sqlite cannot show it).
- **The password update and the session delete run in one database transaction** (not asked for by B1): if the delete fails, the password and the token are not changed either and the answer is a server error. Reason: without it a failed delete would leave the password changed, the sessions alive and an error that reads as "nothing happened"; the reviewer judged it worth keeping; a test pins it. If the server ever puts sessions on another database connection, the transaction does not span it (the delete is last, so the result is the same).
- **Logout now reads the employee profile before it signs out, for every role.** If that read fails (a database error), nobody is signed out and the answer is a server error; on the base a logout did not depend on the profile. Reason: for the CEO a half-done sign-out everywhere is worse than a retry; for other roles it is one small query and the same outcome unless the database fails at that instant. Tested.
- **With a session driver other than `database` nothing is ended, silently.** Reason: B1 says so; the config default is `database`; the deploy checklist has the command that shows the running driver.
- **No named test for "non-database driver, both paths run without error".** Reason: `OwnerPasswordChangeTest::test_ceo_sets_a_new_password_for_a_user` and `CeoRememberedLoginTest::test_logout_clears_the_remember_cookie_and_the_old_cookie_no_longer_signs_in` run both paths under the array driver with no `sessions` table, so a delete attempt would fail them; no second test for a proven behaviour.
- **The guard for an empty user id in `endSessionsOf` has no reachable caller and no test**, and a negative id would run a delete that matches nothing. Reason: both callers pass an authenticated or existing user's id.
- **A static session helper lives in the listener class and is called from two controllers.** Reason: the follow-up allows no new class and the legacy layout has no better home; suggestion for the owner if the auth code grows.
- **Rows of a cut-off browser come back as guest rows** (a later request with the old session id inserts an empty row with no user id), and the tests share one session handler object, so they do not show that insert. Reason: a guest row signs nobody in; no assertion depends on it.
- **The red run of the Logout ordering test has no readable first failure line** (the 500 page's trace filled the output); the cause seen was the role query failing after the sign-out. Reason: found in the developer's report.
- **`node --test 'test/hooks/*.test.mjs'` was not run.** Reason: outside the allowed commands; no kit file was touched.

## 016: night run rows on Checker 1 (2026-10-08)

Minor review findings accepted, not fixed. None is on a path where untrusted input can widen the result, error the page or show other data.

- **`NightStepFilter::nightRowCount()` has no guard for a "not valid" filter.** The only caller (`MacroOutputController::index`) calls it for a valid filter. Reason: a guard that cannot be reached is dead code; on a wrong call the count would be 0 rows of step "null", nothing leaks.
- **The rule "the rows of the night" (state done, not PROCEED) and the rule "an Astra step" are now written twice** (`NightStepFilter`, and `NightRunSummary` / `NightRunController::astraStep()`). Reason: sharing them means changing two files this work was not allowed to touch. `test_S_06_4` pins that both give the same count. Proposed as a follow-up task.
- **The date formats `D, M j` and `M j` are written in two views** (`_night_run.blade.php`, `macro_output/index.blade.php`). Reason: two uses; a shared helper comes with the third.
- **The branch "the night tables do not exist" of `NightStepFilter` has no test.** Reason: trusted path (deploy state); the test database always has the tables. It fails closed ("not valid", no rows).
- **Untested here because the test database is sqlite:** the sub-select and the boolean `proceed` on MySQL and pgsql. Reason: built with the query builder only (bindings, no raw SQL except `1 = 0`), no MySQL or pgsql available in this worktree.
- **`test_S_07_5` and `test_S_07_8` read markup and script text; they do not run JavaScript.** Reason: the project has no JavaScript test runner and adding one is a new dependency. S-07.5 is also an owner check in `qa/stories.md`.
- **`test_S_09_4` has no variant "an Astra step with for_person 0 and an empty by-code list".** Reason: `test_S_09_3` covers the count of 0; the by-code list is unchanged code.

## 017: suppliers group on the item table (2026-10-08)

Minor review findings accepted, not fixed. None lets supplier data reach a view other than the effective CEO suppliers view, and none lets supplier text run as script.

- **`/item/quotes` and `/item/suppliers` answer `ok: true` with empty lists when their query throws** (the existing `catch`). The suppliers view's loaded state trusts that answer, so a failing query would show the red "wala pang supplier" band instead of "hindi na-load". Reason: trusted path (database state), the Old view says the same today, and the honest fix is in two endpoints this work was told to keep as they are. Proposed as a follow-up task.
- **The two toolbar links ("Suppliers view", "Old view") keep the current query on a plain click only.** A middle click or "open in new tab" uses the static address and opens the view with the default range. Reason: the address bar is rewritten by the page after load, so only a click-time handler sees the current query; a new tab is still decided by the server.
- **A CEO who switches to the Marketing view from the suppliers view and back lands in the Old view.** Reason: the Marketing render is the Old view by decision, and it keeps `layout=old` in the address as the Old view does.
- **The loaded-flag lines in the two loaders say "failed" twice** (the `else` and the line after the `catch`). Reason: harmless, and the first line is pinned text.
- **The add / edit form's markup is written twice in the suppliers table** (quote cell and "+N" list card). Reason: second repetition; a shared partial comes with the third.
- **"+N" clearance uses `:has()`** (Firefox before 121 does not know it; the chip would sit over the end of a long name). Reason: the owner's browsers are current; the full name is in the card.
- **The "+N" list card shows name, price, MOQ, edit and remove per quote, not link, photo, earlier price or date.** Reason: this is the design's list card; a quote beyond the third shows those details after an edit of a price moves it into the first three, or in the Old view. On the owner-check list.
- **When the row that holds an open form leaves the table** (a chip or category change), the form is no longer shown and the next "+" replaces what was typed; if the row returns first, the form shows where it was. Reason: caused by the user's own filter change; Esc recovers.
- **Esc with another window of the page open over a quote form closes both** (only the photo popup is given way to). Reason: the page's other windows each listen to Esc on their own; ordering them is a change to shared script.
- **`saveQuote()` itself has no guard against a second call** (the suppliers view disables its Save buttons while saving; the Old view and the photo page do not). Reason: shared function, unchanged by decision. Proposed as a follow-up task.
- **`splRemove` decides "a delete happened" by the list getting shorter.** A list that changes for another reason during the request could close or keep the card wrongly. Reason: no data effect; the existing delete function returns nothing to branch on and is unchanged by decision.
- **Tests that read script and markup text do not run JavaScript** (`test_S_14_8`, `test_S_15_10`, `test_S_16_1`, `test_S_16_6`, `test_S_17_4`, `test_S_20_1`, `test_S_20_2`, `test_S_21_1`). Reason: the project has no JavaScript test runner and adding one is a new dependency. Each has a browser case in `qa/stories.md`.
- **`test_S_14_1`, `test_S_14_3` and the order half of `test_S_14_7` pass on sqlite without the new sort** (sqlite already returns that order). Reason: the test database is sqlite in memory; `test_S_14_2`, `test_S_14_4` and the flag assertions are the ones that fail without it. MySQL and pgsql were not available here.
- **Not pinned by a test:** the suppliers view's `S-18.1` markers for a supplier name (a name never is in a page render; it arrives by fetch) and the hover and tap behaviour of the card. Reason: browser cases.

## 019: the Old view shows the suppliers table (2026-10-08)

Accepted, not fixed:

- **A CEO on `layout=original` who switches to the Marketing view and back lands on the table with suppliers.** The Marketing render keeps `layout=old` in the address, as it always did, and for the CEO view that word now means the table with suppliers. Reason: the Marketing render must stay byte for byte what it was, so it cannot carry another word; "Original table" is one click away.
- **The two toolbar links keep the current query on a plain click only** (a middle click or "open in new tab" uses the static address with the default range). Reason: built like the two links they replace; the address bar is rewritten by the page after load, so only a click-time handler sees the current query.
- **Two comments in the script of the table with suppliers still say "Suppliers view".** Reason: they sit in blocks this work was told to leave as they are, and they are rendered for the CEO view only.
- **The route tests prove "byte for byte the base" in two steps** (the route passes the view the six values of the pinned render; the render with those values has the pinned hash, in `test_S_18_4` and `test_S_19_2`). Reason: the pinned hashes are of direct renders with fixed page and fee data; a body from the route carries the test database's values, so it cannot have the same hash.

## 018: Astra identifies the J&T address like the classic checker (2026-10-08)

Review findings accepted, not fixed. Each fix loop was closed after two rounds. None of these can change a result while the switch is off.

**The text check (new rules only)**
- **A one-word near match on a different name is confirmed unless the other name is a label of the same city** ("Mariano" against MARIANA, 85.7). Reason: it follows from the settled 85 rule. The list has at least 9,744 such pairs of one-word names; 47 of them sit in one city and are caught.
- **"3. Poblacion 4. Cotabato" on one line confirms POBLACION IV.** Reason: a number with a full stop cannot be told from the end of a sentence ("Poblacion 4."); the forms `4)`, `4.)`, `4:`, `4]` are handled.
- **"Fatima V Luna St" confirms FATIMA V** (an initial written without its dot), and a customer who types a list label with its parenthesis word for word can confirm the name inside the parenthesis. Reason: needs the list's own spelling in the chat; six cases in the whole list.
- **A barangay whose name is close to its city's or province's name can be confirmed by "City, Province" alone** (PAMPANG by "Pampanga", BOLACAN by "Bulacan", 18 labels; 7 only through the near match). Reason: needs the model to name a barangay the customer never wrote, at medium confidence; the rule for a barangay named exactly like its city is built.
- **Two labels of one city that are the same barangay written twice** ("(BGY. 10 AND 11)" and "(BGY. 10 & 11)") confirm each other. Reason: same place.

**The program's mapping (new rules only)**
- **A form city that is a unique town name with an empty province maps to that town** ("Jaro" with no province gives Jaro, Leyte, although Jaro is also a district of Iloilo City). Reason: the spec lets a city without a province map when the list has one such city; the barangay must still be in the customer's text and exist in that town. Raised to the reviewer in the result as a decision for the owner.
- **A wording that ends in "City", for a city that is the only one of its name, maps whatever province the form gives** ("Lapu-Lapu City" with "Davao del Sur"). Reason: this is the list-filing case the spec asks for (Cotabato City under COTABATO); it needs a form that says "City" for another place.
- **A contradicting name inside a parenthesis of the province is not read** ("Leyte (Iloilo)"). Reason: no realistic wording found.

**The rule function**
- **Four switch-off paths have no captured test of their own:** existing fields valid with no line, a shop-details mismatch, an invalid phone with a valid line, a province taken from the list. Reason: the move was compared with the base statement by statement by the reviewing agent and the ten captured answers pin the rest; capturing more needs the base code, which is gone from the tree.
- **`replay.label_source` stays `model` when the guard then drops the barangay.** Reason: the word names where the line came from; `guard.result` says the rest.
- **The list file's path is written in two places** (`AstraEncoder::listCrc`, `MacroChecker::loadAddressMaps`). Reason: trusted path, two uses.

**The replay command and the settings box**
- **Two Astra steps for one night date would resolve to the newer one without a word.** Reason: the table's unique key on night and kind makes it unreachable.
- **The duplicate-phone information line costs two more reads per row that would proceed.** Reason: slow on a large night, not wrong; a nightly figure of a few hundred rows.
- **After a failed save of the settings form the box shows the stored value, not what was ticked.** Reason: CEO-only screen, trusted path.
- **Not tested: MySQL, and memory with real-sized chat columns.** Reason: the tests run on sqlite; the command uses the query builder and reads 200 rows per chunk.
- **For logs written before this change the model's own request for a person is inferred and can be wrong in both directions.** Reason: the log did not record it; the report says so in two sentences and prints strict and lenient counts.

**Fix list 1 (the text check, new rules only), accepted after its review**
- **Another barangay written with one wrong letter, or with its words joined ("BachawNorte"), is not seen as "another barangay".** Reason: left out for the time of the run; it needs the customer to name two barangays and the model to keep the withdrawn one. A near check directly after a barangay word would close it.
- **A short name (under five letters) or a bare number without a barangay word is not "another barangay"** ("ngayon sa Pias na po"; "ay mali, 29 po pala"). Reason: the exception the second amendment sets, to keep false holds down.
- **A bracket name written with a slash is not seen** ("Brgy Caluis/Cobra" beside SAN VICENTE (CALUIS/COBRA)); a label with a spaced dash is not seen either. Reason: the text is cut at slashes and dashes; four cases in the reviewer's sweep.
- **Two real pairs where a real word is one letter off the longer name's extra word** ("pala Salvacion" confirms PALTA SALVACION in Virac, "luma Punod" confirms LUMBA-PUNOD in Pantar). Reason: the one-letter allowance keeps "Anilao Labak" confirming ANILAO-LABAC, which an existing case requires.
- **"Poblacion" as the second barangay is not counted when the barangay being confirmed is itself a poblacion label; "malapit sa Poblacion" holds a row in towns whose poblacion carries another name.** Reason: ambiguous either way; the narrow rule was chosen.
- **A street, subdivision or landmark that carries the name of another barangay of the city (five letters or more) holds the row.** Reason: asked for by the second amendment; the real rate is unknown until a replay on stored nights.
- **The last fix commit (`0b4d443`) had no review of its own.** Reason: the time of the run; it only refuses, and its rows and the whole file are green.

**Fix list 2 (the text check, new rules only), accepted after its review**
- **Six ways of writing "Brgy Poblacion" are not seen as another barangay:** "Brgy, Poblacion", "Brgy ng Poblacion", "Brgy (Poblacion)", the two words on two lines, "BrgyPoblacion", "Brgy Población" with an accent. Reason: a break or a word between the two, joined words (already accepted) and an accented letter; each needs the customer to name two barangays and the model to keep the other one.
- **"Brg 5 pcs" confirms BARANGAY 5** (as "Brgy 5 pcs" already did). Reason: a number directly after a barangay word is the rule for numbered barangays; the count word after it is not read.
- **"Salamat. Barrio 28 Caloocan" and "sa Barrio 28" are not confirmed.** Reason: "barrio" counts for confirming only as the first word after a comma or a line break, so that "Bagong Barrio 28" never confirms BARANGAY 28; the safe direction.
- **"Brgy Camposanto 1 - Sur" does not confirm its own label** (labels that hold a spaced dash). Reason: unchanged from before; confirming was not to be changed in this fix list.
- **A bare "Poblacion" naming the customer's new barangay is not seen** ("dati sa Brgy X, ngayon sa Poblacion na po" confirms X). Reason: decided by the third amendment; the word is an everyday landmark.
- **The last fix commit of fix list 2 (`970b665`) had no review of its own.** Reason: it only turns the confirmations its review found back into "not confirmed"; its rows, both test files and the two clean-address counts are unchanged.

## 020: the item table fits the screen, one column per supplier (2026-10-08)

### Part 1: the supplier columns

Minor review findings accepted, not fixed. None lets supplier data or a new marker reach a view other than the CEO's table with suppliers, and none lets supplier text run as script.

- **The warning mark and the "Need a supplier" chip read a PO differently in two rare cases.** A PO line with a quantity and a cost of 0 (a free sample) keeps the item out of the chip's list, but the item is marked; a PO line with a cost and a quantity of 0 does the reverse. Reason: both rules are the ones of the base (the red band used the same rule as the mark), and the chip keeps its rule by decision; on the usual data they give the same items (`WorklistTest::test_S_37_4…`). Proposed as a follow-up task.
- **The server's `cheapest` flag counts every quote of the item, also one whose supplier has no column.** A lone visible price could then be underlined. Reason: only reachable when a supplier row is deleted in the database by hand; the page has no way to delete a supplier.
- **A PO row without a supplier id keeps the item unmarked and shows in no column.** Reason: the server sends the id on every row now; an old cached answer cannot occur (the list is fetched at load).
- **The cell's peso formatter is not hardened against a non-numeric or astronomically large price.** Reason: the server sends a number or nothing and caps a price at 99,999,999.
- **The address of the script file carries a version computed from the file on every render of this table, and the page fails loudly if the file is missing on the server.** Reason: a missing file would leave the table unusable either way; a loud failure is found at once. Named under Merge danger in the result.
- **`public/js/item-table-fit.js` is readable without logging in.** Reason: it holds logic and comments only, no supplier and no price; no render other than the CEO's table with suppliers names it.
- **Test helpers are copied** (`normalise()` in two classes with different base classes; `supplier()`, `quote()`, `po()` in three). Reason: moving them into the shared test case would edit existing test classes this work has no other reason to touch. Proposed as a follow-up task.
- **Tests that read script and markup text do not run the page's script** (the pins of `SuppliersGroupTest`). Reason: as in slice 017; the logic that can be wrong silently moved into the pure functions, which do run (through node).
- **Focus after a save or a remove, the placement of the cards, the cut of a long header and the touch behaviour are not proven by a test.** Reason: browser cases; listed for the reviewer in the result.

### Part 2: the fit

Minor review findings accepted, not fixed. None reaches a view other than the CEO's table with suppliers, none lets stored or supplier text run as script, and none changes the stored column setting except through the drag this work was asked to make safe.

- **With an empty supplier list the page fits as if there were one supplier.** The placeholder column (the link to Finance → Supply) needs a width, so the page hands the fit the group's width in columns (never below 1). The fit function itself gives the spec's row for no supplier. Recorded as a ruling in the result.
- **The merged Prof.% cell on page rows and in TOTAL has no "profit / gross" tooltip any more.** Reason: each of the four old cells had its own sentence; one cell that changes with the period would need a fourth helper for a hover text. Proposed as a follow-up task.
- **A failed save of the column order after a drag is silent** (an expired session, for example), and two fast drags are two requests with no ordering. Reason: the same as the page's own `saveCols()`; the table and the browser copy show the new order until reload. Proposed as a follow-up task for both.
- **The guard against a flip-flopping width also ignores a real widening back to the previous width within 0.6 s** (a side panel closing, the window width unchanged). The table then stays on the narrower fit, which still fits, until the next size change. Reason: the narrower fit is never wrong, only less full; the guard is what makes a loop impossible.
- **In TOTAL, a sum just under a million is rounded at the peso** ("₱1,000,000" for 999,999.50) **and a sum just over is shown with two decimals of a million** ("₱1M" for 1,004,999). Reason: the format the brief asks for; the full value is in the title.
- **The methods that connect the page to the pure functions are pinned as text and were run once outside the suite** (the page's own script loaded in node with a stand-in for the browser: fit at 1366 and 1920, both sets, a junk set, a refused column, the period switch, a drag that posted 44 unique ids in one request), not by a permanent test. Reason: the project has no browser test runner; the logic itself is in the pure functions, which the suite runs.
- **The campaign rows inside an expanded page keep the page's own money format.** Reason: that block is a partial shared with another page (`owner._private_expand_inline`); this table's format stops at its own rows.
- **Content wider than its cell at the minimum widths is cut, not wrapped** (the category select at 88 px, the placeholder link at 72 px, a long promo). Reason: a fixed layout is what keeps the table inside the box; the columns concerned are hidden in the owner's settings today or one step away in both sets. Listed for the browser check.
- **Not pinned by a test:** real `scrollWidth` against `clientWidth`, the single jump when the supplier list arrives, the sticky column below 1280 px, the panel's focus. Reason: browser cases; listed for the reviewer in the result.

### Fix list 1 (after the first look in a browser)

Accepted, not fixed:

- **What made the table's box change width twice in the reviewer's session is not known.** The fault it exposed (a widening back within 0.6 s was ignored and the narrower fit stayed) is fixed and tested on the page component; whether that sequence is what was seen cannot be proven without the browser. Reason: no browser in this work; the result says what to look at.
- **A hovered item row gets one line taller** when its DOI lead line appears. Reason: the brief puts that line on the row hover; keeping its space reserved would make every row a line taller.
- **A number with no space that is still wider than its minimum-width cell breaks onto a second line** (estimated: a five-digit loss with centavos in PROF.PROFIT and PROF.PROFIT(1D) at their minimum). Reason: the minimum widths are the spec's; a broken number is readable, a cut one is not. The result names the two columns and the pixels they would need.
- **The widths of real text were estimated from the font's digit widths, not measured.** Reason: no browser. Proposed before: a browser test for this table.
- **The page component is run in node against stand-ins for the browser** (`tests/js/page.cjs`): it proves the fit's decisions and the markup's bindings, not what a browser draws. Reason: the project has no browser test runner.

## 026: checklist access rules and multi-day report (2026-10-15)

- **`/assign-roles` (GET and POST `/assign-roles/{id}`) has only the `web` middleware**: any visitor, or anyone who signs up on the public `/registerlogin` page, can give any user any role, CEO included, which undoes every role rule in the app (the checklist's too). Reason: outside this brief and the legacy rule; it needs its own brief with priority.
- **"Role contains OIC" also matches roles like "INVOICE…", "VOICE…" or "CHOICE…"** once uppercased. Reason: the brief's rule as written, and roles are set by the owner; switch to matching `- OIC` if such a role ever appears.
- **The report page shows "Server error (403). Please try again." when the AI routes refuse**, not the 403 `message`, and the history panel comes up empty. Reason: the brief keeps the page as it is; a small page change would show the message.
- **The report page still prints the latest analysis and approval text (with its prompt) of every submission the viewer can see**, although the log routes now refuse other people's submissions. Reason: the brief keeps what each role sees; whether staff should see co-workers' AI text there is the owner's call.
- **A task's creator who is plain staff can still duplicate it** (making a new task) although only CEO and OIC create tasks, and **an OIC may create a task in, or move one to, any department** (department is free text). Reason: both are the brief's rules as written; the owner may want to narrow them.
- **On a group task only one person submits per day, so the other assignees count a missed day** in the per-person table even when the task grid shows the day done. Reason: the brief's definition ("days with zero submissions among the days they had a due task"); the owner may prefer counting a day as covered when the task was done by anyone.
- **A task deleted on the first day of the range drops out of the whole range** with any submission made that morning (the day view's fallback still shows it). Reason: edge case; the task was not due on any day of the range.
- **The frequency rule now lives in three places** (`index()`, `report()`, `rangeReport()`), and `once` reads differently in each (the range view: due up to and including the day of its first submission). Reason: the legacy rule rules out refactoring the day view and the daily page here.
- **Untested:** review of a submission whose task is soft-deleted, two approval runs within the same second (the id tie-break), `deleteFile` with another spelling of CEO (the same helper as `deleteSubmission`, which is tested), the CSV as a non-CEO (same data as the tested view), the SQL on real MySQL or pgsql (the suite is sqlite only), and the 1366 px layout (no browser in this run).

## 028: checklist fields to extract and the task change log (2026-10-15)

- **Weekly tasks may lose their day on every save (already true before this brief).** The create and edit forms on `/checklist/manage` have two inputs named `frequency_day` (the weekly select, then the monthly number box, hidden but still sent); PHP keeps the last one, so a weekly task is saved with an empty day and counts as due on Sundays. The new change log will show it as "Frequency day: 3 → —". Reason: outside this brief (no change to how tasks are scheduled). Suggestion for the owner: disable the hidden input, or give the two inputs different names.
- **A malformed values answer on a re-run keeps the submission's previous values** instead of clearing them. Reason: a flaky AI line should not erase good data; the warning in the log says which submission. Revisit if stale values ever mislead.
- **`max_tokens` stays 512 on the approval call.** With the 20-field cap the values line needs at most about 300 tokens beside the 2-3 sentence explanation. Reason: no change to the existing call settings; raise it if tasks ever need more fields.
- **Reorder and bulk actions write one history row per task that changed**, so one drag can write many rows. Reason: per-task rows keep each task's history complete and follow the edit rights per task.
- **`/checklist/manage/history` loads every task (with deleted ones) to apply the edit rule in PHP.** Reason: about 100 tasks; keeps the rule in the one helper the write routes use.
- **Not seen in a browser** (fields editor at 1366 px, chips, history page). Reason: no browser in this session; listed for the owner's check after release.

## 029: orders counted live, main item fresh, tie days kept (2026-10-16)

- **`/encoder/checker_1/update` and `/encoder/checker_1/update-field` write any column named in the request (already true before this brief).** `bulkUpdate` passes `records[id][…]` straight to `update()`, and `updateField` sets `$record->{$field}`. Reason: outside this brief (only the recompute and cache bump were added). Suggestion for the owner: an allow-list of editable columns.
- **JNT supply counts orders and proceed per base item ("1 x X" and "2 x X" together)**, as its proceed already did, while `/owner/private` counts the exact item. Reason: the brief asks for orders and proceed from the same query and key, and changing the supply page's proceed key would change its profit numbers. Revisit if supply profit % should follow the exact main item.
- **An alias save recomputes only the last 90 days of that item.** Reason: same default window as the refresh button; older days follow on the next manual refresh.
- **Each inline edit of item, COD, status, date or page recomputes one or two days**, inside the request. Reason: one day per page is a few hundred rows; move to a queued job if encoders notice a slower save.
- **The refresh button's error JSON shows the exception message (already true before this brief).** Reason: CEO-only route; outside this brief.
- **Untested:** the SQL on real MySQL or pgsql (the suite is sqlite only; the `ts_date` trigger is emulated in one test), the macro import's own recompute (unchanged), and the "tie" marks in a browser (no browser in this run).

## 041: /page-settings as a sheet-like grid (2026-10-17)

- **"+ Page" and "+ Column" live only in that browser until a value is entered.** Storage is rows only (Page | Key | Value), so an empty page or column is not saved, and a reload or another user does not see it. Reason: the brief keeps the storage unchanged. Revisit if the owner wants empty rows to persist.
- **The last-sync column reads `MAX(id)` per page over all API runs on each page load.** About 200 pages × 24 runs a day grow the table by about 5,000 rows a day; there is no (source, page_name) index. Reason: storage is out of scope for this brief. Suggestion: an index on `botcake_psid_import_runs (source, page_name, id)` if the page gets slow. The poll runs this only when a run changed.
- **The poll query (`created_at >= ? OR replaced_at >= ?` on `page_settings`) has no index on those columns.** Reason: the table holds a few thousand rows (pages × keys × history); storage is out of scope.
- **"+ Page" checks `macro_output` for the name without a `PAGE` index**, so a name that matches nothing scans the table once. Reason: same check as the 040 import command; one click by the CEO.
- **After a token or Botcake ID edit by Enter, the table is rebuilt when the save returns and keyboard focus goes back to the top.** Reason: minor; the refocus covers every other cell.
- **Each editable cell takes two Tab stops (the cell, then its History button).** Reason: usable; a key for history would need more keyboard handling than this brief asks.
- **The grid's JavaScript has no automated test and was not opened in a browser in this run** (no JS test setup in the repo; the app needs the server database to run). Reason: the server-side rules are tested; the owner's first use is the browser check. Check: edit a cell while another tab changes it, then press Escape; type a token and press Enter; add a page and a column.

### 041 amendment 1 (2026-10-17)

- **The ad name on the orders list comes from the ad's row with the highest `id` in `ads_manager_reports`, not the newest `day`.** If an older day is imported later, its name is shown. Reason: one simple grouped query; reports are normally imported in date order. Revisit if names look stale.
- **`%` and `_` typed in the customers search act as wildcards.** Reason: read-only and CEO only; sqlite needs an `ESCAPE` clause the other drivers don't, so escaping would need a driver branch.
- **The `macro_output` page-name match (`LOWER(TRIM(PAGE)) = ?`) is now written by hand in three places** (`PsidCoverageController::pageExpr`, `ImportBotcakePageSettings`, `BotcakeCustomersController::orderCountsQuery`). Reason: no shared helper exists for `macro_output`; one is worth adding on the next change there.
- **The SIGTERM/SIGINT handler is not covered by a test.** pcntl is not on this Windows machine; the test calls `forceRelease()` directly. Check on the server: kill a `botcake:sync-customers --page=… --full` run with Ctrl+C and confirm the next run does not print "Skipped".
- **Postgres `NULLS LAST` ordering in the customers view is not run by a test** (the suite is sqlite only, and sqlite already sorts NULLs last when descending).
- **Between pages, a run can lose the lock to another run** (one lock per page): the second run then syncs while the first prints "Skipped" and stops. The two never sync at the same time. Reason: a lock per page keeps every lock well inside its 2 h TTL.
- **`botcake_psids` has no unique index on (source, page, psid) (already true before this brief).** Two syncs at the same moment could insert a duplicate row. Reason: the lock prevents concurrent syncs; storage changes are outside this brief.

## 042: grid rows from orders, Test lahat ng token, Sync lahat (2026-10-17)

- **On MySQL the order-count query groups `PAGE` with the column's case-insensitive collation**, so spellings that differ only in case arrive as one group and the name shown for a page without settings is whichever spelling MySQL returns, not always the most common one. Reason: only the shown name changes (counts and merging are right); a `BINARY` grouping would need a driver branch. Pages with settings show the setting's spelling anyway.
- **A Sync lahat page killed by the 1800 s job timeout leaves the Botcake lock held until its 2 h TTL**, so the next pages wait (they are not skipped) and other syncs are skipped meanwhile. Reason: the by date and window reads stop themselves after 22 minutes (spec 053; the incremental read with its 300-batch cap is gone), so the timeout is far away; `failed()` cannot tell whether the lock it would free is its own.
- **If queueing the next page fails after a page was counted, the run stops and the button stays disabled until the run's state expires (2 h 10 min).** Reason: a database insert into `jobs` failing is rare, and the state expires on its own.
- **`/page-settings/test-token/one` with an array `page_name` returns 500** (same `(string)` cast as the other `/page-settings` endpoints). Reason: CEO only; no data is written.
- **Missing tests (minor):** two presses at the same instant (the start lock), a run with zero eligible pages, and the greyed rows and buttons in a browser (JavaScript, no JS test setup). Check in the browser: the busiest page on top, pages without orders grey at the bottom, Test lahat ng token counting "n / N" and its summary, Sync lahat showing "n / N, ngayon: …".
- **The schedule's `botcake:sync-customers` stops its run with "Skipped" when Sync lahat holds the lock between two of its pages** (existing per-page lock, see 041 amendment 1). Reason: the schedule is off in production; turning it on is a separate decision.
- **`SyncBotcakeCustomersJob` (the row's Sync now, from 040) has `$timeout = 3600`, above the database queue's `retry_after` of 1900 s** (already true before this brief). Reason: not changed here; a page sync that long would be retried by another worker while still running.

### 043 delivery status per campaign / ad set / ad (2026-10-17)

- **The visible /owner/private page row is not the row these numbers add up to.** The page loads `itemSummary()`, whose J&T figures match `from_jnts` by sender (`page_sender_mappings`), over the 60 days before the date, per item + COD. The new columns follow `data()` (orders in the filter range, J&T by waybill), which is the only way to attribute to an ad. Reason: the brief names `data()`; which row the owner wants matched is a decision for the owner.
- **No test compares the campaigns + "Walang ad id" with the real `data()` row.** `data()` cannot run on sqlite (`::int` casts, `ALTER TABLE … ADD PRIMARY KEY`); the flag SQL is shared code instead of a copy, and the test checks the service's own total. Check on the server: compare one page's `page` total from `/owner/private/ad-delivery` with `/owner/private/data` for the same range.
- **`partial_date` is ignored by the delivery columns** (they stop at the end date). Reason: `data()` ignores it as well.
- **On MySQL, an accent-insensitive collation can return a page key spelled differently from the one asked for, and that page then shows zeros.** Reason: rare; the front end sends the page labels as stored.
- **`AdDeliveryStatus` writes `PAGE` by hand where `data()` looks the column name up (`pickCol`).** Reason: trusted schema; production is MySQL with `PAGE`.
- **~~The endpoint accepts any date range for up to 500 pages.~~** Fixed in amendment 2: at most 92 days, and each result is cached for 10 minutes under the /owner/private cache version.
- **After a date change, an expand that is already loaded keeps its old numbers** (existing expand behaviour). The label shows the range those numbers came from.
- **A delivery-only campaign row (orders but no spend in the window) shows "Off" and no ad sets.** Amendment 2 adds the note "walang spend sa range — orders lang, kaya walang ad set sa ilalim". It still shows "Off": its ad sets are not in the window's list, and its numbers are needed for the sum.

## 044: the first sync of a page reads its whole window (2026-10-18)

- **The 22-minute budget is checked between batches, so a run can still pass 1800 s in theory**: the check lands just under 22 min, one batch then takes its worst case (about 5 min: 4 tries × 30 s plus backoff and Retry-After), and matching follows. Reason: that needs every try of one batch to time out at the end of a long run. If the worker kills it, the next sync resumes from the saved batch, though the page lock stays held up to 2 h (see 042).
- **The per-row Sync now job keeps `$timeout = 3600`, above `retry_after` (1900 s)** (from 040). Reason: a window read now stops itself at about 22 min, far below both.
- **Missing tests (minor):** a `--full` that errored, followed by a sync on a page that never completed a window read; and the grid's "Huling sync" text for old runs with no kind (the script drops empty parts with `filter(Boolean)`). Reason: both follow from rules that are already tested.

## 045: Sync lahat control (2026-10-18)

- **An old cache run whose chain was already broken before the deploy is adopted as running until its cache key expires** (up to 2 h 10 min); it is then closed as stopped, because no job holds it (amendment 1). Reason: the old chain normally runs to its last page, and Stop clears it sooner.
- **A job lost without `failed()` (the worker killed hard, the jobs table cleared) leaves its page claimed.** Resume then has nothing to send, and the run shows as running. Reason: Stop clears it, and a new press starts again from the plan of each page, which continues every unfinished window read.
- **The poll reads the old cache key on every request (one cache query with the `database` store).** Reason: it is one indexed read. It can go in a later release, once no old run can be left.
- **The rule "has a Botcake ID and token" appears three times** (`PageSettingsGrid::rowInfo`, the mini rows in `changes()`, the page's script), and the summary labels appear twice (`BotcakeSyncAll::SUMMARY` and the script). Reason: small; a shared helper is worth it on the next change there.
- **A page killed by the 1800 s timeout leaves the Botcake lock held up to 2 h, so the next Sync lahat page re-queues itself every 30 s for that long** (from 042 and 044). Reason: rare, and it recovers on its own; Stop ends the run.

## 046: /chatblast (2026-10-18)

- **The 2 requests a second per token is shared by chatblast jobs only.** The Botcake sync on `default` keeps its own spacing, so one page's token can see about 4 a second while both run. Reason: the sync and chatblast rarely hit the same page at the same moment; giving the sync the cache-based rate key changes its tested timing and belongs in its own change.
- **An `unknown` message (send_flow outcome unsure) cannot be resolved on the page** (marked sent or sent again). Reason: product decision; the safe default is never to send again. Check in Botcake and, if it did not arrive, add the order to a later kind or send by hand.
- **The daily cap is counted, not reserved.** Safe because the `chatblast` worker runs with numprocs 1; with more processes two jobs could pass the cap by one each.
- **The J&T column of the list uses `MAX(from_jnts.status)`** when a waybill has several rows (as /page-settings/psid-coverage/orders does). Reason: the usual case is one row per waybill.
- **When two builds run in the same second, the counts in the notice can be off** (the rows themselves are never doubled: the unique `dedupe_key`).
- **Saving a template re-renders the unsent rows one UPDATE each.** Reason: fine for a few thousand rows; a bulk CASE update is worth it if the list grows past that.
- **(amendment 1) A `skipped` row keeps its `waybill|kind|day` key.** That day is never built again for the waybill (a cap-skipped day can still be sent by hand from the list; a stale or removed day is not sendable). Reason: building it again would risk the same day twice; the owner sends it by hand.
- **(amendment 1) Saving the days re-renders every unsent real row of the kind, one UPDATE per changed row, inside the request.** Reason: fine for the few thousand rows a kind holds; a queued job is worth it if a save gets slow.
- **(amendment 1) The editor's preview uses a fixed sample order** (no rider info), not a row from the list. Reason: rows in the list do not carry their order details to the page.
- **(amendment 2) A Facebook name that itself contains a placeholder such as `{item}` makes the text refused for good** ("hindi buo ang text"). Reason: fails safe; such names are not expected.
- **(amendment 2) The per-customer lock needs a cache store with locks** (`database` with `cache_locks`, as `.env.example`; also redis). Check the server's `CACHE_STORE` before release.

## 047: J&T auto track for chatblast (2026-10-18)

- **A delivering row tracked less than 30 minutes ago is sent without a new track**, so a failed attempt inside those 30 minutes is not seen (the brief's 30-minute rule). A later build in that window does catch it.
- **`status_logs` is read and rewritten per row without a lock.** An hourly upload writing the same row at the same moment can lose one log entry (never the status). Rows can also flip between the report's and J&T's status, adding about two log entries an hour. Reason: bounded; changing how uploads write is out of scope.
- **The text of a delivering row can change at send time** (new rider, other version) after the owner looked at it; "Send test" uses the text as it is. Reason: the brief wants the rider in the message; the side panel shows the text that was sent.
- **The build's candidate list for tracking runs the candidates query with its subselects under DISTINCT.** Reason: correct, a little heavier than needed.
- **Missing tests (minor):** the manual job's dry run and unmapped counters after the refactor; the flat single-item J&T answer shape (the parser already handles it); the build route returning `tracked` and `rider_pending`.
- **(amendment 1) The tracking order runs the candidates query (with its subselects) once per kind, unchunked.** Reason: indexed and the same cost as before; it only orders the waybills to track.
- **(amendment 1) A build does not refresh the vars of rows it already built;** their rider is refreshed at send time (re-tracked when older than 30 minutes, and dropped when J&T does not answer). Reason: never a stale rider either way; refreshing old rows at build is extra work for the same result.

## 048: chatblast campaigns (2026-10-18)

- **Saving a cell re-renders the unsent rows after the campaign row's lock is released.** Two saves a moment apart can let the older re-render finish last, so unsent rows carry the other cell's earlier text until the next edit. Reason: small window; the sender renders nothing itself, and the next save or build corrects it. Moving the re-render inside the lock holds the row lock for the whole re-render.
- **Deleting a campaign checks for messages without a lock.** A build running at that moment can leave rows of a deleted campaign; those rows skip the off, stale and once-a-day checks at send. Reason: deleting is only for an unused campaign and is rare; "off" is the normal way to stop one.
- **Only `chatblast:auto` checks that `chatblast_campaigns` exists.** Between the deploy and `migrate --force`, /chatblast's send and build and any queued job fail (jobs end `failed`, safe to resend). Reason: migrations run right after the deploy; skipping the checks without the table would be worse than failing.
- **Removing a version that is the only one of a middle day removes that day, and the later days move up** (unsent rows of day 3 take the old day 4's text). Reason: the brief's remove button; the confirmation says so.
- **Missing tests (minor):** the 3-hour edge of a send time (runs at 11:59, not at 12:00 for 09:00); a campaign turned off or made manual between the build and the claim.
- **`Chatblast::setSequence()` is only used by the tests** of the day/version rules. Reason: it keeps the 046 validation tests readable; the routes use `editDays()`.
- **The campaign `log` keeps the last 200 changes in the row** (text edits with old and new text). Reason: enough history for "who changed what"; it is left out of every read except the save itself.
- **The stale-cell guard only runs when the request sends `old`.** The page always sends it; another caller that leaves it out edits by position. Reason: only the page calls these routes.
- **A `from_jnts` status outside In Transit / Delivering / Delivered / For Return / Returned ties with In Transit,** so between such copies the newest row decides. Reason: uploads may write any report text; ranking unknown statuses higher let an early stage beat a real In Transit, ranking them lower let a stale In Transit beat Problematic. Worth a check of the distinct statuses on the server.
- **(amendment 1) The flow panel finds a flow or field by id at any depth of Botcake's answer,** so a folder with the same id as the flow would lend its name. Reason: Botcake ids are unique per kind in practice; the answer comes from a trusted service.
- **(amendment 1) The real shapes of Botcake's `flows`, `message_templates` and `custom_fields` answers are not checked here** (no access); the parser is tolerant (nested lists, `components` BODY or `body`). Check one page's panel on the server after release.
- **(amendment 1) Missing tests (minor):** errors cached for 60 s; a template with a plain `body`; two templates matching the flow name; a matching template that is not approved; flows nested more than one folder deep.
- **(amendment 1) The panel's sample text is cached with the Botcake answers for 10 minutes,** so an edited Day 1 text shows there after "Refresh". Reason: the sheet already previews every text live.
- **(amendment 2) Auto never sends a row built before today or before the switch to auto,** including a `no_psid` row that only becomes ready on a later day (its key blocks a new row). Reason: the reviewer's rule against sending the backlog; such rows go by hand from /chatblast, and the confirmation says so.
- **(amendment 2) The "ready now" count in the switch-to-auto confirmation leaves out waybills that have no row yet;** the first auto build makes rows for them and sends them. Reason: those are new rows from today, which auto is meant to send.
- **(amendment 2) Adding a send time that has already passed sets `auto_ran_at` to that time,** which also swallows an earlier slot of today that was due but had not run yet (for example 09:00 waiting for the 09:05 tick when 10:00 is added at 10:02). Reason: narrow, and the next slot runs as usual.
- **(amendment 2) If a job is hard-killed during sending and its customer lock expires (300 s) before `killed()` marks it,** the row is still `sending`, which the across-campaigns rule does not count, so the other campaign could send too. Reason: rare; the same-campaign rule (`blockedToday`) counts `sending` and stays outside the lock as before.
- **(amendment 2) A row skipped "may naipadala na ngayong araw sa ibang campaign" that was queued before midnight and skipped after** lets one extra row be built and skipped again that day. Reason: harmless churn.

## 049: card counter-check of Meta ad payments (2026-10-18)

- **"±2 days" is measured from the sale date only.** A charge with sale 09-09, post 09-12 and a Meta record on 09-13 is not matched, and a charge with only a post date is "Wala sa Meta" without being tried on its post date. Reason: the brief orders the passes sale date, post date, ±2 days; measuring the near pass from the sale date keeps one meaning for `match_days`. Revisit if real statements show long sale-to-post gaps.
- **The statement list shows the totals saved at the last "Itugma"; the statement view recomputes against today's Meta records.** After a new Meta import the two can differ until "Itugma ulit". Reason: the view shows the current truth and the list stays cheap; the view says when it was last matched.
- **Route model binding reads `card_statements` before the `Schema::hasTable` guard,** so the statement and match routes answer 500 (not the "migrate" note) before the migration runs. Reason: only until the migration is run by hand on deploy; the list page has the guard.
- **smalot/pdfparser parses the uploaded PDF without its own memory or time cap.** Reason: only the CEO uploads, a bank's own statement; PHP's memory_limit and max_execution_time still apply.
- **The PDF and screenshot size limits depend on the server** (`upload_max_filesize`, `post_max_size`, and the gateway timeout for a slow vision read, up to 90 s). Reason: server config, outside the code; to check on deploy.
- **The screenshot itself goes to OpenAI with the full card number in the image.** Reason: the brief chose the vision model for screenshots; only the parsed, masked lines are stored. Use a PDF, or crop the number out, to avoid it.
- **Missing tests (minor):** none of the browser behaviour is automated (the Alpine edit serializer, the status filter buttons); the vision answer with more than 500 lines is covered, an answer with a null `content` too.
- **(amendment 1) A single day with more than 150 rows is never split across pages.** Reason: a page holds whole days so its total row stays whole; today's statements have far fewer FACEBK lines a day.
- **(amendment 1) A Meta record outside the statement period that matches nothing is not shown.** Reason: it belongs to another statement's period; the spec counts "wala sa card" only inside the range.
- **(amendment 1) The browser-only parts are not automated:** the unsaved-edits warning on the edit form's page links, and how the side-by-side columns look at phone width. Reason: no browser in this session; the rendered HTML is tested.
- **(amendment 2) The ±1 day group step reserves only for the other day's same-day matches.** A record one day off that a single line two days away would have near-matched can go to the group instead; that line then shows "Wala sa Meta". Reason: the overall difference and the chips stay right; it only moves which row shows the gap.
- **(amendment 2) The 5 s / 45 s OpenAI timeouts are in the code but not asserted by a test** (the fake HTTP client does not expose request options). Reason: constants in `StatementVision`, read in review.
- **(amendment 2) The 10-minute reuse after a 504 relies on PHP-FPM finishing the request after nginx gives up** (`request_terminate_timeout` unset or above 60 s). Reason: server config; with the 45 s read the request normally ends before 60 s anyway.
- **(amendment 2) A card number split by more than 16 separator characters, or of 20+ digits, is not masked as a card;** an 8+ digit run is still masked next to card/acct/account/no./number. Reason: wider gaps in `pdftotext -layout` are column breaks between different values.
- **(amendment 2) Two grouped card numbers side by side merge into one mask** ("4111 1111 1111 5003 4111 1111 1111 5004" → "···· 5004"), and a short number right after a card is masked with it ("… 5003 12" → "···· 0312"). Reason: nothing leaks; telling them apart from a 14–19 digit card is not possible from the digits.
- **(amendment 2) A card number glued to cents ("…5003.50", "…5003,12") is read as an amount and not masked as a card.** Reason: the same guard keeps amounts whole; no statement layout prints a card number glued to cents.
- **(amendment 2) The double-submit guard needs a cache store with locks** (database, file or redis; the `array` store only locks within one request). Reason: the server's cache setting, to confirm on deploy.

## 051: checklist deadlines, late tasks in the staff feed, Checklist badge (2026-10-18)

- **An OIC can override their own submission** when their department key matches their own (a Marketing - OIC's own Marketing task). Reason: the amendment names the department OIC as the override without an exception; ask the owner whether the submitter should be left out.
- **The department rule exists twice:** `ChecklistController::oicDepartment` (OICs only) and `ChecklistBadge::departmentKey` (any user: profile department, else the role without "- OIC"). Reason: legacy rule, no refactor of the existing method; they agree for every OIC role.
- **A deadline set earlier than the scheduled time is taken as is** (scheduled 23:50, deadline 00:05 → late from 00:05 the same day). Reason: only task editors set it, and the field means "late after this time"; a warning in the form would be a separate change.
- **The check-limit lock needs a cache store with locks** (database, file or redis; the `array` store only locks within one request). Reason: the server's cache setting, to confirm on deploy.
- **The automatic approval check at submit counts as check 1 of 3** (amendment 2), so a staff member gets two manual re-checks. The automatic check itself is not limited. Reason: as decided.
- **Untested here:** the `selectSub` override join and both migrations on MySQL (sqlite only), the badge and the override form in a browser. Reason: no MySQL or browser in this session.
- **When a submission is locked, the Analyze button stays enabled** (relabelled "↻ Analysis lang", runs the analysis only, the limit text shows under it). Reason: the one button runs both the analysis and the approval check; the analysis has no limit.
- **The race test holds the lock itself** instead of running two real requests at once, so it proves the 409 path, not the order of lock and count. Reason: PHPUnit runs requests one at a time; the order is read in review.
- **A single-process PHPUnit run can stop at a 55 s time limit on Windows:** controllers call `set_time_limit` (e.g. `ChatblastCampaignController` 55 s), and on Windows the limit counts wall-clock time for the whole process. Reason: not this spec's code; the suite was run one folder per process. Suggestion for the owner: skip or reset the limit when running tests.
## 050: chatblast messages and sequences (2026-10-19)

- **Adding a send time that has already passed stamps the auto run at that time,** which also skips an earlier time of today that was due but had not run yet. Reason: narrow (two times minutes apart, edited in between); the next time runs as usual.
- **A message shared by two or more sequences is re-synced one sequence at a time after it is saved;** if the request dies during a long re-render, a later sequence keeps the old `days` until the next edit of that message or its steps. Reason: rare (shared messages, a killed request); the next edit corrects it.
- **The message page's send counts credit every past send of a (sequence, step) to the message on that step now,** so after a message swap or a step move the counts mix both. Reason: the rows store the day and version, not the message; good enough as a guide.
- **The new columns have no `hasColumn` guard,** so between the deploy and `php artisan migrate --force` the chatblast build, send jobs and `chatblast:auto` fail (rows end `failed`, safe to resend). Reason: migrations run right after the deploy (merge danger noted in the result).
- **The campaign's own cap counts and then sends without a lock,** so two workers could pass it by one each. Reason: the `chatblast` worker runs with one process, as for the page cap (046).
- **The step endpoints check what the page saw only when it sends `steps` and `expect`;** a hand-made request without them is not guarded, and `/step-times` does not compare the old times (last save wins). Reason: the page always sends them; times are visible on the page.
- **Missing tests (minor):** the page cap still applies when the campaign cap is higher; another sequence's sends on the page don't count toward a campaign's cap; a step's own time on an excluded weekday or past the 3-hour limit.
- **(amendment 2) Inside an hourly range, a row skipped in a way that frees its key (campaign cap, page removed, off) is built again at the next hour and skipped again,** up to once per range hour per waybill that day. Reason: no double send (the skip happens again before Botcake); only extra rows in the list. Skipping the rebuild would need a per-day memory of the reason, as for the across-campaigns skip.
- **(amendment 2) `send_range` has no `hasColumn` guard either,** so it shares the deploy-to-migrate window of the other 050 columns (merge danger: migrate right after the deploy).
- **(review) Today's failed and skipped counts on /chatblast/sequence look only at rows built in the last 62 days** (the longest window plus 2), so the index on (status, created_at) is used instead of scanning every skipped row. A much older row sent by hand that fails today is not counted. Reason: display only; such rows are rare.
- **(review) `auto_runs` joins the other new columns without a `hasColumn` guard** (same deploy-to-migrate window; merge danger in the result).
- **(review) A failed optimistic save on the sequence page restores the value it saw at click time,** which can undo a newer value from a second save still in flight; nothing wrong is stored on the server, and the page says to reload. Reason: the server refuses the stale save; a reload shows the truth.
- **(review) Missing test (minor):** two overlapping auto runs claiming the same time (the second claims nothing). Reason: the claim is under the row lock and `queue()` only moves rows that are still sendable, so a row is never queued twice.
## 052: flow names and shipping template on /page-settings (2026-10-18)

- **A flow name that itself begins with a marker** (`!wala`, `!bura`, `!hindi approved`, `!hindi nasuri: `) is shown as that marker. Reason: unrealistic Botcake answer; the worst case is a wrong grey or red line, no data lost.
- **The flow-key and detail-key patterns live in PHP (`PageSettings::DETAIL_PATTERN`, `BotcakeFlowNames::watches`, the `refreshFlows` filter) and in the grid JS (`FLOW_KEY`, `DETAIL_KEY`).** Reason: the browser needs them without a round trip; they change together, and the tests cover the PHP side.
- **`POST /page-settings` (`store`, no UI) changes a flow ID without a refresh.** Reason: the grid saves through `/page-settings/cell`; the next Test token or cell save fills the name.
- **The re-checks of a flow ID or template name before a write are not under a lock.** Reason: they narrow a window of up to 20 s to milliseconds; the next save or Test token rewrites the line.
- **No JS test for the grid's second lines or for ignoring a late save response** (per-cell sequence number in the view). Reason: the project has no JS test harness (adding one is a new dependency); S-052.10 is an owner check by hand.
- **The auto-picked template is stored in `botcake.template.shipping`, and `ChatblastFlowInfo::pickTemplate` treats it as the owner's choice.** Reason: the pick follows the same rules, so the chatblast preview lands on the same template; `ChatblastFlowInfo` belongs to the chatblast pages, which another branch is changing.
- **Deactivating a `.name` or `.text` row only resets its line to "hindi pa nasusuri".** Reason: the next save or Test token fills it again; nothing else reads those rows.
- **The row Test token button still retries the token check (up to 3 times) before the refresh,** so on a slow Botcake its refresh is skipped as "kulang sa oras". Reason: only Test lahat has a per-request limit; the row button's retry behaviour is unchanged apart from the shared rate key.
## 053: daily Botcake sync by subscription date (2026-10-19)

- **`services.botcake.sync_max_batches` (`BOTCAKE_SYNC_MAX_BATCHES`) is no longer read.** It capped the old incremental read; the by date read is bounded by its stop date, the 45-day limit and the 22-minute stop. Reason: removing a config key and its `.env.example` line is outside the brief; the owner may drop both.
- **New code before `migrate` answers 500:** the three new columns are read without a `Schema::hasColumn` guard, so `/page-settings` and any sync fail between the pull and the migration. Reason: the release runs `php artisan migrate --force` right after the pull; the sync flag is still off.
- **The month names in "Okt 17" are fixed in Filipino in `BotcakeCustomerSync::shortDate()`**, not from Carbon's locale. Reason: the app locale is English; one small list for one screen.
- **Missing tests (minor):** the red cell and "susunod: …" are checked as text in the page's script, not in a browser (no JS test setup). Check in the browser: a page whose last sync failed shows its "Huling sync" in red.
- **The re-chat order within one subscription date can still miss a customer** who chats again during a run and moves above the batch already read (the list is ordered by date, then roughly by last interaction). Reason: the by date read cannot see a reorder behind it; the Sunday `--full` reads everything down to 45 days and catches them.
- **The fallback date of an old run (the Manila date of `created_at`) applies to any clean window or full read from batch 1 with no recorded date,** not only to runs from before the migration. After the migration such a run read no dated customer at all (an empty or undated page), and its run date is still an upper bound of what it read. Reason: no marker tells the two apart without writing the old rows, which the fix must not do.
- **A page whose last deciding run is an old incremental `cap` run still does one window read** on the first night (the existing "cap → window read" rule). Reason: an incremental cap means that read never reached the end of the window; the rule is unchanged.
- **For a resumed run, "Huling sync" shows the chain's newest date → the run's own oldest date,** that is, the range the chain has covered so far, not only what that run read. Reason: every run in a chain carries the newest date of its first run, which is what makes the chain's end clean.
- **The clean end of a resumed chain counts as "fresh" for the Sync lahat skip,** though that run started deep in the list and the top was last read when the chain began (up to a day earlier). Reason: the freshness rule counts clean runs and clean chain ends; the next nightly by date read covers the top from the chain's date minus 1 day.
- **A chain that began before the migration has no newest date on its first run,** so its resumed end records its own newest from older batches and gives an older stop date once. Reason: the next read covers more, never less; it happens at most once per page.
- **Missing tests (minor):** a failed `--full` between a by date run and its resume (the window case is covered), and a resume next to a run of another chain.
## 054: /page-settings fits the screen (2026-10-18)

- **The view tests S-054.2 to S-054.4 pin strings of the page's script, not behaviour** (the open detail row through a poll, a re-render and the filter; editing an other key in it). Reason: the project has no JS test harness (adding one is a new dependency); the headless browser check in the result covers these cases.
- **A key added with "+ Column" shows only in the ▸ detail rows,** which are closed by default; the notice now says so. Reason: the owner's layout puts every non-known key in the detail row.
- **The "Template: …" line under the shipping flow is gone;** the template is the next line of the same Flows cell. `paintedBy()` still repaints the shipping flow cell when the template changes. Reason: harmless extra repaint; left to keep the change small.
- **The ⋯ history button of a cell is still mouse-only (shown on hover);** keyboard users reach history through the History links in the ▸ detail row. Reason: same as before this change; showing it on focus was dropped in review because it was not asked for.
- **(amendment 1) Old stored Tagalog text is translated on display** (`BotcakeFlowNames::OLD_TEXT` for flow and template lines, `PageSettingsGrid::OLD_ERRORS` for run errors); the rows are not rewritten. Reason: no data migration for a wording change; a new save, Test token or sync writes English.
- **(amendment 1) Strings outside /page-settings stay Tagalog:** the run `last_message` (not shown on the grid), the Sync all run message, `ChatblastFlowInfo` and the other pages. `BotcakeClient`'s exception text is now English everywhere it shows. Reason: the amendment covers /page-settings; the other pages are out of scope.
- **(amendment 1) The chip filter applies on render;** after a poll or a save a row that no longer matches stays until the next render (Test all re-renders when a chip is on). Reason: re-rendering under an edit would lose the input.
- **(amendment 1) Duplicated text:** the JS `SUMMARY` labels copy `BotcakeSyncAll::SUMMARY`, and the "+ Column" checks copy `PageSettings::validate` messages. Reason: existed before; both copies were changed together. Suggestion for the owner: send them from the server.
- **(amendment 1) S-054.8 and S-054.9 pin script strings;** `state()`, the border and the chips are proven by the headless browser check. Reason: no JS test harness.
- **(amendment 2) "Editing keeps the context visible" is proven by the headless browser check only** (fake Botcake answering after 1.5 s; S-054.10 pins the script). Reason: no JS test harness.
- **(independent review) With a chip on, every poll re-renders the table when nothing is being edited.** Reason: decided, so the rows follow the chip; a click that lands during that re-render (every 5 s) can be lost, as with the existing structure re-render.

## 055: chatblast pages in English (2026-10-19)

- **The artisan console output of `chatblast:build` and `chatblast:auto` is still Tagalog** (`BuildChatblast.php`, `AutoChatblast.php`). Reason: it is not on a page (it lands in the cron log); the brief covers the three chatblast pages.
- **Rows written before the release keep their Tagalog reasons** (data) until their state really changes; recheck treats the Tagalog form of the same reason as unchanged (fixed after the independent review). Reason: no data rewrite for the language; the list becomes English as rows change.
- **Flow panel results cached just before the release show "pangalan" as the template source for at most 10 minutes.** Reason: the cache expires on its own.

## 056: Chatblast as its own app (2026-10-18)

- **The role lookup is written once more,** in `layouts/chatblast.blade.php` (`employeeProfile->role ?? role`), beside the per-controller `getNormalizedRole()`. Reason: it matches the per-controller legacy pattern, and `NavAccess` trims the role itself. Suggestion for the owner: one shared helper.
- **The path `/chatblast/page-settings` is written in four places** (`ChatblastNav` three times, the route prefix). Reason: small; changing it means changing the route anyway.
- **/owner/nav-settings now suggests `/chatblast/page-settings` and its GET sub-pages under "Add to nav"** (`NavLink::unregisteredRoutes`). Reason: only the CEO sees it; adding one grants page settings access, the same as the old link.
- **The old POST and JSON page settings paths are gone.** A page settings tab opened before the deploy gets 405 on save, Test token or Sync until it is reloaded; its GET polls follow the 301. Reason: the spec allows moving them with their callers.
- **Missing tests accepted:** the merged main nav link keeping the page settings link's position, and an inactive `/chatblast/page-settings` link granting nothing. Reason: the first is in the browser screenshots, the second is `NavAccess`'s existing `is_active` rule.
- **At phone width (390 px) /chatblast/page-settings scrolls sideways,** because the "+ Column" form in the page body is wider than the screen; the Dashboard table does too. The new top bar fits. Reason: the page bodies are out of scope, and the spec's no-scroll rule is for 1366 and 1280 px.
- **The page settings sub-pages (Customers, PSID coverage, Orders) still have Tagalog body text** ("Orders ng page", "Lahat ng page", "Mula", "Ipakita"…). Reason: the spec keeps each page's content as it is; translating them is a separate change.
- **(amendment 1) `ChatblastBuildJob` reads its J&T budget from `ChatblastController::WEB_TRACK_BUDGET`.** Reason: the value and its meaning are unchanged (20 s per press, as the web build had). Suggestion for the owner: move it to `Chatblast` beside `TRACK_BUDGET`.
- **(amendment 1) The "ready within its sequence's window" rule is written twice:** the list's default view (`ChatblastController::filtered`) and the build's ready re-track (`Chatblast::buildLocked`). Reason: two copies, below the third-repetition rule.
- **(amendment 1) S-056.12 checks the dashboard script by its strings;** the chip total without skipped, the hidden note at 0 and the progress states are proven by the headless browser check. Reason: no JS test harness.
- **(amendment 1) The default list's date condition is now an OR** (built today, or ready within the window), which may keep the 30 s count query from using the `created_at` index on a large `botcake_messages`. Reason: not measured; the page keeps the light 4 s poll without counts.
- **(amendment 1) A network error (not an HTTP error) while polling stops the poll,** and during a fetch the button stays disabled until a reload. Reason: existed before for the send poll; HTTP errors now keep polling.
- **(amendment 1) Fetch new needs a worker on the default queue** (the same one as Sync now on page settings). If none picks the job up in 10 minutes, the page says "The fetch waited too long for the worker" and the button works again; the queued job still runs later, one at a time under the build lock.
- **(amendment 2) The sender's own status-skip keeps its key** (`ChatblastSender`, `staleFor` / `blockedToday` at send time), while every skip in the fetch now releases it. If a waybill's status changes between queueing and sending and later comes back to the same status, that waybill and day get no new row (a missed message, never a duplicate). Old status-skips from before this deploy also keep their keys. Reason: changing the live send path the night before auto, for a minor; the delivering send path has no simple test hook. Suggestion: release the key on the `staleFor` skip, with a test.
- **(amendment 2) A Delivering sequence without "requires On Delivery" sends the rider from the fetch that built the row**, which can be days old for a backlog row (`{rider}` is refreshed at send only with that flag). Reason: depends on how staff set up a sequence (trusted); the built-in Delivering sequence has the flag.
- **(amendment 2) Send selected and the row's Send button accept `skipped` rows.** Now that a status-skip frees the key, an old skipped Day 1 row and a newer sent Day 1 row of the same waybill can both exist, so staff could send the old one on a later day. Reason: manual staff action; the sender's "already sent today" rule still holds per day.
- **(amendment 2) The "Send all ready" count can be up to 30 s old** between light polls; after a filter change and a click before the reload, the confirm shows the old count with the new scope. What gets queued is always the server's current set. Reason: the light poll is there to keep the 4 s poll cheap.
- **(amendment 2) History dates other statuses by `updated_at`,** so a later write (editing a sequence's text re-renders unsent rows; recheck) moves a processed row into that day's History. Reason: view only; there is no "processed at" column and the amendment asked for no schema change.
- **(amendment 2) History's OR across `sent_at` and `updated_at`** may scan `botcake_messages` on MySQL for long ranges (the count runs every 30 s per open tab). Reason: not measurable on sqlite; check with `EXPLAIN` in production if History is slow.
- **(amendment 2) The first fetch after deploy skips every unsent row outside its sequence's window,** including old failed, no PSID and no config rows; they all show in that day's History as "The order is older than the sequence's N-day window". Reason: intended (no send outside the window); tell staff.
- **(amendment 2) The backlog rule "never re-checked since before auto" cannot happen in production:** auto's own fetch re-checks every row before it selects (if the fetch is busy, auto skips the run; if it throws, the command stops). S-056.15 shows the rule by mocking the fetch out. The real safety is the re-check (status, page, window) and the sender's checks.
- **(amendment 3) The Botcake link format is a best guess** (`https://botcake.io/pages/{page_id}/live_chat`): Botcake's public docs show no live chat URL. Set `BOTCAKE_INBOX_URL` from a real Botcake address (with `{psid}` if it names the customer). Reason: cannot be confirmed without a Botcake login.
- **(amendment 3) "What the customer saw" uses the page's current template body,** not the one in use at send time; after a template change, older sent rows show the new wording. Reason: the template body is not stored per row; the amendment asked for display from stored data.
- **(amendment 3) A real template body that starts with "!" would show as "Template text unknown"** (the markers all start with "!"). Reason: unlikely; display only.
- **(amendment 3) S-056.25 checks the panel script by its strings;** the bubble and the link were proven by the headless browser check. Reason: no JS test harness.
- **(amendment 4) Newest-first J&T tracking covers new candidates only;** ready rows already in To send are re-tracked oldest track first, after every untracked one, so a ready Delivering row that turned Problematic can stay ready until the budget reaches it (the send-time re-track is the final guard). Reason: "within the existing tracking budget"; the hourly refresh reaches more each hour.
- **(amendment 4) Refresh now and a manual-to-auto switch can still build a step with its own time after that time,** which auto then sends at the next unrelated time; only the hourly refresh skips such steps. Reason: staff actions (trusted), and Refresh now worked this way before amendment 4.
- **(amendment 4) In auto sequences, steps with their own time appear in To send only once that time has come** (the refresh does not build them). Reason: the trade-off for never sending them late.
- **(amendment 4) A narrow race between the build lock release and the refresh slot write** can let a second unguarded chatblast:auto run a second full build in the same hour. Reason: dedupe makes it harmless; it only adds load.
- **(amendment 4) Delivering waybills the 60 s budget does not reach are built with an empty rider;** when every version needs {rider}, they become no_config with the rider reason and are never re-tracked, and the undated key blocks a rebuild. Pre-existing, more exposed now that the 06:15 refresh does the day's largest build. Reason: needs its own design (re-track rider-missing rows); not asked here.
- **(independent review) A hard-killed build process leaves the build lock for up to 30 minutes** (`BUILD_LOCK_SECONDS`, up from 300 s so no second build can run beside a long one). Reason: the job path releases its own lock in failed(); a cron process killed mid-build is rare. Suggestion: a heartbeat that renews a shorter lock.
- **(independent review) A refresh that keeps throwing is retried every 5 minutes** for the rest of the hour (the claim is released on error, as asked). Reason: as decided in the review; it never blocks auto's sends.
- **(amendment 7) The "Flow problems" chip still counts a red line on a hidden flow slot** (Delivering, In transit, Broadcast) that has an ID; the line is shown under ▸. Reason: the setup and problem rules were asked to stay as they are.

## 057: a new page sets itself up (2026-10-18)

- **The sender's Ready check is cached 60 s per page** (`ChatblastReady::tested`), and the cache is cleared by `PageSettings::set` and `deactivate` on a setup or `test_ok` change. The uncached query still scans the sent rows (`LOWER(TRIM(page_name))`, no index), now at most once a minute per page. A `set()` inside an outer transaction clears the cache before that transaction commits, so a reader in that gap could cache the old state for up to 60 s. Reason: no caller wraps these keys in an outer transaction today. Suggestion if the scan still shows up: a stored page key column with an index.
- **A stored page name with an inner double space** ("Page  Dos") counts for the grid's `states()` (PHP `pageKey`) but not for the sender's `tested()` (SQL `TRIM` does not collapse inner spaces); such a page's rows could go ready, be refused, and go ready again on each fetch. Reason: rare in orders; after the second review loop. Fix: group in PHP as `states()` does.
- **The empty check and the auto-fill write are two statements** (`detectFlow`, `detectField`): a person's save landing in between (microseconds) could be replaced by the system's pick. Reason: tiny window. Suggestion: a "set if empty" inside the `PageSettings::set` transaction.
- **The four setup keys are written in three places** (`Chatblast::REQUIRED`, `ChatblastReady::CONFIG`, the page's `CONFIG_KEYS`). Reason: two are PHP constants with the same meaning; the page cannot import PHP. Suggestion: pass the list to the page.
- **The Test send line says "Tested <test time>"** even when a later real send is what made the page tested; **the poll does not refresh `info.last_send`**, so a page made Ready by a real send turns green only after a reload. Reason: display only; the server gate is right.
- **The view tests S-057.3, .4 and .8 only find literals in the page script;** the states are proven by the browser check. Reason: no JS test harness.
- **No test for a flows failure after an ok token on the save path** (the field detection should stay and refresh_error be set). Reason: the token-failure and no-answer cases are tested; low value.
- **"Set a Test PSID first" links to the picker on /chatblast,** which a role with only Page settings access cannot open (404). Reason: the picker exists only there; such a role can still type the Test PSID in its cell.
- **A changed Botcake ID clears the old candidate lists before the check;** when Botcake is down, the page shows no candidates and no guide until the next Test. Reason: lists from the old Botcake page would be wrong.
- **The save of a Botcake ID or token can take up to ~25 s** while the check runs inside the request (the Test button already did). Reason: the brief allowed the save response; check the production PHP and nginx timeouts.
- **"Mark as tested" shows only while the page needs a test,** and the Test send line then reads "Tested <time>" like a Send test, without saying it was marked by hand (the ▸ history shows who). Reason: the brief asked for the action only.
- **A page whose templates check failed before this change** keeps no recorded result, so it shows no template line until its next Test or save. Reason: the old "no template" guide guessed from the flow list; nothing is recorded to migrate.

## 059: Botcake chat inbox (2026-10-18)

- **The per-IP limit uses `$request->ip()`, and `trustProxies(at: '*')` trusts `X-Forwarded-For`.** If the front server passes a client's own header through, a caller can rotate IPs past the 429, or use up Botcake's IP allowance for a minute. Reason: the proxy setting is app-wide and older than this work; whether it is exploitable depends on the server setup. The key still guards storage. Suggestion: trust only the real proxy IPs.
- **Two identical requests arriving at the same moment can store two rows** (the duplicate check reads before it inserts; no unique constraint). Reason: the only effect is an extra row on a view-only list; a unique key would need a hash column.
- **`pruneDaily` marks the day as done before the prune runs,** so a prune that throws is retried the next day (or by the 04:25 scheduler on Likha). Reason: a one-day delay on a 90-day retention is harmless.
- **Accepted missing tests (trusted paths or display only):** `chatblast:auto` still running when the prune throws (the call sits in try/catch); the customer view with an empty psid (404) or no page_id; `q[]=x` and `date=garbage` on the Chats page; the Setup box with no key set; the key sent as a GET param or inside a JSON body being left out of the payload (the same code path as the form case that is tested).
- **The mask `'••••' . last 4` now exists twice** (Chats setup box and `PageSetting::displayValue`, with different length thresholds). Reason: the second copy, not the third (DRY rule).
- **No test pins the exact-text duplicate check ("ok" then "OK").** Reason: sqlite's `=` is already case-sensitive, so such a test would pass without the fix; the comparison is in PHP (`===`), which behaves the same on MySQL.
- **`mb_scrub` keeps NUL bytes;** a value with `%00` would make PostgreSQL reject the insert (500). Reason: only a caller with the key can send it, and production runs MySQL.
- **The second fix loop (text/plain body key) got no reviewer re-check** (the 2-loop cap). Reason: a one-line move, pinned by the new S-059.2 data set.
- **(amendment 1) A row whose page could not be found shows only "Unknown page";** the `page` text it was sent with stays in the payload but is not shown, so a misconfigured block is not named on the page. Reason: the amendment asks only for "Unknown page"; a later display tweak if it happens.
- **(amendment 1) The review fix (filter lists page-settings pages) got no reviewer re-check.** Reason: small, pinned by S-059.16's new assertion, and within the amendment's 20-minute budget.
- **(independent review) The prune stops after 40 batches (200,000 rows) a run;** the rest goes in the next run (the next day, or the next chatblast:auto day). Reason: keeps each run short; normal daily volume is far below that.
- **(independent review) A page id first seen in chats appears in the Chats page filter up to 10 minutes late** (the distinct list is cached). Reason: asked for; its rows show in the unfiltered list at once.
- **(independent review) The Chats list shows "Page N · X messages" instead of a total;** no COUNT over the table. Reason: asked for (simplePaginate).

## 061: hourly J&T track-sync, chatblast reads the database (2026-10-18)

- **A crashed run stays "running" on /jnt/track-sync and drops its resume point** (the next run starts from the newest). Reason: rare; the hour claim already stops repeats. Mark leftover running rows failed at the next start if it shows up.
- **The build-time skip of unsent requires-On-Delivery rows has no window limit** (two indexed queries per 500 unsent rows per build). Reason: the unsent set is small; limit it to the campaign window if it grows.
- **"Delivered/Returned are final" is now in three places** (JntTracker, JntAutoTrackSync, the manual job). Reason: legacy minimal touches; share one constant when this area changes again.
- **Missing small tests:** the stop waybill of a capped run became final (the code handles it), a stale row sent at the next due time once fresh, the Off state of the page, a guest POST to the toggle. Reason: each path is a one-line branch next to a covered one.
- **The background start uses its own command string** instead of Laravel's `Application::formatCommandString()`. Reason: works on Linux; the test asserts the trailing `&`.
- **jnt_latest_scans has no timestamps** (the plan said it would); `checked_at` is the only time needed.

## 062: Flow ID and Message field dropdowns (2026-10-18)

- **The page-script tests for the edit dropdown find literals only;** the browser check proves the behaviour. Reason: no JS test harness.
- **A list stored before 062 holds the CHATBLAST / "GPT Input" candidates only** until the page's next Test or Botcake ID / token save, so its dropdown lists only those. Reason: the next Test refreshes it; "Test all tokens" refreshes every page at once.

## 063: Meta payment dates (2026-10-18)

- **The importer holds the whole file in memory before writing** (it decides the date order from every row first, so it no longer streams 2,000 rows at a time). Reason: the date order rule needs a first pass over the rows, and real Meta invoice summaries are a few dozen rows; the upload limit is 50 MB.
- **Text dates that aren't plain slash or ISO (e.g. `03/10/2026 00:00`, `Feb 30, 2026`) still fall to the loose `Carbon::parse`.** Reason: already true before; Meta files today have plain dates, and the range guard catches far-off results.
- **Untested here:** xlsx uploads (DateTime cells and numeric serials skip the order rule), D/M decided by a data row alone, 2-digit years, the backfill's equal-distance and "row changed while running" guards, `--dry-run` with `--apply`, and the MySQL/pgsql backup statements (sqlite only in tests). Reason: on the owner's command path, or branches read by the reviewer; the suite stays small.
- **Two small additions beyond the brief:** `.` is accepted as a date separator, and the command refuses `--dry-run` together with `--apply`. Reason: harmless, kept.
- **The backfill can flag a correct row** when its day is 12 or less and above its month, and it was first imported about a month or more after the payment (the swapped reading is then closer to `created_at`). Reason: the rule is decided in the spec; none of the stored sample files is that late. The owner can check the dry-run CSV: rows whose `source_filename` was a "Billing report" (lowercase) file should not appear.
- **A row with a transaction id or amount but an empty date cell is skipped without being counted as refused.** Reason: already true before; Meta exports have no such rows.

## 067: chat endpoint security (2026-10-19)

- **The IP allowlist checks REMOTE_ADDR.** If the site sits behind a proxy or CDN, REMOTE_ADDR is the proxy's address and enforce mode would refuse every chat. Reason: the forwarded header is client-written while the app trusts every proxy. Check the `ip` / `forwarded_ip` pair in the log (log mode) before switching to enforce.
- **The per-IP rate limit (`throttle:botcake-chat`) still keys on `ip()`,** which a forged `X-Forwarded-For` can change. Reason: the limiter was asked to stay as it is; the key, the per-page PSID-check cap and the PSID check carry the protection.
- **Command: a block write that times out after Botcake applied it** reports "key unchanged", while Botcake now holds a key that was never saved; that page's chats get 401 until the command is run again. Reason: owner-run (trusted) and fixed by a rerun.
- **Command: if saving the key throws after the block was written,** the loop stops with a block whose key is unsaved. Reason: trusted path; rerun fixes it.
- **Command tests not written:** publish failing after the write (key saved, exit 1), `--all --confirm` with one page failing, choosing a page by its Botcake ID. Reason: trusted, owner-run paths.
- **Two first chats of the same new PSID at the same moment insert two `chat_verify` rows** (no unique index on page + psid). Reason: harmless duplicates; the lookup only checks existence.
- **A page in page settings without a `botcake.page_id` keeps the page_id the request sent** (name lookup fallback from 059). Reason: such a page has no token for the PSID check either, so its chats are not kept now ("PSID could not be checked").
- **The active `chat.key` query is written twice** (`BotcakeChat::keyHashes` and the Chats setup box). Reason: second copy, not the third (DRY rule).
- **The PSID-check cap drops real new customers above 60 a minute on one page.** Reason: a key holder must not be able to drain the page's Botcake budget; the count shows it, and `BOTCAKE_CHAT_VERIFY_PER_MINUTE` can be raised.
- **(re-check) Failing closed can drop real chats:** a page whose token expired keeps no chats from new customers, and a page above the per-minute cap drops the extra new-customer chats. Reason: the trade-off for not letting an attacker force the accept path; both show as "PSID could not be checked" in the Setup box, and the cap is in config.
- **(re-check) In enforce mode, `bad_ip` and `bad_key` refusals still write a database cache counter** for requests that never showed a valid key. Reason: counting refusals is asked for; the per-IP limiter bounds it.
- **(re-check) A crafted PSID that made Botcake answer 5xx would count as "down" and be kept** (not verified to happen). Reason: unproven; the fix (refuse PSIDs that are not digits before calling Botcake) would change many test fixtures. Suggestion for a later small change.

## 068: /chatbot page list (2026-10-19)

- **At phone width the page scrolls vertically a little,** so the filters can scroll out of view: the table box keeps a minimum height of 18rem under a taller header. Reason: nothing scrolls sideways and the table header and PAGE column stay sticky inside the box; the brief's "only the data scrolls" holds on desktop.
- **"+ Column" has no way to remove or rename a column on screen.** Reason: not asked; the list is one `app_settings` row (`chatbot.columns`) the owner can edit, capped at 200.
- **Auto-save writes a history row at every typing pause** (up to 10,000 characters for long text). Reason: same behaviour as page settings; the history is how page settings keeps old values.
- **Image previews load the URL in the viewer's browser** (with no referrer). Reason: the owner's own links; any role with access can set them, but only http(s) is accepted and nothing is rendered as HTML.
- **The "Chats today" count reads today's rows of botcake_chats on a cache miss (once a minute).** Reason: fine at today's volume; the 1M-a-day storage work is out of scope.
- **The ACTIVE column's sticky offsets assume a fixed width;** a long Botcake error under the switch wraps inside 12rem. Reason: checked in the browser at 1366 px with no overlap; an unusually long single word could still overlap.
- **Two tabs, or the command and the switch at the same moment,** can leave ACTIVE and the Botcake publish state out of step. Reason: CEO-only actions behind a confirm; the next switch fixes it.
- **The helper in ChatbotController is called `role()`,** not the house `getNormalizedRole()`. Reason: cosmetic; same logic.
- **The scratch app of the browser check left gitignored files** (two session files and a few cache files in this worktree's `storage/framework`, and `%TEMP%\cb068`). Reason: the project guard blocks deleting files from the session; they hold only scratch data and no real key or token.
- **(re-check) The duplicate Botcake ID check in "+ Page" compares the stored text;** a `botcake.page_id` row someone marked secret in Page settings (encrypted) would slip past it. Reason: only trusted users can mark it secret, and page IDs are never secret in practice.

## 069: chatbot flow builder (2026-10-19)

- **The "chatbot" queue needs its own worker** (`php artisan queue:work --queue=chatbot`, under supervisor). Without it no customer gets a reply and the jobs wait in `jobs`; after 30 minutes a run gives up and its chat is passed over. Reason: production setup, done at deploy.
- **With the replies switch off, runs still call OpenAI and still change real customer fields** (only sends and sheet rows are skipped). Reason: the brief's choice ("runs still happen"); flip the switch only after testing, or keep pages unlinked until then.
- **A customer's first burst:** when the very first two chats of a customer arrive together and the second one's job runs first, the first chat is marked done without a run (there is no marker yet). Reason: only the first contact; later chats always run in order.
- **Model names are free text** (a safe pattern, any model) and max tokens go up to 4000. Reason: staff with /chatblast access author flows; a model allowlist can come with cost tracking.
- **The Test tab calls OpenAI for any role with /chatblast access** (30 a minute, the throttle). Reason: the brief asks for real AI in Test.
- **The Google Sheet step can append to any spreadsheet the service account can edit,** and a column template without a variable can be a formula. Reason: flows are authored by staff; this is the TeleSMS rule the brief asked to port.
- **A regex value may contain variables,** so customer text can become part of a pattern. Reason: PCRE's backtrack limit bounds it, and a failed match is "no match".
- **Chatbot replies share each page's Botcake rate (2 a second) with chatblast,** and the throttle is not atomic across workers. Reason: the real limit is per token; a busy page plus a campaign backs up rather than failing at once.
- **Per-run caching is light:** each stored chat costs a few page-settings lookups and a flow query in the trigger, the runner reads the flow per chat, and the outbox reads the page token per send. Reason: indexed lookups; measure at volume before adding caches.
- **The Replies search is a LIKE over one day's rows.** Reason: date-bound as asked; at full volume it is slow but never a full scan.
- **Editor autosave is last-write-wins** between two people editing the same flow. Reason: one owner edits flows today.
- **Customer field keys are compared exactly in memory but by collation in MySQL** ("café" and "cafe" are one row there). Reason: field names are staff-authored.
- **An append past 16,000 characters keeps the oldest text** (ALL USER INPUT stops growing). Reason: the TEXT column limit; the owner's loop clears it on exitloop.
- **The second fix loop (FLOW dropdown shows the linked flow) got no reviewer re-check** (the 2-loop cap). Reason: pinned by S-069.H7.
- **Engine slice 1 was written before its tests** (the tests came right after, in the same slice). Reason: noted in the result; slices 2–4 and both fix loops were test-first.

### Spec 072 (accepted review minors)

- **"+ Page" marks a page Off when the publish step fails,** although the block is already written and an earlier published Default Reply may still be live (chats are then dropped as "Bot off" until the switch is turned On). Reason: the spec asks for "saved but Off" on any Botcake failure; switching On fixes it, and the row shows the error.
- **A non-fatal token-test note (e.g. the flow list) shows in red on a row that is On.** Reason: it is the same text the notice showed before; cosmetic.
- **The path where `turnOn` throws during "+ Page" has no test.** Reason: same fixed message as the token test's catch; `BotcakeClient` returns errors as arrays, so a throw is a bug path only.
- **Two "+ Page" requests at once could each create a key.** Reason: CEO-only, the button is disabled while sending, and the On switch has the same race today; the next On fixes it.
- **The chat's `ad_id` on a `source=api` row can be replaced by the customer sync** (`BotcakeCustomerSync::store` rewrites `ad_id` from the API, to null when the API has none). Reason: outside this change; whether a chat's `ad_id` should win over the sync is an owner decision.

## 074: chatbot inbox (2026-10-19)

- **Run the two new migrations on deploy** (`2026_10_24_100000_add_inbox_indexes`, `2026_10_24_100100_create_chatbot_events_and_reply_user`). The `(page_id, psid, id)` index on `botcake_chats` (about 90 days of rows) is a long ALTER on MySQL; run it at a quiet hour. Until `chatbot_replies.user_id` exists, a manual send reaches the customer and then fails to log with a 500. Reason: migrations run by hand on deploy.
- **The list regroups the whole window every 5 seconds** (two GROUP BY psid queries, bounded by page, window and LIMIT). With 30d on a busy page that reads 30 days of rows per refresh. Reason: the brief asks the list to refresh every 5 s; the queries use the (page_id, received_at) and (page_id, sent_at) indexes. Measure on production before caching the list or grouping on MAX(id) only.
- **The Human/Bot tabs filter after the 500-customer group limit,** and the chat and reply groups are limited separately. A paused customer outside the 500 most recent is not under Human, and a customer ranked high in chats but past 500 in replies shows a wrong dot. Reason: only for pages with more than 500 active customers in the window; 24h narrows it.
- **Manual sends share the page's Botcake rate key with chatblast and the bot.** Reason: the real limit is per token; 30 sends a minute per user bounds it.
- **A double submit of Send** (two quick Enters) can send twice. Reason: the button is disabled while sending; the server has no idempotency key.
- **Manual sends ignore the emergency stop** (CHATBOT_REPLIES_ENABLED=false). Reason: a person chose to send; the switch stops the bot.
- **Events in the same second sort chat, then event, then reply,** so a run's last events can show just above the reply they came with. Reason: timestamps are whole seconds; each event now keeps its own time, so only same-second items tie.
## 075: flow draft, Undo/Redo, Save and Publish (2026-10-19)

- **Deploy order:** run the migration (`2026_10_24_100000_add_draft_to_chatbot_flows.php`) together with the code. Until it runs, the editor's save, Publish and History return 500 (unknown column `draft_version`, missing `chatbot_flow_versions`); live runs are unaffected because they read only `graph`. There is no `Schema::hasTable` guard on the new table. Reason: the editor needs the new columns anyway, so a guard would only hide a missed migration.
- **Overwrite vs. Publish at the same moment:** an Overwrite skips the version check and compares with the published graph read just before its update. If someone publishes in those milliseconds, the overwritten draft may be judged against the old published graph. Reason: it needs a deliberate Overwrite click in a window of milliseconds, and the worst case is a wrong status chip, not lost data.
- **"Duplicate step" copies the step as last applied,** not unsaved edits still open in its form (those are dropped with the form). Reason: same as Cancel; cosmetic.
- **A duplicated flow is "Never published" but carries the source's published graph,** so if a page is linked to the copy before its first Publish, customers get that graph. Reason: this is what the spec asks ("Duplicate copies the published graph and the draft") and the story's choice; whether the copy should count as published is a question for the owner.
- **Untested small paths:** Restore of a stored version that no longer passes `FlowGraph::clean` (422), and the publish note trimmed to 200 characters or sent as an array. Reason: trusted stored data and a defensive cut; each is one line.
- **Supersedes the 069 entry "Editor autosave is last-write-wins":** saves now carry `draft_version` and a stale one gets 409 with Reload / Overwrite.
- **The scratch app of the browser check left gitignored session and cache files** in this worktree's `storage/framework` and `%TEMP%\likha075`. Reason: scratch data only, no real key or token.

## 076: wait for reply can skip after a set time (2026-10-19)

- **Deploy:** run `2026_10_25_100000_create_chatbot_waits_table.php` with the code, and make sure `schedule:run` (cron) and the `chatbot` queue worker run. Without the table, waits work as before, with no timer. Without the scheduler, nothing skips. If both `vps` and `vps2` run the scheduler, each queues the same rows, which is harmless: the one-row claim lets only one job act.
- **An AI step on the skipped branch sees the customer's last message twice:** once as `{{last_input}}` and once in its history, because the skipped run has no chat id. Reason: rare (AI right after a skip), and the extra line is the same text.
- **The tick reads due rows by `due_at` and skips the ones queued in the last 5 minutes in the same scan.** With a very large queue backlog, it reads past those rows. Reason: bounded by the backlog, and the `chatbot` queue drains it; measure before adding an index on `dispatched_at`.
- **A wait whose customer has no `botcake_chats` row is dropped as "page is off or has no flow".** Reason: practically unreachable (a wait is always set by a chat), so the wording does not matter.
- **The editor's amount field caps at 23 hours only as a hint.** Typing 24 hours makes every autosave fail with "Skip after can be at most 23 hours." until it is fixed. Reason: the message is shown, and the server refuses it.
- **A "Clear all fields" on the skipped branch removes the deadline with `__wait`.** Reason: the intended sync; noted for whoever builds on it.
- **While the chatbot worker is down, the tick queues each due wait again every 5 minutes.** The extra jobs end at once (no row, or the claim fails). Reason: a little queue churn during an outage only.
- **The scratch app of the browser check left gitignored session and cache files** in this worktree's `storage/framework` and `%TEMP%\likha075`. Reason: scratch data only.

## 070: chatbot sequences (2026-10-19)

- **Run the migration on deploy** (`2026_10_26_100000_create_chatbot_sequence_tables`) and keep the scheduler and the `chatbot` queue worker running. Until the tables exist, the tick does nothing and exit on reply is skipped. Reason: migrations run by hand.
- **The tick walks overdue rows of paused (inactive) sequences** before reaching due rows of active ones (no row lock any more, so no blocking). If the owner pauses a sequence with many overdue subscribers, each tick re-reads them. Reason: bounded by the 500 batch and the (status, next_send_at) index; measure before adding a sequence-active column to the index.
- **`resumePaused` reads up to 500 paused pages per tick** with no progress marker. Reason: far above today's page count.
- **While the bot is paused for a customer, a "held" event is written every hour** per subscription. Reason: it makes the hold visible in executed actions; events are pruned after 30 days.
- **Replies sent while a page is Off are not stored** (the intake drops chats for an Off page), so they don't trigger exit on reply, and the 24-hour check later reads an older chat. Reason: the intake's Off rule predates sequences; after the page is On, the 24-hour guard still applies.
- **No test for exit on reply failing** (a database error during the intake). Reason: the call is wrapped in try/catch twice and reports; a forced DB error in the intake test is costly to set up.
- **A step flow's "Clear all fields" also clears the customer's wait in the main flow.** Reason: step flows act on the same customer fields by design (the brief allows set-field steps); only waits are protected.
- **A reordered or edited sequence moves in-flight customers by position:** a customer at step 3 runs whatever step is third after the edit. Reason: steps are replaced on save (one JSON save); keeping step ids would need a stable-id editor.
- **With replies switched off, a step still counts as ran** ("Last sent" is set, and the pointer advances) although Botcake was not called. Reason: the emergency stop logs every send as skipped; a run is still a run.
- **The inbox sequence endpoint accepts a 19-digit PSID above PHP's integer limit**, which saturates when cast. Reason: only staff send it, and real PSIDs are 16–17 digits.
- **Unsubscribe and re-subscribe in the inbox while a step is running** can move the restarted subscription past step 1. Reason: the window is the length of one step run; staff can unsubscribe and subscribe again.
## 077: AI history up to 100 (2026-10-19)

- **100 messages can be a large prompt.** Each sent reply is up to 2,000 characters, and a customer chat has no cap of its own, so 100 messages can reach roughly 50,000 tokens per AI call, paid on every call, up to 10 calls per run. Reason: the owner's choice. `ChatbotAi` sends the messages as they are, with no character budget. Suggestion for the owner: a total character cap on the history if OpenAI costs rise.
- **Manual replies from the inbox (`kind = manual`) are not in the AI history,** only the bot's sent replies (`kind = send`). Reason: the brief says to keep the history as it is. Including the staff's own replies is a question for the owner.

## 082: chatblast setup check by fixed names (2026-10-20)

- **First run on live:** run `php artisan botcake:setup-check --all --dry-run` first (it writes nothing), then a real Check all. About 40 pages switch to the new flow and field, and the 16 that are Ready today stay Ready through the check. The ~20 pages without the flow get an X and stop sending until the staff add the flow in Botcake. Reason: the brief's intent; listed here so the switch is planned.
- **A Setup-box save during a running Check all can wait for the nightly run** when the save reads "running" just as the job finishes, or when a worker was killed while "running" (under 60 minutes): the "run again" flag is then never picked up. Reason: a narrow race; the 21:30 run covers it.
- **The chatblast build's no_config rows say "needs a test send"** even when the page's real blocker is an X. The sender, where a send is actually skipped, writes the exact reason. Reason: the build only marks rows not ready; changing its marker is outside this spec.
- **The /chatbot "+ Page" (ChatbotController) runs the same token test and setup check** after adding a page, since it calls `BotcakePageSetup::check`. Reason: unchanged call; the check is the chatblast setup of the same page.
- **On a phone, the "+ Column" button of /chatblast/page-settings is clipped at the right edge** (it was already like that). Reason: outside this spec.
- **The browser check's scratch app ran a real Check all once** (the queue was `sync`): five token tests to botcake.io with fake tokens, which all ended as errors and wrote nothing. Reason: my mistake; the scratch queue is now `null`, and the check no longer presses Save or Check all.

## 087: async chatbot AI (2026-10-20)

- **A 429 that lasts past the timeout still ends on the failed port.** The request is never lost or failed early, but `deadline_at` (`services.openai.timeout`, 60 s) keeps running during the backoff. Reason: a customer shouldn't wait longer than the timeout for an answer; "drops nothing" here means no request is lost.
- **The misrouted count proves the ticket → resume → send leg at volume, not the HTTP pairing.** The fake AI answers from a timer keyed by request id, with no HTTP. Reason: the HTTP pairing is proven by S-087.1 (out-of-order answers through the real Guzzle path); the routing to a customer happens in the resume, which the count does cover.
- **Missing tests (minor):** a wait-skip run (no chat) that reaches an AI node; `skip()` and a sequence step holding while `__ai` is pending; the pool's graceful stop draining its in-flight requests. Reason: each is a small branch over the same claim and lock code that the 12 tests cover; costly to set up for little extra proof.
- **Answered rows still waiting in a busy `chatbot` queue get a duplicate resume job every 30 s.** Reason: harmless (the resume claims the row once); it adds queue rows only under overload.
- **Two servers each running a pool with separate caches** would both send the same requests (twice the OpenAI cost, never two replies: the claim is in the shared database). Reason: production uses one queue server and a database cache.
- **`--stats` "Flow runs" counts the part of a run after the AI answer as a run** (each AI chat counts twice). Reason: the resumed part writes its own `run_started`; the other lines are unaffected.
- **The fake Botcake records every send in the cache** (two extra cache writes per send, test pages only). Reason: needed for the misroute count; keep it in mind when reading the numbers.
- **Next limit after this spec: Botcake sends in the workers.** Each send holds a worker for its latency plus the 2-per-second-per-page throttle sleep. Suggestion for a later spec: send in the pool the same way, or more chatbot workers.

## 088: Redis and Horizon (2026-10-20)

- **One extra query per chatblast dispatch** (the message's kind, to pick the queue): `resume()` over N queued rows makes N small primary-key reads. Reason: cheap next to the three Botcake calls each send makes.
- **The dashboard has no "Queues" link.** The main nav is database rows; a link needs a migration and a decision on placement. Reason: the brief allows none; `/horizon` is typed directly by the CEO.
- **Cutover windows (all trusted, operator-run, nothing lost for good)**, covered as steps in the result:
  - long-running processes keep the old config until restarted (`chatbot:ai-pool`, workers);
  - a release on the old driver after the drain check;
  - the chatblast build lock taken on the database store and released on Redis during the `CACHE_STORE` switch.
- **S-088.4 on the array store tests the store more than the code.** Reason: the brief allows the array store; the Redis data set ran green against a real Redis in WSL, and the load check ran on Redis end to end.
- **phpredis** (the PHP extension) is faster than predis; switch with `REDIS_CLIENT=phpredis` after installing `php8.4-redis`. Reason: the brief chose predis to avoid an extension install now.

## 089: async Botcake sends (2026-10-20)

- **A chatbot send now waits up to 10 minutes in the pool** (was the worker's queue, with no limit but the 30-minute stale-chat rule). Past 10 minutes it takes the failed path. Reason: under a backlog the exact 2/s per page makes sends wait; 10 minutes keeps a late reply better than none. A product decision for the owner to confirm.
- **Pages that share one token share one lane** (the per-page rate is per token, as Botcake counts it). The full-lane exclusion in the claim query lists only the page names whose tickets the pool holds, so a second page on the same token with its own large backlog can still fill the candidate window. Reason: staff set the tokens, and sharing one is rare.
- **A token change during a backlog** makes a second lane for the same page for a moment, so up to 2 × the rate for a few seconds. Reason: rare; the old lane drains in seconds.
- **Blocking senders outside the pool** (sequence steps, the setup check, the chat guard's verify, Test) still use the old non-atomic throttle. They honour the pool's shared rate key, so they wait for the pool's last send, but two of them can still race each other. Reason: the brief scopes exactness to the pool path.
- **The Botcake base URL, headers, 15 s timeout and 10 s Retry-After cap** are written in both `BotcakeClient::shared()` and `BotcakeSendPool`. Reason: two places, and the retry rule itself is already shared (`BotcakeClient::retryWait`).
- **No test for the mock answering on a timer inside the pool.** Reason: the load check runs every send through it, with misrouted = 0.
- **`BotcakeSendQueue::enabled()` runs `Schema::hasTable` on every send.** Reason: one cheap query; a static cache leaked between tests in 087.

## 092: paste blocks of botfield values on /chatbot/pages (2026-10-20)

- **Drag to select a rectangle in the grid is not built** (click, Shift+click and Ctrl/Cmd+A are). Reason: the grid paste was secondary in the spec, and a plain click already opens the row; drag needs care not to fight text selection and the sticky columns. Shift+click covers the same need.
- **Undo can be refused whole** when a previous value no longer passes the rules (a legacy non-link in an image column, or a value over MAX_BYTES saved before the limit). Reason: every value in the table came through the same checks, so this needs data written outside the page; the 422 names the cell, and nothing is half-undone.
- **No test for a failure inside the transaction rolling back** (only the checks before it are tested). Reason: every check runs before the transaction; a failure inside it would be a database error, and Laravel's `DB::transaction` rolls back on any exception.
- **A one-cell paste whose text is a field name ("price", "flow") is read as a header line** and answered "Only a header line was pasted". Reason: it is the spec's own rule (a first line that names fields is a header); the message says what happened.
- **The flow editor canvas (`/chatbot/flows/{id}`) still uses `height: calc(100vh - 17rem)`.** Reason: it has no scrollbar (the canvas pans and zooms inside), and another spec is changing that page now; the same `.cb-page` fix can follow there.
- **Suggestion for the owner: map pasted rows by the PAGE column.** An LDT sheet copied with its PAGE column could fill each page by name instead of by order. Reason: not in this spec; today PAGE is listed as ignored.

## 090: Google Sheet step picker (2026-10-20)

- **A sheet shared as Viewer reads fine in the editor,** so the name, tabs and headers show, but every run then fails at append with "Google refused the row". Reason: the brief covers 403/404 only; telling Viewer from Editor needs a Drive permission call or a standing hint. A product decision for the owner.
- **Google also answers 403 for "API not enabled" or a missing scope,** and those show the share message too. Reason: misleading but leaks nothing, and both are server setup that is already done.
- **No test runs the real Google request** (the `spreadsheets.get` field mask, the `'<tab>'!1:1` range with an apostrophe in the tab name, `getUpdates()->getUpdatedRange()`): `fetchInfo`, `fetchHeaders` and `append` are faked. Reason: it needs a mocked Google HTTP handler for the client library; the three calls are one line each and follow the existing `append`.
- **No test for the rate limit** on `GET /chatbot/sheet` (30 a minute) or for preview inputs like `columns` sent as a string or over 50 items. Reason: the limit is the route's standard `throttle` middleware, and the columns go through `FlowGraph::cleanConfig`, which the hardening tests already cover.
- **The preview shows botfield values** (the page's chatbot settings) when a template names them. Reason: the Test tab builds the same variables for the same users, so this is no new exposure.
- **"Open row" is in the reply's detail on `/chatbot/replies`, not in the list rows.** Reason: a list row opens the detail with one click; the list stays one line per reply.

## 096: page Edit modal on /chatbot/pages (2026-10-20)

- **Editing the paste box again re-applies the paste over a field fixed by hand after the paste.** Reason: each change to the paste box replaces the previous paste as a whole, so a new "Starting at field" or a fixed typo never stacks two pastes; the field turns amber with "Was: …", so it is visible before Save.
- **The modal works on the row as it was when it opened.** If another user saves the same page meanwhile, "Was: …" shows the older value. Reason: Save only sends fields changed in the modal, last write wins as everywhere on this page, and history rows keep every value.
- **The image preview loads whatever http(s) link is typed or pasted** (with `referrerpolicy=no-referrer`). Reason: the grid already does the same for saved links; only staff with /chatblast access type them.
- **The modal's behaviour (amber, flagged, re-paste, confirm on close) is checked in the browser only,** not by an automated test. Reason: the project has no JS test runner for page scripts; the parsing and mapping it relies on are tested in node (`ChatbotPasteScriptTest`).

## 094: multi-line Set field and Clear AI memory (2026-10-20)

- **A reply queued before "Clear AI memory" and sent after it stays forgotten.** The marker holds the highest `chatbot_replies` id at the clear, and a queued reply's row is older. Reason: the brief asks for a chat and reply marker, not a time window; the gap is one in-flight send, and that send was answering the conversation being forgotten.
- **The editor test (S-094.7) only checks the rendered source.** The textarea growth and the 3-line clamp were checked once in headless Chrome, not on every run. Reason: the project has no JS test runner, and adding one is a new dependency.
- **"Also clear these fields" is a list of text boxes with the field suggestions, not a checkbox multi-select.** Reason: the editor knows only the fields the flow already uses, so a checkbox list would hide fields that aren't used yet. This matches the Set field step's field input.

## 097: grouped variable list in the flow editor (2026-10-21)

- **The saved customer keys are read on every editor load** (`DISTINCT key` over the flow's pages in `chatbot_customer_values`; the unique index starts with `page_id, psid`, so the database scans those pages' rows). Reason: the read runs only when a step form's data loads (once per editor open), staff-only; at today's size it is fast. If the table grows to millions of rows, cache the list for a few minutes or add a `(page_id, key)` index.
- **At most 320 saved keys are listed, A–Z, so keys late in the alphabet drop off past that cap** with no note. Reason: a flow's own fields (save to, Set field) are always listed; the cap only trims fields found on customers, and 320 is several times today's count.
- **A name could get the same "Also …" line twice** if two botfield labels are the same after `ChatbotVars::norm` (e.g. "Prompt 1" and "PROMPT_1") and also a customer field. Reason: the column names are owner-made and unique in practice; the chip and the insert still work.
- **The Sequences fold matches the labels "Seq N" and "Seq Img N"** in the editor script, not the column definitions. Reason: these labels are the fixed defaults in `ChatbotFields::defaults()`; a renamed column would only show unfolded.
- **`$groups = $this->variableGroups($flow)` is assigned inside the response array in `graph()`.** Reason: it keeps the change to one line in a legacy method; style only.

## 099: pages check and history on /chatbot/pages (2026-10-21)

- **A field cleared to empty (the cell, or a paste with blanks as empty) does not show in History or the Changes log as its own entry.** Clearing deactivates the row without writing a new one, and the deactivation records no user. Reason: `PageSettings` was not to be changed in this work; the old value stays as an "Old" version. Suggestion for the owner: let `deactivate` record who cleared it, then list clears as "value → (empty)".
- **One image link can still take up to about 40 s** (4 hops × HEAD and ranged GET × 5 s) after the 20 s budget for the run has started. Reason: the spec fixes the 5 s timeout and 3 redirects; the budget stops new links, and a page with that many slow hosts is unlikely. The rest show as "not checked yet".
- **The Changes log pairs a row with the previous row of the same exact page name**, and finds it with one query per entry (50 per page). Reason: `PageSettings::set` always writes the first recorded spelling of a page, so names match; 50 small queries are fine at today's table size.
- **The customer field names known to the Check include a distinct read of `chatbot_customer_values` keys** (cached 10 minutes, at most 5,000). Reason: it keeps fields set by the inbox or older flows from showing as unknown; at a much larger table it may need its own index or list.
- **The Check fingerprint ignores "+ Column" additions and the customer-keys cache.** Reason: both change rarely, and the result expires after 10 minutes anyway.
- **Preview lists the run variables by hand** instead of reusing `ChatbotFlowController::RUN_VARS` for the keys. Reason: the values differ (sample customer), and the list has eight fixed names.
- **An IPv6-only image host** is pinned with brackets in `CURLOPT_RESOLVE` but was not tried against a real IPv6 host. Reason: the server has no known IPv6 egress, and the check then fails closed ("no answer").
- **The screen parts (Check column, Preview, History, Restore, Changes) are checked in headless Chrome only,** not by an automated test. Reason: the project has no JS test runner for page scripts.

## 101: flow editor canvas (2026-10-21)

- **The selected wire is kept as an index into `graph.edges`, which shifts when wires are removed** (a new wire from a port that already had one, a form that drops a port, a deleted step). The selection, the "×" and the Delete key can then point at another wire. Reason: this predates spec 101 (the "×" only makes it easier to see), and Undo recovers it. Fix later by storing the wire itself (from, port) instead of its index, or by clearing the selection whenever edges change.
- **The browser check script is not in the repo** (it sits outside, with a temporary sqlite database, as in specs 092–097). Reason: the repo has no browser-test runner and adding one is a new dependency. The result lists every check and its output so it can be rebuilt.
- **Real devices are not checked:** iOS Safari pinch and gesture events, and a mouse drag that starts on the minimap and leaves it. Reason: the headless check emulates touch through the DevTools protocol only. Try it once on a phone after deploy.

## 098: load fixes (2026-10-21)

- **The send pool drops a booking it reaches more than 20 ms late and books again** (`BotcakeSendPool::LATE`). If a pool tick regularly took longer than that, a busy page's lane could keep rebooking behind blocking senders. Reason: the pool loop ticks every 5 ms while it holds sends, and the local load check (20 pages, chats + 8 sequence processes + 4 Inbox processes) drained every chat with no stall. Suggestion: before dropping, check whether the cached slot still equals the booking; if nobody booked after it, send late and move the slot (`sentAt`).
- **No test for the stale-booking drop or the rebook after a 429 pause.** Reason: the reviewer traced both as correct; keep the suite small.
- **`ChatbotReply::hasRaw()` is checked once per process.** A worker or the send pool that started before the `raw` migration keeps not storing raw bodies until it restarts. Reason: deploy order is trusted; the result's deploy steps run the migration before `horizon:terminate` and the send-pool restart.
- **Other processes may read a changed setting from their memo for up to 2 s** (`PageSettings::MEMO_TTL`), and a cache miss right after a write lets concurrent requests each run the one page query. Reason: bounded and cheap (one query per page per miss).
- **A raw SQL or query-builder write to `page_settings` skips the cache forget** (only the model events and the stress-page cleanup forget). Reason: none exists in `app/` today; a future data migration should call `PageSettings::forget()` or wait 10 minutes.
- **The pool spends its slot when a ticket turns out not to send** (`issue()` returns null). Reason: rare (mismatch, pause, lost ticket) and costs one interval on one page.
- **`ChatbotFields` still reads the distinct botfield keys from `page_settings` on each chat** (the one query left per chat). Reason: not a per-page `get()`; out of this spec. Suggestion: cache it with the same forget.
- **The Inbox thread and the Replies screen don't show the raw body yet**; `/chatbot/replies/{id}` returns it as `raw`. Reason: no UI change in this task; showing it is a small front-end follow-up.

## 100: chatbot results (2026-10-21)

- **The J&T ranking is a second copy of Chatblast's** (`ChatbotResults::deliveryOf`, `PROGRESS`), now matching it, blank status included. Reason: Chatblast's `latest()`/`rank()` are private, and making them public touches a working module this task doesn't need to change; both copies are small. Move them to one shared place on the next change to either.
- **`ChatbotTags::RUN_VARS` repeats the run variable names of `FlowRun::vars()`.** A new run variable would be listed as a customer field in the tags until it is added there too. Reason: keeping `FlowRun` untouched beyond two properties keeps the merge with 098 small; the effect is a label in the JSON, not a number.
- **`/chatbot/results.json` without a page runs over all pages for up to 92 days.** Reason: only staff with /chatblast access reach it, it is throttled (60 a minute) and cached 5 minutes per filter set, and every query is bounded by date on an index. Requiring a page would break Mira's all-pages read.
- **Compare by botfield takes any two page_settings ids**, even two different botfields. Reason: the screen only offers versions seen in By message; a hand-made URL just compares two unrelated values, labelled as such.
- **The `chatbot_events (type, created_at)` index is not guarded against running the migration twice.** Reason: Laravel records the migration once; the columns and the table are guarded.
- **The AI step's sent answer is tagged (flow, version, no botfields) but has no test of its own.** Reason: it uses the same `forSend()` and record path as the Send node, which S-100.1 covers on both the waiting and the pool path.
- **"Chats" in By ad counts the customers the bot sent to with that ad, not incoming chats.** Reason: the ad is stored on the send; counting incoming chats per ad would need the ad on `botcake_chats`, a new pipeline the brief rules out.

## 102: queue pickup (2026-10-21)

- **Nothing checks that `REDIS_READ_WRITE_TIMEOUT` stays above `REDIS_QUEUE_BLOCK_FOR`** when both are set by hand. Reason: both are owner-set env values with safe defaults (60 against 5), and the comments beside them say so; a lower timeout only cuts an idle blocked pop, which the worker retries.
- **`CHATBOT_POOL_IDLE_MS=0` turns an idle pool into a busy loop.** Reason: trusted env only, the default is 200, and 0 is a deliberate choice someone would make while watching it.
- **`block_for` blocks on the first queue of a worker**, so a supervisor with several queues per process (balance `false` or `simple`) would delay its later queues up to `block_for`. Reason: chatbot and chatblast both use balance `auto` (one queue per process) and S-102.2 pins that; whoever changes the balance must lower `REDIS_QUEUE_BLOCK_FOR` or set it to 0.
- **The pools still poll their tables (200 ms idle) rather than waking on a Redis signal.** Reason: the idle tick costs about half a percent of one core per pool and adds 100 ms on average; a wake signal is more moving parts for that 100 ms.

## 103: ad account history (2026-10-21)

- **One active row per ad_account_id is enforced in code only** (transaction + `lockForUpdate`), not by a unique index. Under MySQL's default REPEATABLE READ a double submit of a brand-new ID should deadlock one request (a 500, as the old unique did); under READ COMMITTED or on pgsql two active rows for one new ID are possible, which would double that account's payment rows. Reason: a few staff edit this page by hand; a generated-column unique needs three dialects. Check production's isolation level, or add a generated-column unique if it ever happens.
- **Stale edit form:** if the edited account was deleted or renamed since the form loaded, saving with an ID that is already active updates that other account (the old `updateOrCreate` did the same). Reason: not a regression; needs two people editing the same account at once.
- **ID change differing only in case** (MySQL case-insensitive collation) is refused as a duplicate. Reason: Facebook ad account IDs are numeric.
- **pgsql puts NULL `created_at` first** in the change log (`orderByDesc`). Reason: production is MySQL, where NULLs sort last; old rows have timestamps.
- **Log pairing by time and user** can still pick the wrong old row when one user changes an ID of one account and renames another in the same second. Reason: same-ID renames are now paired correctly (S-103.7); an ID change plus another change in one second by one person through the form is not realistic.
- **No tests for:** re-adding a deleted ID, changing an ID onto an inactive (deleted) ID, deleting twice or an unknown ID. Reason: each is the plain path of `write()`/`destroy()` (no active row → added; update only touches active rows), already covered by S-103.1 and S-103.5.
- **`write()` returns added/changed/unchanged but store() no longer uses it.** Reason: harmless; kept for the reader of the method.

## 104: item settings log gaps (2026-10-21)

- **`/item/cogs` routes (`index`, `grid`, `update`, `delete`, `clean-redundant`) have no `auth` middleware** (`routes/web.php:686-690`). Anyone who can load a page can post a COGS edit with a CSRF token. Since spec 104 such an edit is logged, with "— anonymous —" as the user. Reason: auth and permissions are out of this spec and the owner's call. Suggestion: put the routes in the `web, auth, allowed_ip` group with a role check.
- **Deleting promo on `/owner/private` while RTS is still set sets `promo = NULL` on a NOT NULL column** (`deleteItemSetting`). On MySQL strict mode that is a 500, and nothing changes. Reason: this was already true before spec 104, and the spec forbids changing how values are saved. Now the log row rolls back with it, so no false trace. Suggestion: write '' instead of NULL, as `saveItemSetting` does.
- **The `cogs` → `cogs_ceo` auto-mirror (`mirrorMissingCogsToCogsCeo`) writes no log row.** Reason: it only copies missing (item, date) pairs after a logged `/owner/private` COGS save, and never overwrites a value.
- **`DailyPrimaryItemService::backfillSettingsPrices` sets `page_item_settings.mode_cod_int` with no log row.** Reason: it fills the price tag only where it is NULL, and touches no RTS, promo or COGS value.
- **`rtsSetByMap` ignores delete rows.** If RTS is deleted and later set back to the same value by someone else, the "set by" name stays with the first person. Reason: the same was already true for an RTS clear (rts = 0) through `saveItemSetting`; display only.
- **Small inconsistencies:**
  - `/item/cogs` delete of a cell whose value is NULL logs a row that shows "—". `/owner/private` skips a NULL old value.
  - An `/item/cogs` edit to the same price writes a no-op row ("Changed only" hides it).
  - A `/owner/private` delete without a price tag writes one row per matched price row.

  Reason: cosmetic; each row is true.
- **No tests for:**
  - `clean-redundant` preview (writes nothing)
  - the transaction rollback when the log insert fails
  - a CEO-COGS-only delete
  - a Marketing - OIC viewer of a delete row

  Reason: each is the plain path of existing code (preview returns before the delete; `DB::transaction`; the existing `isCeoView` gate) and none is on an untrusted path with a realistic failure.

## 106: page content check (2026-10-21)

- **A COD written as free text with two numbers ("299 + 50") parses as one number (29950).** Reason: the COD column comes from the order import (trusted), and the mode of the 3 days drowns one odd row; changing the import's COD format is outside the task.
- **The ads and item settings fallbacks match the page name with SQL `LOWER(TRIM())`, so a name with an inner double space misses them** (the orders step matches in PHP and handles it). Reason: trusted tables whose page names are typed through the app; matching in PHP would read every row.
- **A seller's text can close the `<<<TEXT … TEXT>>>` marker and steer the AI's verdict.** Reason: botfields are written by staff, and the worst effect is a wrong pass or X in the Check column; nothing is written or sent.
- **An image answer is keyed by its URL: a picture replaced behind the same URL keeps its old answer** until the URL or the expected item changes. Reason: Facebook/CDN picture URLs change when the picture changes; hashing the image would mean downloading it on our server.
- **Untested here:** the pgsql and mysql runs of the grouped orders query (quoted `PAGE`, `ITEM_NAME`, `COD`; `BTRIM`), the tie-break between two equally common pairs, the 6,000-character cut of a long text, and the job's time budget. Reason: the test DB is sqlite; the others are simple guards and the suite stays small.
## 108: chatbot reply priority (2026-10-21)

- **A sequence step job lost after its unique lock was taken holds that subscription for up to 3 hours** (`RunChatbotSequenceStep::$uniqueFor`). It happens on a queue flush, `horizon:clear`, or a Redis push that fails after the lock. Remedy by hand: delete the cache lock `laravel_unique_job:App\Jobs\RunChatbotSequenceStep:<subscription id>` (or wait out the 3 hours); the next tick then queues it again. Reason: all of these are operator actions or Redis failures on a trusted path. A shorter `uniqueFor` would let a long backlog grow copies again, which is the fault this fixes.
- **`botcake:stress-page --stats` counts database `jobs` on `chatbot` only**, so with `CHATBOT_QUEUE_CONNECTION=database` it leaves out the reply path on `chatbot-high`. Reason: a test-page diagnostic on a trusted path. Production runs on Redis, where the number is 0 either way. The fix is `whereIn('queue', ['chatbot-high', 'chatbot'])`.
- **No Horizon long-wait alert for `chatbot-high`** (`waits` lists only `redis:default`). Reason: `chatbot` had none either, and nothing reads Horizon's notifications today.
- **The reply path only gets two workers of its own until the auto-balancer moves more** (every 3 s, up to 2 at a time). Reason: with the AI and the sends in their pools, a reply-path job takes milliseconds. In the local proof, with 5,000 steps queued, pickup averaged 1 ms (max 19 ms) at 5 chats/s. Raise `minProcesses` only if Horizon shows `chatbot-high` waiting.
- Spec 107 accepted review minors (promo delete):
  - The breakdown matrix list (`OwnerPrivateController` ~4049) now returns `'NONE'` instead of null for a legacy NULL promo; that is the "NULL reads as NONE" decision, and the matrix shows the value as is.
  - Deleting a promo that is already NONE or '' writes no log row, the same as S-104.2 for an empty RTS.
  - An RTS-only save with no promo to carry writes `'NONE'`, which ends an older promo added later with an earlier date; this follows the "one meaning for no promo" decision.

  Reason: each one follows a fixed decision of the spec or an existing rule, and none is on an untrusted path with a realistic failure.

## 111: per-page messaging provider (2026-10-22)

- **`/api/gateway/events` has no throttle**, and a chunked body without `Content-Length` is read before the 64 KB check. Reason: the gateway forwards bursts (up to 40/s per page) and a throttle would turn them into retries; an unsigned request costs one HMAC and is refused. Revisit with a limiter sized from live gateway traffic.
- **Switch window:** the Botcake push checks the provider when it arrives, the gateway job when it runs. A message in the seconds between a staff switch from gateway to Botcake can be skipped by both. Reason: rare and staff-made; automatic switching (next spec) should hand over with an overlap.
- **Dedupe against a Botcake push assumes Botcake's `message_id` is the Facebook `mid`.** Not checked on live data. Only matters during a switch from Botcake to gateway (each side ignores the other's pages otherwise). Suggestion: compare one stored Botcake row with the gateway's mid before the first live switch.
- **`botcake.page_id` = Facebook page id** is read from code only. Suggestion: compare with the gateway's `GET /v1/pages` before switching a page.
- **PSID `(int)` casts stay** where they feed integer columns or signatures (`ChatbotInboxController::send` reply row and `CustomerFields`, `ChatbotInbound::afterStore` sequence exit, `BotcakeOutbox::record`). Columns that would break for non-numeric ids: `chatbot_replies.psid`, `chatbot_customer_values.psid`, `botcake_send_requests.psid`/`page_id` (big integers). Facebook PSIDs are numeric; `botcake_chats.psid` is a string. Reason: changing columns is a migration outside this spec.
- **A queued Botcake send-pool ticket created just before a switch to gateway still goes out through Botcake.** Reason: seconds-long window, and the message still reaches the customer once.

## 113: Forbeast token per page (2026-10-22)

- **With `GATEWAY_KEY` set, a page whose own token is invalid still counts as ready, and its sends use that invalid token** (the page token wins when present). Reason: this is what the spec says ("page token when present, else the global key"; "working token or the global key"). Suggestion: use the page token only when `forbeast.token_status` is ok, if the owner wants the global key as a safety net.
- **The old status stays when `connect()` is skipped** (for example, the Botcake ID is cleared while a Forbeast token exists). Reason: staff-made, and sends still need the Botcake ID. Pressing Test after the ID is set again rewrites the status.
- **Two Tests at the same moment can leave Likha's page secret different from Forbeast's** (PUT and store are not under one lock). Events then get 401 and Forbeast retries until the next Test. Reason: needs a staff double-click within milliseconds and fixes itself with one more Test. Remedy: `Cache::lock` around `ForbeastPageSetup::connect` per page.
- **`chatbot.provider` can still be set to `gateway` through a plain page settings cell**, which skips the "Add the Forbeast token first" check (and spec 111's `gatewayReady`). Reason: this existed before this spec, and only staff with page settings access can do it. Suggestion: make `chatbot.provider` a system key in the grid.
- **Clearing the token on a page that replies via Forbeast leaves it on Forbeast.** Its sends then use `GATEWAY_KEY` or fail with "not set up". Reason: the staff member chose to clear the token, and the row shows the provider and the status. Suggestion: refuse the clear, or switch the page back to Botcake, if the owner prefers.
- **`/chatbot/data` reads a few cached settings per row for the Forbeast info.** Reason: the reads hit the per-page cache, not the database, and the list is about 60 pages.

## 117: auto-switch to Botcake (2026-10-23)

- **Forbeast's error body format is assumed.** The Meta code and subcode are read from `error`, `meta`, `meta_error`, `error.meta` or the body root (`code`, `error_subcode`), else from a `#123` in the message text. If Forbeast forwards "(#200) This person isn't available" without a subcode, the page switches at once, and "(#100) No matching user found" counts toward the 5 failures. Reason: Forbeast's real error shape is unverified. Remedy: confirm it with the Forbeast side and adjust `ForbeastWatch::metaCode` and the subcode list.
- **Changes shows "auto-switch" for every `chatbot.provider` / `chatbot.auto_switch` row without a user**, including rows written from the CLI or tinker (these used to say "system"). Reason: there is no column for "who" other than `created_by`; today only the auto-switch writes these rows without a user.
- **Picking Botcake on a page that is already on Botcake does not clear the "Auto-switched" note** (`PageSettings::set` writes no row when nothing changed). Reason: the note means "switched by the system and nobody has changed it since", and a no-op pick changes nothing.
- **The health check runs one page at a time** (5 s timeout, `withoutOverlapping(10)`, in the background). With Forbeast hanging and 20 Forbeast pages, one run takes about 100 s, so 3 failures take a few minutes. Reason: 3 failures in a row only need to happen within minutes; `Http::pool` is the remedy if many pages move to Forbeast.
- **The health GET repeats the URL and key logic of `GatewayProvider` and `ForbeastPageSetup`** (a third copy). Reason: the legacy rule against refactors; the three can be merged into a small helper in a later chore.
- **A standby chat stays in `botcake_chats` (and the Inbox) when the Forbeast copy got no sent reply** (a flow without a send step, Bot Off, or a failed send). The customer then shows the message twice in the Inbox, and the 90-day prune removes it. Reason: the copy is kept on purpose so a switch can still answer it.
- **Botcake's `message_id` being the Messenger mid is still unconfirmed.** When it isn't, the twin match falls back to the same customer and text within 2 minutes.
- **Missing test variants** (the spec's behaviour is proven once each): the other connection codes (#200, #2022, #613, #4, #32), `connected=false` (only `can_send=false` is tested), the subcode path and blocked or deleted users, "once" for conditions 2–5, and a `_standby` param on a Botcake page (checked in code: it is removed). Reason: the suite-size rule (one test per behaviour); add them as table rows if any of these paths change.
- **A Forbeast run still in flight at the switch (waiting on an AI answer) can get a second reply:** the standby copy is replayed because the Forbeast twin has no sent reply yet. Reason: needs a switch inside the seconds of an AI wait. Remedy: also skip when the customer's `__last_chat` covers the twin and no failed reply is recorded for it.
- **A staff reply from the Inbox (`kind=manual`) does not count as answered**, so a customer staff already answered can get a bot reply after a switch (unless the bot was paused for them). Reason: manual replies are not linked to a chat id; a rare overlap of a switch and a manual reply within 15 minutes.
- **Twins without a matching mid are matched by text within 2 minutes:** two identical messages ("ok" twice) share one twin, and a Forbeast chat whose text differs from Botcake's (postback titles, attachments) finds none, so its standby is replayed after a switch. Reason: depends on the unconfirmed Botcake `message_id` = mid; when that holds, the mid decides.
- **`ChatbotRunner::skip()` loads the customer's unrun chats instead of an `exists()` query** (to leave out standby rows without a JSON query that differs between MySQL and pgsql). Reason: one customer's unrun chats are a handful of rows.

### 117 amendment 1 (standby hidden and pruned)

- **OPEN, for the reviewer's decision: a standby row whose Forbeast copy never arrived is hidden and deleted after 20 minutes.** If Forbeast misses 1–2 messages on a quiet page (below the 3-in-5-minutes inbound condition, health check green), that message exists only as a standby row: it is no longer shown in the Inbox or Chats and is deleted, so nobody answers it. The amendment says to prune all standby rows older than `standby_minutes + 5`, and the code does that. Options: spare (or promote to a real chat) the standby rows that have no Forbeast copy, or accept the loss.
- **The 24-hour window readers (`ChatbotSequences`, `Inbox` last-activity) ignore a standby-only last message**, so a sequence step can end with "outside 24h" when Forbeast missed the customer's latest message. Same root as the point above.
- **The standby rule lives in four places** (`BotcakeChat::active()`, the inline copy in `ChatbotResults::chats`, the prune, `ForbeastWatch::standby()`), and the PHP check treats `false`/`0` differently from the SQL one. Reason: only the system writes `_standby`, always as `true`. Remedy: `BotcakeChat::active()->toBase()` in `ChatbotResults`.
- **The JSON predicate stops the `(page_id, psid, received_at)` index from covering `Inbox::conversations` and `ChatbotResults::chats`.** Reason: row lookups on a page's window; watch it on large pages. Remedy: a real `standby` column with an index (needs a migration).
- **Missing tests:** `active()` keeps rows whose payload is NULL or `[]`; the `/chatblast/chats` pages hide standby rows; the 24-hour window ignores standby rows; the Test-tab picker answers 404 for a standby chat. S-117.15 calls `ChatbotResults::chats` by reflection. Reason: the suite-size rule; each is the same scope line.
- **The watcher's prune covers one day** (a standby row older than that is left to the 90-day prune), because it runs every minute and must not scan the whole table.

## 130: order encode in shadow (2026-10-23)

- **The compare reads a row once R and S are filled, so W (the GPT verify) may still be blank.** The reviewer suggested also waiting for W when S is not ✅. Reason: the script leaves W blank for good on its early-return paths (city not resolved, brgy list empty), so waiting for W would mark those orders "sheet not done" after 48 h instead of comparing them. The spec says wait on R or S only. Suggestion: once the shadow data is in, decide how the sheet marks "finished" (a done flag) and compare on that.
- **A GPT-filled city/barangay that contains regex characters is escaped (`preg_quote`) where the script builds an unescaped `RegExp`** (the script would throw or treat `(` as a group). Reason: the script behaviour there is a crash, not something to copy; only GPT-filled values can contain such characters.
- **`Text::compareLocale` approximates `localeCompare("en", base)` (ASCII-folded, case-insensitive `strcmp`)**, so the order of the T/U/V lists can differ from ICU for punctuation or digits in labels. Reason: it only changes the order of the allowed-list text in a GPT prompt, not which values are allowed.
- **`candidates` in the macro step is 0 on no match; the sheet's `COUNTA(Q:Q)` counts the `#N/A` cell as 1.** Reason: cannot change the result (7 points needs a match); only the number in the steps log.
- **A GPT connection error in steps 3 and 5 is logged and the row goes on; in the script it aborts the whole row.** Reason: aborting would lose the order from the compare for a transient error; the step log shows it.
- **Tests not added:** `Locations`/`compareLocale` ordering with digits and punctuation; `/order-encode?from[]=x` (the code ignores non-strings). Reason: no data can be lost; the suite is kept small.
- **No automatic re-drive of a live order if the `encode` worker is not running** (nothing runs the job, so nothing writes the sheet either). Reason: no page is live in this spec; before any page goes live, the Supervisor program must be running, or a re-drive command added (suggestion for the go-live spec).
- **Suggestions for the owner (not done, legacy rule):** the script quirks listed in `docs/specs/130-order-encode-shadow.result.md`.

## 132: Encode order step (2026-10-23)

- **"Continue without saving" with an encode that fails later loses the order** (only the `order_encodes` row, status `failed`, remains). Reason: it is the owner's explicit choice on the step; the compare page lists failed orders.
- **No automatic re-drive of a live order if the `encode` worker is not running** (same as 130). Reason: the step is not used by any flow yet; the worker is a go-live prerequisite.
- **The models are shown read-only but there is no screen to change them** (`app_settings` keys only). Reason: no settings screen exists for them in 130; adding one is a new feature.
- **The step prefill looks only at the Sheet step "All Orders" of the same flow**, not at other flows of the page. Reason: the builder has no cross-flow data; the fields stay editable.
- **A concurrent double run of the same step (two workers, same run id) could pass the "already queued" check together.** Reason: runs of one customer are serial; the macro_output dedupe still stops a second order row.
- **"Continue without saving" when the encode could not even be queued still counts as an order if "Counts as an order" is ticked** (the step leaves on `next`). Reason: the customer did order; the warning is in the run path and the event is `not_saved`.
- **`order_encodes` has no index on `run_id`/`flow_id`** for the "already queued" check. Reason: the check is also narrowed by `psid`; an index needs a migration, which this spec does not have.

## 133: shadow for every page (2026-10-23)

- **No history for the three import-shadow settings.** Reason: the project has no history table for `app_settings`; adding one is a new feature.
- **The import hook reads `app_settings` and counts today's encodes with a few queries per created order.** Reason: small next to the row's own insert; cache it per import run if a big import ever shows it.
- **`/order-encode/final` loads every order of the range in one pass (cursor) and paginates the disagreements in memory.** Reason: a week is a few thousand rows; a 30-day range of the whole company may be slow. Suggestion: a rollup table per page and day if the owner wants long ranges.
- **A flow shadow row compared by the sheet compare and by compare-final shows in both tabs.** Reason: intended; the two answers differ (sheet DATABASE row vs final order).
- **Orders whose `macro_output` row is deleted before compare-final wait 72 h, then show as "sheet never finished".** Reason: rare and harmless.
- **The cap is not atomic if two import jobs ran at once.** Reason: the `orders` queue runs one import at a time.
- **Reviewer minors:** `Schema::hasTable` and the skipped-counter read/write run per created order once the switch is on or the cap is hit; the counter is not atomic. Reason: small, display-only.
- **`/chatbot/health` accepts any digit string as `page_id`, and each (page, range) makes a 5-minute cache entry.** Reason: only chatbot roles or the events-key holder can call it, and the JSON route is throttled. Suggestion: check `page_id` against the page map.
- **Failure reason order is unreachable, window (#10), HTTP 5xx, timeout, other.** Reason: a 5xx whose body contains "(#10)" counts as window; narrow edge.
