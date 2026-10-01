# Spec 001: Sourcing worklists on `/item`

Handoff: `handoff/001-sourcing-worklist/HANDOFF.md`. Status: approved by Mira 2026-10-01. Answers:
- `ts_date` gap count on production = 0, so the build uses `ts_date`.
- Q1: exclude normalised `cannotproceed` and `odz`.
- Q2: open = `ordered` + `delivered`.
- Q3: `ordered_qty` as entered.
- Q4: "dati" on the Lahat quote line too.
- T6 is high tier.

## Verified facts (2026-10-01)

| Handoff claim | Code says |
|---|---|
| `/item/data` HOLD rule, `STR_TO_DATE`, ignores `STATUS` | Confirmed, `ItemController.php:99-175`. Without `date_range` it has no date filter at all. |
| `HoldService::unitsByBaseItem` | Confirmed (`HoldService.php:27-69`), but its only callers are the daily snapshot (`SnapshotItemHolds`, `ItemHoldSnapshotController::runNow`). Changing it changes `item_hold_snapshots` history, a ripple outside this handoff. |
| Four copies of the key rule | Three agree: `ItemSupplierQuote::keyFor`, `HoldService::itemKey` (applied after the prefix strip), JS `supKey`. **`SupplyFinanceController::itemKey` does not strip `N x`**, so `supply_order_items.item_key` can hold "2 x foo". |
| CEO gate at 205/244/264/295 | Confirmed. |
| `supply_orders.status` | Only `ordered` (on create), `delivered` (arrived, `markDelivered`), `counted` (`saveCount` sets `received_qty`). No cancelled status: a cancel is a hard delete. `received_qty` is NULL until counted. Nothing in the code computes an open quantity today. |
| `supply_item_settings` | Keyed by `item_name` (unique string); `lead_time_days` defaults to 7. |
| Item photos | Confirmed: `store('item-images','public')` (a generated hash name), `image|mimes:jpg,jpeg,png,webp|max:10240`. |
| `latest.dump` tracked | Confirmed (`git ls-files`). Not in `.gitignore`. |

### `macro_output.STATUS` values (for requirement 4, needs Mira's go)

- Written: `PROCEED` (auto checker, AstraEncoder), and by hand from the dropdown (`macro_output/index.blade.php:599-601`): blank, `PROCEED`, `CANNOT PROCEED`, `ODZ`. Free text is accepted (`nullable|string|max:255`), and the sheet import keeps any value.
- No `CANCELLED` value exists. **`CANNOT PROCEED`** is the "won't ship" status (excluded from validation, duplicate checks, red row). **`ODZ`** is a third terminal status, never sent to J&T (my reading: out of delivery zone).
- Existing code normalises with `LOWER(REPLACE(REPLACE(TRIM(STATUS),' ',''),'_',''))` and compares to `'cannotproceed'` and `'odz'` (`OwnerPrivateController`, `SummaryOverallController`, `JntSupplyController`).
- **Proposal:** HOLD excludes rows whose normalised STATUS is `cannotproceed` or `odz`. Blank and `PROCEED` stay. (`JntHoldDownloadController` counts `PROCEED` only. That is stricter, and I don't propose it: blank rows that already have a waybill are real waiting orders.)

### `ts_date` reliability

- Filled by DB triggers on insert and on update of `TIMESTAMP` (mysql and pgsql, migration `2025_12_19_235532`), plus a backfill. No later migration drops them. Every insert goes through `MacroOutput::create` (two importers), so nothing bypasses the trigger.
- `ts_date` is NULL only when the last 10 characters aren't `dd-mm-yyyy`. Both importers normalise TIMESTAMP to `H:i d-m-Y`, so that covers almost every row. A whole-day `ts_date BETWEEN start AND end` gives the same days as today's datetime filter from 00:00:00 to 23:59:59.
- **Gap:** `ImportMacroFromGoogleSheet` keeps the raw sheet value when no format parses. A value such as `00:03 1-10-2026` or one with a trailing space can still parse with `STR_TO_DATE` while the trigger leaves `ts_date` NULL. Those rows would drop out of HOLD. Deploy note for Mira: before deploy, run `SELECT COUNT(*) FROM macro_output WHERE ts_date IS NULL AND STR_TO_DATE(`TIMESTAMP`,'%H:%i %d-%m-%Y') IS NOT NULL` on production; if it's not ~0, hold back the `ts_date` part.
- `OwnerPrivateController`'s HOLD and `JntHoldDownloadController` already filter on `ts_date`, which suggests the triggers exist on production.
- **Verdict: reliable, subject to that one count.** `/item/data` and the worklist filter `mo.ts_date BETWEEN start_date AND end_date`.

## Questions for Mira (need answers with the go)

1. **Cancelled STATUS values.** Proposal: exclude normalised `cannotproceed` and `odz` (see above). Keep NULL, blank and `PROCEED`. Not checked: whether a J&T cancel (`jnt_shipments` `CANCEL_OK`) leaves `macro_output.waybill` set, which would keep that order in HOLD; that needs production data.
2. **Which `supply_orders.status` values are "open".** Your reading is `ordered`. **I recommend `ordered` + `delivered`.** A `delivered` order has arrived but hasn't been counted (`received_qty` NULL). With `ordered` only, Busing marks a PO delivered, the item jumps to "I-order na" with the full HOLD as shortfall, and he reorders stock already on his shelf. `counted` stays closed. Stock on hand after counting is never compared with HOLD; that's out of scope here and goes to the report as a suggestion.
3. **PO lines typed as "2 x foo".** `keyFor` matches them to "foo", but `ordered_qty` is used as is (not ×2). I recommend this, because a PO quantity is what was ordered from the supplier.
4. **"dati ₱X (date)" in the Lahat table too.** I recommend yes: it's the existing quote line (`index.blade.php:753-763`). One added span; the table layout is unchanged.

## Ripples (beyond `/item`)

- `/item/photo` builds its item list from `/item/data` (`photo.blade.php:291`), so the STATUS exclusion and `ts_date` change apply there too. That's intended, but it is a second page.
- `/item/photo` also uses the quote endpoints. Its `saveQuote` sends JSON with no photo, so a save without a photo must keep the existing photo (see below).

## Design

### HOLD (requirement 4)

`HoldService` gets two public methods. Existing behaviour stays as it is.

- `liveHoldQuery(?string $startDate, ?string $endDate): Builder` is the base query: `macro_output mo` left join `from_jnts fj` on the waybill, `fj.waybill_number IS NULL`, waybill not blank, the normalised STATUS not in the approved list (NULL STATUS kept), and `mo.ts_date BETWEEN` when both dates are given. It uses portable SQL only (TRIM/LOWER/REPLACE/NULLIF) with the existing per-driver column quoting, so it runs unchanged on mysql, pgsql and sqlite.
- `groupUnitsByBaseItem(iterable $rows): array` is the existing PHP loop from `unitsByBaseItem`, extracted as is (Blue refactor) and extended additively with `variants => [raw item_name => units]`. `unitsByBaseItem` calls it, so the snapshot output is unchanged apart from an extra key it ignores.
  - The extracted loop gets literal-row tests.
  - The snapshot query itself (`STR_TO_DATE`) stays uncharacterised: it can't run on sqlite, and it isn't changed.
  - The same table test asserts that the group key equals `ItemSupplierQuote::keyFor(raw name)` for representative names ("1 x Hand Grip", "2× hand  grip", "HAND GRIP"). That proves the HOLD side and the PO/quote side join on the same key, without a fifth copy of the rule.
- `ItemController::data` builds on `liveHoldQuery` (keeping its `q` filter, grouping per raw `ITEM_NAME` + page, and its response shape).
- **Not changed:** `HoldService::unitsByBaseItem`'s own query (the snapshot), `JntHoldController`, `OwnerPrivateController`. Whether the snapshot should adopt the live rule goes in the report as a suggestion.

### Classification (pure)

`App\Services\SourcingClassifier::classify(bool $hasPoLine, int $quoteCount, int $openQty, int $holdUnits): array{list: string, shortfall: int}`, first match wins:

1. `hanapan`: `!$hasPoLine && $quoteCount === 0`
2. `may_quote`: `!$hasPoLine && $quoteCount > 0`
3. `i_order`: has a supplier and `$openQty < $holdUnits`. Shortfall = `holdUnits − openQty` (covers "no open PO", where openQty = 0).
4. `naka_order`: otherwise (open qty ≥ HOLD). Shortfall 0.

Constants hold the four list keys. Labels live in the Blade only.

### `GET /item/worklist?start_date&end_date`

- Same `checkAccess()` (other roles → 404). `Marketing` and `Marketing - OIC` get `{ok:true, counts:{}, items:[]}` (counts as a JSON object, `new \stdClass`), the pattern of the other supplier endpoints.
- Dates: `Y-m-d` regex like `photoForm`. Invalid → the same defaults (first day of last month → today, Manila). Reversed → swapped.
- Steps:
  1. `liveHoldQuery` grouped by `ITEM_NAME` → `groupUnitsByBaseItem` → base items with units > 0.
  2. PO lines: `supply_order_items` join `supply_orders` join `suppliers`, matched by `ItemSupplierQuote::keyFor(item_name)`. This is the existing key function, and it also normalises PO names typed with an `N x` prefix.
     - **A PO line** (for both "has a PO line" and open qty) is a line with `ordered_qty > 0` and `unit_cost >= 0`. That excludes negative discount lines and keeps free goods.
     - **The supplier price shown** keeps the `/item/suppliers` rule: the latest line per supplier with `unit_cost > 0`.
  3. Quotes by `item_key`. `supply_item_settings` by `keyFor(item_name)`.
  4. Item photo: `item_images` of the variant with the most units that has a photo.
- Open qty = Σ over PO lines on open orders of `max(0, ordered_qty − COALESCE(received_qty,0))`. The open statuses are question 2 above.
- Row:
  ```
  { key, name, variants:[{name, units}], hold_units, image_url, photo_item_name, list, shortfall,
    suppliers:[{source:'po'|'quote', supplier, price, date, photo_url?}],   // latest per supplier
    open_po: null | { orders, supplier, order_date, days_since, ordered_qty, received_qty, open_qty, lead_time_days } }
  ```
  - `ordered_qty`, `received_qty` and `open_qty` are summed over all open orders, with `orders` = how many.
  - `supplier`, `order_date` and `days_since` come from the oldest open order (the one waiting longest). `days_since` is counted to today in Manila.
  - `photo_item_name` is the raw variant name that Change/Copy and `itemImages` use: the variant with the most units that has a photo, otherwise the variant with the most units.
- Response: `{ok:true, counts:{hanapan,may_quote,i_order,naka_order}, items:[...]}`, sorted by `hold_units` desc, then name asc.
- Missing tables (`Schema::hasTable`) degrade to "no POs / no quotes".

### Quote history and photo (requirement 3)

- **Migration A** `item_supplier_quote_history` (guarded by `Schema::hasTable`): id, quote_id (nullable, not a foreign key), item_key (indexed), item_name, supplier_id, price decimal(10,2) nullable, moq unsigned nullable, link string(500) nullable, action string(10) (`update`|`delete`), quoted_at (the old quote's `updated_at`), updated_by (who made the change), timestamps.
- `quoteSave`: if the quote exists and price, MOQ or link differ, insert the old values and then update.
  - Price compares as nullable strings at 2dp ("100" = "100.00"; null ≠ 0).
  - MOQ compares as a nullable int (null ≠ 0).
  - Link compares as a nullable trimmed string.
- `quoteDelete`: insert the old values with `action=delete`, then delete. Nothing is written when the id/`item_key` pair doesn't match a quote.
- Both run in a DB transaction that reads the existing quote with `lockForUpdate()`, so a double submit writes one history row. History writes are skipped when the table is missing.
- `quoteRows` adds `prev_price` and `prev_date`: the latest history row for the same `(item_key, supplier_id)` whose price is not null and differs from the current price, with its `quoted_at` date. One extra query for all keys. Both quote displays show "dati ₱X (date)" (question 4).
- **Migration B** adds a nullable `photo_path` string to `item_supplier_quotes` (guarded by `Schema::hasColumn`).
- `quoteSave` accepts an optional `photo` with `nullable|image|mimes:jpg,jpeg,png,webp|max:10240` (the item photo rules; Laravel's `image` rule excludes SVG). It's stored with `store('supplier-quote-images','public')`, which generates the file name.
- **No photo in the request → `photo_path` and the file stay as they are.** Fields not sent never overwrite `photo_path`, and `photo_path` is never accepted from the request.
- File order:
  1. Store the new file before the transaction.
  2. After the commit, delete the replaced file (best effort, like `uploadImage`).
  3. If the transaction fails, delete the new file.
  4. `quoteDelete` deletes the quote's file after the commit.
- A photo change isn't a history field.
- `quoteRows` adds `photo_url`.

### Page (requirement 2)

- In the CEO view only (`@if($effectiveIsCEO)`), chips sit above the table: **Lahat** (default, today's table untouched), **Hanapan ng supplier**, **May quote, hindi pa na-order**, **I-order na**, **Naka-order, hinihintay**, each with a count from `/item/worklist`.
- The worklist is fetched in `init()` (CEO only) and refetched in `load()` when the dates change and after a quote is saved or deleted.
- Choosing a list hides the main table and shows a simple table with these columns: Item (photo, name, variants with units, quote thumbnail), HOLD units, Suppliers (🏭 PO / 🏷 quote, price, "dati ₱X (date)"), and Status (open PO: supplier, date, qty, "X araw / lead Y araw" in red when over the lead time; or "kulang N" for the shortfall). Actions: the existing inline "+ supplier quote" form, Change (photo page link), Copy.
- `?list=` is read at construction, added to `qsObj` in `load()` (which today rewrites the query string), and written with `history.replaceState` on chip click. An unknown value falls back to `lahat`.
- The quote form gets a file input. Its `@change` writes the file into `quoteForm.photo`, so no `x-ref` lookup can pick the hidden form's input (the hidden Lahat form and the worklist form share `quoteForm.key`).
- `saveQuote` sends `FormData` (multipart) with the CSRF header as today, and leaves out empty fields instead of appending `null`.
- Change/Copy on a worklist row use `photo_item_name`.
- All output goes through `x-text` / `:src` / `:href`. No `x-html`, no `{!! !!}`.
- Quote `link` in the **new** worklist markup goes through a `safeLink()` helper (http/https only, otherwise no link). The existing Lahat binding is unchanged; its self-XSS (`javascript:` link typed by the CEO himself) goes to `TODO.md` as accepted.
- **T7 has no PHP test seam.** It's checked by `npm run build`, by the server-side tests behind each value it shows, and by `browser-checker` if browser tools exist in the session (otherwise said so in RESULT).

## Threat model

- **Untrusted:** uploaded images; quote fields (price, moq, link, note) and `start_date`/`end_date`/`list` from requests (CEO only, validated); stored `ITEM_NAME`/`PAGE`/supplier names (from sheets and users). Rendered only via Alpine text bindings.
- **Trusted:** repo, config, schema, Busing's and Mira's inputs.
- **Exposure:** every new endpoint and field sits behind `auth` + `checkAccess()` + the exact role `CEO` in the controller (the data layer), not only in the UI.

## Tests (sqlite in memory)

- **Harness:** `tests/Feature/Item/ItemTestCase.php` follows `BoardroomTestCase`. `Schema::create` builds the minimal legacy tables (`users`, `employee_profiles`, `macro_output` with `ITEM_NAME`, `PAGE`, `TIMESTAMP`, `waybill`, `STATUS`, `ts_date`, and `from_jnts`). The real migrations run by `--path` for `supply_item_settings`, the supply finance tables, `item_images`, `item_supplier_quotes`, and the two new ones. `Storage::fake('public')`.
- **SQLite caveat:** the `ts_date` trigger migration can't run on sqlite, so fixtures set `ts_date` themselves, as the trigger would. The changed paths use no MySQL-only SQL. Today's `STR_TO_DATE` date filter can't be characterised on sqlite, and I won't fake it: the characterisation test pins the rule without a date range (waybill, `from_jnts`, blank waybill, per item+page counts, `CANNOT PROCEED` counted today). The `ts_date` range is then added test-first.
- **Seam 1:** `tests/Unit/SourcingClassifierTest.php`, one table of literal cases: each rule, the boundaries (open = HOLD → naka_order, open = HOLD−1 → i_order shortfall 1), and first-match order (a quote and no PO line → may_quote even with open qty 0).
- **Seam 2:**
  - `tests/Feature/Item/WorklistTest.php`:
    - CEO gets the four classified rows with counts, sorted (tie by name), variants grouped, and units = rows × N.
    - Open qty covers open statuses only.
    - The role table: Marketing and Marketing - OIC get the empty shape; another role gets 404.
  - `tests/Feature/Item/QuoteHistoryTest.php`, one change table:
    - a price change writes one history row with the old values
    - MOQ and link changes write one each; null→0 counts as a change
    - an unchanged save, and "100" vs "100.00", write nothing
    - delete writes one row; delete with a mismatched `item_key` writes nothing
    - `prev_price`/`prev_date` are returned
  - `tests/Feature/Item/QuotePhotoTest.php`:
    - a jpg is stored under `supplier-quote-images/` with a generated name
    - a save without a photo keeps `photo_path` and the file
    - a new photo deletes the old file; deleting the quote deletes its file
    - a `photo_path` in the request is ignored
    - rejects with 422 and stores nothing: a non-image, a fake `.jpg`, a file over 10240 KB
    - non-CEO gets 403
- **Seam 3:** `tests/Feature/Item/HoldDataTest.php`: characterisation first, then a STATUS table (NULL, blank, `PROCEED` kept; `CANNOT PROCEED`, `cannot proceed`, `CANNOT_PROCEED`, ` odz ` excluded, per Mira's answer) and the `ts_date` range. Plus `tests/Unit/HoldServiceGroupingTest.php` for the extracted loop and the key-equality table.
- Expected numbers are written by hand from the fixtures.
