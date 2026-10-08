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
