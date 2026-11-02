# Result: spec 220 stock on /item = Nexus stock-in + returned − shipped

## Status

Built and tested on `feat/220-nexus-stock` (base 86db0060). Not pushed, not deployed. Every P1 case and every P2 case has a
passing test (S-220.6.9 is checked on the markup, not in a browser). The plan (`docs/plans/220-nexus-stock.md`) was
accepted with three answers and one change before the build:

1. One `?date=` request per day (the Nexus range call sums over the days asked).
2. A stored item missing from a later valid, non-empty answer for the same day is set to 0 and each zeroing is logged.
3. Returned source "nexus" while unavailable: returned is null, a warning shows, no J&T fallback, nothing is zeroed.
4. Change: a site without a Nexus token keeps the PO-received stock exactly as on 86db0060 (new case S-220.6.12).

## Changes per file

| File | Change |
|---|---|
| `database/migrations/2026_11_16_100000_create_nexus_stock_ins_table.php` | New table `nexus_stock_ins`: stock_date, name_key (sha1 of the NFC/trim/one-space/lowercase name), item_name (raw, ≤ 255), stock_in_lots, stock_in_pieces, remaining, as_of, timestamps; unique (stock_date, name_key). |
| `database/migrations/2026_11_16_100100_seed_stock_returned_source_setting.php` | Seeds `app_settings.stock_returned_source = jnt` only when the row is missing; down removes it only if it is still `jnt`. |
| `config/services.php`, `.env.example` | `services.nexus.url` (default the Nexus server), `.token`, `.timeout`; `NEXUS_API_URL`, `NEXUS_API_TOKEN`, `NEXUS_API_TIMEOUT` with empty values. |
| `app/Services/Nexus/StockInSync.php` | The sync: guards (no token, no table, no start, `--from` invalid, lock held), days from `nexus_stock_synced_through` (or the start) through today, one GET per day, validation, upsert per day in a transaction, zeroing of stored items missing from a valid non-empty answer, settings rows, logs without the token. |
| `app/Console/Commands/NexusStockSync.php` | `nexus:sync-stock {--from=}`; exit 1 on a failed/partial run, 0 when not configured or already running. |
| `routes/console.php` | `nexus:sync-stock` hourly at :10 Manila, `withoutOverlapping(30)`. |
| `app/Services/Stock/ReturnedSource.php`, `ReturnedResult.php` | The returned-source interface and its result (available, pieces, likhaKeys, flags, note). |
| `app/Services/Stock/JntReturnedSource.php` | J&T Returned by signingtime: one grouped query (below); latest from_jnts row per waybill; same shipped-after-start rule and cancelled filter as shipped; per macro_output row × "N x" qty; null and future signingtime counted in flags and logged. |
| `app/Services/Stock/NexusRtsReturnedSource.php` | Stub: always "not available". |
| `app/Services/Stock/ReturnedSources.php` | Reads `stock_returned_source`: missing/`jnt` → J&T, `nexus` → Nexus RTS, anything else → J&T with `unknown_source`. |
| `app/Console/Commands/StockReturnedCompare.php` | `stock:returned-compare` (P2), read-only: J&T and Nexus RTS returned per item side by side. |
| `app/Services/Stock/ItemNameBridge.php` | Nexus name → Likha base key through NFC, `ItemBaseKey::key` and `ItemAliasResolver::canonicalKey` on both sides. |
| `app/Services/ItemStockService.php` | Nexus mode (token set): stock_raw = stock-in + returned − shipped, stock inputs cached 15 min, per-item fields (`stock_in`, `returned`, `shipped`, `returned_over_shipped`, `nexus_remaining`, `nexus_diff`) and `stock_meta`. Without a token: the old path untouched, plus `stock_meta = {mode: po_received, nexus: {configured: false}}`. `col()`, `notCancelledSql()` and `startSetting()` became public static for reuse; `leftByBase` split into the query and the aggregation (same result). |
| `resources/views/item/index.blade.php` | `stock.meta` from the response; helpers `stockSourceText`, `stockSourceIssues`, `stockWarnings`, `stockBreakdown`, `nexusCrossText`, `nexusCrossTip`; the status line and the collapsed unmatched list above the To order table; CSS that wraps at phone width. |
| `resources/views/item/_table_order.blade.php` | A ⚠ mark with the warnings as its title next to "Qty to order". |
| `resources/views/item/_il_expand.blade.php` | Under Stock / Incoming (Nexus mode): "stock-in X + returned Y − shipped Z = N" and "Nexus on shelf R (as of …), difference D". |
| `tests/Feature/Item/ItemTestCase.php` | `from_jnts.status`/`signingtime`, `Http::preventStrayRequests()`, Nexus off by default, the two new migrations, helpers `nexusOn`, `nexusStockIn`, `appSetting`. |
| `tests/Feature/Item/NexusStockSyncTest.php`, `ReturnedStockTest.php`, `NexusStockReadTest.php`, `StockCacheTest.php`, `StockSourceViewTest.php` | The new cases (see `qa/stories.md`, Slice 220). |
| `tests/Feature/Item/ReturnedQueryPerfTest.php` | Opt-in MySQL volume measurement (skipped unless `PERF_220=1`). |
| `tests/Feature/Item/SuppliersGroupTest.php` | The eight whole-page render pins re-pinned: the shared script and CSS of `index.blade.php` changed (the old table partial `_table_old.blade.php` is untouched and its own pin still passes). |
| `qa/stories.md`, `docs/plans/220-nexus-stock.md` | Stories with tests and results; the plan. |

### Existing tests that relied on PO received

Because a site without a Nexus token keeps today's behaviour, `StockEndpointTest` (examples A, B and D, the received-path
tests, the Marketing test) and `LifecycleStockTest` run **unchanged** in that mode and still pass: they pin S-220.6.12.
Their Nexus-mode counterparts are new tests in `NexusStockReadTest`:

| Base test (still passing, PO mode) | Nexus-mode counterpart |
|---|---|
| `StockEndpointTest::test_example_a_stock_incoming_doi_and_order_by` | `test_example_a_in_nexus_mode_gives_the_same_numbers_with_nexus_stock_in` (counted PO 100 kept, adds nothing; Nexus 100; stock 40, DOI 19.0, green) |
| `…test_example_b_no_stock_means_order_now_in_red` | `test_example_b_in_nexus_mode` (same numbers, plus stock_in 0, shipped 0) |
| `…test_example_d_negative_raw_stock_shows_zero_and_needs_count` | `test_example_d_in_nexus_mode` (−25 → 0, needs count) |
| `…test_marketing_gets_the_same_numbers` | `test_S_220_6_8_ceo_and_marketing_see_the_same_numbers_no_price_and_unknown_role_404` |
| `…test_discount_lines_are_not_received_or_incoming`, `…test_delivered_po_counts_as_incoming_but_counted_po_does_not` | `test_S_220_6_7_incoming_and_discount_lines_are_as_before` (incoming 10 and 20 unchanged; counted POs add 0) |
| `…test_po_counted_before_start_does_not_add_to_received` | `test_S_220_6_6_po_received_no_longer_adds` |
| `LifecycleStockTest::test_scaling_doi_example_is_16_point_7_amber` | `test_S_220_8_1_and_8_2_the_formulas_use_the_floored_stock` (Nexus 300 instead of a counted PO 300: DOI 16.7 amber) |

## Test counts

`php artisan test`, the whole suite:

| | Tests | Failed | Passed (incl. with warning) | Skipped |
|---|---|---|---|---|
| Base 86db0060 | 4,194 | 4 | 4,190 | 0 |
| Head (pre-merge, 828b0b10) | 4,270 | 2 | 4,267 | 1 (`ReturnedQueryPerfTest`, opt-in) |

Base failures: BigBroadcast S-167.1, ExampleTest, ItemTableFitScriptTest (catalog column widths), Astra S-26.18.
Head failures: ExampleTest and ItemTableFitScriptTest, both in the base list. BigBroadcast S-167.1 and Astra S-26.18 passed on this run (neither touches /item; both are slow, load-sensitive tests). No new failure. Head = base + 76 new tests; 206 passed plainly and 4,062 with the printer warning (the skipped perf test is among the latter in the artisan printer).
The "warning" on most tests in both runs is a `file_get_contents(<worktree path>)` notice from the `artisan test`
printer in a worktree; the same files through `vendor/bin/phpunit` report OK without it.

Merge of live main c4543c88 (spec 217, no /item file in common; conflict in `qa/stories.md` only, both slices kept): merge commit 15ff5475. On the merge, `tests/Feature/Item`: 313 tests, 1 failure (ItemTableFitScriptTest, base), 1 skipped (perf); the 220 and stock files: 105 tests, all passed, 1 skipped.

New tests: 19 sync, 16 returned, 29 read, 5 cache, 6 view, 1 perf (skipped by default) = 76.

## Story changes

- **S-220.1.2**: one `?date=` request per day from the start (33 as of 2026-11-03), each window one day; the stored
  per-day pieces sum to what Nexus returned per day. The range call sums over the days asked and cannot fill a per-day
  table (confirmed in the Nexus code).
- **S-220.1.12 (added)**: a stored item missing from a later valid, non-empty answer for the same day is set to 0 (not
  deleted) and logged with day, name_key and old pieces.
- **S-220.1.13 (added)**: `--from=Y-m-d` re-asks older days by hand, never before the start.
- **S-220.2.4**: an empty answer for a day with no stored rows is a quiet success (a day with no arrivals); it is a
  warning, with no change and no advance of last-synced, only for a day that has stored rows, malformed JSON, or an
  answer for another date.
- **S-220.4.7**: the status of a waybill is its from_jnts row with the highest id (the row the J&T track sync updates).
- **S-220.6.2**: "the last day" is today in Manila; stock does not depend on the page's end date (as before).
- **S-220.6.3 / 2.7 wording**: the new view is English (pinned by `ItemPageTest`): "Count the stock first" for
  "bilangin", "Nexus not synced yet".
- **S-220.6.12 (added)**: without a Nexus token the stock is PO received − shipped exactly as on 86db0060.
- **S-220.9.1**: the live HOLD query left-joins from_jnts on every call and stays live; a warm call runs no shipped or
  returned query and nothing on nexus_stock_ins.
- **S-220.9.3**: TTL 15 minutes.
- **Done-when, examples B and D**: they needed no change (no PO received in either); with S-220.6.12 every base stock
  test stays as it is and pins the PO mode.

## Config keys

| Key | Where | Default | Note |
|---|---|---|---|
| `NEXUS_API_TOKEN` → `services.nexus.token` | env | empty | Empty = Nexus not configured (PO-received stock, no sync requests). Already on the Likha server. |
| `NEXUS_API_URL` → `services.nexus.url` | env | `https://srv1972783.hstgr.cloud` | |
| `NEXUS_API_TIMEOUT` → `services.nexus.timeout` | env | 20 (s) | Connect timeout 5 s. |
| `stock_returned_source` | app_settings | `jnt` (seeded) | Owner only: `jnt` or `nexus`. No UI; a database edit. |
| `nexus_stock_last_synced_at` | app_settings | — | Written by the sync after a fully successful run (Manila). |
| `nexus_stock_synced_through` | app_settings | — | Last date through which every day succeeded. |
| `nexus_stock_last_error` | app_settings | — | JSON `{at, kind, message, day}`; empty after a success. |
| `item_stock_start` | app_settings | existing | Unchanged (2026-10-02 on Likha). |

## Deploy steps (Likha; Incepxion needs only steps 1–2)

1. Deploy the code; `php artisan migrate --force` (two migrations: the table and the `stock_returned_source` seed).
2. `php artisan config:cache` if the server caches config. The schedule runs under the existing `schedule:run` cron:
   no supervisor change.
3. First-run backfill, by hand, once: `php artisan nexus:sync-stock`. It asks one day at a time from
   `item_stock_start` (about 33 requests) and prints a line per day. Re-run it if it reports "Not complete"; it
   continues from the first failed day. The hourly run does the same on its own if this step is skipped.
4. Open /item: the line above To order should read "Stock = Nexus stock-in + returned − shipped · Nexus last synced …".

Incepxion: no token, so `nexus:sync-stock` prints "Nexus not configured" and /item keeps PO received − shipped.

## Rollback

- Code: redeploy 86db0060 (or the live main). The old code never reads the new table or settings.
- Data, optional: `php artisan migrate:rollback --step=2` drops `nexus_stock_ins` and removes the seeded `jnt` row
  (an owner's `nexus` choice is kept). The sync settings rows are harmless and can stay or be deleted.
- Quick switch-off without a deploy: empty `NEXUS_API_TOKEN` and clear the config cache: /item goes back to PO
  received at once and the sync stops calling Nexus.

## Owner checks after release

1. Compare /item stock with the warehouse count and with "Nexus on shelf" (details ›) for 5 busy items. The gap to
   Nexus is the stock that was already there before 2026-10-02 (Nexus counts it, Likha starts at zero).
2. Open "N Nexus names not matched" above the To order table. Each name there adds nothing to any item: rename it in
   Nexus or add an item mapping.
3. Before ever setting `stock_returned_source = nexus`: run `php artisan stock:returned-compare` and compare the totals.

## 9.2 measurement: the returned query on production-like volume

Throwaway Docker container from the local `mysql:8.4` image (127.0.0.1:33220, generated root password kept only in the
scratch folder, container removed afterwards). Seeded by `ReturnedQueryPerfTest`: 947,600 from_jnts rows
(Jan–Nov 2026, ~3,000 waybills a day, 3% with an older duplicate row, ~25% Returned with signingtime, 0.5% Returned
without), 1,036,454 macro_output rows (300 items with "N x" variants, 8% second rows, 4% held), the production
from_jnts indexes, 42 Nexus items a day. 29,165 Returned rows since the start; 30,289 returned pieces.

| Measure (median of 5) | ms |
|---|---|
| Returned query alone, cold | 1,533 |
| /item/stock `build()`, Nexus mode, cache flushed (cold) | 5,076 |
| /item/stock `build()`, Nexus mode, warm (inputs cached) | 1,364 |
| /item/stock `build()`, PO mode (= today, no cache) | 2,961 |

So a warm call (every call within 15 minutes of a cold one, per host, start, source and sync) is faster than today,
and the one cold call per quarter hour is about 2 s slower than today's every call. Timings on this laptop moved by
±20% between runs.

EXPLAIN (MySQL 8.4) of the final statement:

```
1  fj  ref  idx_from_jnts_status            rows=383502 filtered=69.3  Using where; Using temporary
1  n   ref  from_jnts_waybill_number_index  rows=1      filtered=100   Using where; Not exists; Using index
1  b   ref  from_jnts_waybill_number_index  rows=1      filtered=100   Using where; Not exists
1  mo  ref  macro_output_waybill_index      rows=1      filtered=100   Using where
5  a   ref  from_jnts_waybill_number_index  rows=1      filtered=23.2  Using where
```

Two other shapes were measured and dropped: the plain `EXISTS` (MySQL turns it into a semijoin that starts with a full
scan of from_jnts, 1,190 ms here but growing with the whole table) and a `UNION ALL` of signed-since-start and unsigned
candidates (the optimizer still chose the status index or a full scan, 1,516–2,434 ms).

The exact statement, for a read-only `EXPLAIN` on the server (start 2026-10-02; replace the "now" literal):

```sql
EXPLAIN
SELECT mo.`ITEM_NAME` AS item_name,
       SUM(CASE WHEN r.signingtime IS NOT NULL AND r.signingtime <= '2026-11-03 10:10:00' THEN 1 ELSE 0 END) AS returned_cnt,
       SUM(CASE WHEN r.signingtime IS NULL THEN 1 ELSE 0 END) AS unsigned_cnt,
       SUM(CASE WHEN r.signingtime > '2026-11-03 10:10:00' THEN 1 ELSE 0 END) AS future_cnt
FROM macro_output mo
JOIN (SELECT fj.waybill_number, fj.signingtime
      FROM from_jnts fj
      WHERE fj.status = 'Returned'
        AND (fj.signingtime IS NULL OR fj.signingtime >= '2026-10-02 00:00:00')
        AND NOT EXISTS (SELECT 1 FROM from_jnts n WHERE n.waybill_number = fj.waybill_number AND n.id > fj.id)
        AND NOT EXISTS (SELECT 1 FROM from_jnts b WHERE b.waybill_number = fj.waybill_number AND b.submission_time < '2026-10-02 00:00:00')
        AND (fj.submission_time >= '2026-10-02 00:00:00'
             OR EXISTS (SELECT 1 FROM from_jnts a WHERE a.waybill_number = fj.waybill_number AND a.submission_time >= '2026-10-02 00:00:00'))) r
  ON r.waybill_number = mo.waybill
WHERE (mo.`STATUS` IS NULL OR LOWER(REPLACE(REPLACE(TRIM(mo.`STATUS`), ' ', ''), '_', '')) NOT IN ('cannotproceed', 'odz'))
GROUP BY mo.`ITEM_NAME`;
```

To repeat the measurement: start a throwaway MySQL, then
`PERF_220=1 PERF_DB_HOST=127.0.0.1 PERF_DB_PORT=<port> PERF_DB_USER=root PERF_DB_PASSWORD=<pw> PERF_DB_DATABASE=perf php artisan test tests/Feature/Item/ReturnedQueryPerfTest.php`
(it drops and recreates its tables; `PERF_220_KEEP=1` reuses the seeded data).

## Open risks

1. **Cold /item/stock is slower than today** (≈ 5 s vs ≈ 3 s on the synthetic volume), once per 15 minutes per host,
   start, source and sync; warm calls are faster. If the live EXPLAIN shows a full scan or many more Returned rows,
   the next step is an index on `from_jnts (status, signingtime)` (a migration, not in this spec).
2. **Free-text Nexus names**: a name that matches no Likha item adds nothing; the unmatched list is the only signal.
3. **The zeroing rule** trusts a non-empty Nexus answer for a day as complete. If Nexus ever returns a partial list for
   a day, the missing items for that day go to 0 until the next good answer (each zeroing is logged).
4. **Corrections older than yesterday** are only picked up with `nexus:sync-stock --from=…` by hand.
5. **Nexus remaining** is the latest per name and may be missing for items Nexus did not list recently: a dash.
6. **Phone width (S-220.6.9)** is checked on the markup only (wrapping CSS, scrolling list); no browser run was made.
7. **`item_type_mappings` rows whose item_name carries an "N x" prefix** never match a base key on either side (as in
   the bridge the spec describes).
