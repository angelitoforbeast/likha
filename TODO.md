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
