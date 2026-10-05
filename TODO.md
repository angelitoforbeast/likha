# TODO

Accepted review findings, each with the reason it isn't fixed now (CLAUDE.md workflow step 5).

## Handoff 001: sourcing worklist (2026-10-01)

- **`quoteSave` sets fields the caller doesn't send to null** (note, and moq/link when left out). This was already true before the handoff. Since T5 such a clear also writes a history row. Reason: changing which fields a save may touch is outside the handoff, and both pages send every field they show. Suggestion for the owner: only update fields present in the request.
- **A malformed `date_range` on `/item/data` means no date filter** (`parseRange` returns nulls), and `/item/photo` builds the range string without an empty guard. Reason: already true before the handoff, and the input comes from the page's own date pickers.
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

## Handoff 002: worklist chips filter the item table (2026-10-01)

- **No JS test for the filter logic** (`worklistKeep`, `worklistItem`, the "1 x"/"2 x" base-key case, hold-only rows, sort kept across chips, empty-row conditions). `ItemPageTest` checks only the rendered markup and the order in which the page starts its requests. Reason: the repo has no JS test runner, and adding one is a new dependency. The logic is a few lines on top of 001's tested server rows.
- **If `/item/data` fails while a list is selected, the empty row stays on "Loading…"** (`loadHold` swallows its error, as before 002). Reason: the same silent-failure behaviour Lahat already has; a refresh retries.
- **"No data for selected date." can show next to "Walang item sa listahang ito."** when the summary comes back empty while a list is selected. Reason: cosmetic and rare; the two rows say consistent things.
- **The remaining wait on `/item` is the owner/private `item-summary` request itself.** Production evidence: the worklist HOLD query takes 0.44 s for 72 items, the join columns share a collation, and there are 44 PO lines. Reason: the heavy aggregation in `OwnerPrivateController` is outside handoff 002. Suggestion: profile `item-summary` separately.

## Handoff 003: stock, DOI, order qty, category (2026-10-02)

- **No JS test for the new page logic** (`doiText`, `orderByText`, `doiColour`, `categoryKeep`, the inline editors and their payloads, select reset after a failed save, the `saving` guard, the `order_qty` "—" guard, the `loadStock` stale guard). `ItemPageTest` checks only the rendered markup. Reason: no JS runner in the repo; adding one is a new dependency. The numbers themselves are computed and tested on the server (`StockEndpointTest`, worked examples A–D and every ruling).
- **The `QueryException` fallbacks in `categorySave` / `supplySettingsSave` are never executed by a test.** On MySQL REPEATABLE READ the re-select may not see a racing commit, so a double submit can end in a retryable 422. Reason: CEO-only path, sqlite can't reproduce the race, and no data is lost.
- **A page item whose name is only an `item_type_mappings` alias of the cogs name and has no orders in range has no item-level ITEM VAL.** (shows "—"). Reason: rare, and the scan couldn't confirm production has any alias mappings. The page row still shows its value.
- **Keyword rules match substrings** ("oil" in "toilet", "led" in "filled", "cap" in "capacity"). Reason: the rules are the handoff's trusted data, and the command is a dry run by default so Mira and Busing see every proposal before `--apply`.
- **`items:suggest-categories --apply` inserts without a transaction.** A failure partway leaves part of the set applied, and a re-run completes it, because existing assignments are skipped. Reason: Mira runs it by hand; it's idempotent.
- **After a date-range change, the stock columns keep the previous numbers until `/item/stock` returns** (no per-cell loading marker after the first load), and a failed fetch shows "—" with the error only in the tooltip. Reason: the request is fast and the same as how other columns refresh; it's cosmetic.
- **Item sorting puts `null` values first in ascending order** (the existing `_itemSortValue` comparator). Reason: pre-existing behaviour shared by every column; changing it touches all columns.
- **Missing edge tests the reviewers listed** (trusted or CEO-only paths): the seed migrations re-run or with existing rows, the FK restrict on category delete, the unauthenticated redirect, the suggest command's missing-table exit, a second `--apply`, null `ITEM_NAME`/`ts_date` rows, invalid dates or `view_as` on `/item/stock`, alias canonicalisation in `values`, and a non-CEO viewer with a `cogs_ceo`-only name. Reason: keep the suite small (`.claude/rules/tests.md`); none can lose data, and the code paths mirror ones already tested.
- **`StockMigrationsTest` compares the START date with "today in Manila"** and could fail if a run straddles Manila midnight. Reason: the migrations run in `setUp` before time can be frozen; the chance is tiny.
- **`ItemBaseKey::key()` repeats `ItemSupplierQuote::keyFor`'s regex**, pinned by a unit test. Reason: the legacy rule says no refactors of the existing copies; suggestion for the owner: one shared helper for all five.

## Handoff 004: restock target by lifecycle (2026-10-02)

- **`/jnt/supply` still reads its lifecycle defaults from its own request-param literals (14/30/90/1.5/0.5), not from `ItemLifecycle`'s constants.** Two copies of the same numbers; a test pins the constants. Reason: `/jnt/supply` must not change in this handoff; suggestion for the owner: point `index()` at the constants.
- **`ItemLifecycle::classify` keeps the unused `$hasOldOrders` parameter**, and the table only passes `true`. Reason: moved verbatim; removing it changes `/jnt/supply`'s method.
- **The column-config migration doesn't touch the `owner_private_cols_default` snapshot.** If the CEO ever used "Save as default" while CATEGORY was visible, "Reset to default" brings CATEGORY back. Reason: CEO-owned data, and the CEO can hide it again in one click.
- **The six `item_palugit` rows also show in `/jnt/supply/config` "Other settings"** (the editor lists every non-class group). That's where the CEO edits them, by design (spec §4).
- **The first-date cache key includes the request Host** (as `item-summary`'s keys do, per Mira's answer). A logged-in user who sends odd Host headers can create extra 12-hour cache entries. Reason: login required, same pattern as the existing item-summary cache, Mira's decision.
- **Missing edge tests the reviewers listed** (trusted data): an unknown `lifecycle_override` string (falls to the Active palugit and "— Unknown" badge), `stock_ready=false` keeping `doi_note`, a cached first date winning over the window date, the host-specific cache key, the `palugit_override` column-absent branch of `supplySettingsSave`, a malformed saved column config, and the multi-row override update. Reason: keep the suite small (`.claude/rules/tests.md`); none can lose data, and the code paths mirror tested ones.

## Handoff 005: /item layout (2026-10-02)

- **No runtime test of the new layout's Alpine logic** (`ilAabot`, `ilPill`, `ilDays`, `ilDate`, `ilMoney`, `ilPct`, `ilPieceCost`, `ilOrderReason`, `ilUrgency`, `ilColOn`/`ilIdOn`, `ilPageVal`, `ilTotVisible`, the `il_action_at` sort, the breakpoints and cards). `ItemPageTest` only proves the code and texts are in the markup. Reason: no JS runner in the repo, and `node` isn't in the worker's allowed commands; a runner is a new dependency. The numbers themselves come from the server and are tested there. Suggestion: allow `node --test` for a small pure helper file, or rely on Mira's browser check.
- **The campaigns panel inside /item's expanded block uses 11 px text**, and the shared markup's light greys (`#94a3b8`, `#bcc0c4`) are below AA. Reason: the panel must fit without horizontal scroll (Mira's answer 6), and its colours are in the shared include that `/owner/private` uses, which must not change.
- **On a phone the campaigns table wraps mid-word** (fixed layout, ~12 columns in ~340 px). Reason: the price of "no horizontal scroll" without changing the shared include; Mira's browser check decides if it's usable.
- **Below 1,100 px the header row is hidden, so header sorts aren't reachable in card view.** Reason: per spec (cards); the default urgency sort still applies.
- **ctrl/cmd-click on "Lumang view" / "Bagong view" opens in the same tab** (the link builds its URL on click). Reason: cosmetic; a plain click is the common case.
- **`.il-table th { overflow-wrap:normal }`** could let a long header word poke past its column at 1,100–1,365 px. Reason: unverified without a browser; part of Mira's checklist.
- **When `order_qty` is 0, the reason line's cover figure uses the 2-decimal `units_per_day`** and can differ by 1 from the server's. Reason: nothing is ordered in that case.
- **`ilPageVal` runs a few times per page-card cell** (x-show still evaluates x-text). Reason: only for open items; fine at current counts.
- **`ilPct` on a tiny negative (e.g. −0.04) prints "▼ −0.0%"** (sign is taken before rounding). Reason: cosmetic; fix list 1 asked only for the minus with ▼.
- **The page cards' Prof.Profit(1D) value keeps two decimals**; fix list 1's whole-peso rule is applied only to the KITA NGAYON cells (main row, TOTAL, narrow duplicate), as written. Reason: "nothing else changes"; Mira can extend it.
- **The colspan test matches `ilVw.lt1440` / `ilVw.lt1366` by text order only**; deleting the `ilColOn` guard would still pass. Reason: no JS runner; string-grep seam.
- **Missing edge tests the reviewers listed:** `?layout[]=old` and an empty `?layout=` (both give the new layout by the strict `=== 'old'`), a non-CEO `GET /item`, an item with no demand (`velocity_days` 1), TOTAL cells in the Marketing render, `ilPageVal` for `jnt_rdt` with partial members / `rts_pct` 0 / null price range / manual vs cogs item value. Reason: keep the suite small; trusted or display-only paths.

## Handoff 006: /item simple view in English (2026-10-02)

- **No runtime test of the 006 helpers** (`ilNext`, `ilReason`, `ilSupplier`, `ilCheapQuote`, `ilQtyCost`/`ilQtyCostAmount`, `ilDaysLeft`, `baseProfit7`, `ilCfTint`, `ilOrderTotal`, `ilSalesColspan`, `ilLeadLine`, `ilOrderReason`, `ilSalesADay`, the rank sort, the first-click-ascending `il_next` sort, the tab switch). `ItemPageTest` proves the code and texts are in the markup, not that they pick the right state. Reason: no JS runner, and adding one is a new dependency (same as 005). Suggestion: allow `node --test` on a small pure helper file.
- **"To order now" ₱ sums only red rows that have a cost line**; "Find a supplier" rows with no item value add to items and pcs but not to ₱. Reason: spec §6 allows it; no price, no honest number.
- **A ₱0 quote counts as "no price"** for Next step and the cost line, but still counts as a quote for the server's "Has a quote" chip and shows "₱0.00" in the details. Reason: display mismatch only; the chip logic is unchanged by design.
- **The Lead time line in the details shows the trend word and "losing money" to every role**, even one without the `lifecycle` grant. Reason: same as 005 (`ilLeadLine` was ungated there); Mira decides whether lifecycle is hidden from any role.
- **The Trend entry in the details shows the trend word plus its tip text**, which is also its tooltip. Reason: the visible tip is the "lifecycle detail" the handoff asks for in the details.
- **Below 1,100 px the header row is hidden, so header sorts aren't reachable in card view.** Reason: inherited from 005; the default urgency sort still applies.
- **The Sales total row tints its four % values** with the same owner rule per window. Reason: one colour meaning per column; the 005 total had no fills because 005 had none anywhere.
- **`il-w-item` is a marker class with no CSS rule** (a test pins the flexible Item column by it). Reason: harmless.
- **At 72 px (1,100–1,365 px) a value like "▼ −100.0%" may wrap.** Reason: width approved in spec §8; Mira's browser check decides.
- **`ilCfTint` evaluates the owner's rules with no reference row**, so a rule that compares against another column or a formula is skipped. Reason: the To order value is a combined base-item figure, not a page row.

## Handoff 007: night run (2026-10-04)

Accepted review findings. None is a blocker or a major; the majors were fixed (see `handoff/007-night-run/RESULT.md`).

- **A `queued` macro run waiting 15+ minutes behind a long job on the one `default` worker is closed as stale while healthy.** Reason: harmless (its job returns at start, the new run imports the same rows); the earlier run only reads "Failed / Stale". Trusted path.
- **A night import time set before midnight (e.g. 23:30) is recorded under that day's date, not the next morning's.** Reason: CEO input, trusted; the defaults are 01:00 and 02:00.
- **If the step update fails after a real import start, the step stays "Failed to start: interrupted" though the import runs.** Reason: needs a database failure between two writes; the import page still shows the run.
- **Job dispatch happens after the commit in both import starters**, so a failed dispatch leaves an active run with no job. Reason: database-failure path; macro has Force-stop and the no-progress rule, Likha the 2-hour rule.
- **A stale-closed Likha run's job finishes the sheet it is on** (and marks that sheet done on the closed run) before stopping. Reason: the job is only changed between sheets; same limit as the macro Force-stop.
- **A macro job closed mid-run skips the primary-item recompute and the cache bump** for rows it already imported. Reason: same as today's Cancel branch; the next import recomputes.
- **`app/Http/Controllers/MacroImportController.php` holds a second, unrouted macro start with no guard.** Reason: dead code, not touched by the handoff. Suggestion for the owner: delete the file.
- **`AI_CHECKER_LOG_FAIL` logs a query exception message** (it can contain order text), and the classic engine's `MACRO_CHECKER_*_HTTP` lines still log the response body. Reason: moved as it was / classic engine is out of scope; amendment 007-1 item 14 covered Astra's `post` only. Suggestion: class and status only, as Astra now does.
- **The runner's 500 payload carries the raw exception message**, as the browser gets today. Reason: the browser path must not change; the night job never copies it (fixed reasons only, tested).
- **A row deleted during a night call reads "Status set by a person".** Reason: rows aren't deleted in normal use; nothing is written either way.
- **The blank-STATUS rule exists in four places** (`blankRowsQuery`, `AstraEncoder`'s conditional write, `NightAstraRun::whereStatusBlank` and `isStatusBlank`). Reason: the first two are existing or opt-in code the handoff said not to refactor; they agree (reviewer checked NBSP, tab, '0').
- **A billed call whose runner ends in the exception path adds no cost to the night's estimate.** Reason: the result is null there; the cost is labelled "estimated".
- **A dead Likha run blocks Astra ("Did not run: an import was still running (Likha)") until something starts a Likha import**, which closes runs older than 2 hours. Reason: visible in the banner; with the Likha night switch on it heals itself at 01:00.
- **The tick is scheduled only while the Astra switch is on or a step is waiting/running**, so rows left in a *stopped* run are swept only then. Reason: Retry failed re-opens the step and the tick then runs; nothing is spent meanwhile.
- **After a short blip leaves the breaker's counter at 10 or more, one unrelated final failure before any success stops the run.** Reason: rare; Retry failed recovers it.
- **"10 rows failed in a row" can be fewer than 10 distinct rows** (a row counts on its first attempt and again on its final failure). Reason: it is a count of consecutive failures; stricter is the safe side for money.
- **Run now for yesterday's orders before tonight's Astra time takes that date's one run.** Reason: Mira's decision (amendment 007-1, question 4); the confirm text says so.
- **Banner: a false "has no record" on the day a switch is first turned on after its time** (or a time is moved earlier than now). Reason: the page doesn't know when a switch was turned on; it clears the next night.
- **"N of M done" counts only imports that have a record**, and counts "Done with failed sheets" as done. Reason: the badge shows the worst state and the banner names a missing step.
- **Duration of a re-opened run spans from its first start.** Reason: one record per date.
- **`cost_complete` is derived from the log's model names against the config prices**, not from the engine's `cost_known` flag. Reason: they agree; the flag isn't stored per row.
- **`isCeo()` ignores the CEO's "view as" preview** and now exists in three controllers. Reason: existing pattern, copied as the spec says; a preview is still the CEO.
- **Untested, by design or because sqlite can't run it:** real concurrency of the cache lock, of the claim and of the double-click re-open on MySQL; `INSERT IGNORE` and the `TRIM(STATUS)` clause on MySQL/pgsql; the jobs' between-sheet branches behind the Google client; the catch branches in `routes/console.php` and the job's own catch; the Alpine helpers of the Night run section; CSRF on the three forms (tests run without the middleware). Reason: the test DB is sqlite in memory, the Google fetch is out of test scope (handoff), and the project has no JS test harness.
- **Red runs that were "class or method not found"** for several first slices. Reason: `.claude/rules/tests.md` allows it for a module about to be created; the later slices have behaviour failures.
- **`node --test 'test/hooks/*.test.mjs'` was not run.** Reason: not in the handoff's allowed commands; no kit file was touched.

## Handoff 008: checker log cleanup (2026-10-04)

Closes the 007 item above about `AI_CHECKER_LOG_FAIL` and the `MACRO_CHECKER_*_HTTP` lines. Accepted minors from the review:

- **The runner's 500 payload still carries the raw exception message** (`AiCheckerRowRunner.php:99`). Reason: it is a return value, not a log; handoff 008 forbids any behaviour change. Same item as in 007; proposed as its own task.
- **`errorIdent` now exists twice** (`MacroChecker`, returning `''`; `AstraEncoder`, returning null). Reason: the handoff forbids touching `AstraEncoder` and allows one small private helper; second copy, not the third. Suggestion: one shared helper when either is next touched.
- **No non-JSON test on the search line.** Reason: it reads `type`/`code` through the same helper the OpenAI line's "not json" case proves; `.claude/rules/tests.md` says no second test for a proven behaviour.
- **A hostile provider could put up to 64 characters of `[A-Za-z0-9_.-]` into `error.type` / `error.code`**, and that would be logged. Reason: same rule as `ASTRA_ENCODER_HTTP` (Mira's decision on the shape); OpenAI's values are fixed identifiers, and the 401 key fragment sits in `error.message`, which is never read.
- **`CheckerLogCleanupTest` takes about 6 s.** Reason: the two 800 ms retry sleeps are real and may not change (no behaviour change).
- **`node --test 'test/hooks/*.test.mjs'` was not run.** Reason: the handoff says it is not needed; no hook was touched.

## Handoff 009: Claude Action columns (2026-10-04)

The review found no blocker and no major. Two minors on untrusted paths were fixed (unknown page key note in the command; cache-hit test). Accepted minors:

- ~~The column settings page still offers MOIC / Marketing checkboxes for the two columns; ticking them does nothing.~~ Closed by amendment 009-1 (round 1): the checkboxes are disabled with a "CEO only" hint and the server drops a posted tick.
- **Two `owner-private:claude-action` runs at the same moment for the same new page-day: the second fails on the unique key with a raw query error.** Reason: trusted operator path, nothing is lost or corrupted; the first write stands.
- **A CEO previewing with `view_as=marketing` still receives the `claude_*` keys in the JSON** (the columns are hidden on the page). Reason: D5 gates on the real role and the viewer is the CEO; same pattern as the 007 `isCeo()` item above. No test pins it.
- **Untested:** the rollback when the audit insert fails, the `forDate` / `forPage` path before the migration has run (`Schema::hasTable` guard, read by eye), the Alpine behaviour of the new cells (more/less, clamp, sort by the two columns) and the layout at phone width. Reason: no JS test harness in the project and no browser tools in the session; the view tests check the rendered markup only.
- **Line breaks in the Claude text show as spaces**, as in the Action note (`x-text` in a normal-wrapping cell). Reason: D8 says "the way the Action note does it".
- **The red run for the main table and breakdown markup is missing** (the developer wrote that markup before its tests; `/item` has a real red run). Reason: found after the fact; the tests were checked against the finished markup and the non-CEO test was later proven red by the marker-comment leak.
- **`node --test 'test/hooks/*.test.mjs'` was not run.** Reason: not in the handoff's allowed commands; no kit file was touched.

Round 1 (amendment 009-1), accepted minors from the second review (no blocker, no major):

- **An old grant of the two columns to a team role stays in the stored JSON through save-as-default and reset** (those two copy the stored rows as they are). Reason: it has no effect, `loadConfig` always hides the ids for non-CEO roles and the settings page never lists them (both tested); the next Save from the page rewrites the row without them.
- **The settings page tests assert what the server sends** (the injected `COL_CEO_ONLY` list, the one checkbox template with its `:disabled` binding, the guards in `sectionState`), not two rendered disabled inputs per section; a wrong-way guard in the JS would not be caught. Reason: the rows are built in the browser by Alpine and the project has no JS test harness; the server rules behind them are tested through the real endpoints.
- **No test that a save posting only `order` (drag-to-reorder) keeps the stored `hidden` and `visible_by_role`**, and none that a default snapshot taken with the two columns hidden for the CEO comes back hidden. Reason: both paths are unchanged code shared with every other column; the hide/show and snapshot/reset tests cover the two ids on the same paths.
- **`resources/views/owner/column_settings.blade.php` (the older combined settings view) has its own `sectionState` without the lock.** Reason: no route renders it; the server rules hold whatever a page posts. Suggestion for the owner: delete the dead view.

## Handoff 010: Claude Action and Reason editable by the CEO (2026-10-04)

The review found no blocker and no major; the three named checks pass. One minor on the untrusted path was fixed (a test for one blank field and for whitespace-only texts through the route). Accepted minors:

- **The "unchanged" check in `saveClaudeAction` reads the row outside the service's transaction.** If the artisan command changes the same page-day between that read and the write, the CEO's save can answer `unchanged` and write nothing. Reason: two trusted writers, nothing is lost or corrupted, and the response carries the row as it is now, so the cell shows it.
- **The page posts `ts_date: this.endDate`.** While a reload after a date change is still in flight, the rows show the old end date's note and a save would go to the new date. Reason: the same exposure as the team's Action modal (`row.action_date || this.endDate`), CEO only, and the modal shows the date it will save to under the page name.
- **The 500 ms auto-close after a save closes whatever Claude modal is open then**, also one opened for another row inside that half second. Reason: copied from the Action modal on purpose (D3: same behaviour); nothing is lost, the modal can be reopened.
- **Two CEO saves at the same moment for the same new page-day: the second fails on the unique key with a server error.** Reason: one CEO, same accepted finding as the command in handoff 009; the first write stands.
- **`bumpCacheVersion()` has one-second resolution**: a summary cached in the same second as the save (and after an earlier bump in that second) can stay stale until the next bump or Refresh. Reason: existing behaviour shared with the team's Action save; the cache code is out of scope. The cache test seeds an old version for this reason.
- **The refusal test names three non-CEO roles (Marketing, Marketing - OIC, Data Encoder), not every role string in the app**, and on its own it would also pass if the route did not exist. Reason: the gate is `checkCEOAccess()` (anything that is not the normalized `CEO` gets 404), one representative other role is enough, and the CEO tests plus the route test prove the route exists.
- **`startClaudeDrag`, `claudeModal` and the modal markup copy the Action modal instead of sharing code with it.** Reason: the handoff forbids touching the Action note's own code; the copy lives once, in two partials used by both pages.
- **Untested: everything that needs a browser.** The ✎ chip, focus on the clicked field, textarea auto-height, drag, the in-place cell update after a save, Escape / Tab in the modal, phone width, and the D5 look (`td.claude-col` white background, TOTAL row). Reason: no JS test harness in the project and no browser tools in the session; the view tests check the rendered markup only.
- **A guest gets the auth middleware's answer (401 for a JSON request, redirect to login otherwise), not 404.** Reason: D1 says "the same refusal the page's other CEO-only actions give"; the test compares with `GET /owner/private/daily` as a guest.
- **The red run was one run of the whole new test file per task, not one per slice**, and the absence assertions (non-CEO page source, breakdown without edit) were green before the change because they assert that something is missing. Reason: found in the developers' reports; the presence tests were red for the missing route and the missing markup.
- **`node --test 'test/hooks/*.test.mjs'` was not run.** Reason: not in the handoff's allowed commands; no kit file was touched.

Amendment 010-1 (CEO Action and CEO Reason), accepted minors from the second review (no blocker, no major; the four named checks pass). All are on trusted paths:

- **Before the two new migrations have run, a save through `owner/private/ceo-action` fails with a server error** (the service's `find()` meets a missing table). Reason: deploy order, in the deploy notes: migrate first. The pages and both data endpoints work without the tables (`Schema::hasTable` guard in `forDate` / `forPage`); nothing is lost.
- **No test drops a table to prove that guard**, for either note pair. Reason: same accepted gap as in handoff 009 (read by eye); the guard is two unchanged lines shared by both services.
- **A4 is held by the type-hint and one test, not by a hard barrier**: the Claude command asks for `PageDayClaudeActionService` and gets it; binding `PageDayCeoActionService` over it in a service provider would hand the command the CEO tables. Reason: only someone editing the repo can do that; the CEO service's docblock says no artisan command may use it, `git grep "page_day_ceo" -- app/Console` is empty, and the command test proves the CEO tables stay untouched.
- **The view tests only search the page source**: the `ceo_*` cases in `ilPageVal`, the sort by the two columns and the editor script are never executed. Reason: no JS test harness and no browser tools, as for the Claude columns.
- **The shared save helper's parameter is still called `$claude` and typed as the Claude service** although it also receives the CEO service (its subclass). Reason: cosmetic; renaming would only add diff lines in `OwnerPrivateController.php`.
- **The red run of the backend task has first-failure lines only for the settings tests**; for the migration, route, payload and command tests the developer reported the cause (missing table, missing route, missing key) without the line. Reason: found in the developer's report after the fact.

## Handoff 011: fit-to-width on the owner private table (2026-10-05)

The review found no blocker and one major (the factor was applied once and never checked while sideways overflow is hidden); it was fixed in loop 1 (bounded verify pass) and its arithmetic corrected in loop 2. Both named checks pass. Accepted minors, all on trusted paths:

- **Every change inside the table re-runs the measurement, also a hover on a page or campaign link** (those links write an inline style on hover, and the MutationObserver watches `style` and `class`). Each run sets the zoom to 1 and back and forces a layout of the whole table; possible stutter on a large table. Reason: not a loop (one run per frame, same result), and narrowing the observer would miss real width changes (the more/less toggles and the column bindings also arrive as `style` changes). Needs a browser to measure; see the proposed task in the handoff's RESULT.md.
- **If `init()` throws after the observers are attached, the switch is disabled but the observers stay live**, so a later measure could apply Fit behind a disabled switch. Reason: only `MutationObserver.observe` or the last `schedule()` call could throw there, and neither realistically does.
- **The verify pass can leave an overflow of less than one layout pixel**, because `offsetWidth` is a whole number. Reason: below what the page can measure; it sits inside the container's 16px padding and nothing is cut off.
- **The tests search the page source only.** `owFitFactor`, the verify pass and every observer are never executed; a wrong division with the right guards would stay green. Reason: no JS test harness in the project and none may be added (handoff section 5).
- **Untested: everything that needs a browser.** The fit itself, the sticky header and TOTAL row under zoom, first-paint flicker, scroll position across a recompute, the pencils' click targets at small factors, Firefox and Safari. Reason: no browser tools and no dev server in the session's allowed commands; the checklist for Mira is in RESULT.md.
- **In Fit mode the vertical scrollbar of the table area is always shown**, also when the rows fit the height. Reason: on purpose; a scrollbar that comes and goes changes the available width and is the classic resize loop.
- **The campaigns panel keeps its own sideways scrollbar** (`.expand-wrap{overflow-x:auto;max-width:100%}`) when its inner table is wider than the row. Reason: existing behaviour inside an expanded row, shrunk with the table; removing it means changing the panel, which is out of scope. Question for Mira in RESULT.md.
- **The red run of the first slice was one run of the whole new test file**, and the out-of-scope assertions on the rendered breakdown and item pages were green before the change because they assert that something is missing. Reason: the presence tests and the include scan were red for the missing markup.
- **`node --test 'test/hooks/*.test.mjs'` was not run.** Reason: not in the handoff's allowed commands; no kit file was touched.
