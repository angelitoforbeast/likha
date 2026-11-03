# Plan: spec 222 stock from each item's first Nexus stock-in

Risk tier: medium (the stock number that drives ordering). Nexus mode only; PO mode (no token) is not touched. No new
table and no migration: two new `app_settings` rows and one config key.

## What Nexus does (read at e356be1, `ShopeeOrder::stock()`)

- `in` = every item lot ever stocked in (no date filter), at its Parcel Count; `first` = the Manila day of the item's
  first valid Stock In.
- `shipped` = J&T (`jnt_my_waybill`, not cancelled), per item and Manila day of `submission_time`, counted when the
  day ≥ the item's first; pieces by the "N x" prefix.
- `rts` = RTS Scanner scans (valid) per item and scan day ≥ first, whatever the ship date.
- `stock` = max(0, in + rts − shipped).

Likha mirrors this with its own data: Nexus stock-in rows (`nexus_stock_ins`), J&T `from_jnts` + `macro_output`.

## Design

### Per-item start and stock-in (read side, `ItemStockService`, Nexus mode)

One grouped query on `nexus_stock_ins` (≤ today):

```sql
SELECT item_name, MIN(CASE WHEN stock_in_pieces > 0 THEN stock_date END) AS first_day, SUM(stock_in_pieces) AS pieces
FROM nexus_stock_ins WHERE stock_date <= :today GROUP BY item_name
```

In PHP, each name goes through `ItemNameBridge` to its Likha key; per key: `stock_start` = the earliest `first_day`
over its names, `stock_in` = the sum of their pieces (all of them are on or after the start by definition). A key with
no `first_day` (no row, or shelf-only rows with 0 pieces) is **Not in Nexus**. `item_stock_start` no longer bounds the
stock-in sum in Nexus mode; it still gates `stock_ready` (no start = null fields, as in 220) and the sync window.

### Shipped since each item's start

The 220 query counted waybills whose first J&T submission is ≥ the global start. It becomes one grouped query by item
and Manila day of the first submission, with a lower bound `L` = the earliest first stock-in day of any name:

```sql
SELECT mo.ITEM_NAME AS item_name, DATE(s.first_at) AS day, COUNT(*) AS cnt
FROM macro_output mo
JOIN (SELECT fj.waybill_number, MIN(fj.submission_time) AS first_at FROM from_jnts fj
      WHERE fj.submission_time >= :L
        AND NOT EXISTS (SELECT 1 FROM from_jnts b WHERE b.waybill_number = fj.waybill_number AND b.submission_time < :L)
      GROUP BY fj.waybill_number) s ON s.waybill_number = mo.waybill
WHERE <not cancelled>
GROUP BY mo.ITEM_NAME, DATE(s.first_at)
```

PHP keeps a row when its day ≥ that item's start (× the "N x" qty, as today). A waybill first submitted before `L`
is before every start, so excluding it is exact. `DATE()` works on MySQL, sqlite and pgsql. I chose a PHP filter over
a joined per-key start table because the start is per *Likha key* after bridging (names → key happens in PHP through
`item_type_mappings`), which SQL cannot see; the query stays one grouped query and returns items × days rows
(≈ 300 × 300 at most).

### Returned since each item's start, any ship date

`JntReturnedSource` keeps its rules (latest from_jnts row per waybill, status exactly 'Returned', null and future
signingtime counted in flags, not cancelled) but **drops the "shipped after the start" conditions** and groups by item
and Manila day of `signingtime` (≥ `L`, ≤ now). PHP keeps a row when its day ≥ the item's start. That is the
decision "late returns count". `ReturnedResult` gains per-day pieces (`byDay`: key → day → pieces); the J&T source
fills it; the Nexus RTS stub stays "not available"; a future Nexus RTS source fills the same shape and gets the same
per-item filter. `stock:returned-compare` sums each item from its own start.

### Stock, clamp, Not in Nexus

- In Nexus: `stock_raw` = stock_in + returned − shipped (all from the item's start); `stock` = max(0, raw);
  `stock_needs_count` = raw < 0 (unchanged rule).
- Not in Nexus: `not_in_nexus` true, `stock_start` null, `stock`, `stock_raw`, `stock_in`, `returned`, `shipped`
  null, `stock_needs_count` false. DOI and order qty are computed with stock 0 and the item carries
  `stock_assumed_zero` true. The page shows "Not in Nexus" in the Stock cell and in Next step, and "(no Nexus
  stock-in: counted as 0)" next to the quantity. `stock_meta.nexus.not_in_nexus` lists them (name) with a count;
  the page shows it collapsed, like the unmatched list.
- New per-item field `stock_start` (Y-m-d).

### Nexus boxes label

`nexus_remaining` is shown as "Nexus boxes not yet released: N" with the title "Whole lots (boxes) still on the shelf
in Nexus, not all pieces on hand". `nexus_diff` is dropped from the response and the page (no "difference").

### History backfill (`nexus:sync-stock --history`)

- Config `services.nexus.history_from` (env `NEXUS_STOCK_HISTORY_FROM`, default `2026-01-01`).
- The end is the day before the earliest of: the first stored day, `item_stock_start`, and the last finished history
  window (`nexus_stock_history_done_from`, a new app_settings row). Windows of ≤ 31 days are asked **newest first**
  with `?from=&to=`; after each good window `history_done_from` = its first day, so a stopped run (429, 401, timeout)
  resumes where it stopped. Nothing before `history_from`. About 10 calls for Jan–Sep; Nexus allows 60 an hour.
- Each item's `stock_in` list is split into per-day rows: `stock_date` = the entry's Manila date (must lie inside the
  window, else the entry is skipped), lots and pieces from the entry (validated as today). Rows go through the same
  upsert key (day, name_key) but **without `remaining` and `as_of`** (null); a re-run updates lots and pieces only.
  The backfill never zeroes a row and never touches days on or after the normal window start.
- A malformed answer or an answer for another range: the window fails and is retried next run. Skipped entries feed the
  `skipped` warning of 220.
- Lock: the same sync lock. Hourly and daily runs are unchanged.

### Cache

The inputs key gets `v2` (the shape changes). The lower bound `L` is computed inside the cached closure. Bridging and
the per-item filter stay outside the cache (a new mapping still counts at once).

## Story changes I propose

- **S-220.4.4** ("shipped before the start: not counted") is replaced by S-222.2.1 (late returns count, by the
  owner's decision).
- **S-220.7.1** (difference) is replaced by S-222.6.1 (no difference).
- **S-220.6.2** ("days before the start are not counted"): in Nexus mode the start is per item now.
- **S-222.1.7** (the 7 consistent items): I can only make it a real check with each item's in / shipped / returned
  from the comparison. **Question:** can you send those three numbers per item (and SEAT COVER's, which the brief has)?
  Without them the case is only arithmetic on numbers I would choose, and I would mark it so.

## Tasks

| Task | Side | Tier | Cases | Files |
|---|---|---|---|---|
| T1 history backfill | backend | medium | 5.1–5.6 | `config/services.php`, `.env.example`, `app/Services/Nexus/StockInSync.php` (`history()`), `app/Console/Commands/NexusStockSync.php` (`--history`), `tests/Feature/Item/NexusStockHistoryTest.php` |
| T2 per-item start, shipped and returned per day | backend | medium | 1.1–1.7, 2.1–2.4, 4.1–4.2, 8.1 | `app/Services/ItemStockService.php`, `app/Services/Stock/{JntReturnedSource,ReturnedResult,ReturnedSource,NexusRtsReturnedSource}.php`, `app/Console/Commands/StockReturnedCompare.php`, `tests/Feature/Item/StockFromFirstStockInTest.php`; updates to `NexusStockReadTest`, `ReturnedStockTest`, `StockCacheTest` |
| T3 Not in Nexus | backend | medium | 3.1–3.4 | `ItemStockService.php`, the T2 test file |
| T4 page | frontend | medium | 3.1, 3.2, 3.4, 6.1 | `resources/views/item/index.blade.php`, `_il_expand.blade.php`, `_table_order.blade.php`, `StockSourceViewTest`, `SuppliersGroupTest` pins |
| T5 PO mode, perf, full suite, result | both | low | 7.1, 8.2 | `ReturnedQueryPerfTest` (per-item version), `docs/specs/222-…result.md`, `qa/stories.md` |

Order T1 → T2 → T3 → T4 → T5.

### Existing 220 tests that change (T2)

- `ReturnedStockTest::test_S_220_4_4_a_parcel_shipped_before_the_start_is_not_counted` → late returns count.
- `NexusStockReadTest` cases that seed stock-in only after 09-25 keep their numbers; `test_S_220_6_2_…` (days before
  the start) changes to the per-item start; `test_S_220_7_1_to_7_4_…` drops `nexus_diff`; the 6.8 test's field list
  drops `nexus_diff`. Items with shipments but no Nexus rows (examples B and D in Nexus mode, 6.3/6.5, 2.8) become
  Not in Nexus — I will add a stock-in row where the case is about the formula, and keep one case per Not-in-Nexus
  path.
- `JntReturnedSource::sql()` changes, so the EXPLAIN in the 220 result is superseded by the 222 one.

## Riskiest parts

1. **The shipped query's lower bound moves from 10-02 to the earliest first stock-in** (up to `history_from`, Jan):
   the derived first-submission table now covers most of from_jnts. Measured on the Docker MySQL in T5; if it is much
   slower than 220's ~0.8 s, the fallback is to bound `L` by the earliest *matched* first day rather than any name's.
2. **History completeness**: an item stocked in before `history_from` gets a later start and too little stock-in.
   The owner check on SEAT COVER and the Not in Nexus list catch it; `history_from` is a config value.
3. **Late returns of parcels shipped before the start** add pieces that were never subtracted (the owner's choice,
   as Nexus does).
4. **Not in Nexus items still get an order quantity** (computed with stock 0): it is marked, but a reader could still
   act on it.

## Base suite

`php artisan test` on 943a2d6c (plus the docs-only first commit), 1,396 s: **4,308 tests, 3 failed** (ExampleTest,
ItemTableFitScriptTest, Astra S-26.18 — all on the known base list), 206 passed plainly and 4,099 with the printer
warning of this worktree.
