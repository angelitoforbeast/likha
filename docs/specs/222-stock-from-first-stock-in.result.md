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
| Full suite, head (code at 0a325cfb) | 4,328 | 3 (ExampleTest, ItemTableFitScriptTest, Astra S-26.18) | 1 (perf, opt-in) |
| `tests/Feature/Item`, head | 355 | 1 (ItemTableFitScriptTest, base) | 1 |
| 222 and stock files | 69 | 0 | 1 |

No new failure: the three failures are the base list. Head = base + 20 tests. Most tests show the `artisan test` printer warning of a worktree, as in spec 220.

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
  `item_stock:moves:v2:{host}:{earliest matched first stock-in}:{returned source}:{last synced}:{today}`, with
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

## Deploy steps (Likha; Incepxion needs only step 1)

1. Deploy the code. **No migration.** `php artisan config:cache` if the server caches config. No supervisor change.
   **Scheduler:** one new entry, `stock:warm` every 10 minutes (Manila), next to the existing `nexus:sync-stock`
   hourly at :10 and `nexus:sync-stock --days=7` daily at 02:40; all run under the existing `schedule:run` cron.
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

## Open risks

1. **Cold /item/stock is slower** (synthetic: returned ~3 s vs 220's ~0.7 s; live estimate 2.5–4 s). With the prewarm,
   pages almost never pay it: only a request on a host other than `APP_URL`'s, the minute right after a sync before
   its warm finishes, or a lost scheduler would. The cost moves to the background (one cold computation every
   10 minutes). A real fix needs fewer candidate rows (for example a stored "first returned day" per waybill) and is
   not in this spec.
2. **History completeness**: an item stocked in before `history_from` would get a late start; the scan says Nexus began
   in September, so 2026-01-01 has margin.
3. **Late returns of parcels shipped before an item's start** add pieces that were never subtracted (the owner's choice,
   as Nexus does).
4. **Not in Nexus quantities** are computed with stock 0; they are muted and explained, but still shown.
5. **A Nexus item never ordered in Likha** has no Likha key, so it is in the unmatched list, not in Not in Nexus.
