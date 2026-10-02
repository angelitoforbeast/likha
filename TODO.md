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
