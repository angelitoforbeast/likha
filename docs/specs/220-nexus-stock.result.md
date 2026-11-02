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
| `config/services.php`, `.env.example` | `services.nexus.url` (default the Nexus server), `.token`, `.timeout`, `.cold_wait` (15 s, not in env); `NEXUS_API_URL`, `NEXUS_API_TOKEN`, `NEXUS_API_TIMEOUT` with empty values. |
| `app/Services/Nexus/StockInSync.php` | The sync: guards (no token, no table, no start, `--from` invalid, lock held), days from `nexus_stock_synced_through` (or the start) through today, one GET per day, validation, upsert per day in a transaction, zeroing of stored items missing from a valid non-empty answer, the per-day `stock_in` list of the real Nexus answer, empty stored days kept and listed, `--accept-empty`, 429 stops the run, the daily-pass status, settings rows, logs without the token. |
| `app/Console/Commands/NexusStockSync.php` | `nexus:sync-stock {--from=} {--days=}` {--accept-empty=}; exit 1 on a failed/partial run, 0 when not configured or already running. |
| `routes/console.php` | `nexus:sync-stock` hourly at :10 Manila, `withoutOverlapping(30)`; `nexus:sync-stock --days=7` daily at 02:40 Manila, `withoutOverlapping(60)`. |
| `app/Services/Stock/ReturnedSource.php`, `ReturnedResult.php` | The returned-source interface and its result (available, pieces, likhaKeys, flags, note). |
| `app/Services/Stock/JntReturnedSource.php` | J&T Returned by signingtime: one grouped query (below); latest from_jnts row per waybill; same shipped-after-start rule and cancelled filter as shipped; per macro_output row × "N x" qty; null and future signingtime counted in flags and logged. |
| `app/Services/Stock/NexusRtsReturnedSource.php` | Stub: always "not available". |
| `app/Services/Stock/ReturnedSources.php` | Reads `stock_returned_source`: missing/`jnt` → J&T, `nexus` → Nexus RTS, anything else → J&T with `unknown_source`. |
| `app/Console/Commands/StockReturnedCompare.php` | `stock:returned-compare` (P2), read-only: J&T and Nexus RTS returned per item side by side. |
| `app/Services/Stock/ItemNameBridge.php` | Nexus name → Likha base key: exact `ItemBaseKey::key` first (after NFC and Unicode spaces), then a loose match, then the `item_type_mappings` family, each only when it fits one Likha key; otherwise unmatched, marked ambiguous when it fits several. |
| `app/Services/ItemStockService.php` | Nexus mode (token set): stock_raw = stock-in + returned − shipped, stock inputs cached 15 min, per-item fields (`stock_in`, `returned`, `shipped`, `returned_over_shipped`, `nexus_remaining`, `nexus_diff`) and `stock_meta`. Per item also `nexus_remaining_as_of`, `nexus_remaining_stale`, `nexus_remaining_mixed`. A cache lock so concurrent cold requests compute once. Without a token: the old path untouched, plus `stock_meta = {mode: po_received, nexus: {configured: false, previously_synced}}`. `col()`, `notCancelledSql()` and `startSetting()` became public static for reuse; `leftByBase` split into the query and the aggregation (same result). |
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
| Head after the review fixes (fe33bc88, includes the merge of c4543c88) | 4,293 | 3 | 4,289 | 1 (`ReturnedQueryPerfTest` cold-time test, opt-in) |
| Head after review 2 (c51c0571) | 4,298 | 3 | 4,294 | 1 (perf, opt-in) |

Base failures: BigBroadcast S-167.1, ExampleTest, ItemTableFitScriptTest (catalog column widths), Astra S-26.18.
Head failures: ExampleTest and ItemTableFitScriptTest, both in the base list. BigBroadcast S-167.1 and Astra S-26.18 passed on this run (neither touches /item; both are slow, load-sensitive tests). No new failure. Head = base + 76 new tests; 206 passed plainly and 4,062 with the printer warning (the skipped perf test is among the latter in the artisan printer).
The "warning" on most tests in both runs is a `file_get_contents(<worktree path>)` notice from the `artisan test`
printer in a worktree; the same files through `vendor/bin/phpunit` report OK without it.

Merge of live main c4543c88 (spec 217, no /item file in common; conflict in `qa/stories.md` only, both slices kept): merge commit 15ff5475. On the merge, `tests/Feature/Item`: 313 tests, 1 failure (ItemTableFitScriptTest, base), 1 skipped (perf); the 220 and stock files: 105 tests, all passed, 1 skipped.

New tests: 19 sync, 16 returned, 29 read, 5 cache, 6 view, 1 perf (skipped by default) = 76.

After the review fixes (fe33bc88): failures ExampleTest, ItemTableFitScriptTest and Astra S-26.18, all in the base list; BigBroadcast S-167.1 passed. No new failure. `tests/Feature/Item`: 325 tests, 1 failure (ItemTableFitScriptTest, base), 1 skipped; the six 220 files: 88 tests, all passed, 1 skipped. New tests since the first result: 7 sync, 3 read, 1 cache, 1 perf guard (the view test gained assertions) = 12, so 88 in all.

After the empty-days guard (item 12): `tests/Feature/Item` 326 tests, 1 failure (ItemTableFitScriptTest, base), 1 skipped; the six 220 files 89 tests, all passed, 1 skipped. The full suite was not re-run for this one-test change.

After review 2 (c51c0571): full suite failures ExampleTest, ItemTableFitScriptTest and Astra S-26.18, all in the base list; no new failure. `tests/Feature/Item` 330 tests, 1 failure (ItemTableFitScriptTest, base), 1 skipped; the six 220 files 93 tests, all passed, 1 skipped.

## Review fixes (after the first result)

Live preview on production (read-only, by the reviewer): the returned query took 835 ms using
`idx_from_jnts_signing_status`; 38 of 55 Nexus names matched; `item_type_mappings` has 0 rows.

| # | Finding | Fix | Test |
|---|---|---|---|
| 1 (major) | A skipped item (bad pieces) was missing from the answer and its stored row was zeroed | `parseItems` returns the name_keys of skipped items with a valid name; `store()` never zeroes them | `test_a_skipped_item_keeps_its_stored_row_and_the_skip_is_logged` ("48.0", 48.5, −3, "abc": the 48 stays, skip logged, nothing zeroed) |
| 2 (major) | A day that became empty in Nexus failed forever | Superseded by review 2 below (the timed acceptance was removed) | — |
| 3 (major) | Family mappings collapsed variants onto one key | `ItemNameBridge`: exact base key first, then a loose match (no spaces/dashes/underscores), then the family; each only when it fits one Likha key, else unmatched and marked "ambiguous" (also shown on the page) | `test_S_220_3_2_two_variants_mapped_to_one_family_keep_their_own_stock_and_a_family_name_is_ambiguous`, view test |
| 4 | A non-empty answer with only invalid items counted as success | Treated as malformed: warning, nothing changes, synced-through and last-synced don't advance | `test_S_220_2_4_a_non_empty_answer_with_only_invalid_items_is_malformed_and_changes_nothing` |
| 5 | A missing token on a site that synced before was silent | `stock_meta.nexus.previously_synced` (a Nexus row or `nexus_stock_last_synced_at`); the page shows a red "Nexus token missing — stock shows the old PO count"; a site that never synced keeps the quiet note | `test_a_missing_token_after_an_earlier_sync_is_flagged_and_a_site_that_never_synced_stays_quiet`, `test_S_220_6_12_…`, view test |
| 6 | Backdated arrivals were never picked up | `--days=N`; a daily pass `nexus:sync-stock --days=7` at 02:40 Manila (not 02:10: the hourly run at :10 holds the sync lock and would make the daily pass exit). Hourly stays yesterday + today | `test_the_daily_pass_asks_the_last_7_days_and_the_hourly_stays_on_yesterday_and_today` |
| 7 | Concurrent cold requests all computed | A cache lock per inputs key: one computes, the others poll the cache (microtime, up to `cold_wait`) and take the stored value, or compute after the wait; the lock is freed in `finally` | `test_S_220_9_4_a_cold_request_waits_for_another_computing_request_and_the_lock_is_freed_after` (lock path tested directly; a real two-process test needs a shared database and cache, which the sqlite-in-memory suite does not have) |
| 8 | `remaining` added a renamed name's frozen value | Per Likha key: each name's own latest as_of; names more than 1 h older than the item's newest are left out (`nexus_remaining_mixed`); the item shows the oldest as_of it used (`nexus_remaining_as_of`) and its own staleness | `test_remaining_uses_each_names_own_latest_as_of_and_leaves_out_a_renamed_names_frozen_value` |
| 9 | Failed days of a `--from` run were not retried | `nexus_stock_pending_days`: failed days outside the next normal window are kept and asked first by the next run until they succeed | `test_days_that_fail_in_a_from_run_are_asked_again_by_the_next_hourly_run` |
| 10 | NBSP and other Unicode spaces | `normalizeName` and the bridge treat `[\s\p{Z}]` as space; `trimName` trims them from the stored name's ends | `test_nbsp_and_other_unicode_spaces_are_normalised_and_trimmed` |
| 11 | The perf run had defaults (127.0.0.1:3306 root) | Every `PERF_DB_*` value is required; the run fails when host (loopback-equal), port and database equal any app connection | `test_the_perf_target_needs_every_value_and_is_never_the_apps_own_database` |
| 12 (follow-up) | The 7-day pass could zero a week if Nexus answered empty by mistake | Superseded by review 2 below (the mass-empty guard was removed) | — |

The whole-page render pins in `SuppliersGroupTest` were re-pinned for each set of new view strings (the old table
partial is still untouched).

### Review 2: empty days, the Nexus answer shape

**What the Nexus code says** (`app/Services/ItemStock.php` at e356be1, `ItemStockController`, `AsksForDays`):

- A `?date=D` answer lists every item that **came in on D or is on the shelf now** (stock-in rows of the day, plus
  `Barcode::inStock()`), under its Product Master name. An item with no arrival on D and nothing on the shelf is not
  listed. So an item missing from D's answer had no arrival on D: storing 0 for D is correct per-day data, not a wipe,
  and the item's other days are untouched. Rule 3 stays as it was; the test now shows it (a shelf-only item stored as 0,
  the missing item 0 for that day, its other day kept).
- **`stock_in` is a list** `[{date, lots, pieces}]` per day, not a lot count, and `stock_in_pieces` is its sum. The
  sync read `stock_in` as a number, so every real item would have been skipped and every day reported malformed. Fixed:
  lots are summed over the entries for the asked day (an entry for another date or bad lots skips the item); the old
  single-number form is still read. Note for the open question on 1.2: the range call does carry per-day entries in
  `stock_in`, so a backfill by ranges is possible later; the sync keeps one `?date=` per day as decided.
- An empty `items` therefore means nothing came in AND nothing is on the shelf at all: in practice an outage, never a
  real "the only entry was deleted" day. That supports never auto-zeroing.
- Nexus allows **60 calls an hour per token** (429 after). The first backfill (33) plus the hourly 2 fits; a long
  `--from` would not. A 429 now stops the run (kind `rate_limited`) and the rest is asked next run.
- `to` after Nexus's today is a 422; the sync never asks past today (Manila).

| # | Finding | Fix | Test |
|---|---|---|---|
| R2-1 (major) | An outage just after midnight answering 200 with no items zeroed yesterday silently | An empty answer for a day with stored rows never zeroes them: the rows stay, the day goes on `nexus_stock_unconfirmed_empty`, /item says "Nexus returned nothing for <days>; old numbers kept". Zeroing only by `nexus:sync-stock --accept-empty=YYYY-MM-DD`: it asks that day again and zeroes (each row logged) only if still empty, then drops the day from the list; items again → stored, dropped; Nexus failing → no change. The 1-hour/2-answer acceptance, the before-yesterday acceptance and the mass-empty guard are removed (`nexus_stock_empty_seen` is gone) | `test_an_empty_answer_for_a_stored_day_keeps_the_rows_is_listed_and_does_not_fail_the_run` (00:10 on 11-04, yesterday 48 kept), `test_accept_empty_zeroes_a_day_only_when_nexus_still_answers_empty`, read and view tests |
| R2-2 (major) | Two really emptied days wedged the sync | An empty stored day is not a failure: synced_through and last_synced_at advance, the day is not put on the pending list, the stale warning depends only on last_synced_at. The daily 7-day pass re-asks it; items again → stored and dropped from the list | `test_an_empty_answer_for_a_stored_day_keeps_the_rows_is_listed_and_does_not_fail_the_run`, `test_the_daily_pass_stores_a_day_that_has_items_again_and_drops_it_from_the_list` |
| R2-4 | A daily pass that could not get the lock was silent | `nexus_stock_daily_pass` = `{at, status}` (`skipped_locked`, logged; `ok`/`failed` after a run); /item says "Nexus daily 7-day pass skipped (locked) at …" until the next daily pass runs | `test_a_daily_pass_that_cannot_get_the_lock_is_recorded_not_silent`, read and view tests |
| R2-5 | The Nexus answer shape (above) | Per-day `stock_in` list read; 429 stops the run | `test_the_real_nexus_shape_counts_the_lots_of_the_asked_day_and_the_old_number_shape_still_works`, `test_a_429_stops_the_run_and_the_rest_is_asked_next_run`, `test_an_item_missing_from_a_later_valid_answer_for_a_stored_day_is_set_to_0_and_logged` |

Kept: an item missing from a valid non-empty answer is zeroed (logged), skipped items are protected, all-invalid
answers are malformed. The test fixtures now send the real Nexus shape.

## Story changes

- **S-220.1.2**: one `?date=` request per day from the start (33 as of 2026-11-03), each window one day; the stored
  per-day pieces sum to what Nexus returned per day. (The Nexus code shows the range call's `stock_in` does carry
  per-day entries, so a range backfill is possible later; one request per day was kept as decided.)
- **S-220.1.12 (added)**: a stored item missing from a later valid, non-empty answer for the same day is set to 0 (not
  deleted) and logged with day, name_key and old pieces.
- **S-220.1.13 (added)**: `--from=Y-m-d` re-asks older days by hand, never before the start.
- **S-220.2.4**: an empty answer for a day with no stored rows is a quiet success; for a day with stored rows see
  S-220.1.15 (kept, listed, not a failure). Malformed JSON, an answer for another date, or an answer whose every item
  is invalid: a warning, no change, no advance.
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
- **From the review (added)**: S-220.1.14 (a skipped item keeps its row), S-220.1.16 (daily 7-day pass), S-220.1.17
  (pending days), S-220.1.18 (Unicode spaces), S-220.3.10 (exact first, ambiguous family), S-220.6.13 (token missing
  after a sync), S-220.7.6 (remaining per name's own as_of), S-220.9.6 (one cold computation), S-220.9.7 (perf target
  guard); S-220.2.4 now also covers an answer whose every item is invalid.
- **From review 2**: the timed acceptance (old S-220.1.15) and the mass-empty guard (old S-220.1.19) are removed.
  New S-220.1.15 (an empty stored day is kept, listed and not a failure), S-220.1.19 (`--accept-empty`), S-220.1.20
  (the daily pass drops a day that has items again), S-220.1.21 (a skipped daily pass is recorded), S-220.1.22 (the
  real Nexus `stock_in` shape), S-220.1.23 (429 stops the run). S-220.1.12 now states the per-day meaning of a missing
  item.

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
| `nexus_stock_pending_days` | app_settings | — | JSON list of days that failed outside the normal window (`--from`, `--days`); asked again by the next run. |
| `nexus_stock_unconfirmed_empty` | app_settings | — | JSON list of days Nexus answered empty although rows are stored; the rows are kept and /item warns. Cleared per day by `--accept-empty` or by a later answer with items. |
| `nexus_stock_daily_pass` | app_settings | — | JSON `{at, status}` of the last daily 7-day pass (`ok`, `failed`, `skipped_locked`); /item warns on `skipped_locked`. |
| `services.nexus.cold_wait` | config | 15 (s) | How long a cold /item/stock waits for another request computing the same stock inputs. |
| `item_stock_start` | app_settings | existing | Unchanged (2026-10-02 on Likha). |

## Deploy steps (Likha; Incepxion needs only steps 1–2)

1. Deploy the code; `php artisan migrate --force` (two migrations: the table and the `stock_returned_source` seed).
2. `php artisan config:cache` if the server caches config. The schedule runs under the existing `schedule:run` cron:
   no supervisor change. Two schedule entries: hourly at :10 and the daily 7-day pass at 02:40 Manila.
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
4. If /item says "Nexus returned nothing for <days>; old numbers kept": check those days in Nexus. If the stock-in really is gone, run `php artisan nexus:sync-stock --accept-empty=YYYY-MM-DD` for each day; otherwise wait, the daily pass picks them up when Nexus answers again.

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
   start, source and sync; warm calls are faster. On production the returned query took 835 ms with
   `idx_from_jnts_signing_status` (read-only preview), less than the synthetic 1.5 s; concurrent cold requests now
   compute once.
2. **Free-text Nexus names**: a name that matches no Likha item adds nothing; the unmatched list is the only signal.
3. **The zeroing rule** trusts a non-empty Nexus answer for a day as complete (Nexus lists every item that came in that
   day or is on the shelf). If Nexus ever returns a partial non-empty list for a day, the missing items for that day go
   to 0 until the next good answer (each zeroing is logged). An empty answer never zeroes anything; a day that really
   lost all its stock-in needs `nexus:sync-stock --accept-empty=YYYY-MM-DD` by hand, and until then /item warns and
   keeps the old numbers.
4. **Corrections older than 7 days** are only picked up with `nexus:sync-stock --from=…` by hand.
5. **Nexus remaining** is the latest per name and may be missing for items Nexus did not list recently: a dash.
6. **Phone width (S-220.6.9)** is checked on the markup only (wrapping CSS, scrolling list); no browser run was made.
7. **`item_type_mappings` rows whose item_name carries an "N x" prefix** never match a base key on either side (as in
   the bridge the spec describes). Likha has 0 mapping rows today.
8. **A pending day that keeps failing** (an HTTP error, not an empty answer) keeps the run "not complete", so the
   3-hour warning shows until that day answers.
10. **Nexus rate limit**: 60 calls an hour per token. A `--from` over more than about 55 days stops at the 429 and the
    rest is asked by the following runs.
9. **17 of 55 Nexus names did not match** in the live preview: they add nothing until renamed or mapped (owner check 2).
