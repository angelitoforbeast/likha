# Result: spec 222 stock from each item's first Nexus stock-in

## Status

Built and tested on `feat/222-stock-from-first-stock-in` (base 943a2d6c). Not pushed, not deployed. Every story case
passes (27 cases; the 9.2-style timing is reported below). The plan (`docs/plans/222-stock-from-first-stock-in.md`) was
accepted with these answers: the S-222.1.7 numbers from the live comparison, `history_from` stays 2026-01-01, the
cold queries are bounded by the earliest **matched** first stock-in day, and Not in Nexus items keep an order quantity
(stock 0) shown muted with the Not in Nexus tooltip. No migration.

## What changed (Nexus mode only; PO mode is untouched)

- **Per-item start.** Each Likha item starts at its first Nexus stock-in day: the earliest day with pieces > 0 over
  every Nexus name that bridges to it. Stock = all its Nexus stock-in + J&T Returned signed on/after the start (any
  ship date) − shipped with the first J&T submission on/after the start; `stock` = max(0, raw), "Count the stock first"
  when raw < 0. New per-item field `stock_start`.
- **Not in Nexus.** An item with no Nexus stock-in ever (no row, or shelf-only rows with 0 pieces): `not_in_nexus`
  true, stock / raw / breakdown null, no "Count the stock first"; DOI and order qty computed with stock 0
  (`stock_assumed_zero`). The page shows "Not in Nexus" in the Stock cell and Next step, mutes the Qty to order cell
  with the tooltip, and lists the items (collapsed, with a count) above the To order table.
- **Late returns.** The J&T returned query no longer requires the waybill to have shipped after the start; it groups by
  item and Manila day of signingtime and /item keeps the days on/after each item's start.
- **Nexus boxes.** "Nexus boxes not yet released: N" with the tooltip "Whole lots (boxes) still on the shelf in Nexus,
  not yet released to the floor: not all pieces on hand". `nexus_diff` is gone from the response and the page.
- **History backfill.** `nexus:sync-stock --history` from `services.nexus.history_from` (default 2026-01-01).

## Changes per file

| File | Change |
|---|---|
| `config/services.php`, `.env.example` | `services.nexus.history_from` (env `NEXUS_STOCK_HISTORY_FROM`, default 2026-01-01). |
| `app/Services/Nexus/StockInSync.php` | `history()`: ranges of ≤ 31 days, newest first, from the day before `item_stock_start` (or before the last finished range, `nexus_stock_history_done_from`) back to `history_from`; each item's `stock_in` list split into per-day rows (Manila date inside the range), upsert of lots and pieces only (no remaining, no as_of), never zeroing; stops at the first failed range (429, 401, timeout, malformed) and resumes there. `historyFrom()`. |
| `app/Console/Commands/NexusStockSync.php` | `--history`. |
| `app/Services/ItemStockService.php` | Per-item start, Not in Nexus, the per-day shipped query (`shippedSql()`), the two cached inputs (`item_stock:stockin:v2:…` and `item_stock:moves:v2:…:<lower bound>:…`, 15 min, one computation per key under a lock), Nexus names as a display fallback, `stock_meta.nexus.not_in_nexus` and `not_in_nexus_count`, `nexus_diff` removed. |
| `app/Services/Stock/JntReturnedSource.php` | Returned per item and day of signingtime from the lower bound, without the shipped-after-start conditions. |
| `app/Services/Stock/ReturnedResult.php` | `byDay` (key → day → pieces) and `since()`; a source without days counts all its pieces. |
| `resources/views/item/index.blade.php` | `ilNotInNexus`, `ilNotInNexusTip`, Next step "⚪ Not in Nexus", the Not in Nexus list, the boxes label and tooltip, "from <start>: stock-in … " in the breakdown, `.il-muted`. |
| `resources/views/item/_table_order.blade.php` | Qty to order muted with the tooltip and "Not in Nexus: counted with stock 0". |
| `resources/views/item/_il_expand.blade.php` | "Not in Nexus" in the Stock / Incoming line. |
| `tests/Feature/Item/StockFromFirstStockInTest.php`, `NexusStockHistoryTest.php` | New cases. |
| `tests/Feature/Item/NexusStockReadTest.php`, `ReturnedStockTest.php`, `StockCacheTest.php`, `StockSourceViewTest.php` | Updated for the per-item start (below). |
| `tests/Feature/Item/ReturnedQueryPerfTest.php` | Per-item measurement (lower bound 09-13) next to the spec 220 queries on the same data. |
| `tests/Feature/Item/SuppliersGroupTest.php` | Page pins re-pinned for the new view strings. |
| `app/Console/Commands/StockWarm.php`, `routes/console.php`, `ItemStockService::warmInputs()`, `NexusStockSync` | The prewarm (below): `stock:warm` every 10 minutes and after each successful sync. `tests/Feature/Item/StockWarmTest.php`. |
| `resources/views/item/_agg_cells.blade.php`, `JntReturnedSource::flagsSql()/flags()`, `ItemStockService::moves()/closedDays()/returnedFlags()`, `StockWarm --closed-days`, `StockReturnedCompare` | The review fixes (section "Review fixes"). `tests/Feature/Item/StockDayCacheTest.php`. |
| `qa/stories.md`, `docs/plans/…`, `docs/specs/…` | Slice 222, the plan, this result. |

### 220 tests that changed

- `NexusStockReadTest`: fixture stock-ins moved to the shipping day (09-26) where the case is about the formula, so
  shipments count under the per-item start; items with shipments but no Nexus rows now assert Not in Nexus (examples
  B, 6.5, 6.6 pot, 6.7, 6.12 rope, 2.8, 3.1 rope, 3.2/3.4 before the mapping, 8.6); example D, 4.1, 5.2, 5.3, 5.6
  and 8.1 got a 1-piece stock-in so they keep testing the formula (D −24 instead of −25, 4.1 raw 1 instead of 0,
  5.2 raw 4 instead of 3, 8.1 rope −24); 6.2 became per-item (111 from 09-24); 6.4 and 6.12 field lists swap
  `nexus_diff` for `stock_start`/`not_in_nexus`; 7.1 asserts no `nexus_diff`.
- `ReturnedStockTest::test_S_220_4_4_…` replaced by `test_S_222_2_1_and_2_2_…` (late returns count).
- `StockCacheTest`: the stock-in moved to 09-26; the lock test uses the new stock-in key.
- `StockSourceViewTest`: the 7.x strings follow the new label; two new 222 view tests.

## Test counts

| Run | Tests | Failed | Skipped |
|---|---|---|---|
| Full suite, base 943a2d6c | 4,308 | 3 (ExampleTest, ItemTableFitScriptTest, Astra S-26.18) | 0 |
| Full suite, head (code at 9c0a5a60, with the prewarm) | 4,333 | 3 (ExampleTest, ItemTableFitScriptTest, Astra S-26.18) | 1 (perf, opt-in) |
| Full suite, head after the review fixes (code at 909084dc) | 4,340 | 3 (ExampleTest, ItemTableFitScriptTest, Chatblast BigBroadcastTest S-167.1; Astra S-26.18 passed this run) | 1 (perf, opt-in) |
| `tests/Feature/Item`, head after the release changes | 375 | 1 (ItemTableFitScriptTest, base) | 1 |
| 222 and stock files (`tests/Feature/Item/*Stock*`, `*Returned*`), after the release changes | 174 | 0 | 1 |
| Schedule tests in other folders (Botcake, Chatblast, Jnt, MetaAds, NightRun; `routes/console.php` changed) | 136 | 0 | 0 |

No code-caused failure. BigBroadcastTest S-167.1 (4,940 of 5,000 pending, a timing case in Chatblast, which this branch does not touch) passes on its own: `BigBroadcastTest.php` 10 of 10 OK right after the suite. Head = base + 32 tests. Most tests show the `artisan test` printer warning of a worktree, as in spec 220.

## Story changes (accepted with the plan)

- S-220.4.4 ("shipped before the start: not counted") → S-222.2.1 (late returns count).
- S-220.7.1 (the difference) → S-222.6.1 (no difference).
- S-220.6.2 made per-item (stock-in counts from the item's first day, also before item_stock_start).
- S-222.1.7 uses the live comparison numbers (below).
- Added during the build: S-222.1.2 also pins a waybill first submitted before the start with a newer row after it;
  S-222.3.2 includes the muted cell and its tooltip; S-222.8.1 pins the matched lower bound.

## S-222.1.7 fixture source

From a read-only comparison on live Likha data, 2026-11-03 (In = all stock-in; Ship = first submission on/after the
start; Ret = J&T Returned signed on/after the start, whatever the ship date):

| Item | In | Start | Ship | Ret | Stock |
|---|---|---|---|---|---|
| glow tape | 3016 | 10-09 | 5239 | 2434 | 211 |
| christmas lights | 7000 | 10-24 | 3876 | 1002 | 4126 |
| reflective sticker | 2090 | 10-06 | 3264 | 1467 | 293 |
| rgb strobe light | 3009 | 10-15 | 3197 | 559 | 371 |
| vintage curtain | 2046 | 10-09 | 2234 | 1211 | 1023 |
| invisible belt | 2000 | 10-06 | 2166 | 862 | 696 |
| waterproof phone case | 1826 | 10-08 | 2136 | 1288 | 978 |
| seat cover | 10000 | 09-29 | 8105 | 4613 | 6508 |

The fixture builds these from parcels ("100 x", "10 x", "1 x" variants), with returns shipped before the start, plus
shipments and returns before the start that must not count. All eight come out exactly.

## Measurement (S-222.8.2)

Throwaway Docker `mysql:8.4` (127.0.0.1:33222, generated password in the scratch folder only, container removed).
Same synthetic volume as spec 220 (947,600 from_jnts, 1,036,454 macro_output, production indexes), 42 Nexus items
with first stock-ins from 09-13 (one unmatched Nexus name from 01-15 that must not move the bound). Lower bound =
earliest matched first stock-in = 09-13. Medians of 5; this laptop was slower than during spec 220 (the PO path that
is unchanged measured 2.9–4.8 s now vs 2.4–3.0 s then), so compare within one run:

| Query / call | Spec 222 | Spec 220, same data, same run |
|---|---|---|
| Returned (per item and day, from 09-13) | 2,862–4,121 ms (four runs) | 435–852 ms (four runs) |
| Shipped (per item and first-submission day, from 09-13) | 1,627–1,837 ms (after the rewrite below; 2,130–2,474 ms before) | 1,083–1,167 ms |
| /item/stock cold (cache flushed) | 6,834–7,484 ms | — (PO path, no cache, same runs: 2,865–3,291 ms) |
| /item/stock warm | 1,893–2,074 ms | — |

Live, the spec 220 returned query took 835 ms; by the same ratio the 222 returned query would take about 2.5–4 s cold
on Likha, once per 15 minutes per key (warm calls run neither query).

Why it is slower: it counts every return signed since 09-13 instead of since 10-02 and no longer drops waybills shipped
before the start (48,015 candidate rows vs about 20,000). EXPLAIN ANALYZE of the returned query: the status index
returns 234,765 Returned rows in 0.48 s, the signingtime filter keeps 48,015, the latest-row anti-join takes 0.9 s and
the macro_output join 2.2 s (one index lookup per row). Tried and dropped: splitting off the no-signingtime count (2.7 s,
and two queries), and a `(status, signingtime)` index (the optimizer kept the status index because of the
`signingtime IS NULL OR …`). The shipped query was rewritten to find each waybill's first J&T row with one anti-join
instead of grouping all of from_jnts (same 28,253 item×day rows, about 25 % faster).

EXPLAIN (MySQL 8.4) of the final statements:

```
returned: 1 fj ref idx_from_jnts_status rows=383502 filtered=78.8 Using where; Using temporary
          1 n  ref from_jnts_waybill_number_index rows=1 Not exists; Using index
          1 mo ref macro_output_waybill_index rows=1
shipped:  1 fj ALL rows=944128 filtered=36.9 Using where; Using temporary
          1 b  ref from_jnts_waybill_number_index rows=1 Not exists
          1 mo ref macro_output_waybill_index rows=1
```

The exact statements, for a read-only `EXPLAIN` on the server (lower bound 2026-09-13; replace the "now" literal):

```sql
EXPLAIN
SELECT mo.`ITEM_NAME` AS item_name, DATE(r.signingtime) AS day,
       SUM(CASE WHEN r.signingtime IS NOT NULL AND r.signingtime <= '2026-11-03 10:10:00' THEN 1 ELSE 0 END) AS returned_cnt,
       SUM(CASE WHEN r.signingtime IS NULL THEN 1 ELSE 0 END) AS unsigned_cnt,
       SUM(CASE WHEN r.signingtime > '2026-11-03 10:10:00' THEN 1 ELSE 0 END) AS future_cnt
FROM macro_output mo
JOIN (SELECT fj.waybill_number, fj.signingtime FROM from_jnts fj
      WHERE fj.status = 'Returned' AND (fj.signingtime IS NULL OR fj.signingtime >= '2026-09-13 00:00:00')
        AND NOT EXISTS (SELECT 1 FROM from_jnts n WHERE n.waybill_number = fj.waybill_number AND n.id > fj.id)) r
  ON r.waybill_number = mo.waybill
WHERE (mo.`STATUS` IS NULL OR LOWER(REPLACE(REPLACE(TRIM(mo.`STATUS`), ' ', ''), '_', '')) NOT IN ('cannotproceed', 'odz'))
GROUP BY mo.`ITEM_NAME`, DATE(r.signingtime);

EXPLAIN
SELECT mo.`ITEM_NAME` AS item_name, DATE(f.submission_time) AS day, COUNT(*) AS cnt
FROM macro_output mo
JOIN (SELECT fj.waybill_number, fj.submission_time FROM from_jnts fj
      WHERE fj.submission_time >= '2026-09-13 00:00:00'
        AND NOT EXISTS (SELECT 1 FROM from_jnts b WHERE b.waybill_number = fj.waybill_number
                          AND (b.submission_time < fj.submission_time OR (b.submission_time = fj.submission_time AND b.id < fj.id)))) f
  ON f.waybill_number = mo.waybill
WHERE (mo.`STATUS` IS NULL OR LOWER(REPLACE(REPLACE(TRIM(mo.`STATUS`), ' ', ''), '_', '')) NOT IN ('cannotproceed', 'odz'))
GROUP BY mo.`ITEM_NAME`, DATE(f.submission_time);
```

## Config keys

| Key | Where | Default | Note |
|---|---|---|---|
| `NEXUS_STOCK_HISTORY_FROM` → `services.nexus.history_from` | env | 2026-01-01 | Earliest day `--history` asks. A Jan–Nov API scan found nothing before mini flashlight (09-13). |
| `nexus_stock_history_done_from` | app_settings | — | First day of the last finished history range; `--history` continues before it. |

All slice 220 keys are unchanged. `APP_URL` matters for the prewarm: its host is the host part of the warmed key.

## Prewarm (added after the build)

The slower cold load is taken off the page: the stock inputs are computed in the background.

- `stock:warm` (new command), scheduled every 10 minutes in Manila, `withoutOverlapping(10)`, `runInBackground`. It
  recomputes and stores the inputs even when they are still cached, so the 15-minute TTL never lapses.
- At the end of every successful `nexus:sync-stock` run (hourly, the daily pass, `--from`, `--history`,
  `--accept-empty`) the same warm runs at once, because the key changes with `nexus_stock_last_synced_at` (and history
  or an accepted day changes the data). A failed warm never fails the sync.
- **The warmed key** is the one the page uses: `item_stock:stockin:v2:{host}:{last synced, YmdHis}:{today}` and
  (since the review fixes below) the live key `item_stock:live:v3:…` plus any missing closed-day keys
  `item_stock:day:v1:…` (this replaced `item_stock:moves:v2:…`), with
  `{host}` = the host of `APP_URL`. The inputs do not depend on the page's start or end date, so every /item/stock call
  on that host uses this one key; a request on another host name (for example `www.` when `APP_URL` has none) would
  still compute its own.
- The warm uses the page's stampede lock: if a page request is already computing a key, the warm waits up to
  `cold_wait` and then leaves it (reported as "busy"); it never computes alongside it. A warm that fails (an exception
  while computing) changes nothing in the cache, so the old value stays until its TTL.
- Tests (`StockWarmTest`): after a successful sync the next page call runs no shipped, returned or nexus_stock_ins
  query; a warm at 10:20 keeps a key made at 10:10 alive at 10:33 with the new numbers; a failed warm (from_jnts
  missing) exits 1 and the page still gets the old value with no stock query; a warm while a page holds the lock
  reports "busy" and does not replace the value; the schedule entry; no Nexus = nothing warmed.

## Review fixes (after the live EXPLAIN)

Live Likha (MySQL 8.0.46), before these fixes: returned 11.4 s cold / 1.66 s warm (idx_from_jnts_status, 405,323
rows, signingtime kept 43,373); shipped 2.0–2.2 s (range on idx_from_jnts_submission_time, 92k rows); cold
/item/stock about 14 s.

| # | Fix | Test |
|---|---|---|
| 1 (B1) | `_agg_cells.blade.php` (Suppliers tab and the old table) knows Not in Nexus: the Stock cell shows the `ilNoStock()` text instead of `num(null)` = "0"; DOI, order-by and Qty to order are muted with the tooltip; the qty cell gets "Not in Nexus: stock 0 ang gamit". | `StockSourceViewTest::test_B1_the_suppliers_tab_shows_not_in_nexus_and_mutes_doi_order_by_and_qty` (renders the layout with `_table_suppliers`) |
| 2 | The returned main query is now a plain `fj.status = ? AND fj.signingtime >= ? AND fj.signingtime <= ?` (no `IS NULL OR`). The unsigned and future counts are a separate query, `JntReturnedSource::flagsSql()` (two COUNTs). **Index case:** `idx_from_jnts_signing_status (signingtime, status)` is already in the repo, in `2026_01_23_221803_alter_from_jnts_time_to_datetime_and_add_indexes.php`, so no migration was added. | `StockDayCacheTest::test_the_returned_main_query_is_a_plain_signingtime_range_and_the_flags_are_a_separate_global_count`; `StockCacheTest` (returned queries have no `signingtime is null`); `ReturnedStockTest` (flags via `flags()`) |
| 3 | Per-day cache for closed days (before yesterday, Manila), 26 h TTL (93,600 s), per day per source: `item_stock:day:v1:{host}:shipped:{day}` (by first-submission day) and `item_stock:day:v1:{host}:returned:{source}:{day}` (by signing day). Missing closed days are computed with one range query per source and stored together, under the lock `item_stock:day:v1:{host}:{source}:lock`. Only yesterday and today are live, in `item_stock:live:v3:{host}:{live from}:{source}:{last synced}:{today}` (15 min). `stock:warm --closed-days=7`, daily at 02:55 on its own schedule (see "Release changes"), recomputes the last 7 closed days. `stock:warm` moved off :10 to `5-59/10` (:05, :15, …), so it doesn't collide with the hourly sync. | `StockDayCacheTest`: closed days from the 26 h cache with only yesterday/today live (shipped query bound 11-02 00:00 to 11-03 23:59); the nightly closed-day warm refreshes 10-30 but not 10-15, even after a failed Nexus pass; a regular warm runs only the two live queries; `StockWarmTest` (schedule `5-59/10 * * * *`) |
| 4 | When no Nexus name matches at all, items show "Nexus not synced yet" (never synced) or "No Nexus name matches" (synced, nothing bridged), not "Not in Nexus". Per item `stock_unknown` = `not_synced` / `no_matches`; `stock_meta.nexus.match_state` = `ok` / `not_synced` / `no_matches`; `not_in_nexus` only when `ok`. | `NexusStockReadTest` (B, 6.5, 2.8, 3.2/3.4, 8.6 assert `stock_unknown`); `StockSourceViewTest::test_no_matches_at_all_says_not_synced_or_no_matches_…` |
| 5 | The Not in Nexus tooltip, when "Nexus boxes not yet released: N" shows too, adds: "Nexus still shows boxes of it: they were stocked in before the history start, or a lot was never scanned at Stock In." | same view test (string and the `S.nexus_remaining != null` condition) |
| 6 | The unsigned and future flags stay global and are labelled so: `stock_meta.returned.flags_scope` = "all items, any date (not per item start)", and `stock:returned-compare` prints "(all items)". Cached 1 h in `item_stock:flags:v1:{host}:{source}:{today}`; the warm refreshes it once it is older than 45 min. | `StockDayCacheTest::…flags_are_a_separate_global_count` (a pre-start return counts) and `…flags_count_is_cached_for_an_hour_and_the_warm_refreshes_it_only_after_45_minutes` |

**Accepted staleness.** A closed day is not recomputed until its 26 h TTL ends, apart from the nightly refresh of the
last 7 closed days. So when an old waybill changes later (a status flips to Returned, or a signingtime gets filled in
for a day before yesterday), it shows up within one day: at the next 02:55 refresh for days in the last week, or when
that day's key expires for older days. Today and yesterday are always live.

**Docker timings with the index present** (same throwaway `mysql:8.4`, same 947,600 / 1,036,454 rows; medians of 5,
run 7):

| Query / call | Now | Before this round (same harness) |
|---|---|---|
| Returned, full range from 09-13 (plain range) | 655.5 ms (spec 220's query, same run: 848.2) | 2,862–4,121 ms |
| Returned, live part only (11-02..now) | 30.3 ms | — |
| Shipped, from 09-13 (with the upper bound) | 2,048.9 ms (spec 220's, same run: 1,382.5) | 1,627–1,837 ms |
| Shipped, live part only (11-02..11-03) | 83.3 ms | — |
| Flags (two global COUNTs) | 2,537.7 ms, at most once an hour | (inside the returned query) |
| /item/stock cold (cache flushed: every closed day, flags, stock-in) | 10,173.8 ms | 6,834–7,484 ms |
| /item/stock warm (inputs cached) | 3,510.1 ms (the PO path, no cache, same run: 3,702.3) | 1,893–2,074 ms |
| `stock:warm`, closed days cached | 3,499.5 ms | (was a full cold build every 10 min) |
| Nightly warm (last 7 closed days recomputed) | 3,319.3 ms | — |

This laptop was slow in this run (the unchanged PO path is 3.7 s vs 2.9 s before), so compare within a column. Cold
is slower than before because it now also pays the flags query and fills every closed day; it happens once per 26 h
per day, not every 10 minutes. The regular warm runs only the two live queries (about 110 ms of SQL); the rest of its
time is the /item build itself. On Docker the optimizer still picks `idx_from_jnts_status` for the plain range
(filtered 25 %); live MySQL 8.0.46 already range-scans `idx_from_jnts_submission_time` for shipped and should do the
same with `idx_from_jnts_signing_status` for returned, which needs the EXPLAIN below to confirm.

EXPLAIN (Docker MySQL 8.4), final statements:

```
returned: 1 fj ref idx_from_jnts_status rows=383502 filtered=25.3 Using where; Using temporary
          1 n  ref from_jnts_waybill_number_index rows=1 Not exists; Using index
          1 mo ref macro_output_waybill_index rows=1
flags:    2 fj ref   idx_from_jnts_signing_status rows=4600  (unsigned)
          4 fj range idx_from_jnts_signingtime    rows=24704 (future)
shipped:  1 fj ALL rows=944128 filtered=36.9 Using where; Using temporary
          1 b  ref from_jnts_waybill_number_index rows=1 Not exists
          1 mo ref macro_output_waybill_index rows=1
```

For a read-only `EXPLAIN` on Likha (replace the dates; the cold query runs once per missing closed-day range):

```sql
EXPLAIN
SELECT mo.`ITEM_NAME` AS item_name, DATE(fj.signingtime) AS day, COUNT(*) AS returned_cnt
FROM from_jnts fj JOIN macro_output mo ON mo.waybill = fj.waybill_number
WHERE fj.status = 'Returned' AND fj.signingtime >= '2026-09-13 00:00:00' AND fj.signingtime <= '2026-11-01 23:59:59'
  AND NOT EXISTS (SELECT 1 FROM from_jnts n WHERE n.waybill_number = fj.waybill_number AND n.id > fj.id)
  AND (mo.`STATUS` IS NULL OR LOWER(REPLACE(REPLACE(TRIM(mo.`STATUS`), ' ', ''), '_', '')) NOT IN ('cannotproceed', 'odz'))
GROUP BY mo.`ITEM_NAME`, DATE(fj.signingtime);

EXPLAIN
SELECT
 (SELECT COUNT(*) FROM from_jnts fj JOIN macro_output mo ON mo.waybill = fj.waybill_number
   WHERE fj.signingtime IS NULL AND fj.status = 'Returned'
     AND NOT EXISTS (SELECT 1 FROM from_jnts n WHERE n.waybill_number = fj.waybill_number AND n.id > fj.id)
     AND (mo.`STATUS` IS NULL OR LOWER(REPLACE(REPLACE(TRIM(mo.`STATUS`), ' ', ''), '_', '')) NOT IN ('cannotproceed', 'odz'))) AS unsigned_cnt,
 (SELECT COUNT(*) FROM from_jnts fj JOIN macro_output mo ON mo.waybill = fj.waybill_number
   WHERE fj.signingtime > '2026-11-03 10:10:00' AND fj.status = 'Returned'
     AND NOT EXISTS (SELECT 1 FROM from_jnts n WHERE n.waybill_number = fj.waybill_number AND n.id > fj.id)
     AND (mo.`STATUS` IS NULL OR LOWER(REPLACE(REPLACE(TRIM(mo.`STATUS`), ' ', ''), '_', '')) NOT IN ('cannotproceed', 'odz'))) AS future_cnt;

EXPLAIN
SELECT mo.`ITEM_NAME` AS item_name, DATE(f.submission_time) AS day, COUNT(*) AS cnt
FROM macro_output mo
JOIN (SELECT fj.waybill_number, fj.submission_time FROM from_jnts fj
      WHERE fj.submission_time >= '2026-09-13 00:00:00' AND fj.submission_time <= '2026-11-01 23:59:59'
        AND NOT EXISTS (SELECT 1 FROM from_jnts b WHERE b.waybill_number = fj.waybill_number
                          AND (b.submission_time < fj.submission_time OR (b.submission_time = fj.submission_time AND b.id < fj.id)))) f
  ON f.waybill_number = mo.waybill
WHERE (mo.`STATUS` IS NULL OR LOWER(REPLACE(REPLACE(TRIM(mo.`STATUS`), ' ', ''), '_', '')) NOT IN ('cannotproceed', 'odz'))
GROUP BY mo.`ITEM_NAME`, DATE(f.submission_time);
```

## Release changes (after the re-review)

Live Likha (MySQL 8.0.46), from the coordinator's EXPLAIN: the closed-range returned query still picked
idx_from_jnts_status (405,323 rows read; 13.5 s cold, 1.77 s warm). With `FORCE INDEX (idx_from_jnts_signing_status)`
it is a range over 41,888 rows in 0.72–0.78 s, cold or warm, with identical results (1,555 rows). The live-window
returned query already uses idx_from_jnts_signingtime (29 ms); shipped is 1.3–1.4 s closed and 21 ms live; flags < 1 ms.

| # | Change | Test |
|---|---|---|
| 1 | The nightly closed-day refresh has its own schedule, `stock:warm --closed-days=7` daily at 02:55 Manila, `withoutOverlapping(30)`, `runInBackground`. `nexus:sync-stock --days=7` no longer refreshes closed days, so a Nexus failure never skips the J&T refresh. The warm after every successful sync is unchanged. | `StockWarmTest::test_the_closed_day_refresh_has_its_own_daily_schedule_at_0255_separate_from_the_nexus_pass`; `StockDayCacheTest::test_the_nightly_closed_day_warm_refreshes_the_last_7_closed_days_even_when_nexus_fails` (Nexus answers 500, the sync exits 1, the 02:55 warm still picks up 10-30) and `…the_nexus_daily_pass_no_longer_refreshes_closed_days_but_still_warms_after_success` |
| 2 | Suppliers tab: a Not in Nexus item's DOI shows "—" (grey, muted, with the Not in Nexus tooltip) instead of "kulang N araw". | `StockSourceViewTest::test_B1_…` (the DOI `x-text` is `ilNoStock(row.item_name) ? '—' : doiText(…)`) |
| 3 | The closed-range returned query (`JntReturnedSource::returnedClosed()`, used only by the closed-day pass) adds `FORCE INDEX (idx_from_jnts_signing_status)` on `from_jnts fj`, only when the driver is mysql **and** the index exists. The check is one `information_schema.statistics` query, cached 24 h in `item_stock:idx:v1:from_jnts:idx_from_jnts_signing_status` (1 or 0); if information_schema cannot be read the answer is "no" and nothing errors. sqlite and hosts without the index run the query unchanged. The live window and the flags keep their plans. | `StockIndexHintTest`: hint when the check says yes (and the check runs once per 24 h); no hint when it says no; an unreadable information_schema = no hint, no error; sqlite = no hint and no information_schema query; closed-range results equal the plain query; `/item/stock`'s closed-day pass goes through `returnedClosed()`. On MySQL: `ReturnedQueryPerfTest` asserts the hinted and plain rows are identical. |

**Docker (`mysql:8.4`, same 947,600 / 1,036,454 rows, index present; medians of 5, run 8):** closed-range returned
09-13..11-01 **1,225.5 ms plain → 426.9 ms with FORCE INDEX**, identical rows (6,917 item × day); EXPLAIN with the hint:
`fj range idx_from_jnts_signing_status rows=214650`. Same run: shipped 1,804.5 ms; flags 2,257.4 ms; live returned
26.5 ms, live shipped 58.2 ms; cold /item/stock 7,350.9 ms; warm 2,200 ms; `stock:warm` with closed days cached
2,541.1 ms; nightly closed-day warm 3,574.6 ms; the unchanged PO path 4,082.4 ms.

The exact hinted statement (for a read-only `EXPLAIN` on Likha; replace the dates):

```sql
EXPLAIN
SELECT mo.`ITEM_NAME` AS item_name, DATE(fj.signingtime) AS day, COUNT(*) AS returned_cnt
FROM from_jnts fj FORCE INDEX (idx_from_jnts_signing_status)
JOIN macro_output mo ON mo.waybill = fj.waybill_number
WHERE fj.status = 'Returned' AND fj.signingtime >= '2026-09-13 00:00:00' AND fj.signingtime <= '2026-11-01 23:59:59'
  AND NOT EXISTS (SELECT 1 FROM from_jnts n WHERE n.waybill_number = fj.waybill_number AND n.id > fj.id)
  AND (mo.`STATUS` IS NULL OR LOWER(REPLACE(REPLACE(TRIM(mo.`STATUS`), ' ', ''), '_', '')) NOT IN ('cannotproceed', 'odz'))
GROUP BY mo.`ITEM_NAME`, DATE(fj.signingtime);
```

## Deploy steps (Likha; Incepxion needs only step 1)

1. Deploy the code. **No migration** (`idx_from_jnts_signing_status` is already applied on prod and in the repo).
   `php artisan config:cache` if the server caches config. No supervisor change.
   **Scheduler:** one new entry, `stock:warm` every 10 minutes from :05 (`5-59/10`, Manila), next to the existing
   `nexus:sync-stock` hourly at :10 and `nexus:sync-stock --days=7` daily at 02:40; and a second new entry,
   `stock:warm --closed-days=7` daily at 02:55 (Manila, `withoutOverlapping`), which refreshes the last 7 closed days
   of the stock cache on its own, so a Nexus failure never skips it; all run under the existing `schedule:run` cron. After deploy the first page or
   warm fills every closed day once (about 10 s on Docker); run `php artisan stock:warm` by hand right after deploy
   so no page pays it.
   Check that `APP_URL` is the host the owner opens /item on (e.g. `https://likhaaitech.com`).
2. One-time backfill, by hand: `php artisan nexus:sync-stock --history`. About 9 range calls (2026-01-01 to 10-01),
   newest first, a line per range; Nexus allows 60 calls an hour. If it prints "Not complete", run it again later; it
   continues from the last finished range. When done it prints "Nexus history complete", then "Warmed the /item stock
   inputs …".
3. Open /item and do the owner checks. `php artisan stock:warm` by hand shows the warmed keys and how long it took.

## Rollback

Redeploy 943a2d6c. The history rows stay in `nexus_stock_ins` (days before 10-02) and are harmless to the 220 code,
which only sums stock-in from `item_stock_start`; `nexus_stock_history_done_from` can stay.

## Owner checks after release

1. SEAT COVER shows about 6,500 (6,508 in the comparison), with "from 2026-09-29" in its details.
2. Open "N items Not in Nexus" above the To order table: the comparison expected high quality ballpen, rubber coating
   spray, bed sheet strap, flexible clip phone holder and minimalist wallet. Their quantity to order is muted.
3. Spot-check the 7 matching items (glow tape 211, christmas lights 4,126, reflective sticker 293, rgb strobe 371,
   vintage curtain 1,023, invisible belt 696, waterproof case 978); they move with sales since the comparison.
4. "Nexus N names not matched" is still the list of Nexus names that add nothing.
5. Run `php artisan stock:warm --closed-days=7` by hand and look at its duration ("Warmed the /item stock inputs in
   N ms"). With the index hint it should be a few seconds; much longer means the hint is not in use (check
   `item_stock:idx:v1:from_jnts:idx_from_jnts_signing_status`, or run the hinted EXPLAIN below).

## Open risks

1. **Cold /item/stock is still slow** (Docker 10.2 s; live was about 14 s before the per-day cache). It is now paid
   once: closed days stay 26 h, the 10-minute warm only queries yesterday and today, and the flags once an hour. A page
   pays it only right after deploy (before the first warm), after a cache flush, on a host other than `APP_URL`'s, or
   if the scheduler stops. Live does not pick `idx_from_jnts_signing_status` on its own, so the closed range now forces
   it ("Release changes"); if the index is ever dropped, the check falls back to the plain query within 24 h.
5. **Late status changes on closed days** show up within one day (see "Accepted staleness").
2. **History completeness**: an item stocked in before `history_from` would get a late start; the scan says Nexus began
   in September, so 2026-01-01 has margin.
3. **Late returns of parcels shipped before an item's start** add pieces that were never subtracted (the owner's choice,
   as Nexus does).
4. **Not in Nexus quantities** are computed with stock 0; they are muted and explained, but still shown.
5. **A Nexus item never ordered in Likha** has no Likha key, so it is in the unmatched list, not in Not in Nexus.
