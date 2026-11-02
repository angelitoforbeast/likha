# Spec 220: stock on /item = Nexus stock-in + J&T returned − shipped

**Project:** Likha (likhaaitech.com). The same code runs on Incepxion; there, Nexus is simply not configured, so the stock-in source is empty and the "not synced" behaviour applies.
**Shape:** change · **Weight:** architectural · **Risk tier:** medium (business numbers that drive ordering; no customer messaging)

## Why
Today, stock on /item is PO received minus shipped (app/Services/ItemStockService.php). POs are almost never marked received: 16 rows, the last on 2026-08-19. The stock start is 2026-10-02, so stock only goes down, and DOI and I-ORDER are wrong.

The warehouse records every arrival in the Nexus app. Nexus exposes it through a read-only API, and the token is already in Likha's server env. Parcels J&T returns to the sender should also add back to stock.

The owner wants stock to follow what really came in, so he knows what to order. Prices are not needed for this.

## Decisions already made (don't reopen)
| Decision | Who and why |
|---|---|
| Stock = Nexus stock-in pieces + returned pieces − shipped. Nexus stock-in REPLACES PO received in the stock number. Open-PO "incoming" (paparating) stays. PO prices, unit_cost, COGS and price history are untouched; supply_order_items.received_qty is still stored, only the stock number stops reading it. | Owner, 2026-11-03: the goal is knowing what to order, and the same goods would be counted twice otherwise. |
| Returned, for now = J&T: from_jnts.status = 'Returned', dated by signingtime. 'For Return' does not count. A return counts only if the waybill was counted as shipped after the stock start, mapped through macro_output.ITEM_NAME with the same ItemBaseKey::parse as shipped, once per distinct waybill. | Owner, 2026-11-03: "use the returned status, it has a date". |
| The returned source is swappable: an interface with a J&T implementation now and a Nexus RTS implementation later. A Nexus team endpoint GET /api/rts-returns is being added separately; build only a stub class for it now. The source is chosen by an app_settings row (default jnt). It switches for all history at once, never mixed, and only by the owner after a totals comparison. | Owner, 2026-11-03. |
| The Nexus sync runs HOURLY (Manila time, withoutOverlapping). Each run refreshes a trailing window that includes today. Today's rows are overwritten, never added to. The first run backfills from the stock start in windows of 31 days or less. | Owner, 2026-11-03: "okay sige". |
| /item warns when the last successful sync is more than 3 hours old. It still shows the numbers and never zeroes stock because a sync failed. | Owner, 2026-11-03. |
| Nexus "remaining" is shown as a cross-check next to stock, labelled so that a gap from pre-start opening stock is not read as an error. Unmatched Nexus names are listed, collapsed with a count. | Owner, 2026-10-25. |
| A lean schema: one table for the Nexus stock-in per day and item. Raw names are stored and matched at read time. Last-synced, last-error and the returned-source switch are app_settings rows. No other new table. | The owner's lean-schema and database-driven preference. |

## Facts
- Nexus API: GET https://srv1972783.hstgr.cloud/api/item-stock?date=YYYY-MM-DD or ?from=&to= (31 days max), header `Authorization: Bearer` + env NEXUS_API_TOKEN (already on the Likha server; read it through config/services.php, e.g. services.nexus.token and services.nexus.url).
  - The response is {as_of, from, to, items:[{item, stock_in (lots), stock_in_pieces, remaining}]}.
  - `remaining` = pieces on the shelf now, not as of the day asked. A live call on 2026-11-03 returned 200 with 42 items.
  - Nexus names are free text; there is no SKU.
- from_jnts.status: 'Returned' rows all have signingtime (46,133 since 2026-09-01, the latest 2026-11-02 17:41). 'For Return' has none.
- Code map, from a read of 86db0060:
  - ItemStockService::build: raw = receivedByKey − leftByBase (around lines 88-91). stock = max(0, raw); stock_needs_count = raw < 0.
  - incomingByKey = ordered − received on POs with status ordered or delivered.
  - leftByBase counts waybills whose first from_jnts.submission_time is on or after the start, not cancelled, joined to macro_output.waybill, times the ItemBaseKey::parse pieces.
  - The start is app_settings.item_stock_start.
  - GET /item/stock is ItemController::stock (around line 415). It calls build() every request, with no cache.
  - The view is resources/views/item/index.blade.php (stock section around line 3289).
  - The alias resolver is App\Services\ItemAliasResolver::canonicalKey. It does not strip "N x", so the Nexus↔Likha bridge is canonicalKey(ItemBaseKey::key(name)) on both sides, mapped back to the Likha base key.
  - The worklist chip (/item/worklist, i_order) does not read stock.
  - Scheduling: routes/console.php. meta-ads:sync is the pattern for syncing an API into a table.
  - Http::fake example: tests/Feature/Boardroom/BoardroomTestCase.php.
  - Cache pattern: Cache::remember with host and date in the key.

## Stories (P1 unless marked; case ids S-220.x.y)
You may correct a case when the code proves it wrong. If you do, list it under "Story changes" in the result.

**S-220.1 Hourly sync stores stock-in per item per day.**
- 1.1 A faked item for 2026-11-02 is stored with its pieces and lots, and the request carries the bearer token.
- 1.2 The first run backfills from the start in windows of 31 days or less (2 requests as of 11-03); the sums match.
- 1.3 It runs hourly with a trailing window that includes today, and today's row is overwritten.
- 1.4 Re-runs never add (24 runs = 1 run).
- 1.5 A correction from 48 to 40 stores 40.
- 1.6 No token: a clear message, no request, no change, and the token never appears in output or logs.
- 1.7 Concurrent runs: no duplicates (unique key plus overlap guard).
- 1.8 Non-ASCII, case and spacing variants: no near-duplicate rows, and the text is stored intact.
- 1.9 Nothing before the start is queried; with no start, stock_ready stays false.
- 1.10 0 or null is stored as 0; negative or non-numeric values are skipped and logged.
- 1.11 Manila dates are used at midnight, and the previous day stays in the window.

**S-220.2 A failed or empty sync never zeroes stock; "last synced" is visible.**
- 2.1 /item/stock returns the last-synced time (Manila), and the page shows it.
- 2.2 A 500 or timeout: no row changes, last-synced is kept, the last error and its time are recorded, and the previous stock is returned.
- 2.3 A 401: the same, plus the page says the token needs fixing, without echoing it.
- 2.4 200 with empty items, malformed JSON or no items for a stored day: nothing is deleted or zeroed, last-synced doesn't advance, and a warning is recorded.
- 2.5 Partial windows: the successful ones are stored and the failed ones retried next hour; nothing is deleted.
- 2.6 More than 3 h since the last success (3h01 warns; 3h00 and 2h59 don't): a visible warning, and the numbers still show.
- 2.7 Never synced: the page says "not synced yet" rather than showing a normal zero.
- 2.8 With the table missing, /item/stock does not 500.
- 2.9 After a day of failed runs, the first success catches up and clears the warning.

**S-220.3 Name matching, with an unmatched list.**
- 3.1 Case and spacing variants land on the Likha item.
- 3.2 item_type_mappings is honoured through the same resolver.
- 3.3 An unknown Nexus name goes to the unmatched list with its pieces and changes no item.
- 3.4 Mapping a name later counts its history without a re-sync.
- 3.5 Two Nexus names that resolve to one item are summed once each.
- 3.6 An "N x" prefix in a Nexus name is stripped; pieces come from stock_in_pieces.
- 3.7 A Likha item with no Nexus name gets stock-in 0 and is not listed as unmatched.
- 3.8 The list is collapsed with a count.
- 3.9 Non-ASCII matching is stable.

**S-220.4 J&T returned, by signingtime.**
- 4.1 "2 x GLOW TAPE" shipped after the start, then Returned: returned = 2, and the parcel nets to 0.
- 4.2 No prefix = 1 piece.
- 4.3 'For Return' is not counted.
- 4.4 Shipped before the start: not counted.
- 4.5 Cancelled in macro_output: not counted.
- 4.6 Returned with a null signingtime: not counted, and the count is visible in a log or debug output.
- 4.7 Several from_jnts rows: one count per distinct waybill. Decide and document which row sets the status (suggested: the latest).
- 4.8 A flip away from Returned removes it on the next read; it is never double counted.
- 4.9 Manila date, and future or impossible dates are ignored or flagged.
- 4.10 A multi-row macro_output waybill is counted per row, as shipped.
- 4.11 If returned exceeds shipped, the item is flagged, not hidden.

**S-220.5 Returned-source switch.**
- 5.1 The default is jnt.
- 5.2 "nexus" with a fake source uses it for the whole history, with no mixing.
- 5.3 An unknown value falls back to jnt with a warning; both are never counted.
- 5.4 "nexus" unavailable: returned is shown as unavailable with a warning. There is no silent fallback to J&T and no zeroing.
- 5.5 Only the owner's edit changes the setting; deploying the Nexus source leaves jnt.
- 5.6 The cache key includes the source.
- 5.7 Flipping changes no data.
- 5.8 (P2) A read-only artisan command prints the J&T returned and Nexus RTS totals per item side by side for the comparison. With the Nexus RTS stub, the Nexus side shows "not available".

**S-220.6 Stock = stock-in + returned − shipped.**
- 6.1 Stock-in 100, returned 4, shipped 30 → 74.
- 6.2 The start day and the last day are inclusive; days before the start are not counted.
- 6.3 Negative raw → 0, with needs_count and "bilangin".
- 6.4 No start → null fields.
- 6.5 No Nexus rows and some shipped → 0 with "bilangin", not an error.
- 6.6 PO received no longer adds: a counted PO of 50 plus Nexus stock-in of 50 = 50, not 100. A counted PO with no Nexus stock-in contributes 0.
- 6.7 incoming (paparating) and discount lines are exactly as before.
- 6.8 CEO and Marketing see the same numbers, no price is added, and an unknown role gets 404.
- 6.9 Phone width 390: readable.
- 6.10 Large integers display correctly.
- 6.11 PO unit_cost, /cogs and quote history are unchanged.

**S-220.7 (P2) Nexus remaining cross-check.**
- 7.1 Remaining and the difference (Likha − Nexus) are shown, with the as-of time.
- 7.2 No Nexus row → a dash.
- 7.3 Display only.
- 7.4 Shown as stale after 3 h.
- 7.5 The label explains that pre-start opening stock is in Nexus.

**S-220.8 DOI, order quantity and Ready to order.**
- 8.1 The formulas are unchanged; only the stock input differs.
- 8.2 Uses the floored stock.
- 8.3 The stale and unmatched warnings show next to I-ORDER.
- 8.4 The worklist i_order chip is identical before and after (regression).
- 8.5 Lifecycle, lead, palugit and category are unchanged.
- 8.6 The gap is pinned: a PO counted today but not yet synced is in neither incoming nor stock until the next sync.

**S-220.9 /item is not slower.**
- 9.1 A warm second call runs no query on from_jnts or the new table.
- 9.2 The returned query is one grouped query. The result reports its cold-cache time on realistic data, or explains why it can't.
- 9.3 The cache is cleared or re-keyed on sync or a source change; otherwise the TTL is 1 hour or less.
- 9.4 Concurrent cold requests are correct.
- 9.5 A failed or partial sync does not refresh the cache.

## Threat model
- Untrusted: the Nexus API response (validate types, never trust names as HTML; escape them), and the J&T data.
- Secret: NEXUS_API_TOKEN. Never log it, return it or put it in a test fixture or the result.
- Trusted: app_settings edited by the CEO.

## Done when
- The plan is in docs/plans/220-nexus-stock.md and was accepted before the build.
- Every P1 case has a passing test. The existing tests that relied on PO received for stock are updated: StockEndpointTest examples A, B and D, the received-path tests, and LifecycleStockTest. List each change.
- The full `php artisan test` adds no new failures against 86db0060 (base failures: BigBroadcast S-167.1, ExampleTest, ItemTableFitScriptTest, Astra S-26.18, the last two timing-dependent).
- docs/specs/220-nexus-stock.result.md is filled in:
  - status
  - changes per file
  - test counts on base and head
  - story changes
  - config keys
  - deploy steps: migration, the schedule needs no supervisor change, the first-run backfill command
  - a rollback
  - owner checks after release: compare /item stock for 5 busy items with the warehouse count and the Nexus remaining; look at the unmatched list
  - open risks

## Out of scope
- The Nexus repo.
- Building the Nexus RTS source beyond a stub.
- PO, price or COGS changes.
- /jnt/supply.
- Any change to the J&T sync.

## Budget
A medium-to-large job. Two attempts per failing step, then stop and report.
