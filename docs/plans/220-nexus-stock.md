# Plan: spec 220 stock on /item = Nexus stock-in + returned − shipped

Risk tier: medium (business numbers that drive ordering; no customer messaging). Threat model as in the spec:
the Nexus API response and the J&T data are untrusted, `NEXUS_API_TOKEN` is a secret, `app_settings` edited by
the CEO is trusted. One new table, three settings rows written by the sync, one switch row written only by the
owner. No change to the J&T sync, POs, prices, COGS or `/jnt/supply`.

## Open questions for the reviewer (answer before the build)

1. **The range call cannot fill a per-day table.** The documented response is
   `{as_of, from, to, items:[{item, stock_in, stock_in_pieces, remaining}]}`: one total per item over the whole
   range, no date per item. A table "per day and item" can only be filled from `?date=` calls. Plan: one
   `?date=` request per day. The hourly run asks for yesterday and today (2 requests). The first run asks for
   every day from the start (33 requests as of 2026-11-03), one at a time, each "window" one day (≤ 31).
   S-220.1.2 then reads "one request per day from the start (33 as of 11-03); the stored per-day pieces summed
   over the period equal what Nexus returned for each day". If the Nexus team can add a `date` per item to the
   range response, the backfill can drop to 2 requests later without a schema change. **Confirm, or tell me the
   range response is per day.**
2. **A stored item missing from a later, valid, non-empty answer for the same day.** Plan: the day's answer is
   the truth for that day ("today's rows are overwritten"), so that row is set to 0 (not deleted). That keeps a
   name corrected or a wrong entry removed in Nexus from being counted twice. An empty or invalid answer never
   touches a stored day (S-220.2.4). The alternative is to keep the old row (never zero); that double counts on a
   Nexus rename. **Zero or keep?**
3. **Returned source "nexus" while it is unavailable (S-220.5.4).** Plan: stock = stock-in − shipped, every
   item's `returned` is `null`, and a warning says returned is not available, so stock is shown lower than real
   rather than hidden or zeroed. **OK?**

## Story changes proposed (listed again in the result)

- **S-220.1.2**: one request per day (question 1), not 2 range requests.
- **S-220.2.4**: "200 with empty items" is a normal day when nothing came in and the day has no stored rows
  (Sundays, early morning): it counts as success. It is a warning (no change, last-synced does not advance) only
  for a day that already has stored rows, or when the JSON is malformed. Otherwise a quiet day would trip the
  3-hour warning every morning.
- **S-220.9.1**: the existing live HOLD query (`HoldService::liveHoldQuery`) left-joins `from_jnts` on every
  call today and must stay live. The warm-call case becomes "runs no stock query: neither the shipped nor the
  returned query on `from_jnts` nor any query on the new table; the HOLD query runs as before".
- **S-220.6.3 / 2.7 wording**: the new /item view is English (pinned by `ItemPageTest`), so "bilangin" shows as
  the existing "Count the stock first" and "not synced yet" stays English. The old table partial is pinned byte
  for byte to its base commit and is not touched.
- **S-220.4.7 decided**: the status of a waybill is the status of its from_jnts row with the highest `id`. The
  J&T track sync (`SyncFromJntStatusByTrack`) keeps the last row per waybill in its map, so that is the row it
  updates.
- **S-220.6.2**: "the last day" is today in Manila. Stock never depended on the page's end date and still does
  not (Nexus rows dated after today are ignored).
- **Done-when, existing tests**: examples B and D need no change (no PO received in either, and the formula gives
  the same numbers with no Nexus rows); I add the new fields to their assertions. Example A and the
  received-path tests change (list under T3).

## Design

### Formula (ItemStockService::build)

```
stock_raw = stock_in (Nexus pieces, stock_date in [start, today]) + returned (source) − shipped (as today)
stock     = max(0, stock_raw); stock_needs_count = stock_raw < 0     (unchanged)
```

`receivedByKey()` is removed from the stock number (the method goes; `received_qty` is still stored and still
used by `incomingByKey()`, unchanged). `restockSet()`, DOI, order qty, lifecycle, lead, palugit, category and
values are untouched: only the `stock` input differs (S-220.8.1, 8.2, 8.5). Matched Nexus keys join the item key
list the way received keys did, so an item with stock but no sale in the range still gets a row.

### Table `nexus_stock_ins` (the only new table)

| column | type | note |
|---|---|---|
| id | bigIncrements | |
| stock_date | date | Manila day the request was for |
| name_key | char(40) | sha1 of the normalized name: NFC (intl is present), trim, collapse whitespace, lowercase. Collation-proof unique key (MySQL `utf8mb4_unicode_ci` would merge "café" and "cafe") |
| item_name | string(255) | raw Nexus text, stored intact (latest seen) |
| stock_in_lots | unsignedInteger, default 0 | `stock_in` |
| stock_in_pieces | unsignedInteger, default 0 | `stock_in_pieces` |
| remaining | integer, nullable | Nexus "on the shelf now", display only |
| as_of | dateTime, nullable | the answer's `as_of` (Manila) |
| timestamps | | |

Unique `(stock_date, name_key)`. No other index: about 42 rows a day. Two names in one answer with the same
`name_key` are summed into one row and logged. Matching never uses `name_key`; it reads `item_name` at read time.

### Settings (`app_settings` rows, no new table)

| key | written by | value |
|---|---|---|
| `nexus_stock_last_synced_at` | sync, only after a run where every requested day succeeded | `Y-m-d H:i:s` Manila |
| `nexus_stock_synced_through` | sync | last date D such that every day start..D succeeded |
| `nexus_stock_last_error` | sync; emptied on a full success | JSON `{at, kind, message}`; kind in `unauthorized`, `http_error`, `timeout`, `malformed`, `empty_for_stored_day`, `partial`; message is ours (status code only), never the body or a header |
| `stock_returned_source` | **owner only**; a migration seeds `jnt` when the row is missing and never overwrites | `jnt` or `nexus` |

There is no UI for `stock_returned_source`; the owner changes the row (database edit). No code path writes it
apart from the seed (S-220.5.5).

### Config

`config/services.php`: `'nexus' => ['url' => env('NEXUS_API_URL', 'https://srv1972783.hstgr.cloud'),
'token' => env('NEXUS_API_TOKEN'), 'timeout' => (int) env('NEXUS_API_TIMEOUT', 20)]`. `.env.example` gets the
three names with empty values. Incepxion has no token: the command says "not configured" and exits 0 without a
request; /item shows "not synced yet".

### Sync: `nexus:sync-stock` (App\Console\Commands\NexusStockSync → App\Services\Nexus\StockInSync)

- Schedule: `routes/console.php`, `->timezone('Asia/Manila')->hourlyAt(10)->withoutOverlapping(30)`. Runs under
  the existing `schedule:run` cron: no supervisor change.
- Guards, in order, each with a clear line and no request: no token (S-220.1.6), no valid `item_stock_start`
  (S-220.1.9), `Cache::lock('nexus:sync-stock', 1800)` already held (S-220.1.7, a manual run next to the
  scheduled one).
- Days to ask: from `synced_through` (re-asked, it was "today" when stored; never before the start) or from the
  start on the first run, through today Manila. At least yesterday and today (S-220.1.3, 1.11). Optional
  `--from=Y-m-d` (clamped to the start) to re-ask older days by hand after a Nexus correction.
- One `GET /api/item-stock?date=D` per day, oldest first, `Http::withToken()->acceptJson()->timeout()`; no retry
  inside a run (the next hour retries).
- Per answer: must be 200, valid JSON, `items` an array, `from`/`to` (or the echoed date) equal to D. Per item:
  `item` a non-empty string ≤ 255 chars; `stock_in_pieces` and `stock_in` null/0 → 0, an int or integer string
  in 0..2,147,483,647 → kept, anything else → the item is skipped and logged with its escaped name (S-220.1.10);
  `remaining` an int or null. One transaction per day: upsert the day's items by `(stock_date, name_key)`
  (overwrite, never add: S-220.1.4, 1.5), and set to 0 the day's stored rows missing from a non-empty answer
  (question 2).
- Failures: 401/403 stop the run (all days would fail) with kind `unauthorized`; a 5xx, a timeout or a malformed
  answer records the error and moves on to the next day; nothing is deleted (S-220.2.2–2.5). `synced_through`
  advances only through the first failed day; `last_synced_at` advances only when every day succeeded. After a
  day of failures the first good run asks every missed day and clears the error (S-220.2.9).
- The token is never logged, printed, stored or returned: it only goes in the `Authorization` header. Log lines
  carry the date, the status code and counts (S-220.1.6, threat model).

### Returned source (App\Services\Stock)

```php
interface ReturnedSource {
    public function key(): string;                                  // 'jnt' | 'nexus'
    public function returned(string $start, string $until): ReturnedResult;
}
final class ReturnedResult {          // readonly
    bool $available; array $pieces /* name => pieces */; bool $likhaNames; array $flags; ?string $note;
}
```

- `JntReturnedSource` (`likhaNames = true`, names are `macro_output.ITEM_NAME`): **one grouped query**:

  ```sql
  SELECT mo.ITEM_NAME AS item_name,
         SUM(CASE WHEN r.signingtime IS NOT NULL AND r.signingtime <= :now THEN 1 ELSE 0 END) AS returned_cnt,
         SUM(CASE WHEN r.signingtime IS NULL THEN 1 ELSE 0 END) AS unsigned_cnt,
         SUM(CASE WHEN r.signingtime > :now THEN 1 ELSE 0 END) AS future_cnt
  FROM macro_output mo
  JOIN (SELECT fj.waybill_number, fj.signingtime FROM from_jnts fj
        WHERE fj.status = 'Returned'
          AND (fj.signingtime IS NULL OR fj.signingtime >= :start)
          AND NOT EXISTS (SELECT 1 FROM from_jnts n WHERE n.waybill_number = fj.waybill_number AND n.id > fj.id)
          AND NOT EXISTS (SELECT 1 FROM from_jnts b WHERE b.waybill_number = fj.waybill_number AND b.submission_time < :start)
          AND EXISTS     (SELECT 1 FROM from_jnts a WHERE a.waybill_number = fj.waybill_number AND a.submission_time >= :start)) r
    ON r.waybill_number = mo.waybill
  WHERE <not cancelled, same as shipped>
  GROUP BY mo.ITEM_NAME
  ```

  Latest row per waybill = one count per waybill (4.7, 4.8: a flip away from Returned drops it on the next
  read); the two submission conditions are exactly the shipped rule, so a return is only ever counted for a
  waybill counted as shipped (4.4, and returned ≤ shipped per parcel); counted per `macro_output` row and
  multiplied by `ItemBaseKey::parse` qty in PHP, like shipped (4.1, 4.2, 4.10). Null signingtime and future dates
  are counted in `flags` and logged once per cold read, never in returned (4.6, 4.9). Status compared exactly
  to `'Returned'`, so `'For Return'` never counts (4.3). Placeholders bound; no driver-specific SQL.
- `NexusRtsReturnedSource`: stub, `available = false`, note "not available" (spec: build only a stub).
- `ReturnedSources::current()`: reads `stock_returned_source`; missing → `jnt`; `nexus` → the container's
  `NexusRtsReturnedSource` (tests bind a fake); anything else → `jnt` with warning `unknown_source` (5.1–5.3).
  One source for the whole history, never both (5.2, 5.3, 5.7).
- `stock:returned-compare` (P2, 5.8): read-only; prints per item J&T returned and Nexus RTS returned side by
  side, "not available" for the stub.

### Name bridge (App\Services\Stock\ItemNameBridge)

Likha universe = every base key the build already has, plus every base key ever ordered (`firstDates()`, already
cached 12 h, no new query). `bridge[canonicalKey(k)] = k` (`ItemAliasResolver`, so `item_type_mappings` is
honoured, 3.2); when two Likha keys share a canonical key, the one equal to its own canonical form wins, else the
smallest (documented, deterministic). A Nexus name maps through `canonicalKey(ItemBaseKey::key(name))`: case,
spacing, dashes, "N x" and non-ASCII handled the same on both sides (3.1, 3.6, 3.9; NFC applied first). No match
→ the unmatched list (name escaped by the view, pieces summed per normalized name), and no item changes (3.3).
Matching happens at read time from the raw stored names, so a mapping added later counts all history with no
re-sync (3.4). Each Nexus name's pieces are added once (3.5). Note: `item_type_mappings` rows whose `item_name`
carries an "N x" prefix never match a base key on either side; that is today's behaviour of the bridge in the
spec and I keep it.

### Cache (S-220.9)

Only the stock inputs are cached, as raw aggregates: shipped per raw `ITEM_NAME`, the returned result, Nexus
pieces per raw name for `[start, today]`, and the latest `remaining` per name. Key:

```
item_stock:inputs:v1:{host}:{start}:{returned source}:{nexus_stock_last_synced_at}:{today}
```

TTL 15 minutes (J&T loads hourly; ≤ 1 h). A successful sync changes `last_synced_at` and so the key (9.3); a
failed or partial sync does not (9.5); a source flip changes the key (5.6). Matching runs in PHP on every
request from the cached raw names, so a new alias mapping takes effect at once. Concurrent cold requests
compute the same value and write the same thing; nothing is incremented (9.4). Warm call: no query on
`from_jnts` apart from the live HOLD query, none on `nexus_stock_ins` (9.1, story change). The settings are read
with one `app_settings` query (`whereIn` on the four keys).

### /item/stock response (additive; existing fields unchanged)

Per item: `stock_in`, `returned` (null when the source is unavailable), `shipped`, `returned_over_shipped`
(4.11, the item stays listed), `nexus_remaining`, `nexus_diff` (= stock − remaining; P2). Top level:

```
stock_meta: {
  nexus:    { configured, synced, last_synced_at, stale, last_error:{at,kind,message}|null, token_problem,
              remaining_as_of, remaining_stale, unmatched:[{name,pieces}], unmatched_count },
  returned: { setting, source, available, warning, flags:{unsigned, future} }
}
```

`stale` = more than 3 h since `last_synced_at` (10,800 s: 3h00 is not stale, 3h01 is; 2.6). Never synced →
`synced=false` (2.7). Missing table → everything empty, no 500 (2.8, `Schema::hasTable`). The token never
appears (only `configured` as a boolean). CEO and Marketing get the same numbers; no price is added; unknown role
404 as today (6.8).

### /item view (English, x-text only)

- A status line above the To order table: "Stock = Nexus stock-in + returned − shipped · Nexus last synced
  10:10" or "Nexus not synced yet"; amber when stale ("Nexus not synced for 3+ hours — numbers may be old");
  red when `token_problem` ("Nexus token needs fixing"); returned-source warnings (unknown setting, unavailable).
  Numbers always still show.
- Unmatched Nexus names: `<details>` with "N Nexus names not matched" as the summary, names and pieces inside
  (x-text) (3.8).
- A small warning mark with a title next to the "Qty to order" header when stale or unmatched > 0 (8.3).
- Details panel, Stock line: "stock-in 100 + returned 4 − shipped 30 = 74"; "Nexus on shelf 80 (as of 10:10),
  difference −6 — Nexus also has the stock from before the start, so a gap is expected" (7.1, 7.2 dash, 7.4
  stale, 7.5).
- `num()` formatting for large integers (6.10); wrapping flex at 390 px (6.9). `_table_old.blade.php` untouched.

## Tasks

| Task | Side | Tier | Cases | Files |
|---|---|---|---|---|
| **T1** schema, config, Nexus sync, command, schedule | backend | medium | 1.1–1.11, 2.2–2.5, 2.9 (sync side) | migration `create_nexus_stock_ins_table`, `config/services.php`, `.env.example`, `app/Services/Nexus/StockInSync.php`, `app/Console/Commands/NexusStockSync.php`, `routes/console.php`, `tests/Feature/Item/ItemTestCase.php` (migration path, `from_jnts.status`/`signingtime` columns, `Http::preventStrayRequests()`, a `nexusStockIn()` helper), `tests/Feature/Item/NexusStockSyncTest.php` |
| **T2** returned source and switch, compare command | backend | medium | 4.1–4.11, 5.1–5.8 | migration `seed_stock_returned_source_setting`, `app/Services/Stock/{ReturnedSource,ReturnedResult,JntReturnedSource,NexusRtsReturnedSource,ReturnedSources}.php`, `app/Console/Commands/StockReturnedCompare.php`, `tests/Feature/Item/ReturnedStockTest.php` |
| **T3** stock formula, name bridge, cache, response meta | backend | medium | 2.1, 2.6–2.8, 3.1–3.9, 6.1–6.8, 6.11, 7.1–7.4 (data), 8.1, 8.2, 8.4–8.6, 9.1, 9.3–9.5 | `app/Services/ItemStockService.php`, `app/Services/Stock/ItemNameBridge.php`, `tests/Feature/Item/NexusStockReadTest.php`, `tests/Feature/Item/StockCacheTest.php`; updates to `StockEndpointTest` and `LifecycleStockTest` (below) |
| **T4** /item view | frontend | medium | 2.1/2.3/2.6/2.7 (page), 3.8, 6.9, 6.10, 7.1–7.5, 8.3 | `resources/views/item/index.blade.php` (state from `stock_meta`, helpers), `_table_order.blade.php` (status line, header mark), `_il_expand.blade.php` (breakdown, cross-check), `tests/Feature/Item/StockSourceViewTest.php` |
| **T5** cold-time measurement, full suite, result | both | low | 9.2 | `docs/specs/220-nexus-stock.result.md`, `qa/stories.md` (test names, last run) |

Order T1 → T2 → T3 → T4 → T5 (one developer; T1 and T2 share `ItemTestCase`, T3 needs both, T4 needs T3's
response). Each task: red test per case, then code, then the affected test files; the full suite once in T5.

### Existing tests that change (T3)

- `StockEndpointTest::test_example_a_…`: the counted PO of 100 becomes a Nexus stock-in of 100 (the counted PO
  stays, to show it no longer adds); stock still 40. Adds `stock_in 100, returned 0, shipped 60`.
- `test_example_b_…`, `test_example_d_…`: unchanged numbers; add `stock_in 0, shipped …` assertions.
- `test_marketing_gets_the_same_numbers`: counted PO → Nexus stock-in 100.
- `test_discount_lines_are_not_received_or_incoming`: becomes "discount lines are not incoming, and counted
  POs add nothing to stock" (stock 0, incoming 10).
- `test_po_counted_before_start_does_not_add_to_received`: replaced by S-220.6.6 (counted PO contributes 0).
- `test_delivered_po_counts_as_incoming_but_counted_po_does_not`: incoming 20 unchanged; stock 10 → 0.
- `LifecycleStockTest::test_scaling_doi_example_is_16_point_7_amber`: the counted PO of 300 becomes a Nexus
  stock-in of 300; DOI 16.7 amber unchanged.

## Riskiest parts

1. **Question 1** (range vs per day). Getting it wrong either stores range totals as one day or double counts.
2. **The returned query on production volume** (`from_jnts` hundreds of thousands of rows). Three correlated
   subqueries per Returned row, all on the `waybill_number` index; the outer filter rides
   `idx_from_jnts_signing_status`/`idx_from_jnts_status`. Cached 15 min, so at most one cold run per quarter
   hour per host.
3. **Name matching**: free-text Nexus names with no SKU. A miss lowers stock silently except for the unmatched
   list; the owner check after release covers it.
4. **Incepxion** loses PO received in its stock (Nexus not configured there): every item with sales after the
   start shows 0 and "Count the stock first". This is what the spec says; the reviewer should know it is
   visible.
5. **A stale `remaining`**: Nexus may only list items with activity that day; then the cross-check shows a dash
   for many items. Display only.

## Measuring the returned query (S-220.9.2)

1. A local perf run, not part of the suite (skipped unless `PERF_220=1`): seed production-like volume
   (≈ 500k `from_jnts` rows over 60 days, about 25 % Returned with signingtime, 2 % duplicates per waybill,
   ≈ 500k `macro_output` rows, 300 items, the production indexes from the migrations) and time a cold
   `build()` and the returned query alone, 5 runs, median.
2. The machine has MySQL 8 running (service MySQL80). If the reviewer gives a scratch database and user for it,
   the same seed runs on MySQL for a realistic number; I will not read `.env` to find credentials. Otherwise
   sqlite only, reported as such.
3. A read-only `EXPLAIN` of the exact statement for the reviewer's read-only check on the live server (I make no
   server call).

## Base suite (86db0060, this worktree)

`php artisan test` on 86db0060 (plus the docs-only first commit), 2026-11-03, 1,565 s: **4,194 tests, 4 failed,
206 passed, 3,984 passed with a warning** (45,738 assertions). The 4 failures are exactly the known base list:
`BigBroadcastTest` S-167.1, `ExampleTest`, `ItemTableFitScriptTest` (catalog column widths) and
`AstraAddressRulesTest` S-26.18. The "warning" on most tests is a `file_get_contents(<worktree path>)` warning
from the `artisan test` printer in this worktree, not from the tests: the same files run through
`vendor/bin/phpunit` report OK with no warning (`StockEndpointTest`: OK, 21 tests, 112 assertions). T5 compares
head against these counts, failures by name.

## Review outcome (before the build)

- Q1: one `?date=` call per day (the Nexus range response sums over the days asked). S-220.1.2 updated.
- Q2: zero, not keep; each zeroing logged with day, name_key and old pieces.
- Q3: returned null with a warning, no J&T fallback, nothing zeroed.
- Change: without `services.nexus.token` the stock stays the PO-received path exactly as on 86db0060, with only a quiet
  "Nexus not configured" note (new case S-220.6.12). So the base `StockEndpointTest`/`LifecycleStockTest` stay
  unchanged and pin that mode; their Nexus-mode counterparts are new tests.
- The proposed story changes were accepted.
- 9.2 measured on a throwaway Docker `mysql:8.4` container, not the local MySQL80 service.
